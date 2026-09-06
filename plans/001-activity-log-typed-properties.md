# Plan 001: Add typed properties to ActivityLog model

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- app/Models/ActivityLog.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The `ActivityLog` model has 6733 lines of PHPStan baseline errors because it
accesses undefined properties (`created_at`, `description`, `ip_address`,
`new_values`, `old_values`, `subject_id`, `subject_type`, `type`). These are
actual database columns but not declared as typed properties on the model.
Fixing this eliminates the baseline errors and improves static analysis
coverage across the codebase.

## Current state

- `app/Models/ActivityLog.php` — model with `$fillable` and `$casts` but no
  typed properties for the 7 columns in question.
- `phpstan-baseline.neon` — 6733 lines, mostly `property.notFound` errors
  for `ActivityLog`.
- `app/Console/Commands/ArchiveOldRecords.php` — accesses these properties
  directly (the source of most PHPStan errors).

The `ArchiveOldRecords` command reads:
```php
// ArchiveOldRecords.php (lines 25-35)
$log->created_at
$log->description
$log->ip_address
$log->new_values
$log->old_values
$log->subject_id
$log->subject_type
$log->type
```

Convention: other models in this repo (e.g., `Hardware`, `Person`) declare
typed properties via `$fillable` and `$casts` — the model should follow suit.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHPStan check | `composer phpstan` | exit 0, no errors |
| Pint format | `vendor/bin/pint --dirty --format agent` | exit 0 |

## Scope

**In scope:**
- `app/Models/ActivityLog.php`
- `phpstan-baseline.neon`

**Out of scope:**
- Any changes to `ArchiveOldRecords.php` — it works correctly; fixing the
  model properties fixes the PHPStan errors it generates.

## Steps

### Step 1: Add typed properties to ActivityLog

Open `app/Models/ActivityLog.php` and add the following typed properties
inside the class body (before the `$fillable`):

```php
protected string $type;
protected ?string $description = null;
protected ?int $subject_id = null;
protected ?string $subject_type = null;
protected ?array $old_values = null;
protected ?array $new_values = null;
protected ?string $ip_address = null;
protected string $created_at;
protected string $updated_at;
```

Also add to `$fillable` if not already present:
```php
'type', 'description', 'subject_id', 'subject_type',
'old_values', 'new_values', 'ip_address',
```

And to `$casts`:
```php
'old_values' => 'array',
'new_values' => 'array',
```

**Verify**: `php -l app/Models/ActivityLog.php` → exit 0, no syntax errors

### Step 2: Regenerate PHPStan baseline

Run:
```bash
composer phpstan-baseline
```

This should regenerate `phpstan-baseline.neon` with significantly fewer
errors (the 6733-line baseline should drop dramatically).

**Verify**: `wc -l phpstan-baseline.neon` → fewer than 1000 lines
(previously 6733)

### Step 3: Run full PHPStan to confirm clean

```bash
composer phpstan
```

**Verify**: exit 0, no errors

### Step 4: Run Pint formatter

```bash
vendor/bin/pint --dirty --format agent
```

**Verify**: exit 0

## Test plan

- No new tests needed — this is a typing improvement, not behavior change.
- Existing tests should continue passing:
  ```bash
  composer test -- --filter=ArchiveOldRecords
  ```

## Done criteria

- [ ] `composer phpstan` exits 0
- [ ] `phpstan-baseline.neon` is < 1000 lines
- [ ] `vendor/bin/pint --dirty --format agent` exits 0
- [ ] No files outside in-scope list are modified

## STOP conditions

- The `ActivityLog` migration doesn't have the columns we're typing (verify
  with `php artisan tinker --execute 'dd(\Schema::getColumnListing("activity_logs"))'`)
- PHPStan reports new errors after the fix that aren't in the baseline.

## Maintenance notes

- Future columns added to `activity_logs` table must be added as typed
  properties on this model to avoid PHPStan regressions.
- Consider adding `@property` PHPDoc annotations for IDE autocompletion
  as a follow-up.
