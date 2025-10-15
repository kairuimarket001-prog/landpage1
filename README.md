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

## 故障排除

如果遇到 "Undefined constant" 错误：

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
