# 项目启动指南

## 快速启动

```bash
# 启动所有服务
docker-compose up -d

# 查看日志
docker-compose logs -f

# 停止服务
docker-compose down
```

## 访问地址

- **前端：** http://localhost:3320
- **API：** http://localhost:3321
- **管理后台：** http://localhost:3321/admin
  - 用户名：`admin`
  - 密码：`admin123`

## 数据存储

项目使用 JSONL 文件存储数据，所有数据文件位于 `backend/data/` 目录：

- `users.jsonl` - 用户信息
- `user_profiles.jsonl` - 用户资料
- `user_behaviors.jsonl` - 用户行为追踪
- `assignments.jsonl` - 分配记录
- `customer_services.json` - 客服配置
- `settings.json` - 系统设置

## 故障排除

如果遇到 500 错误或自动加载问题：

```bash
# 重建容器
docker-compose down -v
docker-compose build --no-cache backend
docker-compose up -d
```

## 开发

```bash
# 查看后端日志
docker-compose logs -f backend

# 进入容器
docker-compose exec backend bash

# 安装新依赖
docker-compose exec backend composer require package/name
```
