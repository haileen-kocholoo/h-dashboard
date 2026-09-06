# Plan 002: Remove deprecated histories() method from Hardware model

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

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The `histories()` method on `Hardware` model is deprecated (Issue #246 merge)
and replaced by `audits()`. Keeping deprecated code in the codebase creates
confusion for developers who may not know which method to use. The method
is no longer called anywhere, so removing it is safe.

## Current state

- `app/Models/Hardware.php` lines 103-109:
  ```php
  /**
   * @deprecated Use audits() instead (Issue #246 merge).
   */
  public function histories(): HasMany
  {
      return $this->hasMany(HardwareAudit::class, 'hardware_id');
  }
  ```
- The `audits()` method (lines 98-101) already provides the same functionality:
  ```php
  public function audits(): HasMany
  {
      return $this->hasMany(HardwareAudit::class);
  }
  ```

Convention: deprecated methods should be removed once all callers have migrated.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHPStan check | `composer phpstan` | exit 0 |
| Pint format | `vendor/bin/pint --dirty --format agent` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `app/Models/Hardware.php`

**Out of scope:**
- No other files reference `histories()` — verified by grep.

## Steps

### Step 1: Remove the deprecated method

Open `app/Models/Hardware.php` and delete lines 103-109 (the entire
`histories()` method including its docblock).

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

### Step 4: Run full test suite

```bash
composer test
```

**Verify**: all tests pass (928+)

## Test plan

- No new tests needed — removing unused dead code.
- Verify existing tests pass.

## Done criteria

- [ ] `histories()` method no longer exists in `Hardware.php`
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] `vendor/bin/pint --dirty --format agent` exits 0

## STOP conditions

- If any file calls `->histories()` — that file must be migrated to
  `->audits()` first. (Grep search found zero callers.)

## Maintenance notes

- New code must use `->audits()` relationship on `Hardware` model.
- Consider adding a test that asserts `Hardware::class` has `audits()` method
  to prevent future regressions.
