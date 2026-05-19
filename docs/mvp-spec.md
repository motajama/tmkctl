# tmkctl MVP specification

## Purpose

tmkctl is a PHP/MySQL oral exam dashboard for the course Teorie masové kultury.

The application supports the examiner during oral examination.

## Hosting

The app must run on shared PHP/MySQL hosting such as Active24.

Do not require:

- Node.js
- React
- Python server
- Composer-only dependencies
- background workers
- local AI service
- ChromaDB
- vector database

## MVP features

### 1. Login

- One shared password.
- PHP sessions.
- All pages except login require authentication.

### 2. Questions

- Questions are read from `data/questions.reviewed.json`.
- The JSON is read-only for the web app.
- Show:
  - title
  - short title
  - outline
  - key terms
  - authors
  - examiner focus
  - follow-up questions
  - common mistakes
  - source warning if sources are empty

### 3. Students

- Manual add.
- CSV import.
- Fields:
  - name
  - uco
  - email
  - study_type

Study types:

- single = jednoobor
- double = dvouobor
- unknown = neznámé

Normalize values:

- jednoobor, jednooborové, jednooborovy, 1obor, single => single
- dvouobor, dvouoborové, dvouoborovy, 2obor, double => double
- empty or unknown => unknown

### 4. Exam stack

Four states:

- waiting
- preparing
- examining
- done

Allowed moves:

- waiting -> preparing
- preparing -> examining
- examining -> done
- preparing -> waiting
- examining -> preparing
- done -> examining

Each stack item can have an assigned question.

### 5. Question panel

Two modes:

1. Follow active student.
2. Manual question selection.

Manual mode must clearly warn:

> RUČNÍ REŽIM – tato otázka není přiřazena aktivnímu studujícímu.

### 6. Notes

Notes are connected to:

- student_id
- question_id

Features:

- textarea
- suggested grade
- autosave every 8 seconds
- manual save
- copy to clipboard
- download TXT
- download Markdown

Exports include:

- course name
- date/time
- student name
- UČO
- email
- study type
- question title
- examiner notes
- suggested grade

### 7. UI

Style:

- lightweight retro academic workstation
- VISI-ON / GEM / Motif inspired
- sharp borders
- gray/beige panels
- monospace font
- no cyberpunk
- no animations
- no Tailwind
- no React

Main layout:

- left: students
- center: question
- right: notes
- bottom: disabled AI chat placeholder

### 8. AI

AI is not part of MVP.

The AI chat panel should exist visually but be disabled:

> AI chat bude doplněn v další fázi.

## Implementation preference

Use:

- plain PHP 8+
- PDO
- MySQL
- sessions
- vanilla JS
- plain CSS

Prefer working simplicity over architectural elegance.
