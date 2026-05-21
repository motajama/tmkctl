#!/usr/bin/env python3
"""Extract question seeds from a human-readable question list."""

from __future__ import annotations

import argparse
import json
import re
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

METADATA_LABELS = {
    "literatura",
    "poznámky",
    "poznamky",
    "sylabus",
    "syllabus",
    "obsah",
    "podmínky",
    "podminky",
    "requirements",
    "notes",
    "bibliography",
}

STOPWORDS = {
    "a", "i", "v", "ve", "na", "do", "z", "ze", "se", "ke", "k", "o", "u",
    "the", "and", "of", "to", "in", "for", "with", "on", "an", "a",
}


@dataclass
class ExtractionResult:
    seeds: list[dict[str, Any]]
    lines_read: int
    ignored: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


def read_text_with_fallback(path: Path) -> str:
    for encoding in ["utf-8", "utf-8-sig", "cp1250", "latin-1"]:
        try:
            return path.read_text(encoding=encoding)
        except UnicodeDecodeError:
            continue
    return path.read_text(encoding="utf-8", errors="replace")


def read_docx(path: Path) -> str:
    try:
        import docx  # type: ignore
    except ImportError as exc:
        raise RuntimeError("DOCX extraction requires python-docx.") from exc
    document = docx.Document(path)
    return "\n".join(paragraph.text for paragraph in document.paragraphs)


def read_pdf(path: Path) -> str:
    try:
        import fitz  # type: ignore
    except ImportError as exc:
        raise RuntimeError("PDF extraction requires pymupdf.") from exc
    with fitz.open(path) as document:
        return "\n".join(page.get_text("text") for page in document)


def read_question_source(path: Path) -> str:
    suffix = path.suffix.lower()
    if suffix in {".txt", ".md"}:
        return read_text_with_fallback(path)
    if suffix == ".docx":
        return read_docx(path)
    if suffix == ".pdf":
        return read_pdf(path)
    raise ValueError("Supported question-source formats: .txt, .md, .docx, .pdf")


def normalize_line(value: str) -> str:
    value = re.sub(r"\s+", " ", value.replace("\u00a0", " "))
    return value.strip(" \t-–—")


def strip_question_prefix(line: str) -> tuple[str, bool]:
    patterns = [
        r"^\s*#{1,6}\s*",
        r"^\s*[-*]\s+",
        r"^\s*\d{1,3}[.)]\s+",
        r"^\s*otázka\s*(?:č\.?|cislo|číslo)?\s*\d{1,3}\s*[:.)–-]\s*",
        r"^\s*question\s*\d{1,3}\s*[:.)–-]\s*",
    ]
    numbered = False
    cleaned = line
    for pattern in patterns:
        new = re.sub(pattern, "", cleaned, count=1, flags=re.IGNORECASE)
        if new != cleaned:
            numbered = True
            cleaned = new
            break
    return normalize_line(cleaned), numbered


def looks_like_metadata(line: str, numbered: bool) -> bool:
    low = line.lower().strip(" :")
    if numbered:
        return False
    if low in METADATA_LABELS:
        return True
    if len(low.split()) <= 2 and any(label in low for label in METADATA_LABELS):
        return True
    return False


def looks_like_question(line: str, numbered: bool) -> bool:
    if len(line) < 12:
        return False
    words = line.split()
    if len(words) < 2:
        return False
    if numbered:
        return True
    if line.endswith("?"):
        return True
    if 3 <= len(words) <= 18 and not looks_like_metadata(line, numbered):
        return True
    return False


def short_title(title: str) -> str:
    if ":" in title:
        candidate = title.split(":", 1)[0]
    elif " – " in title:
        candidate = title.split(" – ", 1)[0]
    else:
        meaningful = [word.strip(",.;:()[]") for word in title.split()]
        meaningful = [word for word in meaningful if word and word.lower() not in STOPWORDS]
        candidate = " ".join(meaningful[:6])
    return candidate.strip()[:80] or title[:80]


def hints_from_title(title: str) -> list[str]:
    parts = re.split(r"[,;:/()–—-]+", title)
    hints: list[str] = []
    for part in parts:
        part = normalize_line(part)
        if len(part) >= 4 and part.lower() not in METADATA_LABELS:
            hints.append(part)
    for word in re.findall(r"[\wÁ-ž]{4,}", title, flags=re.UNICODE):
        if word.lower() not in STOPWORDS:
            hints.append(word)
    deduped: list[str] = []
    seen: set[str] = set()
    for hint in hints:
        key = hint.lower()
        if key not in seen:
            seen.add(key)
            deduped.append(hint)
    return deduped[:8]


def format_id(index: int, prefix: str) -> str:
    match = re.match(r"^([A-Za-z_-]*?)(\d+)$", prefix)
    if match:
        return f"{match.group(1)}{index:0{len(match.group(2))}d}"
    return f"{prefix}{index:02d}"


def extract_question_seeds(path: Path, language: str = "cs", start_id: str = "q01", title: str = "", debug: bool = False) -> ExtractionResult:
    text = read_question_source(path)
    raw_lines = text.splitlines()
    seeds: list[dict[str, Any]] = []
    ignored: list[str] = []
    warnings: list[str] = []
    seen_titles: set[str] = set()

    for raw_line in raw_lines:
        line = normalize_line(raw_line)
        if not line:
            continue
        cleaned, numbered = strip_question_prefix(line)
        if looks_like_metadata(cleaned, numbered):
            if debug:
                ignored.append(line)
            continue
        if not looks_like_question(cleaned, numbered):
            if debug:
                ignored.append(line)
            continue
        key = cleaned.lower()
        if key in seen_titles:
            warnings.append(f"Duplicate title ignored: {cleaned}")
            continue
        seen_titles.add(key)
        index = len(seeds) + 1
        seeds.append(
            {
                "id": format_id(index, start_id),
                "title": cleaned,
                "short_title": short_title(cleaned),
                "hints": hints_from_title(cleaned),
                "source_note": f"Extracted from {path.name}" + (f" ({title})" if title else ""),
            }
        )

    if not seeds:
        warnings.append("No question candidates were extracted.")
    return ExtractionResult(seeds=seeds, lines_read=len(raw_lines), ignored=ignored, warnings=warnings)


def write_report(result: ExtractionResult, input_path: Path, report_path: Path, debug: bool = False) -> None:
    lines = [
        "# Question Seed Extraction Report",
        "",
        f"Input file: {input_path}",
        f"Lines read: {result.lines_read}",
        f"Extracted questions: {len(result.seeds)}",
        "",
    ]
    if result.warnings:
        lines.append("## Warnings")
        lines.extend(f"- {warning}" for warning in result.warnings)
        lines.append("")
    if debug and result.ignored:
        lines.append("## Ignored Candidate Lines")
        lines.extend(f"- {line}" for line in result.ignored[:300])
        lines.append("")
    report_path.parent.mkdir(parents=True, exist_ok=True)
    report_path.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Extract question seeds from a human-readable question list.")
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--language", choices=["cs", "en"], default="cs")
    parser.add_argument("--start-id", default="q01")
    parser.add_argument("--title", default="")
    parser.add_argument("--report", type=Path, default=Path("data/generated/question-seed-extraction-report.md"))
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()

    result = extract_question_seeds(args.input, args.language, args.start_id, args.title, args.debug)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result.seeds, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    write_report(result, args.input, args.report, args.debug)
    print(f"Extracted {len(result.seeds)} questions from {args.input}")
    print(f"Wrote seeds to {args.output}")
    print(f"Wrote report to {args.report}")
    return 0 if result.seeds else 1


if __name__ == "__main__":
    raise SystemExit(main())
