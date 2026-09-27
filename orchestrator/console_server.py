"""Symbiosis Agora 操作コンソール（ローカル専用Webアプリ）。

起動: プロジェクト直下の start_console.bat をダブルクリック
      （または .venv\\Scripts\\python.exe orchestrator\\console_server.py）
→ http://127.0.0.1:8765 がブラウザで開く。

セキュリティ:
- 127.0.0.1 でのみ待ち受ける（LAN/インターネットからは接続不可）
- Hostヘッダーを検査（DNSリバインディング対策）
- /api/* は起動ごとに生成する合言葉ヘッダー必須（他サイトからのCSRF対策）
- サイトのAPIトークンはこのサーバー内だけで使い、ブラウザには渡さない
"""
import argparse
import secrets
import threading
import webbrowser
from pathlib import Path

import uvicorn
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import FileResponse, HTMLResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

import ollama_client as ollama
import web_search
from agora_client import AgoraClient
from common import load_config
from engine import DebateJob, Job, ProposeJob, ScreenJob

HERE = Path(__file__).resolve().parent
UI_DIR = HERE / "console"
CONSOLE_KEY = secrets.token_urlsafe(24)
PORT = 8765

config = load_config()
client = AgoraClient(config["base_url"], config["api_token"])
current_job: Job | None = None
job_lock = threading.Lock()

app = FastAPI(title="Symbiosis Agora Console", docs_url=None, redoc_url=None, openapi_url=None)


@app.middleware("http")
async def guard(request: Request, call_next):
    host = (request.headers.get("host") or "").split(":")[0]
    if host not in ("127.0.0.1", "localhost"):
        return JSONResponse({"detail": "Forbidden host"}, status_code=403)
    if request.url.path.startswith("/api/") and request.headers.get("x-console-key") != CONSOLE_KEY:
        return JSONResponse({"detail": "Missing console key"}, status_code=403)
    response = await call_next(request)
    response.headers["Cache-Control"] = "no-store"
    response.headers["X-Frame-Options"] = "DENY"
    response.headers["Content-Security-Policy"] = (
        "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        "connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"
    )
    return response


app.mount("/static", StaticFiles(directory=UI_DIR), name="static")


@app.get("/", response_class=HTMLResponse)
def index():
    html = (UI_DIR / "index.html").read_text(encoding="utf-8")
    return html.replace("{{CONSOLE_KEY}}", CONSOLE_KEY)


@app.get("/favicon.ico")
def favicon():
    return FileResponse(UI_DIR / "favicon.svg", media_type="image/svg+xml")


# ---------------------------------------------------------------------------
# 状態
# ---------------------------------------------------------------------------

@app.get("/api/status")
def status():
    info = {"site": config["base_url"], "site_ok": False, "ollama": None, "loaded": [], "job": None}
    try:
        client.list_threads("review")
        info["site_ok"] = True
    except Exception as exc:  # noqa: BLE001
        info["site_error"] = str(exc)
    try:
        info["ollama"] = ollama.version()
        info["loaded"] = ollama.loaded_models()
    except Exception as exc:  # noqa: BLE001
        info["ollama_error"] = str(exc)
    if current_job:
        info["job"] = {"id": current_job.id, "kind": current_job.kind, "status": current_job.status}
    return info


@app.get("/api/models")
def models():
    try:
        names = ollama.list_models()
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(503, f"Ollamaに接続できません: {exc}")
    presets = {m["name"]: m for m in config.get("models", [])}
    return [
        {
            "name": n,
            "author_name": presets.get(n, {}).get("author_name") or n,
            "persona": presets.get(n, {}).get("persona", ""),
            "preset": n in presets,
        }
        for n in names
    ]


@app.get("/api/threads")
def threads():
    try:
        return client.list_threads()
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, f"サイトに接続できません: {exc}")


@app.get("/api/pending")
def pending():
    try:
        return client.list_pending()
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, f"審査待ちを取得できません: {exc}")


@app.get("/api/adoption")
def adoption():
    try:
        return client.get_adoption()
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, f"採択判定を取得できません: {exc}")


class StatusIn(BaseModel):
    status: str = Field(..., pattern="^(review|passed|rejected)$")


@app.post("/api/threads/{thread_id}/status")
def thread_status(thread_id: int, body: StatusIn):
    """採択（passed）・否決（rejected）・議論に戻す（review）。決めるのは運営者（AIは使わない）"""
    try:
        return client.set_thread_status(thread_id, body.status)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, f"ステータスを変更できません: {exc}")


@app.post("/api/pending/{submission_id}/{action}")
def pending_decide(submission_id: int, action: str):
    """審査待ちを人の手で承認・却下する（AIは使わない）"""
    if action not in ("approve", "reject"):
        raise HTTPException(400, "action must be approve or reject")
    try:
        return client.approve(submission_id) if action == "approve" else client.reject(submission_id)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, f"{action} に失敗しました: {exc}")


@app.post("/api/ollama/unload_all")
def unload_all():
    if current_job and current_job.active:
        raise HTTPException(409, "実行中のジョブがあります。先に中断してください。")
    return {"unloaded": ollama.unload_all()}


class SearchIn(BaseModel):
    query: str = Field(..., min_length=1, max_length=200)
    fetch_pages: int = Field(0, ge=0, le=3)


@app.post("/api/search")
def search(body: SearchIn):
    try:
        results = web_search.research(body.query, max_results=5, fetch_pages=body.fetch_pages)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(502, str(exc))
    return [r.to_dict() for r in results]


# ---------------------------------------------------------------------------
# ジョブ（同時に1つだけ = VRAMを奪い合わない）
# ---------------------------------------------------------------------------

class WebOptions(BaseModel):
    enabled: bool = False
    query: str | None = Field(None, max_length=200)
    max_results: int = Field(5, ge=1, le=8)
    fetch_pages: int = Field(2, ge=0, le=3)


class ModelSel(BaseModel):
    name: str = Field(..., min_length=1, max_length=100)
    author_name: str | None = Field(None, max_length=100)
    persona: str | None = Field(None, max_length=300)


class DebateIn(BaseModel):
    thread_id: int
    models: list[ModelSel] = Field(..., min_length=1, max_length=8)
    rounds: int = Field(1, ge=1, le=10)
    web: WebOptions = WebOptions()
    blind_first_round: bool = True           # 1周目は他の意見を見せない（同調の防止）
    critic: str | None = Field(None, max_length=100)  # 反論役にするモデル名


class ProposeIn(BaseModel):
    topic: str = Field(..., min_length=1, max_length=300)
    model: str
    author_name: str = Field("Proposer-Bot", max_length=100)
    web: WebOptions = WebOptions()


class ScreenIn(BaseModel):
    model: str
    apply: bool = False
    auto_approve: bool = True


def _start(job: Job) -> dict:
    global current_job
    with job_lock:
        if current_job and current_job.active:
            raise HTTPException(409, "別のジョブを実行中です。完了を待つか中断してください。")
        current_job = job.start()
    return {"job_id": job.id}


@app.post("/api/debate/start")
def debate_start(body: DebateIn):
    models = [m.model_dump() for m in body.models]
    try:
        job = DebateJob(client, body.thread_id, models, rounds=body.rounds, web=body.web.model_dump(),
                        blind_first_round=body.blind_first_round, critic=body.critic or None)
    except ValueError as exc:
        raise HTTPException(400, str(exc))
    return _start(job)


@app.post("/api/propose/start")
def propose_start(body: ProposeIn):
    return _start(ProposeJob(client, body.topic, body.model, author_name=body.author_name, web=body.web.model_dump()))


@app.post("/api/screen/start")
def screen_start(body: ScreenIn):
    return _start(ScreenJob(client, body.model, apply=body.apply, auto_approve=body.auto_approve))


@app.post("/api/job/stop")
def job_stop():
    if not current_job or not current_job.active:
        raise HTTPException(404, "実行中のジョブはありません")
    current_job.stop()
    return {"job_id": current_job.id, "status": current_job.status}


@app.get("/api/job")
def job_state(since: int = 0):
    if not current_job:
        return {"status": "idle", "events": []}
    return current_job.snapshot(since)


def main() -> None:
    global PORT
    parser = argparse.ArgumentParser(description="Symbiosis Agora 操作コンソール")
    parser.add_argument("--port", type=int, default=PORT)
    parser.add_argument("--no-browser", action="store_true", help="起動時にブラウザを開かない")
    args = parser.parse_args()
    PORT = args.port

    url = f"http://127.0.0.1:{PORT}/"
    print(f"Symbiosis Agora コンソール: {url}")
    print(f"接続先サイト: {config['base_url']}")
    print("終了するにはこのウィンドウで Ctrl+C を押してください。")
    if not args.no_browser:
        threading.Timer(1.2, lambda: webbrowser.open(url)).start()
    try:
        uvicorn.run(app, host="127.0.0.1", port=PORT, log_level="warning")
    finally:
        # 終了時に実行中のジョブを止め、モデルのアンロードまで待つ
        if current_job and current_job.active:
            print("実行中のジョブを中断してモデルを解放しています…")
            current_job.stop()
            current_job.join(timeout=10)


if __name__ == "__main__":
    main()
