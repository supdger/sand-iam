# SandIAM

SandIAM 是面向 SandAdmin 的 PostgreSQL 身份与访问管理插件，提供客户主体、应用、环境、身份、策略、服务授权与审计。它可独立安装，业务资源和规则由接入应用定义。

当前源码版本为 `0.8.0` 待发布候选，推进按角色、权限与所选上下文识别可办理目标的管理端引导。`info.ini` 的宿主范围声明仍为 `0.1.x`；0.8.0 尚未公开发布，源码检查和本地审查包不代表宿主安装、前端激活或业务权限已验收。

0.8.0 源码候选的应用用户策略使用 PHP-Casbin 内核；现有已发布策略、身份、角色和组配置继续复用。授权允许后，接入应用仍须落实返回的数据范围并在执行时复核实体；机器调用的凭据、服务授权、配额和实时撤销继续由 SandIAM 校验。本地模型、PostgreSQL 和审查 ZIP 验证与实际宿主安装验收分别记录，当前候选尚未发布。

公开下载入口为 [GitHub Releases](https://github.com/supdger/sand-iam/releases)。已公开的 `v0.7.6-preview` 提供 `sand-iam-0.7.6.zip`，属于历史 unsigned preview；下载后按同一 Release 的 `SHA256SUMS` 和构建清单核对。按 Wiki 的[包校验](https://github.com/supdger/sand-iam/wiki/Release-package-verification)核对来源、摘要与所需签名；没有所需签名材料时，不作为正式发行包使用。

0.8.0 的升级候选接受具有已发布 `0.7.3` 完整 43 条迁移账本的 `0.7.3` / `0.7.5` / `0.7.6` 安装；升级脚本在 PostgreSQL 只读事务中核对结构和账本，不执行业务结构或数据修改，异常时拒绝继续。插件管理器更新管理端源码后，宿主仍需按其构建与部署流程激活前端。

已有 SandIAM 表时，先按[既有表接入说明](docs/user-guide/installation-and-upgrade.md#接入已有-sandiam-表)核对账本和候选声明；嵌入版 0.7.3 的 42 条账本不能直接接入 0.8.0。当前源码的接入声明不改变已公开 preview ZIP，也不证明安装器正式发布了接入能力。

0.8.0 源码候选从 SandIAM 总览的“这次要完成什么？”选择当前要办的目标，并按账号权限和所选应用继续；已有应用、身份或配置可复用；确需新应用且账号具备登记权限时，再登记。缺少权限时请对应管理员办理。管理员按当前步骤准备信息，接入开发者在实际应用或服务中验证登录、允许与拒绝、数据范围或撤销结果；后台保存记录不等于业务已经生效。公开 Wiki 的[第一次使用](https://github.com/supdger/sand-iam/wiki/First-use)仍是历史流程，0.8.0 的目标引导说明与候选一起准备，尚未公开发布。

完整使用说明见 [SandIAM Wiki](https://github.com/supdger/sand-iam/wiki)：

- [包校验](https://github.com/supdger/sand-iam/wiki/Release-package-verification)、[安装与升级](https://github.com/supdger/sand-iam/wiki/Installation-and-upgrade)、[第一次使用](https://github.com/supdger/sand-iam/wiki/First-use)；
- [管理员](https://github.com/supdger/sand-iam/wiki/Administrator-guide)、[应用用户](https://github.com/supdger/sand-iam/wiki/Application-user-guide)、[业务接入](https://github.com/supdger/sand-iam/wiki/Application-integration)；
- [配置](https://github.com/supdger/sand-iam/wiki/Configuration-reference)、[安全](https://github.com/supdger/sand-iam/wiki/Security-hardening)、[备份恢复](https://github.com/supdger/sand-iam/wiki/Backup-and-restore)、[排障](https://github.com/supdger/sand-iam/wiki/Troubleshooting)。

使用 SandPackage 安装完整 ZIP，安装前备份；不要只复制插件子目录、创建数据库或手工重放生命周期 SQL。凭证和密钥只通过受控运行环境注入，所有业务授权须验证允许、拒绝、撤销和审计。

贡献见 [CONTRIBUTING.md](CONTRIBUTING.md)，安全问题按 [SECURITY.md](SECURITY.md) 私下报告，变化见 [CHANGELOG.md](CHANGELOG.md)。正式包的锁文件、生成范围与来源证明见 [release-build-contract.json](release-build-contract.json)。

项目按 [Apache-2.0](LICENSE) 分发，版权见 [NOTICE](NOTICE)，第三方许可见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
