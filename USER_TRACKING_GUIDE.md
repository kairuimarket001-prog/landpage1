# 用户行为追踪系统使用指南

## 概述

本系统已成功实现了完整的用户行为追踪功能，每个用户都有独立的标签卡显示其访问行为，并支持分页系统。

## 主要功能

### 1. 用户行为追踪
- **Session ID 追踪**：每个访问用户获得唯一的 session_id
- **行为类型记录**：
  - `page_load` - 用户打开网站
  - `popup_triggered` - 用户触发弹窗
  - `conversion` - 用户产生转化
- **详细信息记录**：IP地址、浏览器、时区、语言、访问的股票等

### 2. 数据存储
- **Supabase 数据库**：所有数据实时保存到 Supabase
- **表结构**：
  - `user_behaviors` - 用户行为记录
  - `page_tracking` - 页面追踪记录
  - `customer_service_assignments` - 客服分配记录

### 3. 后台管理界面
访问 `/admin/user-behaviors` 查看用户行为数据

**登录信息：**
- 用户名：`admin`
- 密码：`admin123`

### 4. 标签卡显示功能
每个用户有独立的标签卡，显示：
- Session ID（会话标识）
- 股票名称和代码
- IP 地址
- 行为时间线：
  - 图标 1：页面加载（蓝色）
  - 图标 2：弹窗触发（橙色）
  - 图标 3：转化完成（绿色）
- 完成状态标签：
  - 已转化（绿色）
  - 已触发弹窗（黄色）
  - 仅访问（蓝色）

### 5. 分页系统
- **每页显示数量**：10 个用户会话
- **分页控制**：
  - 上一页 / 下一页按钮
  - 页码直接跳转
  - 总页数显示
- **自动刷新**：每 30 秒自动更新当前页面数据

## API 端点

### 前端追踪 API
```javascript
// 页面加载追踪
POST /app/maike/api/info/page_track
{
  "session_id": "sess_xxx",
  "action_type": "page_load|popup_triggered|conversion",
  "stock_name": "股票名称",
  "stock_code": "股票代码",
  "url": "访问URL"
}
```

### 后台管理 API
```javascript
// 获取用户行为（分页）
GET /admin/api/user-behaviors?page=1&per_page=10

// 响应格式
{
  "data": [
    {
      "session_id": "sess_xxx",
      "actions": [...],
      "first_action_time": "2025-10-15 10:00:00",
      "last_action_time": "2025-10-15 10:05:00",
      "ip": "123.45.67.89",
      "stock_name": "トヨタ自動車",
      "stock_code": "7203"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total": 150,
    "total_pages": 15
  }
}
```

## 数据统计

管理界面顶部显示以下统计数据：
- **总会话数**：独立访问用户的总数
- **触发弹窗**：触发弹窗的总次数
- **产生转化**：完成转化的总次数
- **转化率**：转化次数 / 总会话数 × 100%

## 前端集成

前端已自动集成追踪功能，无需额外配置：

```javascript
// 在 jp_202573.js 中已自动实现
// 页面加载时自动追踪
window.trackPageLoad();

// 弹窗触发时自动追踪
window.trackPopupTrigger();

// 转化时自动追踪
window.trackConversion();
```

## 数据安全

### Row Level Security (RLS)
所有表都启用了 RLS 策略：
- 仅 service_role 可以插入和查询数据
- 通过后端 API 统一访问，保证数据安全
- 前端无法直接访问数据库

### 索引优化
已创建以下索引以提高查询性能：
- `idx_user_behaviors_session_id` - 按会话ID查询
- `idx_user_behaviors_created_at` - 按时间倒序查询
- `idx_page_tracking_created_at` - 页面追踪时间索引
- `idx_customer_service_assignments_session_id` - 客服分配会话索引

## 测试

运行测试脚本验证系统功能：

```bash
cd /tmp/cc-agent/58667251/project
php test_tracking.php
```

测试内容包括：
1. Supabase 连接测试
2. 插入测试数据
3. 查询最近行为
4. 统计独立会话数
5. 按会话查询行为

## 故障排除

### 问题：无法看到用户行为数据
**解决方案**：
1. 检查 `.env` 文件中的 Supabase 配置
2. 确认数据库表已创建且 RLS 已启用
3. 运行 `test_tracking.php` 验证连接

### 问题：分页不工作
**解决方案**：
1. 检查浏览器控制台是否有错误
2. 确认 API 端点 `/admin/api/user-behaviors` 可访问
3. 验证返回的 JSON 格式是否正确

### 问题：数据未实时更新
**解决方案**：
1. 页面会每 30 秒自动刷新
2. 手动刷新页面获取最新数据
3. 检查前端 JavaScript 是否正常执行

## 技术架构

```
前端 (HTML/JS)
    ↓
追踪脚本 (jp_202573.js)
    ↓
API 端点 (TrackingController)
    ↓
Supabase 客户端 (SupabaseClient)
    ↓
Supabase 数据库
    ↓
管理界面 (AdminController)
```

## 未来扩展

可以考虑添加的功能：
- [ ] 导出用户行为数据为 CSV/Excel
- [ ] 按时间范围筛选数据
- [ ] 按股票代码筛选用户
- [ ] 用户行为可视化图表
- [ ] 实时用户行为监控
- [ ] 用户行为分析报告

## 支持

如有问题，请检查：
1. 后端日志：`/tmp/cc-agent/58667251/project/backend/logs/app.log`
2. Supabase Dashboard：查看数据库记录
3. 浏览器开发者工具：检查网络请求

---

**系统状态**：✅ 正常运行
**最后更新**：2025-10-15
