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

基础报告需要通过真实导入与空 DOCX 创建检查。完整离线 Python、系统字体、原生 C 依赖漏洞审计及各平台真机验收仍在推进，未通过全量验收的平台不列为正式推荐入口。可选 PDF 渲染依赖、中文字体和 Poppler 影响相关格式和扫描件能力，`doctor` 显示当前解释器及各项导入结果。Web 运行时已替换为排除 Mercure 与 Vulcain 的最小自建构建，随包交付原始许可与对应源码/重链接套件（详见下文「从源码构建候选包」）。

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

构建机需要 Python 3.11+、Git、与生产锁匹配的 PHP/Composer、Node/npm。使用干净源码提交生成 `composer install --no-dev` 的生产 vendor、`npm ci && npm run build` 的 public/build，使用同一份 node_modules 运行 `python3 scripts/collect-licenses.py npm . "$NPM_LICENSES"` 收集前端依赖的声明，并以 `scripts/fetch-skills.py resources/skills/upstream-lock.json "$SKILLS"` 获取核验的上游资产。普通用户运行时直接使用包内文件。开发者仍可按 CONTRIBUTING.md 运行原有 Compose 和测试流程。

```sh
python3 scripts/build-local-package.py --source "$CHECKOUT" --platform macos-arm64 \
  --vendor "$PRODUCTION_VENDOR" --assets "$FRONTEND_BUILD" --npm-licenses "$NPM_LICENSES" --skills "$SKILLS" \
  --cli "$CLI_ARCHIVE" --cli-sha256 "$CLI_SHA256" \
  --web-runtime "$WEB_RUNTIME_ARCHIVE" --web-runtime-sha256 "$WEB_RUNTIME_SHA256" \
  --web-source-kit "$WEB_SOURCE_RELINK" --web-source-kit-sha256 "$WEB_SOURCE_RELINK_SHA256" \
  --cli-source-kit "$CLI_SOURCE_RELINK" \
  --cli-source-kit-sha256 "$CLI_SOURCE_RELINK_SHA256" --output "$NEW_OUTPUT_DIRECTORY"
```

构建机还需要 Go 来读取目标二进制的真实构建信息，无需在构建机执行其他架构的 Web。Web 归档必须含可执行的 `bin/frankenphp`、`build-manifest.json` 和 `notices/`。打包器检查目标架构、`nomercure,novulcain` 标签及实际链接模块，拒绝带 Mercure/Vulcain 的旧二进制；保留固定的 Brotli 插件以适配现有 Caddy 配置。这项检查不能替代逐项源码、许可、重链接和漏洞审计。

`build-manifest.json` 的顶层须有 `binary_sha256` 和 `source_kit: {"name": "对应源码包文件名", "sha256": "该包摘要"}`。先封存源码/重链接套件，再把它的摘要写进运行包清单，避免自指；打包器拒绝将同架构的其他套件与此二进制配对。

Web 的源提交、归档摘要、依赖锁和工具链版本见 `licenses/portable-web.json`。先按该平台的对应源码套件构建 PHP 8.5.13 **ZTS embed** 和静态库，设置套件中记录的 `CGO_CFLAGS`、`CGO_LDFLAGS` 与 `WEB_LINKER` 后，可用同一份 Go 入口：

```sh
sh scripts/runtime/build-web-go.sh "$FRANKENPHP_SOURCE" "$NEW_WEB_BINARY"
```

该入口核对上游四个依赖锁文件，使用未经修改的 `caddy/frankenphp` 源入口和固定 Go 1.27.4。Linux musl 的平台链接器须加入套件中记录的 static-pie 参数；最终检查解释器段和动态库依赖。现有 NTS CLI 继续独立用于队列和调度，不能拿它的对象替代 Web ZTS 材料。各平台套件须同时提供原始 C/Go 源、实际补丁和配置、原始声明、对象/静态库、链接命令与映射，以及在新目录替换 LGPL 库后实际重链接和运行的证明。

Go 模块根目录的 LICENSE 还须配合实际编译源内的独立声明。按相同平台、构建标签和环境保存 `go list -deps -json ./frankenphp` 输出，再运行 `python3 scripts/runtime/collect-web-go-notices.py "$ACTIVE_GO_GRAPH" "$SOURCE_NOTICES" "$SOURCE_NOTICE_INDEX"`。它完整读取实际 Go、Cgo、原生和汇编源，保留法律注释及逐源摘要；把结果与模块、标准库的原始声明一同交付，核对实际源归档和最终二进制。输入缺文件或不能无损读取时会报错。

若相应平台 CLI 归档使用系统 CA，构建机还要传入 `--ca`、`--ca-sha256` 和 `--ca-notice` 指定已核验的可信证书包与许可。发行目录会并列保留该平台的 CLI 和 Web 对应源码、构建资料与可替换对象包。程序内 release.json 和同目录 SHA256SUMS 均列出其名称及摘要；Web 许可和构建清单同时进入应用包的 `runtime/web/`。维护者须核对最终归档、对应源码和重链接材料、Web/Python/其他第三方许可、依赖漏洞和签名；跟踪仓库只保存构建及管理源码。
