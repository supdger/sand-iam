# SandIAM

SandIAM 是面向 SandAdmin 6.x 的 PostgreSQL 身份与访问管理插件，提供客户主体、应用、环境、身份、角色、策略、服务授权和访问审计能力。

## 功能

- 客户主体、应用和运行环境管理
- 用户、用户组、角色、资源和数据范围
- 注册、登录、会话、MFA 和 Passkey
- 机器身份、服务授权、凭证轮换和调用审计
- OAuth 2.0、OpenID Connect、SCIM、CAS、LDAP 和 RADIUS
- 管理后台和应用用户自助页面

SandIAM 只负责通用身份、授权和审计。业务数据、业务状态和业务审批规则仍由接入应用负责。

## 环境要求

- SandAdmin 6.x
- PHP 8.2 或更高版本
- PostgreSQL
- PHP 扩展要求见 `plugin/sand-iam/composer.json`

当前版本以 `info.ini` 为准。

## 安装

1. 下载与目标版本对应的完整插件包。
2. 在 SandAdmin 插件管理中上传并安装，不要只复制 `plugin/sand-iam/`。
3. 按需配置插件运行参数。
4. 重新登录后台，为管理员角色授予 SandIAM 菜单和按钮权限。
5. 依次创建客户主体、应用、环境和所需身份或服务授权。

安装前请备份数据库。升级使用同一版本包内的 `update.sql`，卸载会执行 `uninstall.sql`。

## 基本使用顺序

```text
客户主体
  → 接入应用
  → 应用环境
  → 用户或服务调用身份
  → 角色、策略或服务授权
  → 登录或签发调用上下文
  → 查看访问审计
```

凭证明文只显示一次，应保存到业务系统的安全配置或密钥管理服务中，不要写入源码、日志或截图。

## 目录

```text
plugin/sand-iam/                           后端插件
sandadmin-artd/src/views/plugin/sand-iam/ 管理端页面
info.ini                                   插件信息
config.json                                依赖配置
install.sql                                安装脚本
update.sql                                 升级脚本
uninstall.sql                              卸载脚本
```

## 许可证

SandIAM 使用 [Apache License 2.0](LICENSE)。第三方依赖及其许可证见 [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md)。
