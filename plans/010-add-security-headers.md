# Plan 010: Add security headers middleware

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- app/Http/Middleware/ routes/web.php bootstrap/app.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

The application doesn't set security headers like `X-Content-Type-Options`, `X-Frame-Options`, or `Referrer-Policy`. These headers protect against MIME sniffing, clickjacking, and information leakage. For a healthcare application, these are important defense-in-depth measures.

## Current state

- No `X-Content-Type-Options` header set.
- No `X-Frame-Options` header set.
- No `Referrer-Policy` header set.
- `config/session.php` has `http_only` set correctly.

Convention: Laravel recommends using middleware for security headers.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test headers | `curl -I http://localhost:8000/login` | Shows security headers |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- New file: `app/Http/Middleware/SecurityHeaders.php`
- `bootstrap/app.php` (register middleware)

**Out of scope:**
- CSP (Content Security Policy) — complex, separate effort.
- HSTS — requires HTTPS to be enabled first.

## Steps

### Step 1: Create SecurityHeaders middleware

Create `app/Http/Middleware/SecurityHeaders.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        return $response;
    }
}
```

### Step 2: Register middleware in bootstrap/app.php

Add to `bootstrap/app.php` in the middleware stack:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \App\Http\Middleware\SecurityHeaders::class,
    ]);
})
```

**Verify**: `php -l bootstrap/app.php` → exit 0

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

### Step 5: Verify headers are present

```bash
php artisan serve &
sleep 2
curl -I http://localhost:8000/login
kill %1
```

**Verify**: Response includes:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`

## Test plan

- Add a test that verifies security headers are present:
  ```php
  test('security headers are present on responses', function () {
      $response = $this->get('/login');
      
      $response->assertHeader('X-Content-Type-Options', 'nosniff');
      $response->assertHeader('X-Frame-Options', 'DENY');
      $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  });
  ```

## Done criteria

- [ ] `SecurityHeaders` middleware exists
- [ ] Middleware is registered in `bootstrap/app.php`
- [ ] `X-Content-Type-Options: nosniff` header present
- [ ] `X-Frame-Options: DENY` header present
- [ ] `Referrer-Policy: strict-origin-when-cross-origin` header present
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests

## STOP conditions

- Headers conflict with existing functionality (e.g., iframe embedding).

## Maintenance notes

- Consider adding CSP headers in a future iteration.
- Monitor for any iframe embedding requirements that conflict with `X-Frame-Options: DENY`.
- Consider adding `Strict-Transport-Security` when HTTPS is enabled.
