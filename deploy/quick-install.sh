#!/bin/bash

#===============================================================================
# 困King分发平台 - 快速安装脚本
# 用法: curl -sSL https://raw.githubusercontent.com/over958999-byte/ip-manager-web/master/deploy/quick-install.sh | bash
#===============================================================================

set -e

echo ""
echo "=============================================="
echo "    困King分发平台 - 快速安装"
echo "=============================================="
echo ""

# 检查root权限
if [ "$EUID" -ne 0 ]; then
    echo "请使用root用户运行此脚本"
    echo "sudo bash -c \"\$(curl -sSL https://raw.githubusercontent.com/over958999-byte/ip-manager-web/master/deploy/quick-install.sh)\""
    exit 1
fi

# 临时目录
TMP_DIR=$(mktemp -d)
cd "$TMP_DIR"

# 下载安装脚本
echo "正在下载安装脚本..."
curl -sSL -o install.sh https://raw.githubusercontent.com/over958999-byte/ip-manager-web/master/deploy/install.sh

# 添加执行权限
chmod +x install.sh

# 运行安装
./install.sh "$@"

# 清理
cd /
rm -rf "$TMP_DIR"

echo "安装完成！"
