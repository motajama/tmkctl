# Active24-Style Deployment

These steps target shared PHP/MySQL hosting where PHP files are uploaded by FTP/SFTP and MySQL is managed in a hosting control panel.

## 1. Create MySQL Database

In the hosting administration:

- create a MySQL or MariaDB database
- create a database user
- grant that user access to the database
- note the host, database name, username, and password

Use `utf8mb4` if the hosting panel lets you choose the character set.

## 2. Create Local Configuration

Copy:

```text
app/config.example.php
```

to:

```text
app/config.local.php
```

Fill in the production database credentials:

```php
'db_host' => 'HOST_FROM_ACTIVE24',
'db_port' => '3306',
'db_name' => 'DATABASE_NAME',
'db_user' => 'DATABASE_USER',
'db_pass' => 'DATABASE_PASSWORD',
'db_charset' => 'utf8mb4',
'table_prefix' => 'tmkctl_',
```

Generate a real shared login password hash:

```sh
php -r "echo password_hash('your-shared-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste it into both password hash keys in `app/config.local.php`.

Set installer access for setup:

```php
'install_enabled' => true,
'debug' => true,
```

After successful installation, `install.php` attempts to change these values automatically to:

```php
'install_enabled' => false,
'debug' => false,
```

If this automatic update fails because `app/config.local.php` is missing or not writable, set both values manually. Keep `debug` disabled on production.

Never commit or publish `app/config.local.php`.

## 3. Upload Files

Best option: configure the domain web root to point to:

```text
public/
```

Then upload the repository so `app/`, `data/`, `docs/`, `sql/`, and `tools/` are outside the public web root.

If the host requires uploading everything into one public directory, upload all folders but keep the included `.htaccess` files in place. They deny direct web access to `app/`, `data/`, `docs/`, `sql/`, and `tools/`.

## 4. Run Installer

Open:

```text
https://your-domain.example/install.php
```

You should see that database tables are prepared and that the installer/debug mode were disabled automatically.

If `install_enabled` is false, the installer will refuse to run. For local development or a deliberate reinstall, temporarily enable it in `app/config.local.php`, run the installer, then let the installer disable it again. If automatic hardening fails, set `install_enabled => false` and `debug => false` manually.

The installer creates prefixed tables by default:

- `tmkctl_app_settings`
- `tmkctl_students`
- `tmkctl_exam_stack`
- `tmkctl_exam_notes`

If you previously ran an older installer, remove old unprefixed tables manually before reinstalling:

```sql
DROP TABLE IF EXISTS exam_notes;
DROP TABLE IF EXISTS exam_stack;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS app_settings;
```

## 5. Log In and Test

Open:

```text
https://your-domain.example/login.php
```

Log in with the shared password you hashed in `app/config.local.php`.

Test:

- add one student manually
- import a small CSV
- add a student to the stack
- assign a student-selected question
- write and save a note
- export TXT and Markdown
- open OTÁZKY and validate `data/questions.reviewed.json`

## 6. Protect Installer After Setup

Confirm these values are set:

```php
'install_enabled' => false,
'debug' => false,
```

in `app/config.local.php`. The installer normally sets them automatically after a successful run.

You may also remove or rename `public/install.php` on production after setup, but the config toggle is the expected MVP protection.

## 7. Manual Backups

Back up privately:

- MySQL database dump from the hosting panel or `mysqldump`
- `data/questions.reviewed.json`
- `data/backups/` if question packs were replaced through the app
- `app/config.local.php`
- exported TXT/Markdown notes if used as external records

Example database dump:

```sh
mysqldump -h HOST -u USER -p DATABASE_NAME > tmkctl-backup.sql
```

Do not store production database dumps or `config.local.php` in git.

## 8. Question Pack Management

The dashboard loads exam questions from:

```text
data/questions.reviewed.json
```

Use the bottom **OTÁZKY** command, or console command `:questions`, to inspect and validate the current question pack. Upload only a manually reviewed replacement JSON. The validator checks JSON structure and required fields, but it does not verify scholarly accuracy.

The same window can merge one or more JSON files into the current `data/questions.reviewed.json`. The current file is always the base. Validate the merge first, review preview counts and duplicate `id` conflicts, then choose **SLOUČIT / MERGE**. The default conflict strategy keeps existing questions; the alternate strategy replaces existing questions with uploaded ones for matching `id` values.

Before replacing the file through upload or merge, the app creates a backup in:

```text
data/backups/
```

If a backup must be restored, copy the chosen backup manually back to `data/questions.reviewed.json`, then validate it in **OTÁZKY**.

## 9. Shared Workspaces

After login, users choose or create a workspace in **VÝBĚR RELACE / TERMÍNU**. A workspace is one exam run. Students, stack, notes, reset, current term label, and exports are scoped to the selected workspace. Questions remain global.

The app identifies connected browsers with the `tmkctl_client_id` cookie. Cookies must be enabled; otherwise the workspace selection screen blocks entry and shows a warning. Dashboard presence is refreshed by `public/api/heartbeat.php`, and inactive workspaces are hidden from the active list once no fresh presence remains.

Debug deployments can set `debug => true`. In that mode, entering a workspace shows a red warning window with text from `data/debug-stage.txt`.
