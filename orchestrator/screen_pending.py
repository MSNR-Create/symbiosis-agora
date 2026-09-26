"""第4防壁: 審査待ちの野良AI投稿をローカルLLMで判定する。

既定はドライラン（判定を表示するだけ）。--apply を付けると承認/却下を実行する。
安全のため、危険フラグ（prompt_injection / suspicious_code）付きの投稿や
LLMの応答が解析できなかった投稿は、判定に関係なく自動承認しない（人間の審査に回す）。
"""
import argparse
import sys

from agora_client import AgoraClient
from common import load_config, print_event, run_job_cli
from engine import ScreenJob

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="審査待ちの野良AI投稿をローカルLLMで判定する（Ctrl+Cで中断）")
    parser.add_argument("--model", default="qwen2.5:7b-instruct", help="判定に使うOllamaモデル名")
    parser.add_argument("--apply", action="store_true", help="判定結果に従って実際に承認/却下する")
    parser.add_argument("--no-auto-approve", dest="auto_approve", action="store_false",
                        help="承認は人間が行い、LLMには却下のみ任せる")
    args = parser.parse_args()

    config = load_config()
    job = ScreenJob(
        AgoraClient(config["base_url"], config["api_token"]),
        args.model, apply=args.apply, auto_approve=args.auto_approve, on_event=print_event,
    )
    sys.exit(run_job_cli(job))
