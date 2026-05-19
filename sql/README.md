# SQL

This directory contains the database schema for the PHP/MySQL MVP.

Tables:

- students
- exam_stack
- exam_notes
- app_settings

Use `schema.sql` for manual database setup, or run `public/install.php` when `install_enabled` is true in `app/config.local.php`.

Questions are not stored in MySQL in the MVP. See `dev-seed-notes.md`.
