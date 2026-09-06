# Plan 004: Add rate limiting to hardware API endpoints

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- routes/api.php app/Http/Kernel.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: LOW
- **Risk**: MED
- **Depends on**: none
> **Drift check (run first)**: `git diff --stat HEAD -- routes/api.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: LOW
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `843e820`, 2026-09-06

## Why this matters

The hardware API endpoints (`/api/hardware/*`) have no rate limiting, which
could allow abuse through bulk operations. The `bulkMark` and `bulkDelete`
endpoints process unlimited items per request. Adding throttling protects
against denial-of-service and excessive resource consumption.

## Current state

- `routes/api.php` — hardware routes are registered without throttle middleware:
  ```php
  Route::middleware('auth:sanctum')->group(function () {
      // ... hardware routes
  });
  ```
- `app/Http/Kernel.php` — no custom throttle middleware defined.

Convention: Laravel's built-in `throttle` middleware is already available.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test rate limiting | `curl -I http://localhost:8000/api/hardware` (repeated) | 429 after limit |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `routes/api.php`

**Out of scope:**
- `app/Http/Kernel.php` — using built-in throttle middleware.
- Hardware bulk operation limits (separate concern).

## Steps

### Step 1: Add throttle middleware to API routes

Open `routes/api.php` and add `throttle:api` to the auth group:

```php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // ... existing routes
});
```

**Verify**: `php -l routes/api.php` → exit 0, no syntax errors

### Step 2: Verify throttle is registered

In `app/Http/Kernel.php`, ensure `$middlewareAliases` includes:
```php
'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
```

**Verify**: `grep -n "throttle" app/Http/Kernel.php` shows the middleware

### Step 3: Run PHPStan

```bash
composer phpstan
```

**Verify**: exit 0

### Step 4: Run tests

```bash
composer test
```

**Verify**: all tests pass (some tests may need adjustment if they hit rate limits)

## Test plan

- Existing `HardwareControllerTest` and `HardwareBulkAuditSuppressionTest`
  cover the endpoints.
- If tests fail due to rate limiting, increase the limit in test environment
  by adding to `.env.testing`:
  ```
  RATE_LIMIT_SECONDS=1
  RATE_LIMIT_MAX_ATTEMPTS=1000
  ```

## Done criteria

- [ ] Hardware API routes have `throttle:api` middleware
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] Rate limiting returns 429 after exceeding limit

## STOP conditions

- Rate limiting breaks existing tests even after adjusting `.env.testing`.
- The application uses a custom rate limiter that conflicts.

## Maintenance notes

- The default Laravel `api` throttle is 60 requests per minute per IP.
- Consider adding custom rate limits for bulk endpoints (e.g., 10 requests/min
  for `bulkMark` and `bulkDelete`).
- Monitor production logs for `429 Too Many Requests` to tune limits.
