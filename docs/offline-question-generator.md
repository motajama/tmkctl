# Offline Question Generator

This local toolchain prepares draft question packs. It does not change the PHP dashboard and it does not call any cloud API.

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
tools/extract_question_seeds.py
tools/prepare_question_pack.py
tools/validate_question_pack.py
tools/question_schema.py
tools/llm_ollama.py
```

Teaching materials may be placed directly into `materials/` or into any nested subdirectory. Extraction scans recursively by extension and does not move or modify source files.

Supported extensions:

- `.txt`
- `.md`
- `.pdf`
- `.docx`
- `.pptx`

Required `.txt` and `.md` support uses only the Python standard library. Optional formats need local packages:

```sh
python -m pip install pymupdf python-docx python-pptx
```

If your system does not provide `python`, use `python3`.

## Extract Materials

```sh
python tools/extract_materials.py \
  --materials-dir materials \
  --output data/generated/corpus.jsonl
```

The output is chunked JSONL with source metadata, page numbers for PDFs, and slide numbers for PPTX where available.

## Question Seeds From JSON

You can maintain seeds manually in:

```text
data/questions.seed.json
```

Seed format:

```json
{
  "id": "q01",
  "title": "Masová kultura a kulturní průmysl",
  "short_title": "Kulturní průmysl",
  "hints": ["masová kultura", "kulturní průmysl", "Adorno", "Horkheimer"]
}
```

## Question Seeds From Human-Readable Files

A question source file can be DOCX, TXT, Markdown, or PDF if PyMuPDF is installed. It should be a human-readable list of questions.

The extractor recognizes numbered lists, Czech labels such as `Otázka 1:`, Markdown bullets, and headings. It is heuristic and the extracted seed file must be checked.

```sh
python tools/extract_question_seeds.py \
  --input materials/questions.docx \
  --output data/generated/questions.extracted.seed.json \
  --language cs
```

`prepare_question_pack.py` can also use the source directly:

```sh
python tools/prepare_question_pack.py \
  --no-llm \
  --question-source materials/questions.docx \
  --seed-output data/generated/questions.extracted.seed.json \
  --language cs \
  --detail 0.5 \
  --corpus data/generated/corpus.jsonl \
  --output data/generated/questions.generated.json
```

## Language And Detail

Language:

```sh
--language cs
--language en
```

JSON field names stay unchanged. Content is generated in the selected language where possible. In no-LLM mode, seed titles are not translated.

Detail:

```sh
--detail 0.0
--detail 0.5
--detail 1.0
```

- `0.0` = brief
- `0.5` = normal
- `1.0` = detailed

More detail must still remain source-based. It is not permission to invent unsupported claims.

## No-LLM Workflow

From existing seed JSON:

```sh
python tools/prepare_question_pack.py \
  --no-llm \
  --language cs \
  --detail 0.5 \
  --questions data/questions.seed.json \
  --corpus data/generated/corpus.jsonl \
  --output data/generated/questions.generated.json
```

Validate:

```sh
python tools/validate_question_pack.py data/generated/questions.generated.json
```

The no-LLM output is skeletal but structurally valid.

## Ollama Workflow

Ollama support is local-only and optional.

Recommended models:

- `qwen3:1.7b` for weak machines and quick tests
- `qwen3:4b` for stronger laptops
- `qwen3:8b` for stronger Apple Silicon or machines with more memory

## Local Ollama In /home

Start Ollama outside the web app:

```sh
ollama-home-serve
```

Pull the small test model:

```sh
ollama-home pull qwen3:1.7b
```

Generate one Czech test question from a question-source file:

```sh
python tools/prepare_question_pack.py \
  --ollama \
  --model qwen3:1.7b \
  --limit 1 \
  --question-source materials/questions.docx \
  --seed-output data/generated/questions.extracted.seed.json \
  --language cs \
  --detail 0.5 \
  --corpus data/generated/corpus.jsonl \
  --output data/generated/questions.generated.json
```

Ollama, English, detailed:

```sh
python tools/prepare_question_pack.py \
  --ollama \
  --model qwen3:1.7b \
  --limit 1 \
  --language en \
  --detail 0.8 \
  --questions data/questions.seed.json \
  --corpus data/generated/corpus.jsonl \
  --output data/generated/questions.generated.json
```

Useful controls:

- `--limit 1` for weak machines
- `--top-k 6` controls how many chunks are sent to the prompt
- `--max-context-chars 12000` caps prompt context
- `--timeout 300` gives slow local models enough time
- `--temperature 0.1` keeps output conservative

The web dashboard remains independent of Ollama.

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
data/generated/questions.extracted.seed.json
data/generated/question-seed-extraction-report.md
```

Material directories are ignored by default except `.gitkeep` placeholders and the tiny artificial sample text. Do not commit local model files, full extracted corpora, copyrighted teaching materials, or large temporary outputs.
