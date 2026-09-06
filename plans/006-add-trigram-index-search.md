# Plan 006: Add trigram index for better search performance on hardware

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- database/migrations/ app/Models/Hardware.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The `scopeFilterSearch` method on `Hardware` model performs multiple
`LIKE %term%` queries on text columns (`pc_name`, `n_code`, `ip_valid`,
`ip_local`, `mac`, `comments`). These are full-table scans because `LIKE`
with leading wildcard cannot use B-tree indexes. For large hardware tables
(thousands of records), this is slow.

PostgreSQL's `pg_trgm` extension with GIN trigram indexes enables fast
`LIKE` and `ILIKE` queries without changing application code.

## Current state

- `app/Models/Hardware.php` lines 124-134:
  ```php
  $query->where(function ($q) use ($s) {
      $q->where('hardwares.pc_name', 'LIKE', "%{$s}%")
          ->orWhere('hardwares.n_code', 'LIKE', "%{$s}%")
          ->orWhere('hardwares.ip_valid', 'LIKE', "%{$s}%")
          ->orWhere('hardwares.ip_local', 'LIKE', "%{$s}%")
          ->orWhere('hardwares.mac', 'LIKE', "%{$s}%")
          ->orWhere('hardwares.comments', 'LIKE', "%{$s}%")
          ->orWhere('persons.f_name', 'LIKE', "%{$s}%")
          ->orWhere('persons.l_name', 'LIKE', "%{$s}%")
          ->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
  });
  ```

Convention: PostGIS is already used, so `pg_trgm` is available.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Enable pg_trgm | `php artisan tinker --execute 'DB::statement("CREATE EXTENSION IF NOT EXISTS pg_trgm")'` | exit 0 |
| Run migration | `php artisan migrate` | exit 0 |
| Test search | Query with `LIKE '%test%'` should use index | See EXPLAIN output |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- New migration: `database/migrations/YYYY_MM_DD_000003_add_trigram_indexes_to_hardware_and_persons.php`

**Out of scope:**
- Changing the query to use full-text search (larger refactor).
- Modifying the model scopes.

## Steps

### Step 1: Enable pg_trgm extension

Create migration `database/migrations/2026_09_06_000002_enable_pg_trgm_extension.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // pg_trgm is shared; don't drop it in production.
    }
};
```

### Step 2: Create trigram indexes

Create migration `database/migrations/2026_09_06_000003_add_trigram_indexes_to_hardware_and_persons.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hardware search columns
        $hardwareColumns = ['pc_name', 'n_code', 'ip_valid', 'ip_local', 'mac', 'comments'];
        foreach ($hardwareColumns as $column) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hardware_{$column}_trgm ON hardwares USING gin ({$column} gin_trgm_ops)");
        }

        // Person name columns (used in JOIN search)
        $personColumns = ['f_name', 'l_name'];
        foreach ($personColumns as $column) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_persons_{$column}_trgm ON persons USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        $hardwareColumns = ['pc_name', 'n_code', 'ip_valid', 'ip_local', 'mac', 'comments'];
        foreach ($hardwareColumns as $column) {
            DB::statement("DROP INDEX IF EXISTS idx_hardware_{$column}_trgm");
        }

        $personColumns = ['f_name', 'l_name'];
        foreach ($personColumns as $column) {
            DB::statement("DROP INDEX IF EXISTS idx_persons_{$column}_trgm");
        }
    }
};
```

### Step 3: Run migrations

```bash
php artisan migrate --force
```

**Verify**: `php artisan tinker --execute 'dd(\DB::select("SELECT indexname FROM pg_indexes WHERE tablename = '\''hardwares'\'' AND indexname LIKE '\''%trgm'\''"))'` shows new indexes

### Step 4: Run PHPStan

```bash
composer phpstan
```

**Verify**: exit 0

### Step 5: Run tests

```bash
composer test
```

**Verify**: all tests pass

## Test plan

- No new tests needed — this is a performance optimization.
- Existing `HardwareIndexLivewireTest` and `HardwareControllerTest`
  cover search functionality.

## Done criteria

- [ ] `pg_trgm` extension is enabled
- [ ] GIN trigram indexes exist on `hardwares` text columns
- [ ] GIN trigram indexes exist on `persons` name columns
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] Search queries use indexes (verify with `EXPLAIN ANALYZE`)

## STOP conditions

- Database doesn't support `pg_trgm` (using MySQL).
- Index creation fails due to lock contention (use `CONCURRENTLY`).

## Maintenance notes

- Indexes add ~10-20% storage overhead per column.
- Monitor `pg_stat_user_indexes` to verify indexes are being used.
- For MySQL, use full-text indexes instead of trigram indexes.
- Consider adding search on `persons.f_name || ' ' || persons.l_name`
  concatenated column for better performance.
