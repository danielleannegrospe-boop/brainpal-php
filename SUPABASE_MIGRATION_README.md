# BrainPal — Supabase/PostgreSQL PHP API Patch

This ZIP contains the BrainPal PHP API converted to use Supabase PostgreSQL while keeping the existing Ionic/Angular API routes.

## What changed
- Centralized PHP database access in `_shared/db.php` using PDO PostgreSQL.
- Existing `mysqli` endpoint patterns (`prepare`, `bind_param`, `get_result`, `fetch_assoc`, transactions, `insert_id`, etc.) are supported through a compatibility layer so the majority of existing endpoint code does not need to be rewritten manually.
- MySQL-only SQL patterns used by BrainPal were normalized where practical:
  - `NOW()` / `CURDATE()`
  - `DATE_ADD`
  - `DATE_FORMAT` / `TIME_FORMAT`
  - `IFNULL`
  - `GROUP_CONCAT`
  - `INSERT IGNORE`
  - `ON DUPLICATE KEY UPDATE`
  - runtime `CREATE TABLE` clauses using `AUTO_INCREMENT`, `TINYINT(1)`, `ENUM`, `ENGINE=InnoDB`, etc.
- Legacy boolean `0/1` writes are converted for PostgreSQL boolean columns.
- Direct MySQL connections in endpoints were replaced with the shared Supabase connection.
- `diagnostic/update-points.php` now uses the same PostgreSQL connection.
- Vendor libraries were not modified.

## Required setup
Edit `.env` and fill in:
- `SUPABASE_DB_HOST`
- `SUPABASE_DB_PORT`
- `SUPABASE_DB_NAME`
- `SUPABASE_DB_USER`
- `SUPABASE_DB_PASSWORD`

Also restore your real SMTP password in `BP_SMTP_PASSWORD` if your server needs the existing mail functionality.

For production, define these variables in the hosting provider's environment settings instead of committing secrets.

## PHP requirements
Enable:
- `pdo_pgsql`
- `pgsql`

## Important
The PHP API must run on a server/host that can reach Supabase over PostgreSQL. The Ionic app should continue calling the PHP API URL; it does not need direct access to the Supabase database.

## First test
Create a temporary test endpoint or use an existing endpoint after configuring `.env`. Confirm that the API can connect before testing login, registration, dashboard, diagnostic, quiz, schedule, and notifications.
