# Plan 011: Implement per-user rate limiting for API

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- routes/api.php app/Providers/AppServiceProvider.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

Current rate limiting is IP-based (`throttle:60,1`), which means:
1. All users behind the same proxy/NAT share the same rate limit.
2. Legitimate users get throttled when another user on the same IP makes many requests.
3. A single compromised account can exhaust the rate limit for all users behind that IP.

Per-user rate limiting based on API token or user ID is more accurate and fair.

## Current state

- `routes/api.php` line 20:
  ```php
  Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
  ```
- Login endpoint uses `throttle:5,1` (per IP).

Convention: Laravel supports custom rate limiters via `AppServiceProvider`.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Check current rate | `curl -I http://localhost:8000/api/user` (multiple times) | Shows rate limit headers |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `app/Providers/AppServiceProvider.php`
- `routes/api.php`

**Out of scope:**
- Login endpoint — already has its own rate limit.
- Login rate limit change — separate concern.

## Steps

### Step 1: Define custom rate limiter in AppServiceProvider

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('api-user', function (Request $request) {
    return Limit::perMinute(60)->by(
        $request->user()?->id ?? $request->ip()
    );
});
```

### Step 2: Update routes/api.php

Change the throttle middleware from `throttle:60,1` to `throttle:api-user`:

**Before:**
```php
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
```

**After:**
```php
Route::middleware(['auth:sanctum', 'throttle:api-user'])->group(function () {
```

**Verify**: `php -l routes/api.php` → exit 0

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

- Add a test that verifies per-user rate limiting:
  ```php
  test('rate limit is per user not per IP', function () {
      $user = User::factory()->create();
      $token = $user->createToken('test')->plainTextToken;
      
      // Make 60 requests (should succeed)
      for ($i = 0; $i < 60; $i++) {
          $this->withHeader('Authorization', 'Bearer ' . $token)
               ->getJson('/api/user')
               ->assertOk();
      }
      
      // 61st request should be rate limited
      $this->withHeader('Authorization', 'Bearer ' . $token)
           ->getJson('/api/user')
           ->assertStatus(429);
  });
  ```

## Done criteria

- [ ] `api-user` rate limiter defined in `AppServiceProvider`
- [ ] `routes/api.php` uses `throttle:api-user`
- [ ] Rate limit is based on user ID, not IP
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests

## STOP conditions

- Tests fail due to rate limiting (increase limit or add cache driver).

## Maintenance notes

- Consider different limits for different endpoints (read vs write).
- Monitor rate limiting in production to tune the 60 req/min limit.
- Consider adding rate limit headers to responses (`X-RateLimit-Remaining`, etc.).
