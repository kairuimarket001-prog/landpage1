# 快速修复指南

## ⚡ 一键修复（推荐）

```bash
# 给脚本执行权限并运行
chmod +x fix-error.sh
./fix-error.sh
```

这个脚本会自动：
1. 检测您的环境（Docker 或本地）
2. 停止并清理旧容器
3. 重新构建并启动服务
4. 验证修复结果

---

## 🔧 手动修复（如果自动脚本失败）

### 使用 Docker（推荐）

```bash
# 1. 停止并清理
docker-compose down -v

# 2. 重新构建
docker-compose build --no-cache backend

# 3. 启动服务
docker-compose up -d

# 4. 验证
curl http://localhost:3321/health
```

### 不使用 Docker

```bash
# 1. 进入后端目录
cd backend

# 2. 删除旧依赖
rm -rf vendor composer.lock

# 3. 安装依赖
composer install

# 4. 启动服务
php -S localhost:8080 -t public
```

---

## ✅ 验证修复成功

### 测试 1：健康检查
```bash
curl http://localhost:3321/health
```
应该返回：`{"status":"healthy",...}`

### 测试 2：访问管理后台
在浏览器打开：http://localhost:3321/admin

### 测试 3：检查日志
```bash
# Docker 环境
docker-compose logs backend

# 本地环境
tail -f backend/logs/app.log
```

---

## 📚 更多信息

查看完整文档：[ERROR_FIX_GUIDE.md](ERROR_FIX_GUIDE.md)

## 🆘 仍有问题？

1. 查看容器日志：`docker-compose logs -f backend`
2. 检查端口是否被占用：`lsof -i :3321`
3. 验证 vendor 目录：`ls -la backend/vendor/`
4. 重启服务：`docker-compose restart`
