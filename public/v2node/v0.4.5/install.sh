#!/usr/bin/env bash
# Shared installer and node manager for the V2Board (UniProxy) release.
set -euo pipefail

readonly version=v0.4.5
readonly config_dir=/etc/v2node
readonly config_file="$config_dir/config.json"
readonly bin_dir=/usr/local/v2node
readonly manager=/usr/bin/v2node

fail() { printf '%s\n' "$*" >&2; exit 1; }

usage() {
    printf '%s\n' "V2Board v2node $version" \
        '首次安装：bash install.sh --api-host URL --node-id ID --api-key KEY' \
        '节点管理：v2node install|update|update_shell|version|start|stop|restart|status|log|enable|disable|config|generate'
}

service_action() {
    if command -v rc-service >/dev/null && [[ -x /sbin/openrc-run ]]; then
        case "$1" in
            enable) rc-update add v2node default ;;
            disable) rc-update del v2node default ;;
            *) rc-service v2node "$1" ;;
        esac
    else
        systemctl "$1" v2node
    fi
}

protect_config() {
    if [[ -e "$config_file" ]] && grep -Eiq '"NodeToken"[[:space:]]*:' "$config_file"; then
        fail '检测到星核 NodeToken 配置，已停止 V2Board 安装。'
    fi
}

json_string() {
    local value=$1
    [[ "$value" != *[[:cntrl:]]* ]] || fail '配置值不能含控制字符。'
    value=${value//\\/\\\\}
    value=${value//\"/\\\"}
    printf '"%s"' "$value"
}

write_config() {
    local api_host=$1 node_id=$2 api_key=$3 host_json key_json
    [[ "$api_host" == http://* || "$api_host" == https://* ]] || fail '请输入完整的面板 API 地址。'
    [[ "$node_id" =~ ^[1-9][0-9]*$ && -n "$api_key" ]] || fail '请输入有效的 NodeID 和 ApiKey。'
    host_json=$(json_string "$api_host")
    key_json=$(json_string "$api_key")
    install -d "$config_dir"
    (
        umask 077
        cat > "$config_file" <<EOF
{
    "Log": {"Level": "warning", "Output": "", "Access": "none"},
    "Nodes": [{
        "ApiHost": $host_json,
        "NodeID": $node_id,
        "ApiKey": $key_json,
        "Timeout": 15,
        "SpeedLimit": 0
    }]
}
EOF
    )
}

prompt_config() {
    local api_host node_id api_key
    read -rp 'V2Board 节点 API 地址: ' api_host
    read -rp 'NodeID: ' node_id
    read -rsp 'ApiKey: ' api_key
    printf '\n'
    write_config "$api_host" "$node_id" "$api_key"
}

install_dependencies() {
    if command -v curl >/dev/null && command -v unzip >/dev/null; then
        return
    fi
    if command -v apt-get >/dev/null; then
        apt-get update
        apt-get install -y curl unzip ca-certificates
    elif command -v apk >/dev/null; then
        apk add --no-cache curl unzip ca-certificates
    elif command -v dnf >/dev/null; then
        dnf install -y curl unzip ca-certificates
    elif command -v yum >/dev/null; then
        yum install -y curl unzip ca-certificates
    elif command -v pacman >/dev/null; then
        pacman -S --needed --noconfirm curl unzip ca-certificates
    else
        fail '请先安装 curl、unzip 和 ca-certificates。'
    fi
}

install_service() {
    if command -v rc-service >/dev/null && [[ -x /sbin/openrc-run ]]; then
        if [[ ! -e /etc/init.d/v2node ]]; then
            cat > /etc/init.d/v2node <<'UNIT'
#!/sbin/openrc-run
description="V2Board v2node"
command="/usr/local/v2node/v2node"
command_args="server"
directory="/usr/local/v2node"
command_background="yes"
pidfile="/run/v2node.pid"
depend() { need net; }
UNIT
            chmod 755 /etc/init.d/v2node
        fi
    elif [[ ! -e /etc/systemd/system/v2node.service ]]; then
        cat > /etc/systemd/system/v2node.service <<'UNIT'
[Unit]
Description=V2Board v2node
After=network-online.target
Wants=network-online.target

[Service]
WorkingDirectory=/usr/local/v2node
ExecStart=/usr/local/v2node/v2node server
Restart=on-failure
LimitNOFILE=999999

[Install]
WantedBy=multi-user.target
UNIT
        systemctl daemon-reload
    fi
}

install_release() {
    local api_host='' node_id='' api_key='' asset sha

    while [[ $# -gt 0 ]]; do
        case "$1" in
            v0.4.5|0.4.5) shift ;;
            --api-host|--node-id|--api-key)
                [[ $# -ge 2 && -n "$2" && "$2" != --* ]] || fail "参数缺少值：$1"
                case "$1" in
                    --api-host) api_host=$2 ;;
                    --node-id) node_id=$2 ;;
                    --api-key) api_key=$2 ;;
                esac
                shift 2 ;;
            *) fail "兼容入口仅安装 $version，未知参数或版本：$1" ;;
        esac
    done
    protect_config
    if [[ -n "$api_host$node_id$api_key" ]]; then
        [[ -n "$api_host" && -n "$node_id" && -n "$api_key" ]] || fail '请同时提供 ApiHost、NodeID 和 ApiKey。'
    fi
    case "$(uname -m)" in
        x86_64|amd64)
            asset=v2node-linux-64.zip
            sha=fe40bbd39f9cd415763bb70779b5defb49c1a2e25709dd4904b834b5a4ee0d69 ;;
        aarch64|arm64)
            asset=v2node-linux-arm64-v8a.zip
            sha=9954fcc63700571c5b35aacd9e5c516014ffe7fec4fac54a3453ec4f42270457 ;;
        *) fail 'V2Board 兼容发行版仅提供 x86-64 和 ARM64 安装包。' ;;
    esac

    install_dependencies
    install -d "$bin_dir"
    # Keep staging on the same filesystem as the installed binary for atomic replacement.
    stage=$(mktemp -d "$bin_dir/.v2board-release.XXXXXXXX")
    trap 'rm -rf -- "$stage"' EXIT
    curl -fLSs --retry 2 "https://github.com/Anikato/v2node/releases/download/$version/$asset" -o "$stage/$asset"
    printf '%s  %s\n' "$sha" "$stage/$asset" | sha256sum -c -s || fail '兼容安装包 SHA-256 不匹配。'
    unzip -q "$stage/$asset" v2node geoip.dat geosite.dat -d "$stage"
    chmod 755 "$stage/v2node"

    if [[ ! -e "$config_file" ]]; then
        if [[ -n "$api_host" ]]; then
            write_config "$api_host" "$node_id" "$api_key"
        else
            prompt_config
        fi
    fi
    mv -f -- "$stage/v2node" "$bin_dir/v2node"
    install -m 644 "$stage/geoip.dat" "$stage/geosite.dat" "$config_dir/"
    if [[ ! "${BASH_SOURCE[0]}" -ef "$manager" ]]; then
        install -m 755 "${BASH_SOURCE[0]}" "$manager"
    fi
    install_service
    service_action enable
    service_action restart
    printf 'V2Board v2node %s 已安装。\n' "$version"
}

if [[ "${1:-}" == --help || "${1:-}" == -h ]]; then usage; exit 0; fi
[[ $EUID == 0 ]] || fail '请以 root 运行此脚本。'

if [[ "${0##*/}" == v2node ]]; then
    case "${1:-}" in
        install|update) shift; install_release "$@" ;;
        update_shell) printf '%s\n' '请重新运行 V2Board 后台的兼容安装命令来更新管理脚本。' ;;
        version) exec "$bin_dir/v2node" version ;;
        start|stop|restart|status|enable|disable) service_action "$1" ;;
        log)
            command -v journalctl >/dev/null || fail '当前系统请通过 OpenRC 的日志设施查看 v2node 日志。'
            exec journalctl -u v2node -e --no-pager ;;
        config) "${EDITOR:-vi}" "$config_file" ;;
        generate) protect_config; prompt_config; service_action restart ;;
        *) usage ;;
    esac
else
    install_release "$@"
fi
