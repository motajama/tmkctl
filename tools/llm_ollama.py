#!/usr/bin/env python3
"""Small local Ollama client for offline question generation."""

from __future__ import annotations

import json
import socket
import urllib.error
import urllib.request
from typing import Any


def ollama_chat(
    messages: list[dict[str, str]],
    model: str = "qwen3:1.7b",
    base_url: str = "http://localhost:11434",
    temperature: float = 0.1,
    timeout: int = 300,
    debug: bool = False,
) -> str:
    """Call local Ollama /api/chat and return assistant content."""

    url = base_url.rstrip("/") + "/api/chat"
    payload: dict[str, Any] = {
        "model": model,
        "stream": False,
        "options": {"temperature": temperature, "num_predict": 3000},
        "messages": messages,
    }
    try:
        raw = _post_json(url, payload, timeout)
    except urllib.error.HTTPError as exc:
        body = _safe_read_error_body(exc)
        if exc.code == 404 and "model" in body.lower():
            raise RuntimeError(
                f"Ollama model '{model}' was not found. Pull it with: ollama-home pull {model}"
            ) from exc
        detail = f": {body}" if body and debug else ""
        raise RuntimeError(f"Ollama HTTP error {exc.code}{detail}") from exc
    except urllib.error.URLError as exc:
        if "://localhost" in url:
            retry_url = url.replace("://localhost", "://127.0.0.1", 1)
            try:
                raw = _post_json(retry_url, payload, timeout)
            except Exception:
                raw = ""
            else:
                return _assistant_content(raw, debug)
        reason = getattr(exc, "reason", exc)
        if isinstance(reason, ConnectionRefusedError):
            raise RuntimeError("Ollama is not running. Start it with: ollama-home-serve") from exc
        if isinstance(reason, socket.timeout):
            raise RuntimeError(f"Ollama request timed out after {timeout} seconds.") from exc
        raise RuntimeError(f"Cannot reach Ollama at {base_url}. Start it with: ollama-home-serve") from exc
    except TimeoutError as exc:
        raise RuntimeError(f"Ollama request timed out after {timeout} seconds.") from exc

    return _assistant_content(raw, debug)


def _post_json(url: str, payload: dict[str, Any], timeout: int) -> str:
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read().decode("utf-8")


def _assistant_content(raw: str, debug: bool) -> str:
    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        detail = f" Raw response starts: {raw[:500]}" if debug else ""
        raise RuntimeError("Ollama returned invalid JSON." + detail) from exc
    if isinstance(data, dict) and data.get("error"):
        message = str(data["error"])
        if "not found" in message.lower() or "pull" in message.lower():
            raise RuntimeError(
                f"Ollama model '{model}' is not available. Pull it with: ollama-home pull {model}"
            )
        raise RuntimeError(f"Ollama error: {message}")

    content = data.get("message", {}).get("content") if isinstance(data, dict) else None
    if not isinstance(content, str) or not content.strip():
        raise RuntimeError("Ollama response did not contain assistant content.")
    return content.strip()


def _safe_read_error_body(exc: urllib.error.HTTPError) -> str:
    try:
        raw = exc.read().decode("utf-8", errors="replace")
    except Exception:
        return ""
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        return raw[:1000]
    if isinstance(data, dict) and data.get("error"):
        return str(data["error"])
    return raw[:1000]
