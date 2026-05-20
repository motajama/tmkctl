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
```

After installation, change it to:

```php
'install_enabled' => false,
```

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

You should see that database tables are prepared.

If `install_enabled` is false, the installer will refuse to run. Temporarily enable it in `app/config.local.php`, run the installer, then disable it again.

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

## 6. Protect Installer After Setup

Set:

```php
'install_enabled' => false,
```

in `app/config.local.php`.

You may also remove or rename `public/install.php` on production after setup, but the config toggle is the expected MVP protection.

## 7. Manual Backups

Back up privately:

- MySQL database dump from the hosting panel or `mysqldump`
- `data/questions.reviewed.json`
- `app/config.local.php`
- exported TXT/Markdown notes if used as external records

Example database dump:

```sh
mysqldump -h HOST -u USER -p DATABASE_NAME > tmkctl-backup.sql
```

Do not store production database dumps or `config.local.php` in git.
