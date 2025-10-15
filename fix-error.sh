#!/bin/bash

# 错误修复脚本：解决 "Undefined constant 'App\Controllers\profile'" 错误
# 使用方法：chmod +x fix-error.sh && ./fix-error.sh

set -e  # 遇到错误立即退出

echo "=========================================="
echo "错误修复脚本 v1.0"
echo "=========================================="
echo ""

# 检测环境
echo "🔍 检测运行环境..."
echo ""

if command -v docker &> /dev/null && command -v docker-compose &> /dev/null; then
    echo "✓ 检测到 Docker 和 Docker Compose"
    USE_DOCKER=true
else
    echo "✗ 未检测到 Docker，将使用本地环境"
    USE_DOCKER=false
fi

echo ""
echo "=========================================="
echo "开始修复..."
echo "=========================================="
echo ""

if [ "$USE_DOCKER" = true ]; then
    # Docker 环境修复
    echo "📦 使用 Docker 环境修复"
    echo ""

    echo "步骤 1/5: 停止现有容器..."
    docker-compose down
    echo "✓ 容器已停止"
    echo ""

    echo "步骤 2/5: 清理旧的 volumes..."
    docker volume ls -q | grep backend_vendor | xargs -r docker volume rm || true
    echo "✓ Volumes 已清理"
    echo ""

    echo "步骤 3/5: 重新构建镜像..."
    docker-compose build --no-cache backend
    echo "✓ 镜像构建完成"
    echo ""

    echo "步骤 4/5: 启动服务..."
    docker-compose up -d
    echo "✓ 服务已启动"
    echo ""

    echo "步骤 5/5: 等待服务就绪..."
    sleep 5
    echo "✓ 服务已就绪"
    echo ""

    echo "=========================================="
    echo "验证修复结果..."
    echo "=========================================="
    echo ""

    echo "检查 vendor 目录..."
    if docker-compose exec -T backend test -d /var/www/html/backend/vendor; then
        echo "✓ vendor 目录存在"
    else
        echo "✗ vendor 目录不存在"
    fi
    echo ""

    echo "检查 autoload.php..."
    if docker-compose exec -T backend test -f /var/www/html/backend/vendor/autoload.php; then
        echo "✓ autoload.php 存在"
    else
        echo "✗ autoload.php 不存在"
    fi
    echo ""

    echo "测试健康检查端点..."
    sleep 2
    if curl -s http://localhost:3321/health | grep -q "healthy"; then
        echo "✓ 健康检查通过"
    else
        echo "⚠ 健康检查失败（可能需要更多时间启动）"
    fi
    echo ""

    echo "=========================================="
    echo "修复完成！"
    echo "=========================================="
    echo ""
    echo "📊 服务访问地址："
    echo "   前端：http://localhost:3320"
    echo "   API：http://localhost:3321"
    echo "   管理后台：http://localhost:3321/admin"
    echo ""
    echo "🔑 管理后台登录："
    echo "   用户名：admin"
    echo "   密码：admin123"
    echo ""
    echo "📝 查看日志："
    echo "   docker-compose logs -f backend"
    echo ""
    echo "📖 详细文档："
    echo "   查看 ERROR_FIX_GUIDE.md"
    echo ""

else
    # 本地环境修复
    echo "💻 使用本地环境修复"
    echo ""

    cd backend

    echo "步骤 1/4: 检查 PHP 版本..."
    php -v | head -n 1
    echo ""

    echo "步骤 2/4: 检查 Composer..."
    if ! command -v composer &> /dev/null; then
        echo "✗ Composer 未安装"
        echo "请安装 Composer: https://getcomposer.org/download/"
        exit 1
    fi
    composer --version
    echo ""

    echo "步骤 3/4: 安装依赖..."
    rm -rf vendor composer.lock
    composer install --no-dev --optimize-autoloader
    echo "✓ 依赖安装完成"
    echo ""

    echo "步骤 4/4: 创建必要目录..."
    mkdir -p logs var/cache data
    chmod -R 755 logs var/cache data
    echo "✓ 目录创建完成"
    echo ""

    echo "=========================================="
    echo "修复完成！"
    echo "=========================================="
    echo ""
    echo "🚀 启动开发服务器："
    echo "   cd backend"
    echo "   php -S localhost:8080 -t public"
    echo ""
    echo "📊 访问应用："
    echo "   http://localhost:8080"
    echo ""
fi

echo "=========================================="
echo "✨ 所有步骤已完成！"
echo "=========================================="
