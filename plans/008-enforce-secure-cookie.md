# Plan 008: Enforce SESSION_SECURE_COOKIE in production environment

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- config/session.php .env.example .env.example.pgsql`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

The `SESSION_SECURE_COOKIE` configuration defaults to `false`, which means session cookies can be sent over unencrypted HTTP connections. In a healthcare application handling sensitive patient data, this allows session hijacking via network sniffing. The cookie should only be transmitted over HTTPS.

## Current state

- `config/session.php` line 172:
  ```php
  'secure' => env('SESSION_SECURE_COOKIE'),
  ```
- `.env.example` does not define `SESSION_SECURE_COOKIE` — defaults to `null` which is `false`.
- `.env.example.pgsql` does not define `SESSION_SECURE_COOKIE` either.

Convention: Laravel defaults `null` to `false`, which is insecure for production.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Check config | `php artisan tinker --execute 'dd(config("session.secure"))'` | `null` or `false` |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `.env.example`
- `.env.example.pgsql`
- `.env.example.mysql`

**Out of scope:**
- `.env` (production config — not committed to repo)
- `config/session.php` (already correct, just needs env value)

## Steps

### Step 1: Add SESSION_SECURE_COOKIE to .env.example

In `.env.example`, add after the `SESSION_DRIVER` line:

```env
SESSION_SECURE_COOKIE=true
```

### Step 2: Add to .env.example.pgsql

In `.env.example.pgsql`, add:

```env
SESSION_SECURE_COOKIE=true
```

### Step 3: Add to .env.example.mysql

In `.env.example.mysql`, add:

```env
SESSION_SECURE_COOKIE=true
```

### Step 4: Verify configuration loads correctly

```bash
php artisan tinker --execute 'dd(config("session.secure"))'
```

**Verify**: Shows `true`

### Step 5: Run PHPStan

```bash
composer phpstan
```

**Verify**: exit 0

### Step 6: Run tests

```bash
composer test
```

**Verify**: all tests pass

## Test plan

- No new tests needed — configuration change only.
- Existing session-related tests will verify the change doesn't break anything.

## Done criteria

- [ ] `SESSION_SECURE_COOKIE=true` exists in all `.env.example*` files
- [ ] `php artisan tinker --execute 'dd(config("session.secure"))'` returns `true`
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests

## STOP conditions

- Session tests fail after setting `true` (may need to set to `null` in testing environment).

## Maintenance notes

- For local development without HTTPS, set `SESSION_SECURE_COOKIE=false` in local `.env`.
- Ensure production deployment sets this to `true`.
- Consider adding to deployment checklist.
