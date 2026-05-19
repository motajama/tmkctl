# tmkctl

**tmkctl** is a lightweight PHP/MySQL oral exam dashboard for the course *Teorie masové kultury*.

It is designed for shared PHP hosting, especially Active24-style hosting.

## Goals

- fast oral exam workflow
- stable PHP/MySQL dashboard
- student stack management
- question display from reviewed JSON
- examiner notes
- TXT/Markdown export
- retro academic workstation UI

## Non-goals for MVP

The first version does not implement:

- AI chat
- Hugging Face integration
- PDF/PPTX analysis
- automatic question generation
- embeddings
- local LLM tools

These will be added later as a separate offline preparation pipeline.

## Architecture

The project is split into two layers:

1. PHP web dashboard  
   Runs on shared hosting.

2. Offline AI preparation tools  
   To be added later. They will generate `data/questions.reviewed.json`.

## Directory structure

```text
app/        PHP application code
data/       reviewed question JSON and sample student data
docs/       project notes and specifications
public/     web root
public/api/ PHP endpoints
sql/        database schema
tools/      future offline tools
```

## Current MVP

The current MVP is a plain PHP 8+/PDO/MySQL application with:

- shared-password login using PHP sessions
- CSRF protection for POST actions
- repeatable `install.php` table creation
- read-only question display from `data/questions.reviewed.json`
- manual student entry and CSV import
- exam stack workflow
- notes with autosave, manual save, TXT export, and Markdown export

AI chat, Hugging Face integration, PDF/PPTX parsing, and the offline question generator are not implemented yet.

## Local Setup

Requirements:

- PHP 8+
- MySQL or MariaDB
- PHP PDO MySQL extension, usually `php8.2-mysql` on Debian/Ubuntu

Create the local database and user:

```sql
CREATE DATABASE tmkctl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tmkctl'@'localhost' IDENTIFIED BY 'tmkctl_dev_password';
GRANT ALL PRIVILEGES ON tmkctl.* TO 'tmkctl'@'localhost';
FLUSH PRIVILEGES;
```

Copy or edit config:

```sh
cp app/config.example.php app/config.php
```

For local testing, `app/config.php` should contain:

```php
'db_host' => '127.0.0.1',
'db_port' => '3306',
'db_name' => 'tmkctl',
'db_user' => 'tmkctl',
'db_pass' => 'tmkctl_dev_password',
'db_charset' => 'utf8mb4',
```

The default development login password in the committed config is:

```text
tmkctl
```

Generate a real shared password hash before deployment:

```sh
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Run the installer:

```sh
php -S localhost:8000 -t public
```

Open:

```text
http://localhost:8000/install.php
```

Then sign in at:

```text
http://localhost:8000/login.php
```

## CSV Import

CSV must include the `name` column. Optional columns are:

- `uco`
- `email`
- `study_type`

Empty rows are skipped. Students with the same UČO are updated rather than duplicated. If UČO is missing, the importer tries to avoid duplicates by matching normalized name and email.
