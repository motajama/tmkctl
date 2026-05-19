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

