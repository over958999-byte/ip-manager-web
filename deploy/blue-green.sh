#!/bin/bash
# 困King分发平台 - 蓝绿部署脚本
# 实现零停机更新
# 增强：健康检查、自动回滚、部署验证

set -e

# 配置
COMPOSE_FILE="docker-compose.yml"
COMPOSE_BLUE="docker-compose.blue.yml"
COMPOSE_GREEN="docker-compose.green.yml"
HEALTH_ENDPOINT="/health.php"
HEALTH_DETAIL_ENDPOINT="/health.php?detail=1"
MAX_WAIT_TIME=120
SLEEP_INTERVAL=5
ROLLBACK_ON_FAILURE=true
HEALTH_CHECK_RETRIES=3
SMOKE_TEST_ENABLED=true

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() {
    echo -e "${GREEN}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_step() {
    echo -e "${BLUE}[STEP]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

# 获取当前活跃环境
get_active_env() {
    if docker ps --format '{{.Names}}' | grep -q 'ip-manager-app-blue'; then
        if docker ps --format '{{.Names}}' | grep -q 'ip-manager-app-green'; then
            echo "both"
        else
            echo "blue"
        fi
    elif docker ps --format '{{.Names}}' | grep -q 'ip-manager-app-green'; then
        echo "green"
    else
        echo "none"
    fi
}

# 获取下一个部署环境
get_next_env() {
    case $(get_active_env) in
        blue)
            echo "green"
            ;;
        green|both|none)
            echo "blue"
            ;;
    esac
}

# 基础健康检查
health_check() {
    local port=$1
    local max_attempts=$((MAX_WAIT_TIME / SLEEP_INTERVAL))
    local attempt=0
    
    log_info "等待服务就绪... (端口: $port)"
    
    while [ $attempt -lt $max_attempts ]; do
        if curl -sf "http://localhost:${port}${HEALTH_ENDPOINT}" > /dev/null 2>&1; then
            log_info "基础健康检查通过"
            return 0
        fi
        
        attempt=$((attempt + 1))
        echo -n "."
        sleep $SLEEP_INTERVAL
    done
    
    echo ""
    log_error "健康检查超时"
    return 1
}

# 深度健康检查（验证所有组件）
deep_health_check() {
    local port=$1
    local retries=${2:-$HEALTH_CHECK_RETRIES}
    
    log_info "执行深度健康检查..."
    
    for i in $(seq 1 $retries); do
        local response=$(curl -sf "http://localhost:${port}${HEALTH_ENDPOINT}" 2>/dev/null)
        
        if [ -z "$response" ]; then
            log_warn "健康检查无响应 (尝试 $i/$retries)"
            sleep 2
            continue
        fi
        
        # 检查状态是否为healthy或degraded
        local status=$(echo "$response" | grep -o '"status":"[^"]*"' | cut -d'"' -f4)
        
        if [ "$status" = "healthy" ]; then
            log_info "深度健康检查通过 - 状态: $status"
            return 0
        elif [ "$status" = "degraded" ]; then
            log_warn "服务处于降级状态，但可继续"
            return 0
        else
            log_warn "服务状态异常: $status (尝试 $i/$retries)"
        fi
        
        sleep 2
    done
    
    log_error "深度健康检查失败"
    return 1
}

# 冒烟测试
smoke_test() {
    local port=$1
    
    if [ "$SMOKE_TEST_ENABLED" != "true" ]; then
        return 0
    fi
    
    log_info "执行冒烟测试..."
    
    # 测试1: 健康检查端点响应时间
    local start_time=$(date +%s%3N)
    curl -sf "http://localhost:${port}${HEALTH_ENDPOINT}" > /dev/null 2>&1
    local end_time=$(date +%s%3N)
    local response_time=$((end_time - start_time))
    
    if [ $response_time -gt 5000 ]; then
        log_warn "健康检查响应时间过长: ${response_time}ms"
    else
        log_info "健康检查响应时间: ${response_time}ms"
    fi
    
    # 测试2: 主页可访问
    if curl -sf "http://localhost:${port}/" > /dev/null 2>&1; then
        log_info "主页访问正常"
    else
        log_warn "主页访问失败"
    fi
    
    log_info "冒烟测试完成"
    return 0
}

# 回滚到旧环境
rollback() {
    local failed_env=$1
    local old_env=$2
    local old_port=$3
    
    log_error "部署失败，开始回滚..."
    
    # 停止失败的新环境
    if [ -f "docker-compose.${failed_env}.yml" ]; then
        docker compose -f "$COMPOSE_FILE" -f "docker-compose.${failed_env}.yml" down "app-${failed_env}" 2>/dev/null || true
        log_info "已停止失败的环境: $failed_env"
    fi
    
    # 确认旧环境仍在运行
    if [ "$old_env" != "none" ] && [ -f "docker-compose.${old_env}.yml" ]; then
        if ! docker ps --format '{{.Names}}' | grep -q "ip-manager-app-${old_env}"; then
            log_info "重新启动旧环境: $old_env"
            docker compose -f "$COMPOSE_FILE" -f "docker-compose.${old_env}.yml" up -d "app-${old_env}"
            
            # 验证旧环境恢复
            if health_check "$old_port"; then
                log_info "旧环境已恢复"
            else
                log_error "旧环境恢复失败！需要人工干预"
                exit 2
            fi
        else
            log_info "旧环境仍在运行"
        fi
    fi
    
    log_info "回滚完成"
}

# 生成环境特定的 compose 文件
generate_env_compose() {
    local env=$1
    local port=$2
    
    cat > "docker-compose.${env}.yml" << EOF
services:
  app-${env}:
    extends:
      file: ${COMPOSE_FILE}
      service: app
    container_name: ip-manager-app-${env}
    ports:
      - "${port}:80"
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.ip-manager-${env}.rule=Host(\`\${DOMAIN:-localhost}\`)"
      - "traefik.http.services.ip-manager-${env}.loadbalancer.server.port=80"
      - "deployment.env=${env}"
      - "deployment.timestamp=$(date -Iseconds)"
EOF
}

# 部署新版本
deploy() {
    local next_env=$(get_next_env)
    local active_env=$(get_active_env)
    local next_port=$([[ "$next_env" == "blue" ]] && echo "8081" || echo "8082")
    local active_port=$([[ "$active_env" == "blue" ]] && echo "8081" || echo "8082")
    
    log_step "========== 开始蓝绿部署 =========="
    log_info "当前环境: ${active_env:-none}"
    log_info "部署目标: $next_env (端口: $next_port)"
    
    # 生成新环境的 compose 文件
    generate_env_compose "$next_env" "$next_port"
    
    # 拉取最新镜像
    log_step "拉取最新镜像..."
    docker compose -f "$COMPOSE_FILE" pull app 2>/dev/null || true
    
    # 构建新镜像
    log_step "构建新镜像..."
    docker compose -f "$COMPOSE_FILE" -f "docker-compose.${next_env}.yml" build "app-${next_env}"
    
    # 启动新环境
    log_step "启动新环境..."
    docker compose -f "$COMPOSE_FILE" -f "docker-compose.${next_env}.yml" up -d "app-${next_env}"
    
    # 基础健康检查
    if ! health_check "$next_port"; then
        log_error "新环境启动失败"
        if [ "$ROLLBACK_ON_FAILURE" = "true" ]; then
            rollback "$next_env" "$active_env" "$active_port"
        fi
        exit 1
    fi
    
    # 深度健康检查
    if ! deep_health_check "$next_port"; then
        log_error "深度健康检查失败"
        if [ "$ROLLBACK_ON_FAILURE" = "true" ]; then
            rollback "$next_env" "$active_env" "$active_port"
        fi
        exit 1
    fi
    
    # 冒烟测试
    if ! smoke_test "$next_port"; then
        log_warn "冒烟测试有警告，但继续部署"
    fi
    
    # 切换流量
    log_step "切换流量到新环境..."
    
    # 停止旧环境
    if [ "$active_env" != "none" ]; then
        local old_compose="docker-compose.${active_env}.yml"
        if [ -f "$old_compose" ]; then
            log_info "优雅停止旧环境: $active_env"
            # 等待一小段时间让正在处理的请求完成
            sleep 5
            docker compose -f "$COMPOSE_FILE" -f "$old_compose" down "app-${active_env}" 2>/dev/null || true
        fi
    fi
    
    log_info "部署完成！新环境: $next_env"
    log_info "访问地址: http://localhost:${next_port}"
}

# 回滚到上一个版本
rollback() {
    local active_env=$(get_active_env)
    local prev_env=$([[ "$active_env" == "blue" ]] && echo "green" || echo "blue")
    
    log_info "回滚到: $prev_env"
    
    # 检查上一个版本是否存在镜像
    if ! docker images | grep -q "ip-manager.*${prev_env}"; then
        log_error "没有找到上一个版本的镜像"
        exit 1
    fi
    
    # 部署上一个版本
    deploy
}

# 状态检查
status() {
    echo "=========================================="
    echo "困King分发平台 - 部署状态"
    echo "=========================================="
    
    local active=$(get_active_env)
    echo "当前活跃环境: $active"
    echo ""
    
    echo "容器状态:"
    docker ps --filter "name=ip-manager" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
    echo ""
    
    echo "镜像列表:"
    docker images --filter "reference=*ip-manager*" --format "table {{.Repository}}\t{{.Tag}}\t{{.CreatedAt}}\t{{.Size}}"
}

# 清理旧资源
cleanup() {
    log_info "清理旧资源..."
    
    # 删除停止的容器
    docker container prune -f
    
    # 删除未使用的镜像
    docker image prune -f
    
    # 删除未使用的卷
    docker volume prune -f
    
    log_info "清理完成"
}

# 帮助信息
usage() {
    echo "用法: $0 {deploy|rollback|status|cleanup|help}"
    echo ""
    echo "命令:"
    echo "  deploy    部署新版本（蓝绿切换）"
    echo "  rollback  回滚到上一个版本"
    echo "  status    查看当前部署状态"
    echo "  cleanup   清理旧资源"
    echo "  help      显示帮助信息"
}

# 主函数
case "$1" in
    deploy)
        deploy
        ;;
    rollback)
        rollback
        ;;
    status)
        status
        ;;
    cleanup)
        cleanup
        ;;
    help|--help|-h)
        usage
        ;;
    *)
        usage
        exit 1
        ;;
esac
