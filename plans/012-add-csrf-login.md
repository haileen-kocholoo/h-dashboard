# Plan 012: Add CSRF protection to API login endpoint

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
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
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

The `POST /api/login` endpoint has no CSRF protection, making it vulnerable to Cross-Site Request Forgery attacks. An attacker could create a malicious page that submits a login form on behalf of the user. While the API uses Sanctum tokens, the login endpoint itself should be protected.

## Current state

- `routes/api.php` — login endpoint:
  ```php
  Route::post('/login', function (Request $request) {
      // ... login logic
  })->middleware('throttle:5,1');
  ```
- No `VerifyCsrfToken` middleware on this route.

Convention: Laravel API routes typically don't use CSRF, but login endpoints are exceptions because they create sessions/tokens.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test CSRF | Send POST without CSRF token | 419 response |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `routes/api.php` (login route)

**Out of scope:**
- Other API routes — they use Sanctum tokens, not CSRF.
- Web routes — already have CSRF via middleware stack.

## Steps

### Step 1: Add CSRF verification to login route

In `routes/api.php`, add `VerifyCsrfToken` middleware to the login route:

**Before:**
```php
Route::post('/login', function (Request $request) {
    // ... login logic
})->middleware('throttle:5,1');
```

**After:**
```php
Route::post('/login', function (Request $request) {
    // ... login logic
})->middleware(['throttle:5,1', 'verify_csrf_token']);
```

**Verify**: `php -l routes/api.php` → exit 0

### Step 2: Verify CSRF middleware is registered

In `app/Http/Kernel.php` or `bootstrap/app.php`, ensure `VerifyCsrfToken` is available.

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

- Add a test that verifies CSRF protection on login:
  ```php
  test('login endpoint requires CSRF token', function () {
      $response = $this->postJson('/api/login', [
          'n_code' => '1234567890',
          'password' => 'password',
      ]);
      
      $response->assertStatus(419); // CSRF token mismatch
  });
  ```

## Done criteria

- [ ] Login route has `verify_csrf_token` middleware
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] CSRF token is required for login

## STOP conditions

- Flutter app cannot provide CSRF tokens (may need exemption for API).

## Maintenance notes

- If Flutter app needs to call login endpoint, may need to:
  1. Get CSRF cookie first via `GET /sanctum/csrf-cookie`
  2. Include CSRF token in subsequent POST
- Consider API token-based login for mobile clients instead.
