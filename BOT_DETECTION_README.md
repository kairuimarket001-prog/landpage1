# 机器人检测与用户监控系统

## 系统概述

这是一个完整的、科学的机器人检测和人类验证系统，以访问用户为基准，为每个用户提供0-100的评分，判断其是人类还是机器人。

## 核心功能

### 1. 多层次机器人检测
- **IP信誉检查** (20分): 检测数据中心IP、代理服务器、高频请求
- **User-Agent分析** (15分): 验证浏览器签名、检测已知爬虫模式
- **请求模式分析** (15分): 分析请求频率、时序模式、会话特征
- **指纹一致性检查** (20分): 验证浏览器指纹与User-Agent匹配度
- **行为模式分析** (20分): 监测鼠标移动、点击、滚动、键盘输入
- **流量来源验证** (10分): 检查Referer合法性、搜索引擎来源

### 2. 用户分类
- **human** (80-100分): 确认为人类用户
- **suspicious** (40-79分): 可疑用户，需进一步观察
- **bot** (20-39分): 高概率为机器人
- **high_risk** (0-19分): 极高风险，建议阻止

### 3. 数据库结构

所有数据存储在Supabase中：

- `users`: 用户主表，存储用户ID、类型、访问次数
- `user_profiles`: 用户资料，IP、设备、浏览器信息
- `user_fingerprints`: 浏览器指纹数据
- `user_behaviors`: 用户行为轨迹
- `bot_detection_scores`: 评分历史记录
- `traffic_sources`: 流量来源分析
- `customer_service_assignments`: 客服分配记录（已关联用户ID）
- `ip_whitelist/blacklist`: IP黑白名单
- `fingerprint_whitelist`: 指纹白名单

## 使用指南

### 前端集成

在您的HTML页面中引入机器人检测脚本：

```html
<script src="/frontend/static/js/bot-detection.js"></script>
```

脚本会自动：
1. 收集浏览器指纹
2. 追踪用户行为（鼠标、点击、滚动）
3. 上传数据到后端进行评分
4. 每10秒上传一次行为数据

您也可以手动初始化配置：

```javascript
BotDetection.init({
    apiBaseUrl: 'https://your-domain.com',
    uploadInterval: 15000,  // 15秒上传一次
    maxMouseMovements: 200  // 最多记录200个鼠标移动
});
```

### 后端API

#### 1. 分析用户
```
POST /api/bot-detection/analyze
Content-Type: application/json

{
    "session_id": "sess_xxx",
    "fingerprint_hash": "abc123..."
}

响应：
{
    "success": true,
    "user_id": "uuid",
    "score": 85,
    "user_type": "human",
    "confidence": 0.92,
    "risk_level": "low"
}
```

#### 2. 保存指纹
```
POST /api/bot-detection/fingerprint
Content-Type: application/json

{
    "user_id": "uuid",
    "canvas": "data:image/png...",
    "webgl": "{...}",
    "fonts": ["Arial", "Verdana"],
    ...
}
```

#### 3. 保存行为数据
```
POST /api/bot-detection/behavior
Content-Type: application/json

{
    "user_id": "uuid",
    "session_id": "sess_xxx",
    "mouse_movements": [{x:100, y:200, timestamp:123}],
    "click_events": [...],
    "scroll_events": [...],
    "time_on_page": 45,
    "interaction_count": 23
}
```

### 管理后台

访问用户监控中心：`/admin/user-monitoring`

功能包括：
- 实时统计：总用户数、人类/机器人比例、24小时新增
- 用户列表：显示所有用户的ID、评分、类型、来源
- 筛选功能：按用户类型、评分范围筛选
- 用户详情：鼠标悬停显示完整用户资料
- 评分详情：查看每个检测因子的得分明细
- 手动标记：管理员可手动修改用户类型

#### 管理后台API

获取用户列表：
```
GET /admin/api/user-monitoring/users?page=1&per_page=50&user_type=bot&min_score=0&max_score=40
```

获取用户详情：
```
GET /admin/api/user-monitoring/users/{userId}
```

更新用户：
```
PUT /admin/api/user-monitoring/users/{userId}
Content-Type: application/json

{
    "user_type": "human",
    "notes": "已验证为真实用户",
    "is_whitelisted": true
}
```

获取统计数据：
```
GET /admin/api/user-monitoring/statistics
```

## 黑白名单管理

### IP白名单
信任的IP地址会自动通过检测，获得满分：

```sql
INSERT INTO ip_whitelist (ip_address, reason, added_by)
VALUES ('192.168.1.1', '公司内网', 'admin');
```

### IP黑名单
已知恶意IP会直接被标记为高风险：

```sql
INSERT INTO ip_blacklist (ip_address, reason, added_by)
VALUES ('1.2.3.4', '已知爬虫IP', 'admin');
```

### 指纹白名单
信任的设备指纹会跳过检测：

```sql
INSERT INTO fingerprint_whitelist (fingerprint_hash, reason, added_by)
VALUES ('abc123...', '管理员设备', 'admin');
```

## 评分逻辑详解

### IP评分 (20分)
- 白名单IP: +20分
- 黑名单IP: 0分
- 数据中心IP: -10分
- 已知代理IP: -8分
- 高频请求IP: -5分

### User-Agent评分 (15分)
- 包含已知机器人关键词: 0分
- 无效浏览器签名: -8分
- 过时的浏览器版本: -5分
- 可疑User-Agent格式: -7分

### 请求模式评分 (15分)
- 5秒内超过10次请求: -10分
- 规律性时间间隔: -8分
- 缺乏典型用户行为: -5分

### 指纹评分 (20分)
- 无指纹数据: -10分
- 指纹白名单: +20分
- 指纹与User-Agent不一致: -12分
- 常见机器人指纹: -15分
- 可疑指纹特征: -8分

### 行为评分 (20分)
- 无行为数据: -15分
- 无鼠标移动: -10分
- 无自然点击模式: -8分
- 无滚动活动: -7分
- 检测到机器人行为: -12分

### 来源评分 (10分)
- 无Referer: -3分
- 伪造搜索引擎来源: -8分
- 可疑Referer: -5分

## 置信度计算

置信度 = 数据完整度 × 评分一致性

- 数据完整度：是否有指纹、行为、IP、User-Agent数据
- 评分一致性：各项评分的方差（方差越小越一致）

## 性能优化

1. **数据库索引**：所有常用查询字段都已建立索引
2. **批量上传**：前端每10秒批量上传行为数据
3. **缓存策略**：评分结果可缓存，减少重复计算
4. **异步处理**：指纹和行为数据采集不阻塞用户体验

## 隐私合规

系统设计符合GDPR和隐私法规：
- 所有数据匿名化存储
- 不收集个人身份信息
- 行为数据仅用于机器人检测
- 用户可选择退出追踪

## 扩展建议

### 未来可添加的功能：

1. **机器学习模型**：基于历史数据训练分类模型
2. **实时告警**：当机器人比例异常升高时发送通知
3. **A/B测试**：测试不同检测策略的效果
4. **可视化图表**：评分分布、时间趋势、来源分析
5. **自动学习**：根据用户后续行为自动调整评分
6. **验证码集成**：对低分用户展示验证码挑战
7. **速率限制**：基于评分的动态速率限制

## 故障排查

### 问题：用户评分过低
- 检查是否缺少指纹或行为数据
- 查看评分详情，找出扣分原因
- 考虑将用户IP或指纹加入白名单

### 问题：机器人评分过高
- 检查机器人的指纹是否与人类相似
- 分析行为数据，寻找异常模式
- 调整评分权重，增加行为分析权重

### 问题：前端数据未上传
- 检查浏览器控制台是否有错误
- 确认API端点是否可访问
- 检查CORS配置是否正确

## 技术栈

- **后端**: PHP (Slim Framework)
- **数据库**: Supabase (PostgreSQL)
- **前端**: 原生JavaScript
- **检测引擎**: 自定义评分引擎

## 维护建议

1. 定期审查评分逻辑，根据实际效果调整
2. 每周分析误报率和漏报率
3. 监控系统性能，确保不影响用户体验
4. 更新已知机器人特征库
5. 定期备份Supabase数据

## 支持与反馈

如有问题或建议，请联系开发团队。

---

**最后更新**: 2025-10-15
**版本**: 1.0.0
