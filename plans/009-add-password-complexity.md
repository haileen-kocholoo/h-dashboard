# Plan 009: Add password complexity requirements

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat HEAD -- resources/views/livewire/auth/register.blade.php routes/api.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: MED
- **Depends on**: none
- **Category**: security
- **Planned at**: commit `5d8af3a`, 2026-09-06

## Why this matters

Currently, the only password validation is `required`, which allows single-character passwords like "a" or "1". For a healthcare application handling sensitive data, this is a significant security risk. Weak passwords are the most common attack vector.

## Current state

- `resources/views/livewire/auth/register.blade.php` — registration form validates:
  ```php
  'password' => 'required|string|min:6|confirmed',
  ```
  (or similar — need to verify)

- `routes/api.php` — API login only validates:
  ```php
  'password' => 'required',
  ```

Convention: Laravel provides `Password::min(8)->mixedCase()->numbers()` rules.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Test registration | `php artisan tinker --execute 'dd(Validator::make(["password" => "abc"], ["password" => "required|string|min:8|confirmed"])->fails())'` | `true` |
| PHPStan check | `composer phpstan` | exit 0 |
| Tests | `composer test` | all pass |

## Scope

**In scope:**
- `resources/views/livewire/auth/register.blade.php` (Livewire component)
- `routes/api.php` (if there's a registration endpoint)

**Out of scope:**
- Login endpoint — only validates credentials exist, doesn't create new passwords
- Existing user passwords — not forcing password changes

## Steps

### Step 1: Update registration password validation

In `resources/views/livewire/auth/register.blade.php`, find the password validation rule and update it:

**Before:**
```php
'password' => 'required|string|min:6|confirmed',
```

**After:**
```php
'password' => [
    'required',
    'string',
    'min:8',
    'max:255',
    'confirmed',
    \Illuminate\Validation\Rules\Password::min(8)
        ->mixedCase()
        ->numbers()
        ->uncompromised(),
],
```

**Verify**: `php -l resources/views/livewire/auth/register.blade.php` → exit 0

### Step 2: Add error message for Persian users

In the same file, add a custom validation message:

```php
'password.min' => 'رمز عبور باید حداقل ۸ کاراکتر باشد',
'password.mixed' => 'رمز عبور باید شامل حروف بزرگ و کوچک باشد',
'password.numbers' => 'رمز عبور باید شامل اعداد باشد',
```

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

- Add a test that verifies weak passwords are rejected:
  ```php
  test('registration rejects weak passwords', function () {
      Livewire::test('auth.register', [
          'n_code' => '1234567890',
          'name' => 'Test',
          'password' => 'weak',
          'password_confirmation' => 'weak',
      ])
      ->assertHasErrors(['password']);
  });
  ```
- Add a test that verifies strong passwords are accepted:
  ```php
  test('registration accepts strong passwords', function () {
      Livewire::test('auth.register', [
          'n_code' => '1234567890',
          'name' => 'Test',
          'password' => 'StrongPass123',
          'password_confirmation' => 'StrongPass123',
      ])
      ->assertHasNoErrors(['password']);
  });
  ```

## Done criteria

- [ ] Password validation requires minimum 8 characters
- [ ] Password validation requires mixed case letters
- [ ] Password validation requires at least one number
- [ ] `composer phpstan` exits 0
- [ ] `composer test` passes all tests
- [ ] New tests for password validation exist and pass

## STOP conditions

- `Password::uncompromised()` requires API access to HaveIBeenPwned — if API is unavailable, remove that rule.

## Maintenance notes

- Consider adding password strength indicator to registration form.
- Document password requirements in UI.
- Consider adding password expiration policy for healthcare compliance.
