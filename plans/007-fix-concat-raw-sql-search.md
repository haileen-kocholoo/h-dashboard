# Plan 007: Fix PostgreSQL-specific CONCAT in scopeFilterSearch

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- app/Models/Hardware.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: correctness
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The `scopeFilterSearch` method uses PostgreSQL-specific `CONCAT` function:

```php
->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
```

This works on PostgreSQL but would fail on MySQL. The project has
`.env.example.mysql` suggesting MySQL support is planned or was used.
Making this cross-platform improves portability.

## Current state

- `app/Models/Hardware.php` line 133:
  ```php
  ->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
  ```
- `.env.example.mysql` exists — MySQL was/is a deployment target.
- The rest of the model uses standard Eloquent, not raw SQL.

Convention: prefer Eloquent query builder over raw SQL for portability.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test search | `php artisan tinker --execute 'App\Models\Hardware::scopeFilterSearch(App\Models\Hardware::query(), "test")->toSql()'` | Shows query |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `app/Models/Hardware.php`

**Out of scope:**
- Other raw SQL in the codebase (GIS queries, etc.) — those are PostgreSQL-
  specific by design.

## Steps

### Step 1: Replace CONCAT with Eloquent query

In `app/Models/Hardware.php`, find the `scopeFilterSearch` method and
replace the `orWhereRaw` with two separate `orWhere` conditions:

**Before:**
```php
->orWhereRaw("CONCAT(persons.f_name, ' ', persons.l_name) LIKE ?", ["%{$s}%"]);
```

**After:**
```php
->orWhere(function ($q) use ($s) {
    $q->where('persons.f_name', 'LIKE', "%{$s}%")
      ->orWhere('persons.l_name', 'LIKE', "%{$s}%");
});
```

**Verify**: `php -l app/Models/Hardware.php` → exit 0, no syntax errors

### Step 2: Run Pint formatter

```bash
vendor/bin/pint --dirty --format agent
```

**Verify**: exit 0

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

- Existing `HardwareIndexLivewireTest` tests search functionality.
- The search should now work on both PostgreSQL and MySQL.

## Done criteria

- [ ] `scopeFilterSearch` no longer uses `orWhereRaw` with `CONCAT`
- [ ] Search still returns results for first name, last name, and full name
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests

## STOP conditions

- Search functionality breaks after the change (test with real data).

## Maintenance notes

- The new approach searches `f_name` and `l_name` separately, which may
  return slightly different results (e.g., searching "John Doe" would match
  any record with "John" in first name OR "Doe" in last name).
- If exact full-name matching is needed, consider a computed column or
  concatenation in the SELECT clause.
