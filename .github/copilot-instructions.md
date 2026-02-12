# AI 开发指令

## 数据库操作规范

### 重要：数据库文件管理

1. **唯一数据库文件**: 项目中只有一个数据库定义文件
   - 路径: `backend/database_merged.sql`
   - 版本: 4.0 (完整合并版)

2. **禁止创建新数据库文件**
   - ❌ 不要创建新的 `.sql` 文件
   - ❌ 不要创建 `migrate_*.sql`、`update_*.sql`、`patch_*.sql` 等文件
   - ❌ 不要将数据库变更拆分到多个文件

3. **所有数据库变更必须在 `backend/database_merged.sql` 中进行**
   - ✅ 新增表：直接添加到对应的部分
   - ✅ 修改表结构：直接修改原有的 CREATE TABLE 语句
   - ✅ 新增字段：在原有表定义中添加
   - ✅ 新增索引：在原有表定义中添加
   - ✅ 新增存储过程：添加到存储过程部分
   - ✅ 新增定时事件：添加到定时事件部分
   - ✅ 新增初始数据：添加到初始数据部分

### 数据库文件结构

`backend/database_merged.sql` 文件按以下部分组织：

```
第一部分: 基础配置表 (config)
第二部分: IP相关表 (ip_country_cache, ip_blacklist, ip_threats, threat_intel_sources)
第三部分: Cloudflare域名表 (cf_domains)
第四部分: 跳转系统表 (jump_domains, jump_groups, jump_rules, jump_logs, jump_unique_visitors, jump_stats)
第五部分: 短链接表 (short_links, short_link_logs)
第六部分: 反爬虫系统表 (antibot_*)
第七部分: 用户与权限表 (users, role_permissions, login_attempts, api_tokens)
第八部分: 日志与审计表 (audit_logs, system_logs, domain_safety_logs)
第九部分: Webhook表 (webhooks, webhook_logs)
第十部分: 备份与任务表 (backups, system_metrics, metrics_snapshot, stats_hourly, stats_daily, cache_warmup_config)
第十一部分: 遗留兼容表 (redirects, ip_pool, visit_stats, visit_logs, unique_visitors, antibot_config, antibot_requests, antibot_behavior, backup_logs, domains, shortlinks)
第十二部分: 日志归档表 (jump_logs_archive, audit_logs_archive)
第十三部分: 初始数据
第十四部分: IP黑名单默认数据
第十五部分: 存储过程
第十六部分: 定时事件
第十七部分: 性能优化索引
第十八部分: 读写分离健康检查视图
```

### 修改示例

**添加新表时：**
1. 找到对应的部分
2. 在该部分末尾添加新表定义
3. 使用 `CREATE TABLE IF NOT EXISTS`
4. 添加必要的索引和注释

**修改现有表时：**
1. 找到原有的 CREATE TABLE 语句
2. 直接修改该语句，添加/删除/修改字段
3. 不要创建 ALTER TABLE 语句的单独文件

**添加初始数据时：**
1. 找到第十三部分或第十四部分
2. 使用 `INSERT ... ON DUPLICATE KEY UPDATE` 或 `INSERT IGNORE` 语法

### PHP代码中使用数据库

当PHP代码需要使用新的表或字段时：
1. 先在 `backend/database_merged.sql` 中添加表/字段定义
2. 然后在PHP代码中使用

### 版本控制

修改数据库文件后，更新文件末尾的版本信息：
```sql
SELECT '版本: X.X (完整合并版)' as version;
```

---

## 部署规范

### 自动部署

项目配置了 CI/CD 自动部署：
- ✅ 任何更新推送到仓库后，CI 会自动部署到服务器
- ✅ 无需手动上传文件或登录服务器操作
- ✅ 只需 `git push` 即可完成部署

---

## 其他开发规范

### API 返回格式

后端 API 统一使用 `BaseController::success()` 方法返回数据：
```php
$this->success(['key' => $value]);  // 返回 {success: true, data: {key: value}}
```

前端统一从 `res.data` 获取数据。

### 文件编码

所有文件使用 UTF-8 编码，数据库使用 `utf8mb4` 字符集。
