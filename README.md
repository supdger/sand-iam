# SandIAM

SandIAM 是 SandAdmin 的身份与访问管理插件。管理员用它管理应用用户、角色、权限、服务调用凭证和审计；接入开发者将登录和授权能力接入自己的业务系统。

## 版本更新

[Wiki 版本更新](https://github.com/supdger/sand-iam/wiki/Changelog)直接说明近期功能变化、修复和升级影响；[完整更新日志](CHANGELOG.md)保留逐版记录。下载与发布状态以[最新正式版](https://github.com/supdger/sand-iam/releases/latest)为准。

## 源码与安装包

直接使用请下载 Release 附件中的完整 `sand-iam-<版本>.zip`，或在 SandAdmin 的“插件仓库”选择 SandIAM。GitHub 的 `Source code (zip)` 是源码压缩包，不是插件安装包。

本仓包含插件后端、管理端源码和安装生命周期。开发与构建见 [开发指南](https://github.com/supdger/sand-iam/wiki/Development)与[构建校验](https://github.com/supdger/sand-iam/wiki/Build-tools)。详细操作指南统一维护在 Wiki；本次源码整改后的后续构建不再复制整套指南，已发布的 0.8.3 安装 ZIP 保留原载荷。离线操作前取得匹配版本指南。

## 安装与第一次使用

需要已有的 SandAdmin `>=0.1.0` PostgreSQL 宿主、SandPackage，以及有权安装插件和管理应用的账号。宿主兼容范围、运行环境和包校验要求见[安装与升级](https://github.com/supdger/sand-iam/wiki/Installation-and-upgrade)；安装或升级前备份已有数据。

1. 在 SandAdmin“插件仓库”选择 SandIAM，或上传完整安装 ZIP，核对安装计划后安装。
2. 按安装指南完成配置，并由宿主管理员构建、激活包内管理端源码。安装器报告成功后，重新登录并打开 **SandIAM → 总览**。
3. 在“这次要完成什么？”中选择目标，例如“让用户登录应用”。复用已有应用，按页面引导完成登录配置与账号开通，再到该应用的登录入口验证。

第一次登录的可确认结果是：正确密码能登录，错误密码被拒绝，退出后旧会话不能继续访问。缺少菜单或办理权限时联系宿主管理员；详细步骤见[第一次使用](https://github.com/supdger/sand-iam/wiki/First-use)。

## 使用文档

- [Wiki 首页](https://github.com/supdger/sand-iam/wiki)：按管理员、应用用户和接入开发者的任务选择指南。
- [管理员操作](https://github.com/supdger/sand-iam/wiki/Administrator-guide)、[应用用户操作](https://github.com/supdger/sand-iam/wiki/Application-user-guide)：管理应用与使用自己的账号。
- [业务接入](https://github.com/supdger/sand-iam/wiki/Application-integration)：接入登录、授权与数据范围。
- [配置](https://github.com/supdger/sand-iam/wiki/Configuration-reference)、[备份恢复](https://github.com/supdger/sand-iam/wiki/Backup-and-restore)、[排障](https://github.com/supdger/sand-iam/wiki/Troubleshooting)：部署与维护。

## 许可与反馈

项目按 [Apache-2.0](LICENSE) 分发，版权与第三方许可见 [NOTICE](NOTICE) 和 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。贡献见 [CONTRIBUTING.md](CONTRIBUTING.md)，安全问题按 [SECURITY.md](SECURITY.md) 私下报告；问题与建议请提交到 [Issues](https://github.com/supdger/sand-iam/issues)。
