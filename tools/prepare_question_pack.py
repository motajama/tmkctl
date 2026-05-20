#!/usr/bin/env python3
"""Prepare a generated question pack from seeds and extracted corpus chunks."""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any

from llm_ollama import ollama_chat
from question_schema import minimal_question, validate_questions


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def load_corpus(path: Path) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    if not path.is_file():
        return rows
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            rows.append(json.loads(line))
    return rows


def tokenize(value: str) -> list[str]:
    return [token.lower() for token in re.findall(r"[\wÁ-ž]{3,}", value, flags=re.UNICODE)]


def seed_keywords(seed: dict[str, Any]) -> list[str]:
    values = [str(seed.get("title", "")), str(seed.get("short_title", ""))]
    values.extend(str(item) for item in seed.get("hints", []))
    seen: set[str] = set()
    keywords: list[str] = []
    for value in values:
        for token in tokenize(value):
            if token not in seen:
                seen.add(token)
                keywords.append(token)
    return keywords


def match_chunks(seed: dict[str, Any], corpus: list[dict[str, Any]], limit: int = 5) -> list[dict[str, Any]]:
    keywords = seed_keywords(seed)
    scored: list[tuple[int, dict[str, Any]]] = []
    for chunk in corpus:
        text = str(chunk.get("text", "")).lower()
        score = sum(text.count(keyword) for keyword in keywords)
        if score > 0:
            scored.append((score, chunk))
    scored.sort(key=lambda item: (-item[0], str(item[1].get("chunk_id", ""))))
    return [chunk for _, chunk in scored[:limit]]


def source_refs(chunks: list[dict[str, Any]]) -> list[dict[str, Any]]:
    refs: list[dict[str, Any]] = []
    for chunk in chunks:
        refs.append(
            {
                "chunk_id": chunk.get("chunk_id", ""),
                "source": chunk.get("source", ""),
                "source_path": chunk.get("source_path", ""),
                "page": chunk.get("page"),
            }
        )
    return refs


def fallback_question(seed: dict[str, Any], chunks: list[dict[str, Any]]) -> dict[str, Any]:
    question = minimal_question(seed, source_refs(chunks))
    hints = [str(value).strip() for value in seed.get("hints", []) if str(value).strip()]
    if hints:
        question["outline"] = [
            f"Vymezit téma: {seed.get('title', '')}.",
            "Vysvětlit hlavní pojmy: " + ", ".join(hints[:4]) + ".",
            "Doplnit oporu v materiálech a konkrétní příklady při ruční revizi.",
        ]
        question["key_terms"] = [
            {"term": hint, "definition": "Doplnit definici při ruční revizi.", "authors": []}
            for hint in hints[:5]
        ]
    if not chunks:
        question["source_refs"] = [
            {
                "chunk_id": "no_match",
                "source": "questions.seed.json",
                "source_path": "data/questions.seed.json",
                "page": None,
            }
        ]
    return question


def build_prompt(seed: dict[str, Any], chunks: list[dict[str, Any]]) -> str:
    chunk_text = "\n\n".join(
        f"[{chunk.get('chunk_id')}] {chunk.get('source')} page={chunk.get('page')}\n{chunk.get('text')}"
        for chunk in chunks
    )
    return f"""Vytvoř návrh jedné otázky pro ústní zkoušku v češtině.

Vrať pouze validní JSON objekt. Nepiš markdown ani komentáře.
Pracuj pouze z dodaných úryvků a nevymýšlej zdroje.
Pokud je opora slabá, nech pole stručná a nastav review_status na "needs_review".
Jinak nastav review_status na "generated".
source_refs musí odkazovat pouze na dodané chunk_id.

Požadovaný tvar:
{{
  "id": "...",
  "title": "...",
  "short_title": "...",
  "outline": [],
  "key_terms": [],
  "authors": [],
  "examiner_focus": [],
  "followup_questions": [],
  "common_mistakes": [],
  "source_refs": [],
  "review_status": "generated"
}}

Seed:
{json.dumps(seed, ensure_ascii=False, indent=2)}

Úryvky:
{chunk_text}
"""


def extract_json_object(raw: str) -> dict[str, Any] | None:
    cleaned = raw.strip()
    if cleaned.startswith("```"):
        cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned)
        cleaned = re.sub(r"\s*```$", "", cleaned)
    try:
        value = json.loads(cleaned)
    except json.JSONDecodeError:
        start = cleaned.find("{")
        end = cleaned.rfind("}")
        if start < 0 or end <= start:
            return None
        try:
            value = json.loads(cleaned[start : end + 1])
        except json.JSONDecodeError:
            return None
    return value if isinstance(value, dict) else None


def normalize_generated_question(seed: dict[str, Any], candidate: dict[str, Any], chunks: list[dict[str, Any]]) -> dict[str, Any]:
    fallback = fallback_question(seed, chunks)
    question = dict(fallback)
    question.update(candidate)
    question["id"] = str(seed.get("id", question.get("id", ""))).strip()
    question["title"] = str(seed.get("title", question.get("title", ""))).strip()
    question["short_title"] = str(seed.get("short_title", question.get("short_title", ""))).strip() or question["title"]
    for field in ["outline", "key_terms", "authors", "examiner_focus", "followup_questions", "common_mistakes", "source_refs"]:
        if not isinstance(question.get(field), list):
            question[field] = fallback[field]
    if question.get("review_status") not in {"generated", "needs_review", "reviewed"}:
        question["review_status"] = "needs_review"
    if question["review_status"] == "reviewed":
        question["review_status"] = "generated"
    if not question["source_refs"]:
        question["source_refs"] = fallback["source_refs"]
    return question


def write_report(path: Path, report: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(report).rstrip() + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Prepare data/generated/questions.generated.json.")
    parser.add_argument("--questions", type=Path, default=Path("data/questions.seed.json"))
    parser.add_argument("--corpus", type=Path, default=Path("data/generated/corpus.jsonl"))
    parser.add_argument("--output", type=Path, default=Path("data/generated/questions.generated.json"))
    parser.add_argument("--report", type=Path, default=Path("data/generated/questions.generation-report.md"))
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--no-llm", action="store_true", help="Generate skeletal valid questions without AI.")
    mode.add_argument("--ollama", action="store_true", help="Use local Ollama to draft question content.")
    parser.add_argument("--model", default="qwen3:4b")
    parser.add_argument("--ollama-url", default="http://localhost:11434")
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()

    use_ollama = args.ollama
    if not args.no_llm and not args.ollama:
        args.no_llm = True

    seeds = load_json(args.questions)
    if not isinstance(seeds, list):
        raise SystemExit("questions seed file must contain a JSON array")
    corpus = load_corpus(args.corpus)

    report = [
        "# Question Pack Generation Report",
        "",
        f"Generated at: {datetime.now().isoformat(timespec='seconds')}",
        f"Mode: {'ollama' if use_ollama else 'no-llm'}",
        f"Seeds: {args.questions}",
        f"Corpus: {args.corpus}",
        f"Corpus chunks: {len(corpus)}",
        "",
    ]

    generated: list[dict[str, Any]] = []
    ollama_failures = 0
    for seed in seeds:
        if not isinstance(seed, dict):
            report.append("- Skipped non-object seed.")
            continue
        chunks = match_chunks(seed, corpus)
        report.append(f"## {seed.get('id', '')} {seed.get('title', '')}")
        report.append(f"Matched chunks: {', '.join(str(chunk.get('chunk_id')) for chunk in chunks) or 'none'}")

        if use_ollama:
            try:
                raw = ollama_chat(build_prompt(seed, chunks), args.model, args.ollama_url)
                parsed = extract_json_object(raw)
                if parsed is None:
                    report.append("LLM returned invalid JSON; fallback object used.")
                    report.append("")
                    report.append("Raw response:")
                    report.append("```text")
                    report.append(raw)
                    report.append("```")
                    question = fallback_question(seed, chunks)
                else:
                    question = normalize_generated_question(seed, parsed, chunks)
            except Exception as exc:
                if args.debug:
                    raise
                ollama_failures += 1
                report.append(f"Ollama failed: {exc}")
                report.append("Fallback object used.")
                question = fallback_question(seed, chunks)
        else:
            question = fallback_question(seed, chunks)

        generated.append(question)
        report.append("")

    validation = validate_questions(generated)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(generated, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    report.append("## Validation")
    report.append(f"Questions: {validation['stats']['question_count']}")
    report.append(f"Errors: {len(validation['errors'])}")
    report.append(f"Warnings: {len(validation['warnings'])}")
    if validation["errors"]:
        report.append("")
        report.append("Errors:")
        report.extend(f"- {error}" for error in validation["errors"])
    if validation["warnings"]:
        report.append("")
        report.append("Warnings:")
        report.extend(f"- {warning}" for warning in validation["warnings"])
    write_report(args.report, report)

    print(f"Wrote {len(generated)} questions to {args.output}")
    print(f"Wrote report to {args.report}")
    if ollama_failures:
        print(f"Ollama failed for {ollama_failures} question(s). See report. Start Ollama with: ollama run qwen3:4b")
    if validation["errors"]:
        print("Generated pack has validation errors.")
        return 1
    print("Generated pack is structurally valid.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
