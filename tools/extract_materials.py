#!/usr/bin/env python3
"""Extract teaching materials into a chunked JSONL corpus."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Iterable


def normalize_text(text: str) -> str:
    text = text.replace("\x00", " ")
    text = re.sub(r"[ \t\r\f\v]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def chunk_text(text: str, chunk_chars: int, overlap_chars: int) -> list[str]:
    text = normalize_text(text)
    if not text:
        return []
    if len(text) <= chunk_chars:
        return [text]

    chunks: list[str] = []
    start = 0
    while start < len(text):
        end = min(len(text), start + chunk_chars)
        if end < len(text):
            boundary = max(text.rfind("\n", start, end), text.rfind(". ", start, end))
            if boundary > start + int(chunk_chars * 0.55):
                end = boundary + 1
        chunk = text[start:end].strip()
        if chunk:
            chunks.append(chunk)
        if end >= len(text):
            break
        start = max(0, end - overlap_chars)
    return chunks


def safe_stem(path: Path) -> str:
    return re.sub(r"[^A-Za-z0-9]+", "_", path.stem).strip("_").lower() or "source"


def read_txt_like(path: Path) -> list[tuple[int | None, str]]:
    return [(None, path.read_text(encoding="utf-8", errors="replace"))]


def read_pdf(path: Path) -> list[tuple[int | None, str]]:
    try:
        import fitz  # type: ignore
    except ImportError:
        print("Skipping PDF extraction; install pymupdf.")
        return []

    pages: list[tuple[int | None, str]] = []
    with fitz.open(path) as document:
        for index, page in enumerate(document, start=1):
            pages.append((index, page.get_text("text")))
    return pages


def read_docx(path: Path) -> list[tuple[int | None, str]]:
    try:
        import docx  # type: ignore
    except ImportError:
        print("Skipping DOCX extraction; install python-docx.")
        return []

    document = docx.Document(path)
    text = "\n".join(paragraph.text for paragraph in document.paragraphs)
    return [(None, text)]


def read_pptx(path: Path) -> list[tuple[int | None, str]]:
    try:
        from pptx import Presentation  # type: ignore
    except ImportError:
        print("Skipping PPTX extraction; install python-pptx.")
        return []

    presentation = Presentation(path)
    slides: list[tuple[int | None, str]] = []
    for index, slide in enumerate(presentation.slides, start=1):
        parts: list[str] = []
        for shape in slide.shapes:
            text = getattr(shape, "text", "")
            if text:
                parts.append(text)
        slides.append((index, "\n".join(parts)))
    return slides


def iter_sources(materials_dir: Path) -> Iterable[tuple[Path, str]]:
    mapping = {
        "txt": ".txt",
        "md": ".md",
        "pdf": ".pdf",
        "docx": ".docx",
        "pptx": ".pptx",
    }
    for subdir, suffix in mapping.items():
        root = materials_dir / subdir
        if not root.is_dir():
            continue
        for path in sorted(root.rglob(f"*{suffix}")):
            if path.name.startswith("."):
                continue
            yield path, subdir


def extract_source(path: Path, kind: str) -> list[tuple[int | None, str]]:
    if kind in {"txt", "md"}:
        return read_txt_like(path)
    if kind == "pdf":
        return read_pdf(path)
    if kind == "docx":
        return read_docx(path)
    if kind == "pptx":
        return read_pptx(path)
    return []


def build_corpus(materials_dir: Path, chunk_chars: int, overlap_chars: int) -> list[dict[str, object]]:
    rows: list[dict[str, object]] = []
    for source_path, kind in iter_sources(materials_dir):
        extracted = extract_source(source_path, kind)
        for page, text in extracted:
            chunks = chunk_text(text, chunk_chars, overlap_chars)
            for index, chunk in enumerate(chunks, start=1):
                page_part = f"p{page:03d}" if page is not None else "p000"
                chunk_id = f"{safe_stem(source_path)}_{page_part}_c{index:03d}"
                rows.append(
                    {
                        "chunk_id": chunk_id,
                        "source": source_path.name,
                        "source_path": source_path.as_posix(),
                        "page": page,
                        "text": chunk,
                        "char_count": len(chunk),
                    }
                )
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description="Extract teaching materials into data/generated/corpus.jsonl.")
    parser.add_argument("--materials-dir", type=Path, default=Path("materials"))
    parser.add_argument("--output", type=Path, default=Path("data/generated/corpus.jsonl"))
    parser.add_argument("--chunk-chars", type=int, default=3500)
    parser.add_argument("--overlap-chars", type=int, default=300)
    args = parser.parse_args()

    if args.chunk_chars < 500:
        parser.error("--chunk-chars must be at least 500")
    if args.overlap_chars < 0 or args.overlap_chars >= args.chunk_chars:
        parser.error("--overlap-chars must be non-negative and smaller than --chunk-chars")

    rows = build_corpus(args.materials_dir, args.chunk_chars, args.overlap_chars)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as handle:
        for row in rows:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")

    print(f"Wrote {len(rows)} chunks to {args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
