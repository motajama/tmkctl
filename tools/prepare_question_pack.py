#!/usr/bin/env python3
"""Prepare a generated question pack from seeds and extracted corpus chunks."""

from __future__ import annotations

import argparse
import json
import re
from datetime import datetime
from pathlib import Path
from typing import Any

from extract_question_seeds import extract_question_seeds, write_report as write_seed_report
from llm_ollama import ollama_chat
from question_schema import validate_questions


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


def filter_question_source_from_corpus(corpus: list[dict[str, Any]], question_source: Path | None) -> list[dict[str, Any]]:
    if question_source is None:
        return corpus
    try:
        source_resolved = question_source.resolve()
    except OSError:
        source_resolved = question_source
    filtered: list[dict[str, Any]] = []
    for row in corpus:
        source_path = Path(str(row.get("source_path", "")))
        try:
            row_resolved = source_path.resolve()
        except OSError:
            row_resolved = source_path
        if row_resolved == source_resolved or source_path.as_posix() == question_source.as_posix():
            continue
        filtered.append(row)
    return filtered


def normalize_for_match(value: str) -> str:
    return value.lower()


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


def match_chunks(seed: dict[str, Any], corpus: list[dict[str, Any]], limit: int = 6) -> list[dict[str, Any]]:
    keywords = seed_keywords(seed)
    exact_phrases = [
        normalize_for_match(str(seed.get("title", ""))),
        normalize_for_match(str(seed.get("short_title", ""))),
        *[normalize_for_match(str(item)) for item in seed.get("hints", [])],
    ]
    exact_phrases = [phrase for phrase in exact_phrases if len(phrase) >= 3]
    scored: list[tuple[int, dict[str, Any]]] = []
    for chunk in corpus:
        text = normalize_for_match(str(chunk.get("text", "")))
        keyword_hits = sum(1 for keyword in keywords if keyword in text)
        occurrence_hits = sum(text.count(keyword) for keyword in keywords)
        phrase_hits = sum(1 for phrase in exact_phrases if phrase in text)
        score = phrase_hits * 10 + keyword_hits * 3 + occurrence_hits
        if score > 0:
            scored.append((score, chunk))
    scored.sort(key=lambda item: (-item[0], str(item[1].get("chunk_id", ""))))
    return [chunk for _, chunk in scored[:limit]]


def detail_counts(detail: float) -> dict[str, int]:
    def interp(low: int, high: int) -> int:
        return max(low, min(high, round(low + (high - low) * detail)))

    return {
        "outline": interp(3, 10),
        "key_terms": interp(2, 10),
        "authors": interp(1, 8),
        "examiner_focus": interp(2, 8),
        "followup_questions": interp(2, 10),
        "common_mistakes": interp(1, 8),
        "source_refs": interp(2, 8),
    }


def detail_instruction(detail: float, language: str) -> str:
    if language == "en":
        if detail <= 0.25:
            return "Keep the output very brief."
        if detail >= 0.75:
            return "Create detailed examiner support, but only where supported by supplied excerpts."
        return "Use normal examiner-support detail."
    if detail <= 0.25:
        return "Piš velmi stručně."
    if detail >= 0.75:
        return "Vytvoř podrobnou oporu zkoušejícího, ale pouze tam, kde je opora v dodaných úryvcích."
    return "Použij běžnou míru detailu pro oporu zkoušejícího."


def truncate_text(value: str, limit: int) -> str:
    value = re.sub(r"\s+", " ", value).strip()
    if len(value) <= limit:
        return value
    return value[: max(0, limit - 20)].rstrip() + " ... [truncated]"


def source_refs(chunks: list[dict[str, Any]], limit: int | None = None) -> list[dict[str, Any]]:
    refs: list[dict[str, Any]] = []
    for chunk in chunks[:limit]:
        chunk_id = str(chunk.get("chunk_id", ""))
        source = str(chunk.get("source", ""))
        page = chunk.get("page")
        slide = chunk.get("slide")
        detail = f"chunk_id: {chunk_id}"
        if page is not None:
            detail += f", page: {page}"
        if slide is not None:
            detail += f", slide: {slide}"
        refs.append({"source": source, "detail": detail, "chunk_id": chunk_id, "page": page, "slide": slide})
    return refs


def fallback_text(language: str) -> dict[str, str]:
    if language == "en":
        return {
            "outline_intro": "Define the topic",
            "concepts": "Explain the main concepts",
            "review": "Add precise support from materials during human review",
            "definition": "Add definition during human review.",
            "focus": "Check that the student can explain the topic and apply it to an example.",
            "focus_more": "Add concrete assessment points during human review.",
            "followup": "How would you explain this topic using a concrete example?",
            "mistake": "Add typical mistakes during human review.",
        }
    return {
        "outline_intro": "Vymezit téma",
        "concepts": "Vysvětlit hlavní pojmy",
        "review": "Doplnit přesnou oporu v materiálech při ruční revizi",
        "definition": "Doplnit definici při ruční revizi.",
        "focus": "Ověřit, že studující umí téma vysvětlit a použít na příkladu.",
        "focus_more": "Doplnit konkrétní kontrolní body po ruční revizi.",
        "followup": "Jak bys vysvětlil/a toto téma na konkrétním příkladu?",
        "mistake": "Doplnit typické chyby po ruční revizi.",
    }


def fallback_question(seed: dict[str, Any], chunks: list[dict[str, Any]], language: str, detail: float) -> dict[str, Any]:
    counts = detail_counts(detail)
    text = fallback_text(language)
    hints = [str(value).strip() for value in seed.get("hints", []) if str(value).strip()]
    title = str(seed.get("title", "")).strip()
    short_title = str(seed.get("short_title", "")).strip() or title
    terms = hints[: counts["key_terms"]] or [short_title or title]

    outline = [
        f"{text['outline_intro']}: {title}.",
        f"{text['concepts']}: {', '.join(terms[:4])}.",
        text["review"] + ".",
    ]
    while len(outline) < counts["outline"]:
        outline.append(text["review"] + ".")

    examiner_focus = [text["focus"], text["focus_more"]]
    while len(examiner_focus) < counts["examiner_focus"]:
        examiner_focus.append(text["focus_more"])

    followups = [text["followup"]]
    while len(followups) < counts["followup_questions"]:
        followups.append(text["followup"])

    mistakes = [text["mistake"]]
    while len(mistakes) < counts["common_mistakes"]:
        mistakes.append(text["mistake"])

    return {
        "id": str(seed.get("id", "")).strip(),
        "title": title,
        "short_title": short_title,
        "outline": outline[: counts["outline"]],
        "key_terms": [
            {"term": term, "definition": text["definition"], "authors": []}
            for term in terms[: counts["key_terms"]]
        ],
        "authors": [],
        "examiner_focus": examiner_focus[: counts["examiner_focus"]],
        "followup_questions": followups[: counts["followup_questions"]],
        "common_mistakes": mistakes[: counts["common_mistakes"]],
        "source_refs": source_refs(chunks, counts["source_refs"]) or [
            {"source": "questions seed", "detail": "chunk_id: no_match", "chunk_id": "no_match", "page": None, "slide": None}
        ],
        "review_status": "needs_review",
    }


def prompt_chunks(chunks: list[dict[str, Any]], max_context_chars: int) -> str:
    if not chunks:
        return "No relevant excerpts available."
    per_chunk = max(600, max_context_chars // max(1, len(chunks)))
    parts: list[str] = []
    used = 0
    for chunk in chunks:
        text = truncate_text(str(chunk.get("text", "")), per_chunk)
        part = (
            f"chunk_id: {chunk.get('chunk_id')}\n"
            f"source: {chunk.get('source')}\n"
            f"page: {chunk.get('page')}\n"
            f"slide: {chunk.get('slide')}\n"
            f"text: {text}"
        )
        if used + len(part) > max_context_chars and parts:
            break
        parts.append(part)
        used += len(part)
    return "\n\n---\n\n".join(parts)


def build_prompt(seed: dict[str, Any], chunks: list[dict[str, Any]], max_context_chars: int, language: str, detail: float) -> str:
    counts = detail_counts(detail)
    expected = {
        "id": str(seed.get("id", "")),
        "title": str(seed.get("title", "")),
        "short_title": str(seed.get("short_title", "")),
        "outline": [],
        "key_terms": [{"term": "...", "definition": "...", "authors": []}],
        "authors": [{"name": "...", "role": "..."}],
        "examiner_focus": [],
        "followup_questions": [],
        "common_mistakes": [],
        "source_refs": [{"source": "...", "detail": "chunk_id: ..."}],
        "review_status": "generated",
    }
    language_line = "Piš česky." if language == "cs" else "Write in English."
    course = "Teorie masové komunikace" if language == "cs" else "Theory of Mass Communication"
    return f"""Course: {course}. {language_line}
Task: produce one JSON object for examiner support. {detail_instruction(detail, language)}
Use only excerpts. Do not invent sources or unsupported authors.
Return JSON only, no Markdown, no commentary.
review_status: "generated" or "needs_review".
Approximate item counts: outline {counts['outline']}, key_terms {counts['key_terms']}, authors up to {counts['authors']}, examiner_focus {counts['examiner_focus']}, followup_questions {counts['followup_questions']}, common_mistakes {counts['common_mistakes']}.
Seed: {json.dumps(seed, ensure_ascii=False)}
JSON shape: {json.dumps(expected, ensure_ascii=False)}
Excerpts:
{prompt_chunks(chunks, max_context_chars)}
"""


def extract_json_object(text: str) -> dict[str, Any] | None:
    cleaned = text.strip()
    try:
        value = json.loads(cleaned)
        return value if isinstance(value, dict) else None
    except json.JSONDecodeError:
        pass

    fence = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", cleaned, flags=re.DOTALL | re.IGNORECASE)
    if fence:
        try:
            value = json.loads(fence.group(1))
            return value if isinstance(value, dict) else None
        except json.JSONDecodeError:
            pass

    start = cleaned.find("{")
    end = cleaned.rfind("}")
    if start >= 0 and end > start:
        try:
            value = json.loads(cleaned[start : end + 1])
            return value if isinstance(value, dict) else None
        except json.JSONDecodeError:
            return None
    return None


def normalize_generated_question(seed: dict[str, Any], candidate: dict[str, Any], chunks: list[dict[str, Any]], language: str, detail: float) -> dict[str, Any]:
    fallback = fallback_question(seed, chunks, language, detail)
    question = dict(fallback)
    question.update(candidate)
    question["id"] = str(seed.get("id", question.get("id", ""))).strip()
    question["title"] = str(question.get("title", seed.get("title", ""))).strip() or fallback["title"]
    question["short_title"] = str(question.get("short_title", seed.get("short_title", ""))).strip() or fallback["short_title"]
    for field in ["outline", "key_terms", "authors", "examiner_focus", "followup_questions", "common_mistakes", "source_refs"]:
        if not isinstance(question.get(field), list):
            question[field] = fallback[field]
    if question.get("review_status") not in {"generated", "needs_review"}:
        question["review_status"] = "needs_review"
    if not question["source_refs"]:
        question["source_refs"] = fallback["source_refs"]
    return question


def raw_block(value: str, limit: int = 3000) -> str:
    text = value if len(value) <= limit else value[:limit] + "\n... [truncated]"
    return "```text\n" + text + "\n```"


def write_report(path: Path, report: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("\n".join(report).rstrip() + "\n", encoding="utf-8")


def load_seeds(args: argparse.Namespace) -> tuple[list[dict[str, Any]], str, list[str]]:
    notices: list[str] = []
    if args.question_source and args.questions:
        notices.append("Notice: both --questions and --question-source were provided; using --question-source.")
    if args.question_source:
        result = extract_question_seeds(args.question_source, args.language, debug=args.debug)
        report_path = Path("data/generated/question-seed-extraction-report.md")
        write_seed_report(result, args.question_source, report_path, args.debug)
        if args.seed_output:
            args.seed_output.parent.mkdir(parents=True, exist_ok=True)
            args.seed_output.write_text(json.dumps(result.seeds, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        return result.seeds, str(args.question_source), notices
    if args.questions:
        data = load_json(args.questions)
        if not isinstance(data, list):
            raise SystemExit("questions seed file must contain a JSON array")
        return [item for item in data if isinstance(item, dict)], str(args.questions), notices
    raise SystemExit("Provide --questions data/questions.seed.json or --question-source path/to/questions.docx")


def main() -> int:
    parser = argparse.ArgumentParser(description="Prepare data/generated/questions.generated.json.")
    parser.add_argument("--questions", type=Path)
    parser.add_argument("--question-source", type=Path)
    parser.add_argument("--seed-output", type=Path)
    parser.add_argument("--corpus", type=Path, default=Path("data/generated/corpus.jsonl"))
    parser.add_argument("--output", type=Path, default=Path("data/generated/questions.generated.json"))
    parser.add_argument("--report", type=Path, default=Path("data/generated/questions.generation-report.md"))
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--no-llm", action="store_true", help="Generate skeletal valid questions without AI.")
    mode.add_argument("--ollama", action="store_true", help="Use local Ollama to draft question content.")
    parser.add_argument("--model", default="qwen3:1.7b")
    parser.add_argument("--ollama-url", default="http://localhost:11434")
    parser.add_argument("--temperature", type=float, default=0.1)
    parser.add_argument("--timeout", type=int, default=300)
    parser.add_argument("--limit", type=int, default=0, help="Process only the first N seeds. 0 means all.")
    parser.add_argument("--top-k", type=int, default=6)
    parser.add_argument("--max-context-chars", type=int, default=12000)
    parser.add_argument("--language", choices=["cs", "en"], default="cs")
    parser.add_argument("--detail", type=float, default=0.5)
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()

    if not 0.0 <= args.detail <= 1.0:
        parser.error("--detail must be between 0.0 and 1.0")

    use_ollama = args.ollama
    if not args.no_llm and not args.ollama:
        args.no_llm = True
        print("Notice: neither --ollama nor --no-llm was provided; using --no-llm.")

    seeds, question_source_label, notices = load_seeds(args)
    if args.limit > 0:
        seeds = seeds[: args.limit]
    corpus = filter_question_source_from_corpus(load_corpus(args.corpus), args.question_source)

    for notice in notices:
        print(notice)

    report = [
        "# Question Pack Generation Report",
        "",
        f"generated_at: {datetime.now().isoformat(timespec='seconds')}",
        f"mode: {'ollama' if use_ollama else 'no-llm'}",
        f"model: {args.model if use_ollama else 'n/a'}",
        f"ollama_url: {args.ollama_url if use_ollama else 'n/a'}",
        f"language: {args.language}",
        f"detail: {args.detail}",
        f"question_source: {question_source_label}",
        f"seed_output: {args.seed_output if args.seed_output else 'n/a'}",
        f"processed_questions: {len(seeds)}",
        f"top_k: {args.top_k}",
        f"max_context_chars: {args.max_context_chars}",
        f"corpus: {args.corpus}",
        f"corpus_chunks: {len(corpus)}",
        "",
    ]

    generated: list[dict[str, Any]] = []
    generated_count = 0
    fallback_count = 0
    for seed in seeds:
        chunks = match_chunks(seed, corpus, limit=args.top_k)
        chunk_ids = [str(chunk.get("chunk_id")) for chunk in chunks]
        question_status = "needs_review"
        question_errors: list[str] = []

        if use_ollama:
            try:
                messages = [{"role": "user", "content": build_prompt(seed, chunks, args.max_context_chars, args.language, args.detail)}]
                raw = ollama_chat(
                    messages,
                    model=args.model,
                    base_url=args.ollama_url,
                    temperature=args.temperature,
                    timeout=args.timeout,
                    debug=args.debug,
                )
                parsed = extract_json_object(raw)
                if parsed is None:
                    question_errors.append("Model returned invalid JSON; fallback object used.")
                    question = fallback_question(seed, chunks, args.language, args.detail)
                    report.append(f"## {seed.get('id', '')} {seed.get('title', '')}")
                    report.append(f"selected_chunk_ids: {', '.join(chunk_ids) or 'none'}")
                    report.append("status: fallback")
                    report.append("warnings/errors:")
                    report.append("- Model returned invalid JSON; fallback object used.")
                    report.append("")
                    report.append("Raw invalid model output:")
                    report.append(raw_block(raw))
                    report.append("")
                    fallback_count += 1
                else:
                    question = normalize_generated_question(seed, parsed, chunks, args.language, args.detail)
                    question_status = str(question.get("review_status", "generated"))
                    if question_status == "generated":
                        generated_count += 1
                    else:
                        fallback_count += 1
            except Exception as exc:
                if args.debug:
                    raise
                question_errors.append(str(exc))
                question = fallback_question(seed, chunks, args.language, args.detail)
                fallback_count += 1
        else:
            question = fallback_question(seed, chunks, args.language, args.detail)
            fallback_count += 1

        generated.append(question)
        if not (use_ollama and question_errors and "Raw invalid model output" in "\n".join(report[-6:])):
            report.append(f"## {seed.get('id', '')} {seed.get('title', '')}")
            report.append(f"selected_chunk_ids: {', '.join(chunk_ids) or 'none'}")
            report.append(f"status: {question_status if use_ollama else 'needs_review'}")
            if question_errors:
                report.append("warnings/errors:")
                report.extend(f"- {error}" for error in question_errors)
            report.append("")

    validation = validate_questions(generated)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(generated, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    report.append("## Summary")
    report.append(f"number_generated: {generated_count}")
    report.append(f"number_needs_review_or_fallback: {fallback_count}")
    report.append("")
    report.append("## Validation")
    report.append(f"questions: {validation['stats']['question_count']}")
    report.append(f"errors: {len(validation['errors'])}")
    report.append(f"warnings: {len(validation['warnings'])}")
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
    print(f"Generated: {generated_count}; fallback/needs_review: {fallback_count}")
    if validation["errors"]:
        print("Generated pack has validation errors.")
        return 1
    print("Generated pack is structurally valid.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
