# Offline Question Generator

This toolchain prepares draft question packs locally. It does not change the PHP dashboard and it does not call any cloud API.

The reviewed production file remains:

```text
data/questions.reviewed.json
```

Generated output must be reviewed by a human before it is renamed or uploaded through the web UI window **OTÁZKY**.

## Directory Layout

```text
materials/
  pdf/
  pptx/
  docx/
  txt/
  md/

data/questions.seed.json
data/generated/

tools/extract_materials.py
tools/prepare_question_pack.py
tools/validate_question_pack.py
tools/question_schema.py
tools/llm_ollama.py
```

Put teaching materials into the matching `materials/` subdirectory. The required extractor supports `.txt` and `.md` with only the Python standard library.

Optional extractors are best-effort:

- PDF: install `pymupdf`
- DOCX: install `python-docx`
- PPTX: install `python-pptx`

If an optional dependency is missing, extraction skips that file type with a warning.

## Question Seeds

Edit:

```text
data/questions.seed.json
```

Each seed is intentionally small:

```json
{
  "id": "q01",
  "title": "Masová kultura a kulturní průmysl",
  "short_title": "Kulturní průmysl",
  "hints": ["masová kultura", "kulturní průmysl", "Adorno", "Horkheimer"]
}
```

Seeds define what questions should exist. Materials provide evidence and wording for draft content.

## No-LLM Workflow

This mode works without AI and without extra Python packages.

```sh
python tools/extract_materials.py
python tools/prepare_question_pack.py --no-llm
python tools/validate_question_pack.py data/generated/questions.generated.json
```

If your system does not provide `python`, use `python3`.

Outputs:

```text
data/generated/corpus.jsonl
data/generated/questions.generated.json
data/generated/questions.generation-report.md
```

The no-LLM output is skeletal but structurally valid. It uses keyword matching from seed titles and hints to attach `source_refs`.

## Ollama Workflow

Ollama support is local-only and optional.

Recommended models:

- `qwen3:4b` for weaker machines
- `qwen3:8b` for stronger Apple Silicon or machines with more memory

Start Ollama:

```sh
ollama run qwen3:4b
```

Generate with Ollama:

```sh
python tools/extract_materials.py
python tools/prepare_question_pack.py --ollama --model qwen3:4b
python tools/validate_question_pack.py data/generated/questions.generated.json
```

Use a different local endpoint if needed:

```sh
python tools/prepare_question_pack.py --ollama --model qwen3:8b --ollama-url http://localhost:11434
```

If Ollama is unavailable, the script explains how to start it and falls back per question only when recoverable. Use `--debug` to see raw tracebacks during development.

## Review Workflow

```text
questions.generated.json
-> human review and edits
-> questions.reviewed.json
-> upload through web UI OTÁZKY
```

The validator checks structure, not academic correctness. It cannot verify whether claims, authors, or interpretations are scholarly accurate.

## Git Hygiene

Generated files are ignored by default:

```text
data/generated/corpus.jsonl
data/generated/questions.generated.json
data/generated/questions.generation-report.md
```

Do not commit local model files, full extracted corpora, copyrighted teaching materials, or large temporary outputs.
