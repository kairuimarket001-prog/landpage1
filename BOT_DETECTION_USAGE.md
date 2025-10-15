# 机器人检测系统使用指南

## 系统概述

机器人检测系统已完全重构，使用JSONL文件存储，集成到现有的"分配记录"管理页面中。

## 核心特性

### 1. 用户评分系统 (0-100分)
- **IP评分** (20分): 检测数据中心IP、代理、高频请求
- **User-Agent评分** (15分): 验证浏览器签名、检测爬虫
- **请求模式评分** (15分): 分析请求频率和时序
- **指纹评分** (20分): 浏览器指纹验证
- **行为评分** (20分): 鼠标、点击、滚动分析
- **来源评分** (10分): Referer验证

### 2. 用户分类
- **human** (80-100分): 人类用户
- **suspicious** (40-79分): 可疑用户
- **bot** (20-39分): 机器人
- **high_risk** (0-19分): 高风险

### 3. 数据存储

所有数据使用JSONL文件存储在 `backend/data/` 目录：

- `users.jsonl` - 用户主记录
- `user_profiles.jsonl` - 用户设备和网络信息
- `user_fingerprints.jsonl` - 浏览器指纹数据
- `user_behaviors.jsonl` - 用户行为数据
- `bot_detection_scores.jsonl` - 评分历史
- `assignments.jsonl` - 客服分配记录（已扩展，包含session_id）
- `ip_whitelist.json` - IP白名单
- `ip_blacklist.json` - IP黑名单
- `fingerprint_whitelist.json` - 指纹白名单

## 前端集成

### 1. 引入脚本

在HTML页面中引入机器人检测脚本：

```html
<script src="/frontend/static/js/bot-detection.js"></script>
```

脚本会自动：
- 收集浏览器指纹
- 追踪用户行为
- 上传数据到后端
- 每10秒批量上传一次

### 2. 自定义配置

```javascript
BotDetection.init({
    apiBaseUrl: 'https://your-domain.com',
    uploadInterval: 15000,  // 15秒
    maxMouseMovements: 200
});
```

## 后端API

### 1. 分析用户
```http
POST /api/bot-detection/analyze
Content-Type: application/json

{
    "session_id": "sess_xxx",
    "fingerprint_hash": "abc123..."
}

响应：
{
    "success": true,
    "user_id": "user_xxx",
    "score": 85,
    "user_type": "human",
    "confidence": 0.92,
    "risk_level": "low"
}
```

### 2. 保存指纹
```http
POST /api/bot-detection/fingerprint
Content-Type: application/json

{
    "user_id": "user_xxx",
    "canvas": "data:image/png...",
    "webgl": "{...}",
    "fonts": ["Arial", "Verdana"],
    ...
}
```

### 3. 保存行为
```http
POST /api/bot-detection/behavior
Content-Type: application/json

{
    "user_id": "user_xxx",
    "session_id": "sess_xxx",
    "mouse_movements": [{x:100, y:200, timestamp:123}],
    "click_events": [...],
    "scroll_events": [...],
    ...
}
```

## 管理后台

访问：`/admin/assignments`

页面已更新为"用户监控 - 分配记录"，显示：

### 表格列
1. **时间** - 访问时间
2. **用户ID** - 唯一标识符（显示前8位）
3. **用户资料** - 鼠标悬停查看设备、IP、系统、浏览器
4. **判断来源** - IP地址（前15位）
5. **评分** - 0-100分（高/中/低颜色编码）
6. **用户类型** - 人类/可疑/机器人/高风险（彩色标签）
7. **股票代码** - 用户查询的股票
8. **客服** - 分配的客服名称
9. **状态** - 成功/失败/待处理

### 颜色编码
- **评分**
  - 绿色 (70-100): 可信用户
  - 橙色 (40-69): 中等风险
  - 红色 (0-39): 高风险

- **用户类型**
  - 绿色标签: 人类
  - 黄色标签: 可疑
  - 红色标签: 机器人
  - 深红标签: 高风险

## 黑白名单管理

### IP白名单
在 `backend/data/ip_whitelist.json` 添加：

```json
[
    {
        "ip": "192.168.1.1",
        "reason": "公司内网",
        "added_by": "admin",
        "expires_at": null
    }
]
```

### IP黑名单
在 `backend/data/ip_blacklist.json` 添加：

```json
[
    {
        "ip": "1.2.3.4",
        "reason": "已知爬虫",
        "added_by": "admin",
        "expires_at": null
    }
]
```

### 指纹白名单
在 `backend/data/fingerprint_whitelist.json` 添加：

```json
[
    {
        "fingerprint_hash": "abc123...",
        "reason": "管理员设备",
        "added_by": "admin",
        "expires_at": null
    }
]
```

## 工作流程

1. **用户访问网站**
   - 前端`bot-detection.js`自动收集指纹
   - 生成session_id并存储在sessionStorage
   - 调用`/api/bot-detection/analyze`获取user_id

2. **用户查询客服**
   - 调用`/app/maike/api/customerservice/get_info`
   - 传递session_id
   - 后端保存分配记录（含session_id）

3. **后台查看**
   - 管理员访问`/admin/assignments`
   - 系统根据session_id关联用户数据
   - 显示评分、类型、设备信息

4. **持续监控**
   - 前端每10秒上传行为数据
   - 系统实时更新用户评分
   - 行为越多，评分越准确

## 评分逻辑

### IP评分 (20分)
- 白名单IP: 满分
- 黑名单IP: 0分
- 数据中心IP: -10分
- 高频请求IP: -5分

### User-Agent评分 (15分)
- 包含已知机器人关键词: 0分
- 无效浏览器签名: -8分
- 过时浏览器: -5分
- 可疑格式: -7分

### 请求模式评分 (15分)
- 5秒内>10次请求: -10分

### 指纹评分 (20分)
- 无指纹: -10分
- 指纹白名单: 满分

### 行为评分 (20分)
- 无行为数据: -15分
- 无鼠标移动: -10分
- 无点击: -8分
- 无滚动: -7分

### 来源评分 (10分)
- 无Referer: -3分

## 文件结构

```
backend/
  data/
    users.jsonl                 # 用户记录
    user_profiles.jsonl         # 用户资料
    user_fingerprints.jsonl     # 指纹数据
    user_behaviors.jsonl        # 行为数据
    bot_detection_scores.jsonl  # 评分历史
    assignments.jsonl           # 分配记录
    ip_whitelist.json           # IP白名单
    ip_blacklist.json           # IP黑名单
    fingerprint_whitelist.json  # 指纹白名单
  src/
    Controllers/
      BotDetectionController.php  # 机器人检测控制器
      AdminController.php         # 管理后台（已更新）
      CustomerServiceController.php # 客服控制器（已更新）
    Utils/
      BotDetectionEngine.php      # 评分引擎
frontend/
  static/
    js/
      bot-detection.js            # 前端检测库
```

## 故障排查

### 问题：用户评分显示0
- 检查是否有user_id关联
- 确认bot_detection_scores.jsonl有数据
- 查看是否session_id匹配

### 问题：用户资料不显示
- 检查user_profiles.jsonl是否有记录
- 确认user_id关联正确
- 查看前端是否调用了analyze API

### 问题：前端数据未上传
- 检查浏览器控制台错误
- 确认API端点可访问
- 查看bot-detection.js是否加载

## 最佳实践

1. **定期审查评分**: 每周检查误报率
2. **更新黑白名单**: 根据实际情况调整
3. **监控性能**: 确保文件不过大
4. **备份数据**: 定期备份JSONL文件
5. **调整权重**: 根据效果优化评分逻辑

## 技术栈

- 后端: PHP (Slim Framework)
- 存储: JSONL文件
- 前端: 原生JavaScript
- 检测: 自定义评分引擎

---

**版本**: 2.0.0 (JSONL版)
**最后更新**: 2025-10-15
