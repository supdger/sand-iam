# SandIAM

SandIAM 是面向 SandAdmin 的 PostgreSQL 身份与访问管理插件，提供客户主体、应用、环境、身份、策略、服务授权与审计。它可独立安装，业务资源和规则由接入应用定义。

当前版本为 `0.7.6` 预发布候选，提供管理端首次接入、上下文选择和错误恢复改进。`info.ini` 的宿主范围声明为 `0.1.x`；本次安装升级验收以 SandPackage `0.1.9` 为目标，具体结果见该版本 Release 说明，未验收版本不视为已支持。此候选未完成完整产品正式发行门槛，不作为生产可用声明。

公开下载入口为 [GitHub Releases](https://github.com/supdger/sand-iam/releases)。本次包名为 `sand-iam-0.7.6.zip`，对应 `v0.7.6-preview`；下载后按同一 Release 的 `SHA256SUMS` 和构建清单核对，不因版本号相同就视为同一包。按 Wiki 的[包校验](https://github.com/supdger/sand-iam/wiki/Release-package-verification)核对来源、摘要与所需签名；没有所需签名材料时，不作为正式发行包使用。

本版本仅接受具有已发布 `0.7.3` 完整 43 条迁移账本的 `0.7.3` / `0.7.5` 安装升级；升级脚本在 PostgreSQL 只读事务中核对结构和账本，不执行业务结构或数据修改，异常时拒绝继续。插件管理器更新管理端源码后，宿主仍需按其构建与部署流程激活前端。

第一次使用从[登记公司与应用](https://github.com/supdger/sand-iam/wiki/First-use)开始，再按目标开通员工账号或系统调用。后台新增用户身份不等于已经设置登录账号与密码；员工须接受邀请或按应用开放的注册方式开通账号。管理员完成配置后，由接入应用验证真实登录、允许访问和拒绝访问；未接入业务系统时可以完成后台配置，不能据此认定业务权限已经生效。

完整使用说明见 [SandIAM Wiki](https://github.com/supdger/sand-iam/wiki)：

- [包校验](https://github.com/supdger/sand-iam/wiki/Release-package-verification)、[安装与升级](https://github.com/supdger/sand-iam/wiki/Installation-and-upgrade)、[第一次使用](https://github.com/supdger/sand-iam/wiki/First-use)；
- [管理员](https://github.com/supdger/sand-iam/wiki/Administrator-guide)、[应用用户](https://github.com/supdger/sand-iam/wiki/Application-user-guide)、[业务接入](https://github.com/supdger/sand-iam/wiki/Application-integration)；
- [配置](https://github.com/supdger/sand-iam/wiki/Configuration-reference)、[安全](https://github.com/supdger/sand-iam/wiki/Security-hardening)、[备份恢复](https://github.com/supdger/sand-iam/wiki/Backup-and-restore)、[排障](https://github.com/supdger/sand-iam/wiki/Troubleshooting)。

使用 SandPackage 安装完整 ZIP，安装前备份；不要只复制插件子目录、创建数据库或手工重放生命周期 SQL。凭证和密钥只通过受控运行环境注入，所有业务授权须验证允许、拒绝、撤销和审计。

贡献见 [CONTRIBUTING.md](CONTRIBUTING.md)，安全问题按 [SECURITY.md](SECURITY.md) 私下报告，变化见 [CHANGELOG.md](CHANGELOG.md)。正式包的锁文件、生成范围与来源证明见 [release-build-contract.json](release-build-contract.json)。

项目按 [Apache-2.0](LICENSE) 分发，版权见 [NOTICE](NOTICE)，第三方许可见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
