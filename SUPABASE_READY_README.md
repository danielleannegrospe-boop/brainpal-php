# BrainPal Supabase/PostgreSQL Conversion Package

This package is based on `brainpal(20260913-074420).zip` and the working
Supabase PostgreSQL migration.

## What was updated

- Centralized PHP PostgreSQL connection in `_shared/db.php`
- Legacy `mysqli`-style API compatibility retained for existing endpoints
- PostgreSQL translation for common MySQL SQL patterns:
  - `NOW()`, `CURDATE()`
  - `DATE_ADD`, `DATE_SUB`, `TIMESTAMPDIFF`
  - `WEEKDAY`
  - `DATE_FORMAT`, `TIME_FORMAT`
  - `GROUP_CONCAT`
  - MySQL `IF(...)` expressions
  - `INSERT IGNORE`
  - `ON DUPLICATE KEY UPDATE`
  - MySQL runtime `CREATE TABLE` syntax (`AUTO_INCREMENT`, `TINYINT(1)`,
    `ENUM`, `ENGINE`, `COLLATE`, `UNIQUE KEY`, `KEY`)
  - MySQL double-quoted string literals
  - BrainPal mixed-case columns such as `firstName`, `lastName`, `studentNo`
- Student login was corrected for PostgreSQL and verified against Supabase.
- Registration now persists the selected `semester`.
- SMTP configuration no longer contains a hard-coded fallback password.
- Added `.env.example`.
- Added `test-student-registration.html`.
- Kept `test-supabase.php` for connection testing.
- PHP syntax checked all non-vendor PHP files.

## Environment

Copy `.env.example` values into `.env` and fill in:

- `SUPABASE_DB_USER`
- `SUPABASE_DB_PASSWORD`
- `BP_SMTP_PASSWORD`

Do not commit or publicly upload `.env`.

## Local test order

1. Start Apache in XAMPP.
2. Open:
   `http://localhost/brainpal/test-supabase.php`
3. Test academic endpoint:
   `http://localhost/brainpal/register/get-active-academic.php`
4. Test admin login with `test-admin-login.html`.
5. Test student login with `test-student-login.html`.
6. Test registration with `test-student-registration.html`.
7. For registration email verification, call the existing student OTP endpoint after registration.

## Important

The ZIP contains no real passwords in `.env`. Re-enter the current
Supabase database password and Gmail App Password in `.env` before testing.

After the migration is working, rotate any database password or Gmail App
Password that was previously exposed outside the local `.env`.
