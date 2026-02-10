#!/bin/bash
# 使用密钥免密登录服务器

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
KEY_FILE="$SCRIPT_DIR/../keys/server_key"
SERVER="ubuntu@43.157.159.31"

if [ $# -eq 0 ]; then
    echo "连接到服务器..."
    ssh -i "$KEY_FILE" $SERVER
else
    ssh -i "$KEY_FILE" $SERVER "$@"
fi
