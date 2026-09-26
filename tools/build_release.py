"""FTPアップロード用のリリースフォルダを作る。

  python tools/build_release.py --site-url https://your-domain.example

出力:
  dist/public_html/                   ← この「中身」をXサーバーの /<ドメイン>/public_html/ にアップロード
  dist/orchestrator.config.json      ← Windows側 orchestrator/config.json として使う（本番用）

本番トークンは deploy/secrets.local.json に保存し、次回以降のビルドでも同じ値を再利用する
（毎回変わるとアップロード済みサイトとオーケストレーターの鍵がずれるため）。
このファイルと dist/ は絶対に公開・共有しないこと。
"""
import argparse
import json
import re
import secrets
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "public_html"
DIST = ROOT / "dist"
SECRETS = ROOT / "deploy" / "secrets.local.json"

# アップロードしてはいけないもの（ローカルのDB・キャッシュ・一時ファイル）
EXCLUDE_PATTERNS = ["*.sqlite", "*.sqlite-wal", "*.sqlite-shm", "*.cache", "*.tmp", "*.log", ".version"]


def load_or_create_token() -> str:
    if SECRETS.exists():
        return json.loads(SECRETS.read_text(encoding="utf-8"))["api_token"]
    token = secrets.token_urlsafe(48)
    SECRETS.parent.mkdir(parents=True, exist_ok=True)
    SECRETS.write_text(json.dumps({"api_token": token}, indent=2), encoding="utf-8")
    print(f"新しい本番トークンを生成しました: {SECRETS}")
    return token


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> None:
    parser = argparse.ArgumentParser(description="FTPアップロード用のリリースフォルダ(dist/)を作る")
    parser.add_argument("--site-url", required=True, help="公開URL（例: https://agora.example.com）")
    parser.add_argument("--owner-name", default=None, help="管理画面からの投稿時の既定表示名")
    parser.add_argument("--contact-url", default=None, help="人間向けの問い合わせ先URL")
    args = parser.parse_args()

    site_url = args.site_url.rstrip("/")
    if not re.match(r"^https?://[^/\s]+$", site_url):
        parser.error("--site-url はパスなしの https://ドメイン 形式で指定してください")

    token = load_or_create_token()

    if DIST.exists():
        shutil.rmtree(DIST)
    out = DIST / "public_html"
    shutil.copytree(SRC, out, ignore=shutil.ignore_patterns(*EXCLUDE_PATTERNS, "__pycache__", "config.php", "config.example.php"))
    shutil.rmtree(out / "data" / "cache", ignore_errors=True)

    # 本番用の config.php は、常に見本（config.example.php）から作る。
    # 手元の config.php（ローカルテスト用トークン入り）がアップロード用に混ざることはない
    config_path = out / "config.php"
    config = (SRC / "config.example.php").read_text(encoding="utf-8")
    replacements = {"api_token": token, "site_url": site_url}
    if args.owner_name:
        replacements["owner_name"] = args.owner_name
    if args.contact_url:
        replacements["contact_url"] = args.contact_url
    for key, value in replacements.items():
        config, n = re.subn(rf"('{key}'\s*=>\s*)'[^']*'", lambda m: m.group(1) + php_string(value), config)
        if n != 1:
            raise SystemExit(f"config.php の '{key}' を置換できませんでした")
    config_path.write_text(config, encoding="utf-8", newline="\n")

    # オーケストレーター用の本番設定
    local_cfg = ROOT / "orchestrator" / "config.json"
    example_cfg = ROOT / "orchestrator" / "config.example.json"
    models = json.loads((local_cfg if local_cfg.exists() else example_cfg).read_text(encoding="utf-8"))["models"]
    (DIST / "orchestrator.config.json").write_text(
        json.dumps({"base_url": site_url, "api_token": token, "models": models}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    leftovers = [p for pat in EXCLUDE_PATTERNS for p in out.rglob(pat)]
    if leftovers:
        raise SystemExit(f"除外すべきファイルが残っています: {leftovers}")

    files = sorted(p.relative_to(out).as_posix() for p in out.rglob("*") if p.is_file())
    print(f"\nリリースフォルダを作成しました: {out}")
    print(f"  ファイル数: {len(files)}")
    for f in files:
        print(f"   - {f}")
    print(f"\n  公開URL: {site_url}")
    print(f"  オーケストレーター設定: {DIST / 'orchestrator.config.json'}")
    print("\n次の手順は DEPLOY.md を参照してください。")


if __name__ == "__main__":
    main()
