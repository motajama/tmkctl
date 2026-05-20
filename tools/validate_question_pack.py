#!/usr/bin/env python3
"""Validate a tmkctl question JSON file."""

from __future__ import annotations

import argparse
from pathlib import Path

from question_schema import validate_question_file


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate a tmkctl question JSON file.")
    parser.add_argument("path", type=Path, help="Path to questions JSON file.")
    args = parser.parse_args()

    result = validate_question_file(args.path)
    stats = result["stats"]
    print(f"File: {args.path}")
    print(f"Questions: {stats['question_count']}")
    print(f"reviewed: {stats['reviewed_count']}")
    print(f"generated: {stats['generated_count']}")
    print(f"needs_review: {stats['needs_review_count']}")
    print(f"without source_refs: {stats['without_source_refs_count']}")
    print(f"JSON: {'OK' if result['valid_json'] else 'ERROR'}")
    print(f"Schema: {'OK' if result['schema_valid'] else 'ERROR'}")

    if result["errors"]:
        print("\nErrors:")
        for error in result["errors"]:
            print(f"- {error}")

    if result["warnings"]:
        print("\nWarnings:")
        for warning in result["warnings"]:
            print(f"- {warning}")

    return 0 if not result["errors"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
