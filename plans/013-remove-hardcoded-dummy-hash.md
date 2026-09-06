# Plan 013: Remove hardcoded dummy hash from login endpoint

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

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

The login endpoint uses a hardcoded bcrypt hash (`'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'`) as a dummy hash for constant-time comparison when a user doesn't exist. While the approach is correct (prevents timing attacks), the hardcoded hash is a well-known value that could be used in offline attacks.

## Current state

- `routes/api.php` lines 20-35:
  ```php
  static $dummyHash = null;
  if ($dummyHash === null) {
      $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password'
  }
  $userHash = $user ? $user->password : $dummyHash;
  $passwordMatches = Hash::check($credentials['password'], $userHash);
  ```

The comment `// 'password'` reveals the plaintext the hash was generated from.

Convention: Avoid hardcoding sensitive values in source code.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `routes/api.php` (login route)

**Out of scope:**
- Other authentication logic.

## Steps

### Step 1: Replace hardcoded hash with generated hash

In `routes/api.php`, update the dummy hash generation:

**Before:**
```php
static $dummyHash = null;
if ($dummyHash === null) {
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // 'password'
}
```

**After:**
```php
static $dummyHash = null;
if ($dummyHash === null) {
    // Generate a dummy hash at runtime to avoid hardcoded value in source
    $dummyHash = Hash::make(Str::random(32));
}
```

Add `use Illuminate\Support\Str;` at the top if not already present.

### Step 2: Remove the revealing comment

Remove the comment `// 'password'` that reveals the plaintext.

### Step 3: Verify the code still works

```bash
php artisan tinker --execute 'dd(Hash::check("test", Illuminate\Support\Facades\Hash::make("test")))'
```

**Verify**: Shows `true`

### Step 4: Run PHPStan

```bash
composer phpstan
```

**Verify**: exit 0

### Step 5: Run tests

```bash
composer test
```

**Verify**: all tests pass

## Test plan

- Existing login tests will verify the change doesn't break authentication.
- The behavior should be identical: non-existent users still get timing-attack protection.

## Done criteria

- [ ] No hardcoded bcrypt hash in `routes/api.php`
- [ ] Dummy hash is generated at runtime
- [ ] Comment `// 'password'` is removed
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests

## STOP conditions

- Login fails after the change (test with real credentials).

## Maintenance notes

- The timing attack protection is maintained: `Hash::check` still runs even for non-existent users.
- Consider moving this logic to a dedicated `LoginService` class for better testability.
