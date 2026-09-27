"""Ollama API クライアント。

VRAM節約のため、既定では応答が終わったモデルはすぐアンロードする（keep_alive=0）。
連続して同じモデルを使う場合だけ keep_alive を延ばし、使い終わったら unload() する。
"""
import json
import threading
from typing import Callable

import requests

OLLAMA_BASE_URL = "http://127.0.0.1:11434"


class Cancelled(Exception):
    """生成が中断された"""


def list_models() -> list[str]:
    """チャットに使えるモデル名の一覧（埋め込み専用モデルは除く）"""
    resp = requests.get(f"{OLLAMA_BASE_URL}/api/tags", timeout=10)
    resp.raise_for_status()
    names = [m["name"] for m in resp.json().get("models", [])]
    return sorted(n for n in names if "embed" not in n.lower())


def loaded_models() -> list[dict]:
    """現在VRAM/メモリに載っているモデル"""
    resp = requests.get(f"{OLLAMA_BASE_URL}/api/ps", timeout=10)
    resp.raise_for_status()
    return [
        {"name": m["name"], "size_vram": m.get("size_vram", 0), "expires_at": m.get("expires_at")}
        for m in resp.json().get("models", [])
    ]


def version() -> str:
    resp = requests.get(f"{OLLAMA_BASE_URL}/api/version", timeout=5)
    resp.raise_for_status()
    return resp.json().get("version", "?")


def unload(model: str) -> None:
    """モデルを即座にアンロードしてVRAMを解放する"""
    requests.post(
        f"{OLLAMA_BASE_URL}/api/generate",
        json={"model": model, "keep_alive": 0},
        timeout=30,
    ).raise_for_status()


def unload_all() -> list[str]:
    names = [m["name"] for m in loaded_models()]
    for name in names:
        unload(name)
    return names


def chat(
    model: str,
    system_prompt: str,
    user_prompt: str,
    *,
    timeout: int = 300,
    keep_alive: int | str = 0,
    cancel: threading.Event | None = None,
    on_token: Callable[[str], None] | None = None,
    json_mode: bool = True,
    schema: dict | None = None,
) -> str:
    """チャット応答を返す。

    keep_alive: 応答後にモデルを保持する時間。0 なら応答後すぐアンロード。
    cancel:     セットされたら生成を打ち切って Cancelled を送出する（ストリームを閉じるとOllama側も停止する）。
    on_token:   生成中のテキスト断片を受け取るコールバック（進捗表示用）。
    schema:     JSON Schema を渡すと、その形（キー名・型）で出力させる（Ollama の構造化出力）。
    """
    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt},
        ],
        "stream": True,
        "keep_alive": keep_alive,
    }
    if schema is not None:
        payload["format"] = schema  # キー名まで固定する（小型モデルが独自のキー名で答えるのを防ぐ）
    elif json_mode:
        payload["format"] = "json"  # 常に妥当なJSONを返させる（小型モデルの出力崩れ対策）

    if cancel is not None and cancel.is_set():
        raise Cancelled()

    parts: list[str] = []
    finished = threading.Event()
    with requests.post(
        f"{OLLAMA_BASE_URL}/api/chat", json=payload, stream=True, timeout=(10, timeout)
    ) as resp:
        resp.raise_for_status()

        # モデル読み込み中などトークンがまだ届かない間でも即中断できるよう、
        # 中断されたら別スレッドから接続を閉じる（接続が切れるとOllamaも生成を止める）
        def watch() -> None:
            while not finished.is_set():
                if cancel.wait(0.2):
                    resp.close()
                    return

        if cancel is not None:
            threading.Thread(target=watch, daemon=True).start()

        try:
            for line in resp.iter_lines():
                if cancel is not None and cancel.is_set():
                    raise Cancelled()
                if not line:
                    continue
                chunk = json.loads(line)
                if chunk.get("error"):
                    raise RuntimeError(chunk["error"])
                piece = chunk.get("message", {}).get("content", "")
                if piece:
                    parts.append(piece)
                    if on_token:
                        on_token(piece)
                if chunk.get("done"):
                    break
        except Cancelled:
            raise
        except Exception:
            if cancel is not None and cancel.is_set():
                raise Cancelled()
            raise
        finally:
            finished.set()
    if cancel is not None and cancel.is_set():
        raise Cancelled()
    return "".join(parts)
