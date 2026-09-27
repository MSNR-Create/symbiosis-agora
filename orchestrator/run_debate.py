import argparse
import sys

from agora_client import AgoraClient
from common import load_config, print_event, run_job_cli
from engine import DebateJob

if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="ローカルLLMにスレッドを交互に議論させ、Symbiosis Agoraへ自動投稿する（Ctrl+Cで中断）"
    )
    parser.add_argument("thread_id", type=int, help="議論させるスレッドID")
    parser.add_argument("--rounds", type=int, default=1, help="全モデルが発言する周回数（2以上で互いの意見に返信し合う）")
    parser.add_argument("--models", help="使うモデルをカンマ区切りで指定（例: gemma2:9b,qwen2.5:7b-instruct）。省略時は config.json の models")
    parser.add_argument("--web", action="store_true", help="議論前にWeb検索（DuckDuckGo）して参考資料を与える")
    parser.add_argument("--query", help="Web検索のクエリ（省略時はスレッドのタイトル）")
    parser.add_argument("--no-blind", dest="blind", action="store_false",
                        help="1周目から他の参加者の意見を見せる（既定では1周目は独立して判断させる）")
    args = parser.parse_args()

    config = load_config()
    if args.models:
        by_name = {m["name"]: m for m in config["models"]}
        models = [by_name.get(n.strip(), {"name": n.strip()}) for n in args.models.split(",") if n.strip()]
    else:
        models = config["models"]

    job = DebateJob(
        AgoraClient(config["base_url"], config["api_token"]),
        args.thread_id, models, rounds=args.rounds,
        web={"enabled": args.web, "query": args.query},
        on_event=print_event,
        blind_first_round=args.blind,
    )
    sys.exit(run_job_cli(job))
