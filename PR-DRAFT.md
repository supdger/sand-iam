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

---

# PR 草稿：SandIAM 0.9.7 — B3 开通功能页 + 用户与权限工作台 + 菜单迁移（WP-B1）

分支：`fix/0410-phase2`（WP-A1 基线 `ffff270` 之上新增 3 个提交 + 本文档提交，未推送、未合并、未部署）

## 范围

SandIAM 管理端前端（`sandadmin-artd/src/views/plugin/sand-iam/`）+ 菜单迁移 046 + 发布链同步。后端 B3 契约由 WP-A1 提供，本工作包只消费不改后端。

## 变更内容

- **B3「开通功能」页**：新增 `application-capability/index.vue` 与路由实体 `sandadmin-artd/src/views/plugin/sand-iam/application-capability/`（菜单 component=`/plugin/sand-iam/application-capability/index`）。页面标题「给「<应用中文名>」开通功能」，副标题说明勾选功能点「允许使用」即可。能力项按 `group_name` 分组渲染中文名+白话描述，未映射/缺字段条目由后端过滤不显示；已开通项默认勾选并标「已开通」；取消已开通项的勾选需二次确认（「收回后这个应用就不能再用…」）。提交发送完整期望集合 + `expected_revision`（不透明 revision 原样透传），`Idempotency-Key` 每次用户主动提交新生成，网络/5xx 不确定结果重试复用同键同体，`X-Request-Id` 每请求独立。结果五态：成功 / 部分失败（逐项中文原因 + 「重试没开通的」）/ 业务失败（「没能开通：<白话原因>」+「重试」）/ 系统故障（「系统出了点问题，请稍后再试」+「复制问题信息」+有界重试）/ 只读（`can_modify=false` 时禁用勾选并提示找管理员）。无 `application_id` 时先选应用；403/404/加载失败各有独立视图。全程不展示权限码、环境、受众、凭证、JSON、路径、请求号以外的技术信息。
- **API 客户端**：`api/applicationCapabilities.ts` 提供目录/保存请求、响应结构守卫（形状不符按系统故障处理，不猜测渲染）、422 `failed` 负载解析、`SAND_IAM_*`/`SAND_*` 机器码前缀剥离的白话错误提取（纯英文兜底文案不视为业务原因）。
- **页面级错误边界**：`components/SandIamErrorBoundary.vue` + `api/errorBoundary.ts`（移植 sand-ai 仓先例）：捕获 Vue 渲染/生命周期异常、`window.error`、`unhandledrejection`（含动态 import/chunk 加载失败）；展示固定中文文案，复制信息仅含错误码/时间/页面/请求编号（安全字符集过滤）；资源加载失败 30 秒内最多自动重载 1 次防循环。B3 页与用户与权限页已接入。
- **应用列表入口**：`application/index.vue` 行操作新增「开通功能」按钮（`row-actions` 既有插槽），按 `sand_iam:application_capability:index` 权限显隐，携带 `application_id` 跳转；原 CRUD/筛选/关系列表行为不变。
- **H5「用户与权限」工作台**：`people-access/index.vue` 由 TaskPath 空壳改为真实工作台——三级选择（接入应用→员工→角色）+「分配角色」主操作 +「已分配的角色」列表（状态、生效中/已撤销、撤销需确认）。数据走既有 `identity`/`role`/`identity-role` index 与 `identity-role/grant|revoke` 接口；`application_id`/`identity_id`/`keywords` 过滤与 `status=1` 启用过滤在前后端双侧执行，组织范围与资源归属校验完全由后端 `AdminOrganizationAccess`/`assertApplication` 完成。权限不足时只读降级（可查看不可分配）；加载/空态/错误各有中文提示与重试。原 TaskPath 手动配置步骤收进「高级：手动配置步骤（一般不用改）」折叠区，页面路由与任务深链不变。
- **菜单迁移 046**（`migrations/046_application_capability_menu.pgsql`，plugin 镜像逐字节一致）：种子 `SandIAMApplicationCapability` 页（name=开通功能、type=2、绝对 path=`/sand-iam/application-capability`、component 指向新页、`is_hidden=1` 侧栏隐藏但路由可达）；045 种子的两个能力权限节点（type=3）重挂到新页下（slug/name/status 不变）；已持有能力权限节点或应用页的角色自动补授新页 `sand_system_role_menu`。DO 块自校验：根菜单唯一、无重复页、权限节点归位；台账按既有模式登记 revision=46 与自校验和；全语句幂等，重跑收敛无重复（scratch PG 实测两遍）。
- **菜单分组调整**（`lifecycle/menu-regroup.pgsql`）：`SandIAMApplicationCapability` 归入「应用接入」组；`SandIAMConnection`（旧七步接入）移入「高级」组并更名「手动接入」，存储 path 转绝对路径 `/sand-iam/connection`，有效 URL 与旧深链不变；旧页面、API、数据、凭证、停用状态全部保留，仅降级为高级路径。
- **发布链同步**：`build-lifecycle.php` 迁移清单纳入 046（install/update/uninstall 再生成，台账 47 行）；`build-upgrade-097.php` 准入 46→47 行台账、条件执行 046、钉死 046 文件 sha256 与自校验和、菜单 regroup 源哈希校验同步；`check-package-integrity.php` 将 046 列入不可变迁移与 0.9.7 更新迁移名校验；`check-existing-schema.php` 期望台账 47 行；`install.sql`/`update.sql`/`plugin/sand-iam/{install,update}.sql`/`existing-schema.json` 由生成器重建，根与插件迁移载荷逐字节一致。

## 验证证据（本地实跑）

| 检查 | 命令 | 结果 |
| --- | --- | --- |
| PHP 语法 | `php -l` build-lifecycle/build-upgrade-097/check-package-integrity/check-existing-schema | 全部通过 |
| 迁移幂等 | scratch PG18 库（自建自删）先跑 install 前置态（46 行台账+045 行+权限节点+角色授权）→ `psql -f migrations/046…` 两遍 + `psql -f lifecycle/menu-regroup.pgsql` 两遍 | 首遍建页/重挂权限节点/补角色授权；重跑 INSERT 0 0、台账 47 行不重复、role_menu 无重复；regroup 收敛：开通功能页入应用接入、connection 入高级更名手动接入、绝对路径保留 |
| 前端 import 解析 | `php sand-ai/tools/frontend-gate.php <repo> sand-iam`（相对 import 解析层） | 本批新增/改动文件全部可解析；**基线既有** `getting-started/{behavior,viewport-entry}.ts` 引用已于 `ee81373` 删除的 `*.auth-mock`/`*.http-mock` 共 4 条不可解析（非本批引入，未在 WP-B1 范围内处置） |
| 生命周期生成 | `php release/tools/build-lifecycle.php --write` | install/update/uninstall 0.9.7 profile=47 迁移再生成成功 |
| 现有库结构 | `php release/tools/check-existing-schema.php` | PASS（台账 47 行、install sha256、结构指纹一致） |
| 包完整性 | `php release/tools/check-package-integrity.php` | 28/29 PASS；唯一 FAIL「payload 与 HEAD Git blobs 一致」因工作区未提交，提交后应转 PASS（WP-A1 同口径） |
| 禁用词扫描 | `grep -rIn '律序\|lvxu\|lvku'`、`198\.18\.\|sand_iam:\|SAND_IAM_`（限本批前端文件） | 本批文件零命中；全仓命中均为基线既有测试夹具/检测正则 |
| 文案核对 | 必需中文串 grep（开通功能/允许使用/已开通/收回确认/系统故障/复制问题信息/手动接入/用户与权限/分配角色/高级折叠） | 全部存在 |

## 已知边界 / 未验证项

- 未推送、未合并、未部署；无线上/生产验证；未对真实业务库执行迁移（仅 scratch 库验证）。
- 前端 `vue-tsc --noEmit` 未执行：宿主 `repos/sandadmin` 无 node_modules 且约束禁止写其他仓库；以 import 解析扫描 + 人工审查替代，建议合并前在宿主 checkout 跑一次 `yarn build` 内置 `vue-tsc --noEmit`。
- `getting-started` 两个行为测试入口引用已删除 mock 模块（基线既有），frontend-gate 全量扫描因此 4 条未解析；是否清理该死代码属独立工作包。
- B3 页面与工作台的端到端联调（真实菜单下发、CheckAuth、后端 422/409 分支）未实跑，依赖宿主运行时；契约字段已按 WP-A1 后端实现逐字段核对。
- 迁移 046 在全新安装与 0.9.6→0.9.7 升级两条路径的最终树形态已由 scratch 库分别推演（组不存在时挂根、regroup 再归位；升级路径组已存在直接归位），未在真实宿主菜单表全量数据上跑。

# WP-F3：getting-started 死代码挂架清理（frontend-gate 归零）

- 工作包：WP-F3（第二批收尾修复，固定模型 SWE-2）
- 背景：WP-B1 已披露 `getting-started/{behavior,viewport-entry}.ts` 引用
  `ee81373` 删除的 `*.auth-mock`/`*.http-mock` 共 4 条不可解析（phase1 基线
  既有，非本批引入）。
- 查证结论：该目录下 4 个挂架文件
  （`getting-started.behavior.ts`、`getting-started.viewport-entry.ts`、
  `getting-started.behavior-entry.ts`、`getting-started.index-mount.ts`）
  构成自闭合的浏览器行为测试挂架簇——仅互相引用，无任何存活代码、路由、
  菜单、脚本或测试引用（全仓 grep `runGettingStartedBehaviorHarness`/
  `mountGettingStartedPage`/`wizardHarnessWindow`/`configureWizardAuth`/
  `configureWizardHttpMock`/`wizardHttpSavePostCount` 及文件名，仓库内
  命中仅 `release/tools/package-payload-policy.php` 排除清单与本文档）。
  `ee81373`「keep only user-facing payload」已删除全部 mock 与
  `sandadmin-artd/tests/`、`portal/tests/` 测试树，挂架失去全部消费者；
  且 `package-payload-policy.php` 本就将这 4 个文件排除在发布载荷外。
- 页面本体未动：`index.vue`/`wizardEntry.ts`/`wizardState.ts`/
  `WizardStepForm.vue` 为存活页面——菜单种子（`install.sql`、
  `lifecycle/base.pgsql`、`plugin/sand-iam/install.sql`）注册
  「第一次使用」页，`components/GoalGuide.vue` 与 `index/index.vue`
  仍路由至 `/sand-iam/getting-started`。
- 处置：删除上述 4 个死代码文件（347 行）。`package-payload-policy.php`
  排除清单中对应条目保留为无害残留（路径不存在时排除项不生效；若未来
  恢复挂架文件仍会被排除在发布载荷外），未改动该文件。

## 验证（实跑）

| 命令 | 退出码 | 输出末段 |
|---|---|---|
| `php ../sand-ai/tools/frontend-gate.php . sand-iam`（清理前复现） | 1 | `未解析：getting-started/{behavior,viewport-entry}.ts: ./getting-started.auth-mock|http-mock` 共 4 条 `拒绝产出发行 ZIP` |
| `php ../sand-ai/tools/frontend-gate.php . sand-iam`（清理后） | 0 | `前端闸门：相对 import 解析 112 个文件 / 384 条相对 import` `前端闸门：通过` |

- pre-commit（`scan_change_quality.py --repository-review`）：prepare →
  /tmp 评审结论 → record → check 通过，未删 hook、未用 `--no-verify`、
  未用 `git add -A`。

## 未执行/未验证项

- 未推送、未合并、未部署；仅本地 `fix/0410-phase2` 分支小粒度提交。
- `vue-tsc --noEmit` 未执行（同 WP-B1 既有边界：宿主无 node_modules）；
  删除的 4 个文件不被任何存活文件 import，不影响类型检查面。
