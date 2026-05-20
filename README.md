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
- PDF/PPTX parsing
- offline question generation
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

## Import z IS MU

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

## Provoz zkouškového dne

Ve spodním stavovém řádku nastav název aktuálního termínu, například `TMK - 10. 6. 2026`, a ulož ho tlačítkem **ULOŽIT TERMÍN**. Název se ukládá do `tmkctl_app_settings` pod klíčem `current_exam_label` a zobrazuje se ve spodním stavovém řádku.

Doporučený postup pro jeden termín:

1. Nastav název termínu.
2. Importuj studující z CSV nebo z IS MU.
3. Proveď zkoušení přes frontu, potítko, zkoušení a hotovo.
4. Průběžně ukládej poznámky.
5. Po skončení použij spodní příkaz **EXPORTUJ VŠE**.
6. Před dalším termínem použij spodní příkaz **RESET**.

Exporty:

- **MARKDOWN FILE** stáhne Markdown soubor se všemi poznámkami.
- **TXT FILE** stáhne prostý text se všemi poznámkami.
- **JSON/ZIP** stáhne ZIP s `notes.md`, `notes.txt`, `students.csv` a `exam_state.json`, pokud je dostupné PHP rozšíření `ZipArchive`. Bez něj stáhne JSON export.
- **KOPÍROVAT VŠE** zkopíruje všechny poznámky do schránky, pokud ji prohlížeč zpřístupní.

Reset termínu je dostupný jen přes POST s CSRF tokenem a vyžaduje přesné potvrzení `RESET`. Smaže studující, stack a poznámky aktuálního běhu a resetuje aktivního studujícího. Otázky v `data/questions.reviewed.json`, konfigurace aplikace a databázové schéma zůstávají zachované. Název termínu zůstává zachovaný, pokud nezaškrtneš **Smazat i název termínu**.

Před resetem vždy nejdřív udělej export. Reset neslouží jako záloha a nemaže otázky.

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
