"""Local stdio MCP bridge for the existing V2Board admin API."""

import json
import os
from pathlib import Path
from typing import Annotated, Literal
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from mcp.server import MCPServer
from mcp.server.mcpserver.exceptions import ToolError
from mcp.types import ToolAnnotations
from pydantic import Field

NodeType = Literal["v2node", "vmess", "vless", "shadowsocks", "trojan", "tuic", "hysteria", "anytls"]
NodeId = Annotated[int, Field(gt=0, strict=True)]
Visibility = Annotated[bool, Field(strict=True)]
MAX_RESPONSE = 16 * 1024 * 1024
READ_ONLY = ToolAnnotations(read_only_hint=True, open_world_hint=False)
NODE_FIELDS = (
    "id", "type", "name", "protocol", "network", "host", "port", "server_port",
    "parent_id", "show", "online", "available_status", "last_check_at", "last_push_at",
)
OVERVIEW_FIELDS = (
    "online_user", "month_income", "month_register_total", "day_register_total",
    "ticket_pending_total", "commission_pending_total", "day_income", "last_month_income",
    "commission_month_payout", "commission_last_month_payout",
)
NODE_STATUS = {
    0: "过去五分钟没有配置拉取记录",
    1: "近期拉取了配置，过去五分钟未上报流量",
    2: "近期拉取了配置并上报了流量",
}


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Panel:
    def __init__(self, admin_api: str, auth_file: Path):
        parts = urlsplit(admin_api)
        local_http = parts.scheme == "http" and parts.hostname in {"localhost", "127.0.0.1", "::1"}
        if (
            not (parts.scheme == "https" or local_http)
            or not parts.hostname
            or parts.username is not None
            or parts.query
            or parts.fragment
            or not parts.path.partition("/api/v1/")[2].strip("/")
        ):
            raise ValueError("配置 V2BOARD_MCP_ADMIN_API 为包含 /api/v1/后台路径 的完整 HTTPS 地址。")
        self.base_url = admin_api.rstrip("/")
        self.auth_file = auth_file
        self.http = build_opener(NoRedirect())

    def request(self, endpoint: str, payload: dict | None = None):
        try:
            auth_data = self.auth_file.read_text(encoding="utf-8").strip()
        except (OSError, UnicodeError):
            raise ToolError("无法读取 V2Board 管理员会话文件。") from None
        if not auth_data or any(not (33 <= ord(character) <= 126) for character in auth_data):
            raise ToolError("管理员会话文件应仅包含后台请求头使用的原始 auth_data。")

        headers = {"Accept": "application/json", "Authorization": auth_data}
        body = None
        if payload is not None:
            body = urlencode(payload).encode("ascii")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        req = Request(f"{self.base_url}/{endpoint}", data=body, headers=headers)
        try:
            with self.http.open(req, timeout=15) as res:
                raw = res.read(MAX_RESPONSE + 1)
        except HTTPError as exc:
            if exc.code in (401, 403):
                raise ToolError("管理员会话已失效或权限不足。") from None
            raise ToolError(f"后台请求失败，HTTP {exc.code}。") from None
        except (OSError, URLError):
            raise ToolError("无法连接到配置的 V2Board 后台。") from None
        if len(raw) > MAX_RESPONSE:
            raise ToolError("V2Board 后台响应超出大小上限。")
        try:
            data = json.loads(raw)
        except (ValueError, UnicodeError):
            raise ToolError("后台未返回 JSON，请检查管理员 API 路径。") from None
        if not isinstance(data, dict) or "data" not in data:
            raise ToolError("后台响应缺少 data 字段。")
        return data["data"]

    def nodes(self) -> list[dict]:
        data = self.request("server/manage/getNodes")
        if not isinstance(data, list) or any(not isinstance(node, dict) for node in data):
            raise ToolError("后台返回的节点列表格式无效。")
        return [select_fields(node, NODE_FIELDS) for node in data]


def select_fields(data: dict, fields: tuple[str, ...]) -> dict:
    return {
        field: data[field]
        for field in fields
        if field in data and (data[field] is None or isinstance(data[field], (str, int, float, bool)))
    }


def build_server(panel: Panel, enable_writes: bool) -> MCPServer:
    server = MCPServer("V2Board")

    @server.tool(annotations=READ_ONLY)
    def get_overview() -> dict:
        """查询后台概览，包括在线用户、注册、收入、待处理工单和佣金。金额单位为分。"""
        data = panel.request("stat/getOverride")
        if not isinstance(data, dict):
            raise ToolError("后台返回的概览格式无效。")
        return select_fields(data, OVERVIEW_FIELDS)

    @server.tool(annotations=READ_ONLY)
    def get_order_trend() -> list[dict]:
        """查询最近最多 31 个记录日的收入、收款、注册和佣金汇总。金额单位为元。"""
        data = panel.request("stat/getOrder")
        if not isinstance(data, list) or any(not isinstance(point, dict) for point in data):
            raise ToolError("后台返回的趋势数据格式无效。")
        return [select_fields(point, ("type", "date", "value")) for point in data]

    @server.tool(annotations=READ_ONLY)
    def list_nodes(node_type: NodeType | None = None, status: Literal[0, 1, 2] | None = None) -> list[dict]:
        """列出节点，可按类型或状态筛选。状态 0 表示近期未拉取，1 表示近期未上报，2 表示正常。"""
        return [
            node for node in panel.nodes()
            if (node_type is None or node.get("type") == node_type)
            and (status is None or str(node.get("available_status")) == str(status))
        ]

    @server.tool(annotations=READ_ONLY)
    def diagnose_node(node_type: NodeType, node_id: NodeId) -> dict:
        """查看单个节点的面板拉取、流量上报状态与时间戳。子节点的状态可能继承父节点。"""
        for node in panel.nodes():
            if node.get("type") == node_type and str(node.get("id")) == str(node_id):
                status = node.get("available_status")
                status_label = next(
                    (label for code, label in NODE_STATUS.items() if str(code) == str(status)),
                    "面板未返回状态",
                )
                return {"node": node, "diagnosis": status_label}
        raise ToolError("该类型下没有找到对应的节点 ID。")

    if enable_writes:
        @server.tool(annotations=ToolAnnotations(
            read_only_hint=False, destructive_hint=False, idempotent_hint=True, open_world_hint=False,
        ))
        def set_node_visibility(node_type: NodeType, node_id: NodeId, visible: Visibility) -> dict:
            """更新节点在用户订阅中的可见性。隐藏会影响订阅展示，节点服务本身仍会运行。"""
            result = panel.request(f"server/{node_type}/update", {"id": node_id, "show": int(visible)})
            if result is not True:
                raise ToolError("面板没有确认保存成功。")
            return {"type": node_type, "id": node_id, "visible": visible}

    return server


if __name__ == "__main__":
    admin_api = os.environ.get("V2BOARD_MCP_ADMIN_API", "").strip()
    auth_path = os.environ.get("V2BOARD_MCP_AUTH_FILE", "").strip()
    if not auth_path:
        raise SystemExit("请设置 V2BOARD_MCP_AUTH_FILE。")
    try:
        panel = Panel(admin_api, Path(auth_path).expanduser())
    except ValueError as error:
        raise SystemExit(str(error)) from None
    build_server(panel, os.environ.get("V2BOARD_MCP_ENABLE_WRITE") == "1").run(transport="stdio")
