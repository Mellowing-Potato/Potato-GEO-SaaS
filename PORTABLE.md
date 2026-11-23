# 本机运行言及

本机候选包使用同一套 Laravel 应用、生产 Composer 依赖、已构建网页和锁定技能。程序和数据在独立目录。正式下载与签名发行仍待逐平台验收。现阶段需要维护者提供对应平台的完整包、安装脚本、SHA256SUMS 清单和通过独立可信渠道取得的清单摘要。

## 安装

macOS arm64 和 Linux aarch64、x86_64 有构建入口。平台的实际验证层级见本版发行记录。Windows x64 入口可用 `powershell -File scripts/install-local.ps1 -Diagnosis` 查看阻碍。Intel Mac 等待匹配的 Web 产物与实机验收。

从源码检出使用 `scripts/install-local.sh`，或在已校验的发行材料目录使用 `install-local.sh`。下面的环境变量分别指向发行清单、可信摘要和装有 python-docx、Markdown、beautifulsoup4 的本机解释器。安装器可以读取本地清单或 HTTPS 地址，按 CPU 选择同目录安装包。离线包放在其他位置时可增加 `--package /path/to/package.tar.gz`。

```sh
sh scripts/install-local.sh --manifest "$MANIFEST" --manifest-sha256 "$MANIFEST_SHA256" --python-bin "$PYTHON"
```

在交互终端中输入首位管理员邮箱和密码。无人值守可另传 `--email admin@example.com --password-file "$PRIVATE_PASSWORD_FILE"`，密码文件由当前用户拥有，权限设为 0600，只含一行。首次安装会等待 Web、worker 和调度心跳，调度心跳最多要等到下一分钟。终端会显示浏览器地址和该实例的管理命令。

默认安装在 macOS 的 `~/Library/Application Support/Yanji` 或 Linux 的 `${XDG_DATA_HOME:-$HOME/.local/share}/yanji`，下分 `program` 和 `instance`。可分别用 `--program-home`、`--instance` 指定本地绝对路径，用 `--port` 另选回环端口。多个实例可共享同一程序目录，每个实例须使用各自的数据目录和端口。

基础报告需要通过真实导入与空 DOCX 创建检查。完整离线 Python 和系统字体仍须在对应架构验收。可选 PDF 渲染依赖、中文字体和 Poppler 影响相关格式和扫描件能力，`doctor` 显示当前解释器及各项导入结果。阶段 1 的现成 Web 产物含额外的第三方模块，公开随包分发前须完成 Web 许可交付或换成已验收的最小构建。

## 日常操作

下例设置 `YANJI` 为安装成功时显示的实例管理程序绝对路径。Web 仅监听本机回环地址。三个服务由同一用户的后台管理进程托管。

```sh
"$YANJI" start
"$YANJI" status
"$YANJI" logs
"$YANJI" doctor
"$YANJI" stop
"$YANJI" restart
"$YANJI" reset-password --email admin@example.com
```

`logs` 显示近期服务生命周期记录。实例包含原始 `.env`、加密凭据和私有报告，应按私有数据保护。正常停止会等待正在执行的作业，最长 570 秒。遇到作业硬超时，管理器先确认旧进程退出，再核对该笔数据库预约，记录失败并派继任者。已发送模型请求的结算需要按工作台中的实际状态核对。

```sh
"$YANJI" backup --output "$NEW_BACKUP_FILE"
"$YANJI" restore --from "$NEW_BACKUP_FILE" --instance "$NEW_INSTANCE_DIR" --port 8102 --python-bin "$PYTHON"
"$YANJI" upgrade --manifest "$NEW_MANIFEST" --manifest-sha256 "$NEW_MANIFEST_SHA256"
```

备份目标须为实例外的新文件。恢复目标须为空或尚未创建。恢复保留原管理员、加密密钥、持久化内容和私有报告。升级先验证清单与程序包，然后暂停服务、备份和迁移。有效的升级门只允许健康检查与真实后台心跳。失败时保留恢复备份，尚未开放写入时回滚旧程序和数据。若管理器报告可能已有写入，应保留故障现场并核对备份后恢复到新目录。

`"$YANJI" magpie --tenant=ID` 进入该工作区的本机 Magpie 授权入口。安装、诊断与启停不会探测模型或调用推理。

## 从源码构建候选包

构建机需要 Python 3.11+、Git、与生产锁匹配的 PHP/Composer、Node/npm。使用干净源码提交生成 `composer install --no-dev` 的生产 vendor、`npm ci && npm run build` 的 public/build，并以 `scripts/fetch-skills.py resources/skills/upstream-lock.json "$SKILLS"` 获取核验的上游资产。普通用户运行时直接使用包内文件。开发者仍可按 CONTRIBUTING.md 运行原有 Compose 和测试流程。

```sh
python3 scripts/build-local-package.py --source "$CHECKOUT" --platform macos-arm64 \
  --vendor "$PRODUCTION_VENDOR" --assets "$FRONTEND_BUILD" --skills "$SKILLS" \
  --cli "$CLI_ARCHIVE" --cli-sha256 "$CLI_SHA256" --web "$WEB_BINARY" \
  --web-sha256 "$WEB_SHA256" --cli-source-kit "$CLI_SOURCE_RELINK" \
  --cli-source-kit-sha256 "$CLI_SOURCE_RELINK_SHA256" --output "$NEW_OUTPUT_DIRECTORY"
```

若相应平台 CLI 归档使用系统 CA，构建机还要传入 `--ca`、`--ca-sha256` 和 `--ca-notice` 指定已核验的可信证书包与许可。发行目录会保留该平台原名的 CLI 对应源码、构建资料与可替换对象包。程序内 release.json 和同目录 SHA256SUMS 均列出其名称及摘要。维护者须核对最终归档、对应源码和重链接材料、Web/Python/其他第三方许可、依赖漏洞和签名；跟踪仓库只保存构建及管理源码。
