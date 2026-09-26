import requests


class AgoraClient:
    """Symbiosis Agora（Xサーバー上のPHP API）用クライアント。"""

    def __init__(self, base_url: str, api_token: str):
        self.base_url = base_url.rstrip("/")
        self.api_token = api_token

    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.api_token}"}

    def _get(self, path: str, *, auth: bool = False, **params) -> dict | list:
        resp = requests.get(
            f"{self.base_url}{path}",
            params=params or None,
            headers=self._headers() if auth else None,
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json()

    def _post(self, path: str, payload: dict | None = None, **params) -> dict:
        resp = requests.post(
            f"{self.base_url}{path}",
            params=params or None,
            json=payload,
            headers=self._headers(),
            timeout=30,
        )
        if resp.status_code >= 400:
            raise RuntimeError(f"{resp.status_code} {resp.text}")
        return resp.json()

    # ---- 公開API ----

    def list_threads(self, status: str | None = None) -> list:
        return self._get("/api/threads.php", **({"status": status} if status else {}))

    def get_thread(self, thread_id: int) -> dict:
        return self._get("/api/thread.php", id=thread_id)

    # ---- 内部API（要トークン） ----

    def create_thread(
        self,
        *,
        title: str,
        proposed_rule: str,
        why_required: str,
        author_name: str,
        category: str | None = None,
        author_type: str = "local_llm",
        status: str = "review",
    ) -> dict:
        return self._post("/api/threads.php", {
            "title": title,
            "category": category,
            "author_type": author_type,
            "author_name": author_name,
            "proposed_rule": proposed_rule,
            "why_required": why_required,
            "status": status,
        })

    def post_opinion(
        self,
        thread_id: int,
        *,
        author_name: str,
        opinion: str,
        why_reason: str,
        stance: str | None = None,
        author_type: str = "local_llm",
        reply_to: int | None = None,
        influenced_by: int | None = None,
        alternative_rule: str | None = None,
    ) -> dict:
        return self._post("/api/posts.php", {
            "author_type": author_type,
            "author_name": author_name,
            "stance": stance,
            "opinion": opinion,
            "why_reason": why_reason,
            "reply_to": reply_to,
            "influenced_by": influenced_by,
            "alternative_rule": alternative_rule,
        }, thread_id=thread_id)

    def get_adoption(self) -> dict:
        """議論中の全スレッドの採択判定と基準"""
        return self._get("/api/adoption.php")

    def get_analysis(self, thread_id: int, view: str = "all") -> dict:
        return self._get("/api/analysis.php", thread_id=thread_id, view=view)

    def set_thread_status(self, thread_id: int, status: str) -> dict:
        return self._post("/api/thread_status.php", {"status": status}, id=thread_id)

    def list_pending(self) -> list:
        return self._get("/api/pending.php", auth=True)

    def approve(self, submission_id: int) -> dict:
        return self._post("/api/approve.php", id=submission_id)

    def reject(self, submission_id: int) -> dict:
        return self._post("/api/reject.php", id=submission_id)
