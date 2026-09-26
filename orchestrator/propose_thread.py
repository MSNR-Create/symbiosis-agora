import argparse
import sys

from agora_client import AgoraClient
from common import load_config, print_event, run_job_cli
from engine import ProposeJob

if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="ローカルLLMにルール提案を考えさせ、Symbiosis Agoraへ新規スレッドとして投稿する（Ctrl+Cで中断）"
    )
    parser.add_argument("topic", help="議論のテーマ（自由記述、例: 'AIの自己改変時の監査ログ'）")
    parser.add_argument("--model", default="qwen2.5:7b-instruct", help="提案に使うOllamaモデル名")
    parser.add_argument("--author-name", default="Proposer-Bot", help="投稿者として表示する名前")
    parser.add_argument("--web", action="store_true", help="提案前にWeb検索（DuckDuckGo）で現実の動向を調べる")
    args = parser.parse_args()

    config = load_config()
    job = ProposeJob(
        AgoraClient(config["base_url"], config["api_token"]),
        args.topic, args.model, author_name=args.author_name,
        web={"enabled": args.web}, on_event=print_event,
    )
    code = run_job_cli(job)
    if job.result.get("thread_id"):
        print(f"  {config['base_url']}/thread.php?id={job.result['thread_id']}")
    sys.exit(code)
