# tmkctl

**tmkctl** is a lightweight PHP/MySQL oral exam dashboard for the course *Teorie masové kultury*.

It targets shared PHP/MySQL hosting, including Active24-style environments.

## MVP Scope

Implemented:

- shared-password login with PHP sessions
- CSRF protection for POST actions
- MySQL/MariaDB schema installer
- student entry and CSV import
- exam stack management
- read-only question display from `data/questions.reviewed.json`
- examiner notes with autosave
- TXT/Markdown export
- disabled AI chat placeholder

Not implemented yet:

- AI chat
- Hugging Face integration
- embeddings or vector database

## Directory Structure

```text
app/        PHP application code and configuration loader
data/       reviewed question JSON and sample student data
docs/       project notes and deployment documentation
public/     web root for the PHP app
public/api/ PHP JSON/download endpoints
sql/        database schema and database notes
tools/      future offline tools
```

## Configuration Strategy

Committed:

- `app/config.php` - safe loader with defaults and environment variable support
- `app/config.example.php` - template documenting all required keys

Not committed:

- `app/config.local.php` - local or production credentials and password hash

`app/config.php` automatically loads `app/config.local.php` when it exists. Never commit `app/config.local.php`.

## Local Development Quickstart

Requirements:

- PHP 8+
- MySQL or MariaDB
- PHP PDO MySQL extension, for example `php8.2-mysql` on Debian/Ubuntu

Create a database:

```sql
CREATE DATABASE tmkctl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tmkctl'@'localhost' IDENTIFIED BY 'tmkctl_dev_password';
GRANT ALL PRIVILEGES ON tmkctl.* TO 'tmkctl'@'localhost';
FLUSH PRIVILEGES;
```

Create local config:

```sh
cp app/config.example.php app/config.local.php
```

For the local database above, use:

```php
'db_host' => '127.0.0.1',
'db_port' => '3306',
'db_name' => 'tmkctl',
'db_user' => 'tmkctl',
'db_pass' => 'tmkctl_dev_password',
'db_charset' => 'utf8mb4',
'install_enabled' => true,
'debug' => true,
```

The example config uses development login password:

```text
tmkctl
```

Start PHP:

```sh
php -S localhost:8000 -t public
```

Run installer:

```text
http://localhost:8000/install.php
```

Then log in:

```text
http://localhost:8000/login.php
```

After successful installation, `install.php` attempts to set this automatically in `app/config.local.php`:

```php
'install_enabled' => false,
'debug' => false,
```

If that automatic hardening fails because the file is missing or not writable, set both values manually. For local development, re-enable them manually when you need to rerun the installer.

## Deployment Overview

Preferred deployment:

- configure the hosting web root to `public/`
- keep `app/`, `data/`, `docs/`, `sql/`, and `tools/` outside public web access

If shared hosting requires uploading everything into one public directory, keep the included `.htaccess` files. They deny direct access to non-public folders.

Production steps:

1. Create a MySQL database and user in the hosting control panel.
2. Copy `app/config.example.php` to `app/config.local.php`.
3. Fill production DB credentials in `app/config.local.php`.
4. Generate a real password hash:

   ```sh
   php -r "echo password_hash('your-shared-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```

5. Set `install_enabled => true`.
6. Upload files.
7. Open `public/install.php` through the browser.
8. Confirm the installer reports that `install_enabled` and `debug` were disabled automatically. If it cannot update `app/config.local.php`, set both values to `false` manually.
9. Log in and import students.

Detailed instructions are in [docs/deployment-active24.md](docs/deployment-active24.md).

## Database Schema

The installer and schema file create:

- `tmkctl_app_settings`
- `tmkctl_students`
- `tmkctl_exam_stack`
- `tmkctl_exam_notes`

The default table prefix is `tmkctl_`. It can be changed with the `table_prefix` config key before installation.

Schema export:

```text
sql/schema.sql
```

Questions are not inserted into the database in the MVP. They are loaded from:

```text
data/questions.reviewed.json
```

If you previously ran an older installer, remove old unprefixed tables manually before reinstalling:

```sql
DROP TABLE IF EXISTS exam_notes;
DROP TABLE IF EXISTS exam_stack;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS app_settings;
```

## CSV Import

CSV must include:

```csv
name
```

Optional columns:

```csv
uco,email,study_type
```

Recognized `study_type` values:

- `jednoobor`, `jednooborové`, `jednooborovy`, `1obor`, `single` -> `single`
- `dvouobor`, `dvouoborové`, `dvouoborovy`, `2obor`, `double` -> `double`
- empty or unknown values -> `unknown`

Sample file:

```text
data/students.sample.csv
```

## Import From IS MU

For SZZ pages in IS MU, open the exam terms page, press `Ctrl+A`, then `Ctrl+C`, and paste the whole page text into **Import z IS MU**.

The importer detects exam-term blocks and offers only terms related to **Teorie masové komunikace / TMK**. Thesis defense terms and unrelated SZZ blocks are ignored even when they contain MSZU students. Choose the TMK date/day, preview students, then import selected rows or all importable rows.

Study codes are mapped as:

- `MSZU01` -> `single` / `jednoobor`
- `MSZU02` -> `double` / `dvouobor`

The preview shows duplicates before import. Rows with an existing UČO are skipped by default. Questions are still loaded from `data/questions.reviewed.json`; the IS import only adds students to the waiting stack.

Parser sample:

```text
data/is-mu-paste.sample.txt
```

## Exam-Day Operation

Set the current exam-term label in the bottom status bar, for example `TMK - 10. 6. 2026`, and save it with **ULOŽIT TERMÍN**. The label is stored as workspace-scoped `current_exam_label` and shown in the bottom status bar.

Recommended workflow for one exam term:

1. Set the exam-term label.
2. Import students from CSV or IS MU.
3. Run the exam through the waiting, preparation, examination, and done states.
4. Save notes continuously.
5. After the exam term, use the bottom **EXPORTUJ VŠE** command.
6. Before the next exam term, use the bottom **RESET** command.

Exports:

- **MARKDOWN FILE** downloads a Markdown file with all notes.
- **TXT FILE** downloads a plain-text file with all notes.
- **JSON/ZIP** downloads a ZIP with `notes.md`, `notes.txt`, `students.csv`, and `exam_state.json` when the PHP `ZipArchive` extension is available. Without it, the app downloads a JSON export.
- **KOPÍROVAT VŠE** copies all notes to the clipboard when the browser allows it.

Exam-term reset is available only through POST with a CSRF token and requires exact confirmation text `RESET`. It deletes students, stack state, and notes for the current run, and clears the current selection in that workspace. Questions in `data/questions.reviewed.json`, app configuration, and the database schema remain unchanged. The exam-term label remains unchanged unless **Smazat i název termínu** is checked.

Always export before resetting. Reset is not a backup mechanism and it does not delete questions.

## Question Management

Questions live in:

```text
data/questions.reviewed.json
```

The bottom **OTÁZKY** command opens a window for inspecting the current pack, uploading a new manually reviewed JSON file, and merging multiple JSON files. The same window opens through console command `:questions`.

Validation checks only file structure:

- valid JSON and top-level array
- required `id`, `title`, outline fields, and metadata fields
- duplicate `id` values
- allowed `review_status` values: `reviewed`, `generated`, `needs_review`
- warnings for empty `source_refs` and items that are not `reviewed`

Validation does not check academic correctness. Upload only a manually reviewed file. The **NAHRÁT NOVÝ BALÍK** button replaces the entire `questions.reviewed.json`, but only after successful validation. Before replacement, the previous file is backed up automatically to:

```text
data/backups/questions.reviewed.YYYYMMDD-HHMMSS.json
```

Backup restore is manual at this stage: copy the selected backup back to `data/questions.reviewed.json` and validate it in the **OTÁZKY** window.

### Merging Multiple JSON Files

The **MERGE JSON** section uses the current `data/questions.reviewed.json` as the base. Select one or more JSON files and run **VALIDOVAT MERGE** first. The preview shows:

- current question count
- added question count
- replaced question count
- conflicting duplicate `id` values
- validation warnings and errors

Conflicting `id` values are resolved with one global strategy:

- `ponechat existující` is the default and never overwrites a question from the current file
- `nahradit nahranými` uses the uploaded JSON question for the same `id`

The **SLOUČIT / MERGE** button writes the result only when every input file is valid and the merged pack is valid too. A backup is always created in `data/backups/` before writing.

## Help Content

The **NÁPOVĚDA** window loads content from this fragment:

```text
public/assets/help.html
```

The fragment does not contain `<html>`, `<head>`, `<body>`, or CSS. Formatting and image embedding are described in `docs/help-format.md`.

A later phase may add an AI generator that prepares `generated` JSON. This phase only manages the final reviewed file and does not generate questions.

## Shared Workspaces

After login, the app opens **VÝBĚR RELACE / TERMÍNU**. A workspace represents one shared exam run. Multiple users can enter the same workspace and see the students, queue, preparation slot, active examination, notes, and exports for that term together.

Active workspaces are visible only while at least one browser has fresh presence in them. Presence is refreshed by a heartbeat request. Browser identity uses the `tmkctl_client_id` cookie; cookies must be enabled, otherwise the user cannot enter a workspace.

Students, stack state, notes, reset, and exports are scoped by workspace. Questions in `data/questions.reviewed.json` remain global for the whole installation. Details are in `docs/workspaces.md`.

## Debug Mode

When config `debug => true`, entering the dashboard shows a red warning window titled **DEBUG REŽIM — NEFINÁLNÍ VERZE**. The current phase text is read from:

```text
data/debug-stage.txt
```

The file is plain editable TXT. If it is missing, the app shows a safe fallback message without internal paths or secret values.

## Offline Question Generator

Optional local Python tools can prepare draft question packs from seed questions and local teaching materials:

```text
docs/offline-question-generator.md
```

The PHP web dashboard does not require Python and does not call AI. Generated files must be reviewed by a human before they become `data/questions.reviewed.json` and are uploaded through **OTÁZKY**.

## Security Notes

- Do not commit `app/config.local.php`.
- Do not deploy with the example password.
- After setup, keep `install_enabled => false` and `debug => false`. The installer attempts to set both automatically.
- Back up `app/config.local.php` privately.
- Back up the MySQL database before exams or deployments.
- Back up `data/questions.reviewed.json`; the web app treats it as read-only.

## Manual Backup

Back up:

- MySQL database dump
- `data/questions.reviewed.json`
- `app/config.local.php` privately
- exported notes, if needed as separate exam records

Example:

```sh
mysqldump -h HOST -u USER -p DATABASE_NAME > tmkctl-backup.sql
```
