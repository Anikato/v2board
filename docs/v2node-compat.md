# V2Board 节点安装

部署新版面板后，从后台节点列表复制一键安装命令。面板应从 Laravel 的 public 目录提供 `/v2node/v0.4.5/install.sh`，并让节点能访问该地址。命令会将节点 ID、面板 API 地址和通讯密钥传给脚本。

脚本使用 Anikato v2node `v0.4.5` 的 UniProxy 兼容包，支持 Linux x86-64 和 ARM64。下载后先检查 SHA-256，再替换二进制。首次安装写入 `ApiHost`、`NodeID` 和 `ApiKey`。更新保留已有节点配置，检测到星核 `NodeToken` 配置时会退出。

安装时会把同一份脚本写为 `/usr/bin/v2node`。此后 `v2node update` 仍安装这份固定兼容包。要刷新安装和管理脚本，请重新执行后台命令。

已经装有旧管理脚本的节点，应先执行新的后台命令替换脚本。旧脚本的 `v2node update` 仍可能访问 Anikato 默认发行入口。
