# Plan 005: Fix static $suppressAudit race condition in concurrent requests

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- app/Models/Hardware.php app/Http/Controllers/Api/HardwareController.php app/Observers/HardwareAuditObserver.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED
- **Depends on**: none
- **Category**: correctness
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

`Hardware::$suppressAudit` is a static boolean flag. When multiple concurrent
requests execute bulk operations, one request's `finally` block can reset the
flag while another request is still mid-operation, causing audit entries to
leak or be incorrectly suppressed.

Example race:
```
Request A: suppressAudit = true  (bulkMark starts)
Request B: suppressAudit = true  (bulkMark starts)
Request A: suppressAudit = false (bulkMark ends) — fine
Request B: suppressAudit = false (bulkMark ends) — fine

BUT if Request A takes longer:
Request A: suppressAudit = true
Request B: suppressAudit = true
Request B: suppressAudit = false — now A's flag is cleared
Request A: suppressAudit = false — redundant but OK
```

In PHP-FPM/Apache, each request gets its own process, so static state is
isolated. However, in Laravel Octane or long-running workers, this becomes
a real problem.

## Current state

- `app/Models/Hardware.php` line 21:
  ```php
  public static bool $suppressAudit = false;
  ```

- `app/Http/Controllers/Api/HardwareController.php` lines 296-303:
  ```php
  Hardware::$suppressAudit = true;
  try {
      $count = Hardware::whereIn('id', $accessibleHardwareIds)
          ->update(['mark' => $validated['mark']]);
  } finally {
      Hardware::$suppressAudit = false;
  }
  ```

- `app/Observers/HardwareAuditObserver.php` lines 16-18:
  ```php
  if (Hardware::$suppressAudit) {
      return;
  }
  ```

Convention: static flags are acceptable in standard PHP-FPM deployments.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test audit suppression | `composer test -- --filter=BulkAudit` | all pass |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `app/Models/Hardware.php`
- `app/Http/Controllers/Api/HardwareController.php`
- `app/Observers/HardwareAuditObserver.php`

**Out of scope:**
- Changing the observer to use events (larger refactor, separate plan).

## Steps

### Step 1: Replace static flag with request-scoped context

Instead of a static boolean, use a request-scoped key. Modify the approach
to check if we're in a bulk context via the observer itself:

In `app/Models/Hardware.php`, keep the static property but document its
limitations:

```php
/**
 * Flag to suppress audit logging during bulk operations.
 *
 * NOTE: This is safe in standard PHP-FPM deployments where each request
 * has its own process. In Laravel Octane or long-running workers, use
 * request()->attributes->set('suppress_audit', true) instead.
 */
public static bool $suppressAudit = false;
```

### Step 2: Add a request-scoped alternative

In `app/Observers/HardwareAuditObserver.php`, update the suppression check:

```php
protected function shouldSuppress(): bool
{
    return Hardware::$suppressAudit
        || request()->attributes->get('suppress_audit', false);
}
```

Then replace all `Hardware::$suppressAudit` checks with `$this->shouldSuppress()`.

### Step 3: Update HardwareController to use request attributes

```php
// Instead of:
Hardware::$suppressAudit = true;
try { ... }
finally { Hardware::$suppressAudit = false; }

// Use:
request()->attributes->set('suppress_audit', true);
try { ... }
finally { request()->attributes->forget('suppress_audit'); }
```

**Verify**: `php -l` on all modified files → exit 0

### Step 4: Run tests

```bash
composer test -- --filter=BulkAudit
composer test
```

**Verify**: all tests pass

## Test plan

- Existing `HardwareBulkAuditSuppressionTest` covers this functionality.
- Add a test that verifies request-scoped suppression works:
  ```php
  test('request attribute suppress_audit prevents audit logging', function () {
      // Set request attribute
      request()->attributes->set('suppress_audit', true);
      
      // Create hardware (should not log audit)
      $hardware = Hardware::factory()->create();
      
      // Verify no audit entry
      $this->assertDatabaseMissing('hardware_audits', [
          'hardware_id' => $hardware->id,
          'action' => 'created',
      ]);
      
      // Cleanup
      request()->attributes->forget('suppress_audit');
  });
  ```

## Done criteria

- [ ] `HardwareAuditObserver` uses `shouldSuppress()` method
- [ ] `HardwareController` uses request attributes for suppression
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] New test for request-scoped suppression exists

## STOP conditions

- The observer is used in contexts where `request()` is not available (e.g.,
  artisan commands, queue jobs). In that case, fall back to static flag.

## Maintenance notes

- For Laravel Octane deployments, the request-scoped approach is essential.
- Document this pattern in `AGENTS.md` for future contributors.
- Consider a service class to encapsulate bulk operation context.
