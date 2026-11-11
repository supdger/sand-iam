# AGENTS.md — SandIAM

## 本仓是什么

SandIAM 是 SandAdmin 的身份与访问管理插件：PHP/Webman 后端 + Vue3/TS 管理端 + Dart/PHP/TypeScript 三端 SDK + 独立 TypeScript 账号门户。仅 PostgreSQL，业务表前缀 `sand_iam_*`。

主要目录：

- `plugin/sand-iam/`：插件主体。`app/` 后端分层（admin、api、oidc、cas、kerberos、radius、security、model、service 等）、`bin/` 两个运行时预检、`config/`、`migrations/`（47 个 `.pgsql`）、已提交的 `vendor/`、`install/update/uninstall.sql`、`info.ini`
- `migrations/`：根目录迁移镜像，与插件内一致
- `lifecycle/`：安装生命周期 SQL（`base.pgsql`、`bridge/`、`menu-regroup.pgsql`、`remove.pgsql`、各版本升级预检）
- `sandadmin-artd/src/views/plugin/sand-iam/`：管理端 Vue/TS 视图
- `portal/`：独立 TS 账号门户（pnpm，`scripts/build.mjs`）
- `sdk/{dart,php,typescript}/`：三端 SDK；typescript 已提交 `dist/`
- `release/tools/`：发布构建与校验 PHP 脚本；`release/recovery/` 恢复材料
- `examples/`：`machine-service-client`、`webman-business-app` 接入示例
- `docs/user-guide/`：用户文档；`tests/`：4 个 `*-fixture.php`（需真实 PostgreSQL）
- 根 `install/update/uninstall.sql`、`config.json`、`info.ini`、`SBOM.cdx.json`、`existing-schema.json`

## 命名与注释规范

完整规范见 `/Users/supdger/agent-work/grokbot/devin-queue/命名与注释规范.md`（v2）；交付前跑 `/Users/supdger/agent-work/grokbot/devin-queue/naming_check.py` 自查。要点：

数据库（PostgreSQL only）：

- 表名 `sand_iam_*`，小写蛇形单数；去前缀 ≤3 词、≤30 字符；主键 `id`；列主体 ≤3 词、≤30 字符，不以本表名词开头
- 布尔 `is_/has_/can_/must_` 或形容词/过去分词；时间点 `*_time`、日期 `*_date`，固定三列 `create_time`/`update_time`/`delete_time`；时长带单位
- `status` = 启停（1 启用、2 停用），`state` = 流转（字符串 + CHECK）；数量带单位；索引约束 `uk_/ix_/fk_/ck_` + 完整表名

接口与契约：

- 路径全小写连字符、资源复数、动作放末段动词；JSON/查询参数小写蛇形、与列同名；枚举小写蛇形（任务状态 `queued/running/succeeded/failed/cancelled`）；错误码大写蛇形；权限码 `域:资源:动作`
- 迁移按序号追加；已发布迁移不改名、不改内容（CONTRIBUTING 硬性要求）；不得创建 `sa_*` 业务表

代码：

- 类 PascalCase、函数/变量 camelCase、常量大写蛇形；函数/方法 ≤4 词、动词开头；不用 `Manager/Helper/Util/Processor/Wrapper/Info/Data` 收尾；不用 `usr/pwd/cnt/msg/btn/tbl/mgr/svc/cfg/val/ret` 等自造缩写；变量名不带类型后缀；布尔 `is/has/can/should` 开头
- 前端组件 `PascalCase.tsx`、hook `useXxx.ts`、测试 `同名.test.ts(x)`；PHP 遵守 PSR-12/PSR-4

注释：

- 只写代码看不出的原因/约束；禁流水账、「此函数用于」、横幅分隔线、表情、改动记录、注释掉的旧代码；新文件注释简体中文；`TODO(负责人或#编号): 原因`

统一叫法：`dept`、`file`、`create_time`/`update_time`/`delete_time`、`status` = 启停、`state` = 流转、`announcement`、`task`/`task_run`、`schedule`/`schedule_run`、`dict_type`、`audit_log`、`visibility`、`cancelled`（双写 l）、`client`。

## 提交规矩

- 只 `git add` 具体文件，禁 `git add -A`/`git add .`；不提交 `.env`、密钥、token、真实账号、个人数据、本机路径（CONTRIBUTING）
- DCO：每个提交必须 `git commit -s` 带 `Signed-off-by`
- 不 force push、不 `reset --hard`、不 `--no-verify`；不合并 PR、不打 tag
- 源码仓改动不等于可发布：正式 ZIP 由维护者从 clean commit 构建（CONTRIBUTING）

## 测试和检查命令

- `php -l <file>` 逐文件语法检查
- `php plugin/sand-iam/bin/check-runtime-configuration.php`、`check-runtime-requirements.php`（运行时预检）
- `php release/tools/check-package-integrity.php`、`check-release-payload.php`、`generate-sbom.php --check`（CONTRIBUTING 本地检查）
- `portal/`：`pnpm install` 后 `pnpm typecheck`；注意 `test:contract`/`verify` 引用的 `tests/portal-contract.test.mjs` 在仓内不存在（`portal/tests/` 缺失），脚本当前跑不通
- `sdk/typescript`：`pnpm build`；其 `test` 脚本同样引用缺失的 `tests/client.test.mjs`；`sdk/dart` 声明了 test 依赖但无 `test/` 目录
- `tests/*-fixture.php` 需真实 PostgreSQL scratch 库，先获环境授权
- 仓内无 `.github/` 等 CI 配置

## 许可证

根 `LICENSE` 为 MIT（版权 青岛沙盒创意网络科技有限公司）；第三方许可见 `NOTICE`、`THIRD_PARTY_NOTICES.md`。`sdk/{dart,php,typescript}/` 各自带 `LICENSE`/`NOTICE`（MIT）；`plugin/sand-iam/vendor/` 为已提交依赖，各自许可见 `THIRD_PARTY_NOTICES.md`。
