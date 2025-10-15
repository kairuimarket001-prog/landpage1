# 数据追踪分页系统 - 简化版

## 概述

本系统实现了基于文件存储的数据追踪分页功能，每页最多显示10条记录。数据存储在 JSONL 文件中，无需数据库配置。

## 数据存储

### 文件位置

1. **用户行为追踪**: `backend/data/user_behaviors.jsonl`
2. **页面追踪记录**: `backend/logs/tracking.log`
3. **客服分配记录**: `backend/data/assignments.jsonl`

### 数据格式

每个文件使用 JSONL 格式（每行一个 JSON 对象）：

```jsonl
{"session_id":"sess_123","action_type":"page_load","stock_name":"股票名","timestamp":"2025-10-15 10:00:00"}
{"session_id":"sess_124","action_type":"popup_triggered","stock_name":"股票名","timestamp":"2025-10-15 10:01:00"}
```

## API接口

### 分页查询接口

所有接口支持分页参数：

```
GET /api/behaviors?page=1&per_page=10      # 用户行为追踪
GET /api/tracking?page=1&per_page=10       # 页面追踪记录
GET /api/assignments?page=1&per_page=10    # 客服分配记录
```

### 参数说明

- `page`: 页码（从1开始，默认1）
- `per_page`: 每页记录数（默认10条，最大50条）

### 响应格式

```json
{
  "data": [
    {
      "session_id": "sess_123",
      "action_type": "page_load",
      "timestamp": "2025-10-15 10:00:00"
    }
  ],
  "page": 1,
  "per_page": 10,
  "total": 150,
  "total_pages": 15
}
```

## 前端使用

### 1. 引入分页组件

```html
<script src="/static/js/pagination.js"></script>
```

### 2. 创建分页实例

```javascript
createPagination({
  containerId: 'my-container',
  apiUrl: '/api/behaviors',
  perPage: 10,
  emptyMessage: '暂无数据',
  renderItem: function(item) {
    return `<div>${item.session_id}</div>`;
  }
});
```

### 3. 完整示例

```html
<!DOCTYPE html>
<html>
<head>
    <script src="/static/js/pagination.js"></script>
</head>
<body>
    <div id="behaviors-list"></div>

    <script>
        createPagination({
            containerId: 'behaviors-list',
            apiUrl: '/api/behaviors',
            perPage: 10,
            emptyMessage: '暂无用户行为数据',
            renderItem: function(behavior) {
                return `
                    <div class="item">
                        <strong>${behavior.action_type}</strong>
                        <span>${behavior.timestamp}</span>
                    </div>
                `;
            },
            onDataLoaded: function(data) {
                console.log('加载了', data.length, '条记录');
            }
        });
    </script>
</body>
</html>
```

## 测试页面

访问 `/frontend/test_pagination.html` 可以查看完整的分页功能演示。

## 管理后台

管理后台的用户行为追踪页面已集成分页功能：

- 访问路径：`/admin/user-behaviors`
- 每页显示10条记录
- 自动计算统计数据
- 支持上一页/下一页导航
- 显示页码和总页数

## 数据流程

```
用户行为 → 前端收集 → API发送 → 后端接收 → 保存到JSONL文件
                                          ↓
                                    分页API读取文件
                                          ↓
                                    返回分页数据
                                          ↓
                                    前端组件展示
```

## 特点

✅ **无需数据库**：所有数据存储在文件中
✅ **简单部署**：无需额外配置
✅ **高性能**：文件读取速度快
✅ **易维护**：JSONL 格式易于查看和编辑
✅ **分页支持**：每页最多10条记录
✅ **响应式设计**：适配各种屏幕尺寸

## 性能优化建议

### 对于大量数据

如果文件变得很大（超过10000行），建议：

1. **定期清理旧数据**
   ```bash
   # 保留最近1000行
   tail -n 1000 user_behaviors.jsonl > user_behaviors.tmp
   mv user_behaviors.tmp user_behaviors.jsonl
   ```

2. **按日期归档**
   ```bash
   # 将旧数据移到归档文件
   mv user_behaviors.jsonl archive/user_behaviors_$(date +%Y%m%d).jsonl
   touch user_behaviors.jsonl
   ```

3. **使用缓存**
   - 在 PHP 中缓存文件读取结果
   - 使用 Redis 或 Memcached 缓存热数据

## 文件结构

```
project/
├── backend/
│   ├── data/
│   │   ├── user_behaviors.jsonl    # 用户行为数据
│   │   └── assignments.jsonl       # 分配记录
│   ├── logs/
│   │   └── tracking.log            # 追踪日志
│   └── src/
│       └── Controllers/
│           └── TrackingController.php
├── frontend/
│   ├── static/
│   │   └── js/
│   │       └── pagination.js       # 分页组件
│   └── test_pagination.html        # 测试页面
└── PAGINATION_SIMPLE_GUIDE.md      # 本文档
```

## 常见问题

### Q: 数据没有显示？

**A:** 检查以下几点：
1. 文件路径是否正确
2. 文件是否有读取权限
3. JSONL 格式是否正确（每行一个JSON对象）
4. 浏览器控制台是否有错误

### Q: 分页不工作？

**A:** 确认：
1. pagination.js 已正确加载
2. 容器ID是否正确
3. API是否返回正确的数据格式
4. 检查网络请求是否成功

### Q: 性能问题？

**A:** 优化方案：
1. 限制文件大小（定期清理旧数据）
2. 使用缓存减少文件读取
3. 考虑使用数据库存储大量数据
4. 减少每页显示的记录数

### Q: 如何备份数据？

**A:** 简单备份：
```bash
# 创建备份目录
mkdir -p backups/$(date +%Y%m%d)

# 复制数据文件
cp backend/data/*.jsonl backups/$(date +%Y%m%d)/
cp backend/logs/tracking.log backups/$(date +%Y%m%d)/
```

## 升级到数据库

如果将来需要升级到数据库存储，可以：

1. 将 JSONL 数据导入数据库
2. 修改 TrackingController 使用数据库查询
3. API 接口保持不变
4. 前端代码无需修改

## 总结

这是一个轻量级的分页解决方案，适合：

- ✅ 中小型应用
- ✅ 快速原型开发
- ✅ 不想配置数据库的场景
- ✅ 需要简单部署的项目

数据存储在文件中，易于查看、备份和迁移。分页功能完整，用户体验良好。
