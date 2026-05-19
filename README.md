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

## MVP status

This repository contains the first PHP/MySQL MVP:

- shared-password login with PHP sessions
- repeatable installer for MySQL/MariaDB tables
- read-only question display from `data/questions.reviewed.json`
- student add/import workflow
- oral exam stack with allowed state transitions
- active-student and manual-question modes
- examiner notes with autosave and TXT/Markdown export
- disabled AI chat placeholder for a later phase

The MVP intentionally does not include AI, Hugging Face, PDF/PPTX parsing, Node.js, React, Tailwind, Composer dependencies, or a Python server.

## Local setup

Requirements:

- PHP 8+
- MySQL or MariaDB
- PDO MySQL extension enabled

On Debian/Ubuntu with PHP 8.2, the local package is usually:

```sh
sudo apt install php8.2-mysql
```

Create a database and user, then configure the app. The committed `app/config.php` uses environment variables and placeholder defaults only. For local development you can either edit `app/config.php` or export variables:

```sh
export TMKCTL_DB_HOST=127.0.0.1
export TMKCTL_DB_PORT=3306
export TMKCTL_DB_NAME=tmkctl
export TMKCTL_DB_USER=tmkctl
export TMKCTL_DB_PASS='your-db-password'
export TMKCTL_PASSWORD_HASH="$(php -r 'echo password_hash("change-me", PASSWORD_DEFAULT);')"
```

Start the built-in PHP server from the repository root:

```sh
php -S 127.0.0.1:8000 -t public
```

Open:

- `http://127.0.0.1:8000/install.php` to create/update tables
- `http://127.0.0.1:8000/login.php` to sign in

The placeholder development password in `app/config.php` is `change-me`. Replace it before using the app anywhere real.

## Student CSV import

CSV files must use this header:

```csv
name,uco,email,study_type
```

Recognized `study_type` values are normalized as:

- `jednoobor`, `jednooborové`, `jednooborovy`, `1obor`, `single` -> `single`
- `dvouobor`, `dvouoborové`, `dvouoborovy`, `2obor`, `double` -> `double`
- empty or unknown values -> `unknown`

See `data/students.sample.csv`.

## Active24-style deployment

Upload the repository so that the hosting web root points to `public/`. If the host cannot point the domain directly at `public/`, place the contents of `public/` in the web-accessible directory and keep `app/` and `data/` outside public access when possible.

Set MySQL credentials and a real password hash in `app/config.php`, or use hosting environment variables if available:

- `TMKCTL_DB_HOST`
- `TMKCTL_DB_PORT`
- `TMKCTL_DB_NAME`
- `TMKCTL_DB_USER`
- `TMKCTL_DB_PASS`
- `TMKCTL_PASSWORD_HASH`

Run `public/install.php` after upload. It only creates missing tables and is safe to run repeatedly.

Do not deploy with the placeholder password hash. Generate a real hash with:

```sh
php -r "echo password_hash('your-shared-password', PASSWORD_DEFAULT), PHP_EOL;"
```
