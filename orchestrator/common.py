import json
import os
import sys
import time
from pathlib import Path

# 環境変数 AGORA_CONFIG で別の設定ファイル（例: ローカルテスト用）を指定できる
CONFIG_PATH = Path(os.environ.get("AGORA_CONFIG") or Path(__file__).parent / "config.json")


def load_config() -> dict:
    if not CONFIG_PATH.exists():
        sys.exit(
            f"設定ファイルが見つかりません: {CONFIG_PATH}\n"
            "config.example.json をコピーして config.json を作成し、base_url と api_token を設定してください。"
        )
    return json.loads(CONFIG_PATH.read_text(encoding="utf-8"))


def parse_model_json(raw: str) -> dict:
    raw = raw.strip()
    if raw.startswith("```"):
        raw = raw.strip("`")
        if raw.lower().startswith("json"):
            raw = raw[4:]
    start = raw.find("{")
    end = raw.rfind("}")
    if start == -1 or end == -1:
        raise ValueError(f"モデル出力からJSONを抽出できませんでした: {raw[:200]!r}")
    return json.loads(raw[start:end + 1])


def print_event(event: dict) -> None:
    prefix = {"error": "[エラー] ", "warn": "[注意] ", "skip": "  [スキップ] ", "posted": "  -> ",
              "unload": "  (VRAM) ", "search": "[Web] "}.get(event["type"], "")
    print(prefix + event["message"], flush=True)


def run_job_cli(job) -> int:
    """ジョブをコンソールで実行する。Ctrl+C で中断（モデルはアンロードしてから終了）"""
    job.start()
    try:
        while job.active:
            time.sleep(0.2)
    except KeyboardInterrupt:
        print("\n中断しています…（Ctrl+C をもう一度押すと強制終了）", flush=True)
        job.stop()
    # status は後片付けの前に確定するので、スレッド自体の終了（アンロード・最後の出力）まで待つ。
    # 短い間隔で待つことで、2回目の Ctrl+C による強制終了も効くようにする
    while job.alive:
        job.join(0.2)
    return 0 if job.status == "finished" else 1
