# PR 草稿：SandIAM 0.9.7 — 应用功能授权聚合后端（WP-A1）

分支：`fix/0410-phase2`（基线 `6d765c7`，本地 4 个提交，未推送、未合并、未部署）

## 范围

仅 SandIAM 后端。前端页面、H5、跨仓集成属后续工作包（WP-B1 等），本 PR 不含 UI。

## 变更内容

- **端点**：`GET/POST /application/{application_id}/capabilities`，新增 `ApplicationCapabilityController`（`plugin/sand-iam/app/admin/controller/`）与 `ApplicationCapabilityService`（`plugin/sand-iam/app/service/`）；`#[Permission]` 注解 `sand_iam:application_capability:index`/`save`，已 `Route::disableDefaultRoute`。`AccessMatrixController` 行为不变、向后兼容。
- **目录**：`ServiceCatalog` 支持服务级 `group_name`/`audience` 与动作级 `description`（字符串或结构化声明均可）；缺有效中文名/说明/分组的条目过滤且限频记录，不回退裸 action code；停用服务/动作不复活。
- **迁移**：044 增加 `sand_iam_service.group_name`/`audience`、`sand_iam_service_action.description` 并做确定性中文回填（受众取声明值或授权历史唯一值，歧义留 NULL）；045 种子两个隐藏权限节点（type 3、随连接菜单分组、既有角色继承）。根目录与 `plugin/sand-iam/migrations/` 逐字节镜像，含自校验和，幂等。
- **写路径**：serializable 事务 + `pg_advisory_xact_lock`（键含 application_id+organization_id）+ 锁内 revision 复核 + 逐项 grant/revoke + 同事务审计；序列化失败映射 409。`Idempotency-Key` 必填（缺失 400）；receipt 作用域 actor/org/app/`capabilities`/key，指纹=规范化排序 capability_ids + expected_revision + 策略版本；同键同指纹重放持久化结果（`idempotent_replay=true`，先于 revision 校验），同键异指纹 409；持久化结果不含明文凭证。
- **状态语义**：全成功 `success`；部分业务拒绝 HTTP 200 `partial`；全部拒绝 HTTP 422 `failed`；输入 400、无权限 403、不可见 404、revision/幂等冲突 409。
- **托管绑定**：仅解析当前应用有效机构环境；无候选仅创建 `prod`/`生产环境`，多候选中文冲突不取首行；每 (environment, service) 复用或创建 `code=managed`/「托管调用身份」/契约受众 client，受众不符逐项冲突；禁用环境/client/服务/动作不复活；`status=2` 且 `revoked_time` 为空的管理员停用 grant 不静默复活（逐项冲突），撤销项重选复用同一行恢复；撤销保留历史行（status=2+revoked_time）。
- **版本与发布物**：0.9.6→0.9.7 全量元数据同步（info.ini、config、SBOM、existing-schema、release-build-contract、vendor installed.php 规范化）；`install.sql`/`update.sql`/`uninstall.sql` 由 `build-lifecycle.php` 再生成（46 迁移）；新增 `build-upgrade-097.php`（严格 43→46 准入 + 044/045 不可变字节校验 + revision045 目录快照 `release/tools/resources/schema-045-catalog.json` 钉死）；`docs/user-guide/` 9 篇用户文档按包完整性契约从 `110668c^` 原样恢复；CHANGELOG 增加 0.9.7 条目。

## 验证证据（本地实跑）

| 检查 | 命令 | 结果 |
| --- | --- | --- |
| PHP 语法 | `php -l` 全部改动 PHP 文件 | 全部通过 |
| PG fixture | `php tests/application-capability-fixture.php`（真实 PostgreSQL 18.4 scratch 库，自动建删） | 50+ 断言全部 PASS：目录中文过滤、revision 稳定、授权/撤销/重授权、幂等重放/409、禁用环境/client/grant 不复活、多候选冲突、审计原子、无明文凭证 |
| 生命周期 | `php release/tools/build-lifecycle.php --check` | PASS（生成字节与冻结载荷一致） |
| 包完整性 | `php release/tools/check-package-integrity.php` | 29/29 PASS（含「payload 与 HEAD Git blobs 一致」，提交后通过） |
| 迁移镜像 | `cmp` 根/plugin 044、045 | 逐字节一致；sha256 与 `build-upgrade-097.php` 钉死值一致 |

## 已知边界 / 未验证项

- 未推送、未合并、未部署；无任何线上/生产验证。
- fixture 覆盖服务层契约用例；HTTP 层（CheckAuth/Permission 注解拦截、真实路由分发、统一异常出口）未做端到端实跑，依赖宿主运行时。
- 并发串行化以 advisory lock + serializable 事务保证；fixture 未做多连接并发压测（PG 单连接顺序验证）。
- 403 文案沿用仓库 `SAND_IAM_*: 中文` 错误码前缀惯例，与契约「白话 message」语义一致但含机器可读前缀，供 WP-B1 前端按 code 分支。
- `docs/user-guide/` 恢复的是 `110668c^` 时点内容；若 Wiki 侧文档已演进，本仓副本可能滞后，需后续文档工作包对齐。
