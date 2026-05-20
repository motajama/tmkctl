# SQL

This directory contains the database schema for the PHP/MySQL MVP.

Tables:

- tmkctl_app_settings
- tmkctl_students
- tmkctl_exam_stack
- tmkctl_exam_notes

The application default table prefix is `tmkctl_`. Change `table_prefix` in configuration before installation if this deployment needs a different prefix.

Use `schema.sql` for manual database setup, or run `public/install.php` when `install_enabled` is true in `app/config.local.php`.

Questions are not stored in MySQL in the MVP. See `dev-seed-notes.md`.
