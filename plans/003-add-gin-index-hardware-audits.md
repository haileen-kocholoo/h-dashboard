# Plan 003: Add GIN index on hardware_audits.changes for faster JSON queries

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- database/migrations/`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: perf
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The `hardware_audits` table has a GIN index on `changes` column (from
migration `2026_08_11_000001_add_gin_index_to_hardware_audits_changes.php`),
but it's used with `whereJsonContains` queries that may not fully utilize
the index. The audit modal and audit list use this query pattern:

```php
$query->whereJsonContains('changes', ['field' => $request->field]);
```

This is O(n) scan on large tables. Adding a proper functional GIN index
with `jsonb_path_ops` will speed up these queries significantly.

## Current state

- `database/migrations/2026_08_11_000001_add_gin_index_to_hardware_audits_changes.php`:
  Adds a GIN index but may be using default `jsonb_ops` which is slower
  for containment queries.
- `app/Http/Controllers/Api/HardwareAuditController.php` line 33:
  ```php
  $query->whereJsonContains('changes', ['field' => $request->field]);
  ```
- `app/Models/HardwareAudit.php`:
  ```php
  protected $casts = [
      'changes' => 'array',
  ];
  ```

Convention: migrations follow `YYYY_MM_DD_000001_description.php` format.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Check existing index | `php artisan tinker --execute 'dd(\Schema::getIndexListing("hardware_audits"))'` | Shows current indexes |
| Run migration | `php artisan migrate` | exit 0 |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- New migration file: `database/migrations/YYYY_MM_DD_000002_add_gin_jsonb_path_ops_to_hardware_audits.php`
- `app/Http/Controllers/Api/HardwareAuditController.php` (optional optimization)

**Out of scope:**
- `app/Models/HardwareAudit.php` — casts stay as-is.

## Steps

### Step 1: Create a new migration to add functional GIN index

Create `database/migrations/2026_09_06_000001_add_gin_jsonb_path_ops_to_hardware_audits.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_hardware_audits_changes_jsonb_path_ops ON hardware_audits USING gin (changes jsonb_path_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_hardware_audits_changes_jsonb_path_ops');
    }
};
```

**Verify**: `php artisan migrate --force` → exit 0

### Step 2: Verify index exists

```bash
php artisan tinker --execute 'dd(\DB::select("SELECT indexname FROM pg_indexes WHERE tablename = '\''hardware_audits'\'' AND indexname LIKE '\''%jsonb_path_ops'\''"))'
```

**Verify**: Returns the new index name

### Step 3: Run PHPStan

```bash
composer phpstan
```

**Verify**: exit 0

### Step 4: Run tests

```bash
composer test
```

**Verify**: all tests pass

## Test plan

- No new tests needed — this is a database optimization, not behavior change.
- The existing `HardwareAuditTest` and `HardwareAuditModalLivewireTest`
  cover the audit functionality.

## Done criteria

- [ ] New GIN index with `jsonb_path_ops` exists on `hardware_audits.changes`
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] `php artisan migrate` reports no pending migrations

## STOP conditions

- The existing GIN index already uses `jsonb_path_ops` (check first).
- Database doesn't support GIN indexes (using MySQL instead of PostgreSQL).

## Maintenance notes

- `CONCURRENTLY` requires PostgreSQL — this project uses PostgreSQL, so it's safe.
- For MySQL, this migration should be adapted to use a different approach.
- Monitor query performance after deployment with `EXPLAIN ANALYZE`.
