# V2Board MCP

用支持 stdio 的 MCP 客户端启动本地桥接进程。桥接进程使用现有后台 API 和管理员会话，可在无需部署新面板接口的情况下接入。

## 安装和连接

从仓库目录创建 Python 3.10+ 虚拟环境：

```bash
python3 -m venv "$HOME/.local/share/v2board-mcp/venv"
"$HOME/.local/share/v2board-mcp/venv/bin/python" -m pip install -r mcp/requirements.txt
```

将已登录 V2Board 后台的 API 请求 `Authorization` 请求头值原样保存到本机文件。它是管理员登录返回的 `auth_data`，应使用原始 JWT。将文件设为仅当前用户可读，例如 `chmod 600 ~/.config/v2board-mcp/auth-data`。会话失效后更新文件，工具调用时会重新读取。

在客户端添加 stdio MCP 服务。以下是采用 `mcpServers` 配置格式的示例。按本机路径和实际后台 API 地址替换：

```json
{
  "mcpServers": {
    "v2board": {
      "command": "/home/you/.local/share/v2board-mcp/venv/bin/python",
      "args": ["/path/to/v2board/mcp/server.py"],
      "env": {
        "V2BOARD_MCP_ADMIN_API": "https://panel.example.com/api/v1/your-admin-path",
        "V2BOARD_MCP_AUTH_FILE": "/home/you/.config/v2board-mcp/auth-data"
      }
    }
  }
}
```

后台 API 地址可从已登录的节点列表请求中取得，去掉末尾的 `/server/manage/getNodes`。远端使用 HTTPS，本机回环地址可用 HTTP。

## 工具

| 工具 | 用途 |
| --- | --- |
| `get_overview` | 在线用户、注册、收入、待处理工单和佣金汇总。金额字段单位为分。 |
| `get_order_trend` | 最多 31 个记录日的收入、收款、注册和佣金趋势。金额字段单位为元。 |
| `list_nodes` | 查询节点地址、可见性、在线数、最近配置拉取和流量上报。可按类型及状态筛选。 |
| `diagnose_node` | 根据节点类型和 ID 查看单个节点的面板状态。子入口可能继承父节点记录。 |
| `set_node_visibility` | 给服务环境变量添加 `V2BOARD_MCP_ENABLE_WRITE=1` 后启用。按类型和 ID 设置节点是否出现在订阅中。 |

节点诊断依据面板记录，节点地址不会被主动探测。隐藏节点会改变后续订阅展示，节点服务仍会运行。工具使用字段白名单，节点密钥、安装命令和用户订阅令牌不会出现在返回结果里。
