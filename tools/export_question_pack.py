#!/usr/bin/env python3
"""Export tmkctl question JSON packs to DOCX, PDF, or CSV."""

from __future__ import annotations

import argparse
import csv
import html
import os
import shutil
import subprocess
import sys
import tempfile
import textwrap
import zipfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

from question_schema import load_json, validate_questions

FORMATS = {"docx", "pdf", "csv"}


@dataclass(frozen=True)
class RenderBlock:
    text: str
    style: str = "normal"


def text_value(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return value.strip()
    if isinstance(value, bool):
        return "yes" if value else "no"
    if isinstance(value, (int, float)):
        return str(value)
    if isinstance(value, dict):
        preferred = [
            ("term", "definition"),
            ("name", "role"),
            ("source", "detail"),
            ("file", "pages_or_slides"),
        ]
        for left_key, right_key in preferred:
            left = text_value(value.get(left_key))
            right = text_value(value.get(right_key))
            if left and right:
                return f"{left}: {right}"
            if left:
                return left
        parts = [f"{key}: {text_value(item)}" for key, item in value.items() if text_value(item)]
        return "; ".join(parts)
    if isinstance(value, list):
        return "\n".join(text_value(item) for item in value if text_value(item))
    return str(value).strip()


def numbered_items(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    return [text_value(value) for value in values if text_value(value)]


def question_blocks(questions: list[dict[str, Any]], title: str) -> list[RenderBlock]:
    blocks = [RenderBlock(title, "title")]
    for index, question in enumerate(questions, start=1):
        question_title = text_value(question.get("title")) or f"Question {index}"
        question_id = text_value(question.get("id"))
        status = text_value(question.get("review_status"))
        meta = " | ".join(part for part in [question_id, status] if part)

        blocks.append(RenderBlock(question_title, "heading1"))
        if meta:
            blocks.append(RenderBlock(meta, "meta"))

        sections = [
            ("Outline", numbered_items(question.get("outline"))),
            ("Key Terms", numbered_items(question.get("key_terms"))),
            ("Authors", numbered_items(question.get("authors"))),
            ("Examiner Focus", numbered_items(question.get("examiner_focus"))),
            ("Follow-up Questions", numbered_items(question.get("followup_questions"))),
            ("Common Mistakes", numbered_items(question.get("common_mistakes"))),
            ("Sources", numbered_items(question.get("source_refs"))),
        ]
        for section_title, items in sections:
            if not items:
                continue
            blocks.append(RenderBlock(section_title, "heading2"))
            for item_index, item in enumerate(items, start=1):
                blocks.append(RenderBlock(f"{item_index}. {item}", "normal"))
    return blocks


def paragraph_xml(text: str, style: str = "normal") -> str:
    escaped = html.escape(text, quote=False)
    if style == "title":
        props = '<w:pStyle w:val="Title"/><w:spacing w:after="320"/>'
    elif style == "heading1":
        props = '<w:pStyle w:val="Heading1"/><w:spacing w:before="260" w:after="120"/>'
    elif style == "heading2":
        props = '<w:pStyle w:val="Heading2"/><w:spacing w:before="160" w:after="80"/>'
    elif style == "meta":
        props = '<w:rPr><w:color w:val="666666"/><w:i/></w:rPr><w:spacing w:after="120"/>'
    else:
        props = '<w:spacing w:after="80"/>'
    return (
        "<w:p>"
        f"<w:pPr>{props}</w:pPr>"
        f'<w:r><w:t xml:space="preserve">{escaped}</w:t></w:r>'
        "</w:p>"
    )


def write_docx(path: Path, blocks: list[RenderBlock]) -> None:
    body = "".join(paragraph_xml(block.text, block.style) for block in blocks)
    document_xml = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        f"<w:body>{body}"
        '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1440" w:right="1440" '
        'w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
        "</w:body></w:document>"
    )
    styles_xml = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:sz w:val="22"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:rPr><w:b/><w:sz w:val="30"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>
</w:styles>"""
    content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
</Types>"""
    rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>"""
    word_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>"""

    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("[Content_Types].xml", content_types)
        archive.writestr("_rels/.rels", rels)
        archive.writestr("word/_rels/document.xml.rels", word_rels)
        archive.writestr("word/document.xml", document_xml)
        archive.writestr("word/styles.xml", styles_xml)


def csv_row(question: dict[str, Any]) -> dict[str, str]:
    return {
        "id": text_value(question.get("id")),
        "title": text_value(question.get("title")),
        "short_title": text_value(question.get("short_title")),
        "outline": text_value(question.get("outline")),
        "key_terms": text_value(question.get("key_terms")),
        "authors": text_value(question.get("authors")),
        "examiner_focus": text_value(question.get("examiner_focus")),
        "followup_questions": text_value(question.get("followup_questions")),
        "common_mistakes": text_value(question.get("common_mistakes")),
        "source_refs": text_value(question.get("source_refs")),
        "review_status": text_value(question.get("review_status")),
    }


def write_csv(path: Path, questions: list[dict[str, Any]]) -> None:
    fields = list(csv_row({}).keys())
    with path.open("w", newline="", encoding="utf-8-sig") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for question in questions:
            writer.writerow(csv_row(question))


def pdf_escape_text(text: str) -> str:
    encoded = text.encode("utf-16-be", errors="replace").hex().upper()
    return f"<FEFF{encoded}>"


def pdf_stream(lines: Iterable[tuple[str, int]], x: int = 54, y: int = 790, leading: int = 15) -> bytes:
    parts = ["BT", f"/F1 10 Tf", f"{x} {y} Td"]
    current_size = 10
    for line, size in lines:
        if size != current_size:
            parts.append(f"/F1 {size} Tf")
            current_size = size
        parts.append(f"{pdf_escape_text(line)} Tj")
        parts.append(f"0 -{leading} Td")
    parts.append("ET")
    return "\n".join(parts).encode("ascii")


def pdf_lines(blocks: list[RenderBlock]) -> list[tuple[str, int]]:
    lines: list[tuple[str, int]] = []
    for block in blocks:
        size = {"title": 18, "heading1": 14, "heading2": 11, "meta": 9}.get(block.style, 10)
        width = {"title": 58, "heading1": 72, "heading2": 88, "meta": 96}.get(block.style, 96)
        if block.style in {"heading1", "heading2"} and lines:
            lines.append(("", size))
        for wrapped in textwrap.wrap(block.text, width=width, break_long_words=False) or [""]:
            lines.append((wrapped, size))
    return lines


def write_pdf(path: Path, blocks: list[RenderBlock]) -> None:
    office = shutil.which("libreoffice") or shutil.which("soffice")
    if office:
        with tempfile.TemporaryDirectory(prefix="tmkctl-export-") as temp_dir:
            temp_path = Path(temp_dir)
            docx_path = temp_path / "questions.docx"
            write_docx(docx_path, blocks)
            try:
                subprocess.run(
                    [
                        office,
                        "--headless",
                        f"-env:UserInstallation=file://{temp_path / 'lo-profile'}",
                        "--convert-to",
                        "pdf",
                        "--outdir",
                        str(temp_path),
                        str(docx_path),
                    ],
                    check=True,
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                    env={
                        **dict(os.environ),
                        "HOME": str(temp_path),
                        "XDG_RUNTIME_DIR": str(temp_path),
                    },
                )
            except subprocess.CalledProcessError as exc:
                print(
                    f"Warning: LibreOffice PDF conversion failed with exit code {exc.returncode}; using built-in PDF fallback.",
                    file=sys.stderr,
                )
                converted = None
            else:
                converted = docx_path.with_suffix(".pdf")
            if converted is None:
                pass
            elif not converted.is_file():
                print("Warning: LibreOffice did not produce a PDF file; using built-in PDF fallback.", file=sys.stderr)
            else:
                shutil.copyfile(converted, path)
                return

    lines = pdf_lines(blocks)
    lines_per_page = 48
    page_chunks = [lines[index : index + lines_per_page] for index in range(0, len(lines), lines_per_page)] or [[("", 10)]]
    objects: list[bytes] = []

    def add_object(content: bytes) -> int:
        objects.append(content)
        return len(objects)

    catalog_id = add_object(b"<< /Type /Catalog /Pages 2 0 R >>")
    pages_id = add_object(b"")
    font_id = add_object(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")
    page_ids: list[int] = []
    for chunk in page_chunks:
        stream = pdf_stream(chunk)
        stream_id = add_object(b"<< /Length " + str(len(stream)).encode("ascii") + b" >>\nstream\n" + stream + b"\nendstream")
        page_id = add_object(
            b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            b"/Resources << /Font << /F1 " + str(font_id).encode("ascii") + b" 0 R >> >> "
            b"/Contents " + str(stream_id).encode("ascii") + b" 0 R >>"
        )
        page_ids.append(page_id)

    kids = " ".join(f"{page_id} 0 R" for page_id in page_ids).encode("ascii")
    objects[pages_id - 1] = b"<< /Type /Pages /Kids [" + kids + b"] /Count " + str(len(page_ids)).encode("ascii") + b" >>"
    assert catalog_id == 1

    output = bytearray(b"%PDF-1.4\n%\xe2\xe3\xcf\xd3\n")
    offsets = [0]
    for object_id, content in enumerate(objects, start=1):
        offsets.append(len(output))
        output.extend(f"{object_id} 0 obj\n".encode("ascii"))
        output.extend(content)
        output.extend(b"\nendobj\n")
    xref_offset = len(output)
    output.extend(f"xref\n0 {len(objects) + 1}\n".encode("ascii"))
    output.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        output.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
    output.extend(
        f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref_offset}\n%%EOF\n".encode("ascii")
    )
    path.write_bytes(bytes(output))


def infer_format(output: Path, requested: str | None) -> str:
    if requested:
        return requested
    suffix = output.suffix.lower().lstrip(".")
    if suffix in FORMATS:
        return suffix
    raise ValueError("Cannot infer format from output extension. Use --format docx, --format pdf, or --format csv.")


def load_questions(path: Path, strict: bool) -> list[dict[str, Any]]:
    data, load_errors = load_json(path)
    if load_errors:
        raise ValueError("\n".join(load_errors))
    validation = validate_questions(data)
    if strict and validation["errors"]:
        joined = "\n".join(f"- {error}" for error in validation["errors"])
        raise ValueError(f"Question pack schema validation failed:\n{joined}")
    if not isinstance(data, list):
        raise ValueError("Top-level JSON value must be an array.")
    return [item for item in data if isinstance(item, dict)]


def default_title(input_path: Path) -> str:
    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    return f"Question Pack ({input_path.name}, {stamp})"


def main() -> int:
    parser = argparse.ArgumentParser(description="Export tmkctl question JSON packs to DOCX, PDF, or CSV.")
    parser.add_argument("input", type=Path, help="Input question JSON file.")
    parser.add_argument("output", type=Path, help="Output file path. Extension can define the format.")
    parser.add_argument("--format", choices=sorted(FORMATS), help="Output format. Defaults to output extension.")
    parser.add_argument("--title", help="Document title for DOCX/PDF exports.")
    parser.add_argument("--no-validate", action="store_true", help="Export best-effort even when the pack schema has errors.")
    args = parser.parse_args()

    try:
        output_format = infer_format(args.output, args.format)
        questions = load_questions(args.input, strict=not args.no_validate)
        args.output.parent.mkdir(parents=True, exist_ok=True)
        if output_format == "csv":
            write_csv(args.output, questions)
        else:
            blocks = question_blocks(questions, args.title or default_title(args.input))
            if output_format == "docx":
                write_docx(args.output, blocks)
            elif output_format == "pdf":
                write_pdf(args.output, blocks)
            else:
                raise ValueError(f"Unsupported format: {output_format}")
    except ValueError as exc:
        print(str(exc))
        return 1
    except OSError as exc:
        print(f"Cannot write {args.output}: {exc}")
        return 1

    print(f"Exported {len(questions)} questions to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
