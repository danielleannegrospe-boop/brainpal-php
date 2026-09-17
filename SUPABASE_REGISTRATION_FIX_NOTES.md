# BrainPal Supabase Registration Fix

This patch fixes PostgreSQL boolean binding for legacy mysqli-style bind_param() calls.

Also updates register/save-user.php to:
- quote case-sensitive PostgreSQL identifiers such as firstName/lastName
- send is_verified and diagnostic_completed as PostgreSQL booleans
- return statement debug information when INSERT fails

After extraction, keep your existing working .env values. Do not replace your .env with placeholder values.

Test: http://localhost/brainpal/test-student-registration.html
