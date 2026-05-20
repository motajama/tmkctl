#!/usr/bin/env python3
"""Shared schema validation for offline tmkctl question packs."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path
from typing import Any

ALLOWED_REVIEW_STATUSES = {"reviewed", "generated", "needs_review"}
ARRAY_FIELDS = [
    "outline",
    "key_terms",
    "authors",
    "examiner_focus",
    "followup_questions",
    "common_mistakes",
    "source_refs",
]


def empty_stats() -> dict[str, int]:
    return {
        "question_count": 0,
        "reviewed_count": 0,
        "generated_count": 0,
        "needs_review_count": 0,
        "without_source_refs_count": 0,
    }


def load_json(path: Path) -> tuple[Any | None, list[str]]:
    try:
        return json.loads(path.read_text(encoding="utf-8")), []
    except FileNotFoundError:
        return None, [f"File not found: {path}"]
    except json.JSONDecodeError as exc:
        return None, [f"Invalid JSON: {exc.msg} at line {exc.lineno}, column {exc.colno}"]
    except OSError as exc:
        return None, [f"Cannot read {path}: {exc}"]


def validate_questions(data: Any) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    stats = empty_stats()

    if not isinstance(data, list):
        return {
            "valid_json": True,
            "schema_valid": False,
            "errors": ["Top-level JSON value must be an array."],
            "warnings": [],
            "stats": stats,
        }

    ids: list[str] = []
    for index, item in enumerate(data, start=1):
        label = f"Question #{index}"
        stats["question_count"] += 1
        if not isinstance(item, dict):
            errors.append(f"{label}: item must be an object.")
            continue

        question_id = str(item.get("id", "")).strip()
        title = str(item.get("title", "")).strip()
        status = str(item.get("review_status", "")).strip()

        if not question_id:
            errors.append(f"{label}: missing id.")
        else:
            ids.append(question_id)

        if not title:
            errors.append(f"{label}: missing title.")

        if not str(item.get("short_title", "")).strip():
            warnings.append(f"{label}: missing short_title.")

        for field in ARRAY_FIELDS:
            if field not in item or not isinstance(item[field], list):
                errors.append(f"{label}: {field} must be an array.")

        if isinstance(item.get("outline"), list) and not item["outline"]:
            warnings.append(f"{label}: outline is empty.")
        if isinstance(item.get("key_terms"), list) and not item["key_terms"]:
            warnings.append(f"{label}: key_terms is empty.")
        if not isinstance(item.get("source_refs"), list) or not item["source_refs"]:
            stats["without_source_refs_count"] += 1
            warnings.append(f"{label}: source_refs is empty.")

        if status not in ALLOWED_REVIEW_STATUSES:
            errors.append(f"{label}: invalid review_status.")
        else:
            stats[f"{status}_count"] += 1
            if status != "reviewed":
                warnings.append(f"{label}: review_status is not reviewed.")

    duplicates = [question_id for question_id, count in Counter(ids).items() if count > 1]
    for question_id in duplicates:
        errors.append(f"Duplicate id: {question_id}.")

    return {
        "valid_json": True,
        "schema_valid": not errors,
        "errors": errors,
        "warnings": sorted(set(warnings)),
        "stats": stats,
    }


def validate_question_file(path: Path) -> dict[str, Any]:
    data, load_errors = load_json(path)
    if load_errors:
        return {
            "valid_json": False,
            "schema_valid": False,
            "errors": load_errors,
            "warnings": [],
            "stats": empty_stats(),
        }
    return validate_questions(data)


def minimal_question(seed: dict[str, Any], source_refs: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    hints = [str(value).strip() for value in seed.get("hints", []) if str(value).strip()]
    title = str(seed.get("title", "")).strip()
    short_title = str(seed.get("short_title", "")).strip() or title
    first_hint = hints[0] if hints else title

    return {
        "id": str(seed.get("id", "")).strip(),
        "title": title,
        "short_title": short_title,
        "outline": [
            f"Vymezit téma: {title}.",
            "Doplnit přesnou osnovu po ruční kontrole materiálů.",
        ],
        "key_terms": [
            {
                "term": first_hint,
                "definition": "Doplnit definici při ruční revizi.",
                "authors": [],
            }
        ],
        "authors": [],
        "examiner_focus": [
            "Ověřit, že studující umí téma vysvětlit a použít na příkladu.",
            "Doplnit konkrétní kontrolní body po ruční revizi.",
        ],
        "followup_questions": [
            f"Jak bys vysvětlil/a téma {short_title} na konkrétním příkladu?"
        ],
        "common_mistakes": [
            "Doplnit typické chyby po ruční revizi."
        ],
        "source_refs": source_refs or [],
        "review_status": "needs_review",
    }
