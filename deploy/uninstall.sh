#!/bin/bash

#===============================================================================
# IP管理器 - 卸载脚本
#===============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

INSTALL_DIR="/var/www/ip-manager"
DB_NAME="ip_manager"
DB_USER="ip_manager"

echo ""
echo "=============================================="
echo -e "${RED}IP管理器 - 卸载脚本${NC}"
echo "=============================================="
echo ""

# 检查root权限
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}请使用root用户运行此脚本${NC}"
    exit 1
fi

read -p "确定要卸载IP管理器吗？这将删除所有数据！(y/N): " confirm
if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "取消卸载"
    exit 0
fi

echo ""

# 停止服务
echo -e "${YELLOW}[1/5]${NC} 停止服务..."
systemctl stop nginx 2>/dev/null || true

# 删除Nginx配置
echo -e "${YELLOW}[2/5]${NC} 删除Nginx配置..."
rm -f /etc/nginx/sites-enabled/ip-manager 2>/dev/null || true
rm -f /etc/nginx/sites-available/ip-manager 2>/dev/null || true
rm -f /etc/nginx/conf.d/ip-manager.conf 2>/dev/null || true

# 删除数据库
echo -e "${YELLOW}[3/5]${NC} 删除数据库..."
mysql -e "DROP DATABASE IF EXISTS ${DB_NAME};" 2>/dev/null || true
mysql -e "DROP USER IF EXISTS '${DB_USER}'@'localhost';" 2>/dev/null || true

# 删除项目文件
echo -e "${YELLOW}[4/5]${NC} 删除项目文件..."
rm -rf "$INSTALL_DIR"

# 重启服务
echo -e "${YELLOW}[5/5]${NC} 重启Nginx..."
systemctl start nginx 2>/dev/null || true

echo ""
echo "=============================================="
echo -e "${GREEN}卸载完成！${NC}"
echo "=============================================="
echo ""
echo "注意: PHP、MySQL、Node.js、Nginx等环境未被卸载"
echo "如需卸载环境，请手动执行相应命令"
echo ""
