#!/usr/bin/env python3
"""Small local Ollama client for offline question generation."""

from __future__ import annotations

import json
import urllib.error
import urllib.request


def ollama_chat(prompt: str, model: str, base_url: str, temperature: float = 0.1) -> str:
    url = base_url.rstrip("/") + "/api/chat"
    payload = {
        "model": model,
        "stream": False,
        "options": {"temperature": temperature},
        "messages": [
            {
                "role": "user",
                "content": prompt,
            }
        ],
    }
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            data = json.loads(response.read().decode("utf-8"))
    except urllib.error.URLError as exc:
        raise RuntimeError(
            "Ollama is not reachable. Start it with: ollama run qwen3:4b"
        ) from exc
    except json.JSONDecodeError as exc:
        raise RuntimeError("Ollama returned invalid JSON.") from exc

    content = data.get("message", {}).get("content")
    if not isinstance(content, str) or not content.strip():
        raise RuntimeError("Ollama response did not contain assistant content.")
    return content.strip()
