# SandIAM 发布与校验工具

本独立仓库从已提交且干净的 Git 源码构建可安装 ZIP。工具不连接数据库、不启动服务、不安装或上传。

先在仓库根执行声明、生命周期、依赖与载荷校验：

```sh
php tools/check-existing-schema.php
php tools/build-lifecycle.php --check
php tools/check-package-integrity.php
php tools/check-release-payload.php
```

提交本次差异并确认工作区干净后，指定一个尚不存在、仓库外的绝对输出目录：

```sh
php tools/build-independent-release.php /absolute/new-artifact-directory
```

成功输出 `primary/sand-iam-0.7.6.zip`、`manifest.json` 和 `SHA256SUMS`。构建器从同一 commit 的 Git blob 创建两套独立载荷，逐条目与 ZIP 摘要必须一致。失败时保留输出供检查；修复源码后使用新的输出目录重试。

`release-build-contract.json` 固定 vendor、SDK、锁文件字节与原依赖构建工具链。默认校验只证明冻结字节一致；`check-package-integrity.php --reproduce-toolchain` 另要求原工具链，不表示工具已经重新安装或构建依赖。`build-lifecycle.php --profile=legacy-0.7.3 --output=/absolute/new-directory` 仅供历史嵌入版 42 条账本比对；默认 `--check` 核对当前 0.7.6 SQL 而不写入源码。源码替换必须明确指定 `--write`，它仍不执行数据库 SQL。

本次产物为无签名的集成测试预发布，不能据此声明生产可用。接入旧表需使用支持 `existing-schema.json` 的 SandPackage 工件，并先核查实际账本；42 条账本不能直接接入 0.7.6。详细步骤见包内安装说明与对应 Release。
