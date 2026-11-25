# 本机运行言及（免 Docker 便携版）

> **v0.1.1 安装文件同版本修复重发（2026-11-25）**：修复 macOS UTF-8 Shell 的远程下载变量边界；下载地址仍为 v0.1.1，使用下方标准 `curl ... | sh` 命令。旧版安装在账号提示前中断、尚未生成实例密钥且默认数据库为空时，安装器会检查原配置与初始化/绑定状态，符合条件后接续本机免账号初始化；已有账号和数据保留。 本次替换安装文件、清单及对应引导脚本，并将发布标签对齐修复源码；请使用新摘要，首次发行的旧摘要不适用于重发资产。v0.1.0 资产保持不变。本次完整 CI：10 项发行任务全部成功，覆盖双架构测试/审计、四平台便携构建、Docker 镜像发布、资产发布和两场 Mac 公开入口验收（[本次修复重发流 #45594562303](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303)）。本次 Mac 标准公开入口分别验收：fresh 全新安装：通过；使用公开标准 `curl ... | sh`、默认目录和 8000 端口，服务就绪，已核对免账号 Cookie、工作区受保护 API 及拒绝匿名/无绑定令牌（[任务日志](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303/job/139153283994)）；interrupted 中断安装：通过；使用公开标准 `curl ... | sh`、默认目录和 8000 端口，服务就绪，已核对免账号 Cookie、工作区受保护 API 及拒绝匿名/无绑定令牌（[任务日志](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303/job/139153284005)）。入口证据仅含默认目录和端口、标准命令、工作台 Cookie 与受保护 API；不扩大为完整 GUI 或 Magpie 真实推理验收。 v0.1.1 首次发行的八项任务成功和本地包验收属于历史记录，没有覆盖当时读者经生成引导脚本走 HTTPS 下载的入口，不能当作该入口成功证据。

免 Docker 便携包使用与 Docker 部署相同的 Laravel 应用、生产 Composer 依赖、已构建网页与锁定技能资产，并随包内置独立 **Python 3.12.16** 与开源报告字体（DejaVu Sans、Noto Serif CJK），普通用户无需预先安装 Docker、PHP 或 Python。程序与实例数据分离存放；安装完成时由本机启动器在系统浏览器打开工作台，日常使用 `yanji open` 再次打开。直接在新的浏览器会话或智能体内置预览中输入本地地址，不会自动获得访问会话。支持第三方模型直连与可选本机 [Magpie](https://github.com/yetone/magpie)。

智能体安装采用相同的安装器和本机打开方式，交付要求见 [通用智能体安装协议](docs/INSTALL-AGENT.md)。其中新增的稳定双击入口、`entry` 和 `onboarding` 属于未发布候选，不能按本页正式版下载命令声称已获得这些新增能力；候选真实 GUI 验收仍待完成。

> `v0.1.1` 已正式发布，统一 `SHA256SUMS`（`51e92778fce0c1c2caae9cdf72c650b055623e8c57f1897dddc34ded18b8230a`）、平台便携安装包、对应源码/重链接材料及固定摘要引导脚本见 [GitHub Release v0.1.1](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/tag/v0.1.1)。

> **v0.1.1 本机单人模式**：全新便携实例无需邮箱、密码或手动登录，由本机启动器授权打开工作台；`yanji open` 可再次打开。保留独立工作区、正常会话鉴权和仅本机回环访问。已有账号实例或显式账号模式保留原登录方式，不覆盖账号与数据。维护者已确认 Apple Silicon Mac 候选安装、更新和免登录体验；v0.1.1 首次发行的历史证据：8 个任务全部通过：双架构完整测试与审计、四平台组包和原生验收、双架构镜像及 17 项发行资产上传（[发行流 #45572085735](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45572085735)）。

> **v0.1.1 模型接入**：在「设置 → 模型配置」填写自己的模型 Key，或在本机单人模式点击「连接本机 Magpie」，检测已启动的服务并读取当前用途的模型目录。无需查工作区 ID 或执行授权命令。维护者已确认 Mac 候选服务检测和目录读取；当前来源截图仍为第三方直连，模型激活与真实推理未跑。使用者仍须选择、保存并明确启用模型。

## 支持平台与当前验证状态

下表及首次 v0.1.1 发行流均为历史验收，不作为旧 Mac 远程入口已通过的证据；本次同版本修复重发 CI：10 项发行任务全部成功，覆盖双架构测试/审计、四平台便携构建、Docker 镜像发布、资产发布和两场 Mac 公开入口验收（[本次修复重发流 #45594562303](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303)）。四平台完整 GUI、Windows 桌面与 Linux amd64 Docker 手工业务流程仍待验收。

| 平台 | 运行时与随包资产 | 当前验证状态 |
| --- | --- | --- |
| **macOS Apple Silicon (`macos-arm64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **本地实机与 `macos-15` 发布流均已通过**：无宿主机 Python 冷安装、`yanji` 帮助与 `doctor`、私有静态 404 护栏、501 作业轮换、2 秒硬超时接替、热停排水、备份恢复、同实例重装/共享缓存复用、缓存与清单编码篡改拒绝、11 技能渲染、真实浏览器登录/工作台/`#aikeys`/建项端到端 |
| **Windows x64 (`windows-x86_64`)** | 自建无 Mercure/Vulcain `frankenphp.exe`（Brotli 1.2.1）+ PHP 8.5.13 ZTS + 随包 Python 3.12.16 + 开源字体 | **原生 `windows-2025` 发布流完整通过**：全套构建、11 技能渲染矩阵（仅随包 Python 与字体）、中文空格路径冷安装（`install-local.ps1`）、`yanji.cmd` 帮助与 `doctor`、私有静态 404 与公开联接对照、501 作业轮换、2 秒硬超时接替、热停排水、备份恢复、同实例重装/共享缓存复用与篡改拒绝（本机桌面浏览器手工点击与真实付费模型调用未在此阶段实测） |
| **Linux arm64 (`linux-aarch64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **原生架构 VM 与 `ubuntu-24.04-arm` 发布流完整通过**：11 技能渲染矩阵、无宿主机 Python 冷安装、404、501 作业轮换、超时接替、热停排水、备份恢复、重装/缓存复用与篡改拒绝 |
| **Linux x64 (`linux-x86_64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **原生 `ubuntu-24.04` 发布流完整通过**：11 技能渲染矩阵、无宿主机 Python 冷安装、404、501 作业轮换、超时接替、热停排水、备份恢复、重装/缓存复用与篡改拒绝 |
| **Intel Mac (`macos-x64`)** | 不纳入首批发布范围 | 未构建、未验证（请使用 Docker 部署） |

> 同步说明：14 项底层 C 依赖已做公开 CVE 核对；Brotli 已在 `licenses/portable-web.json` 与 Windows Web 构建中升级至 `v1.2.1`；SQLite 3.45.2 之后的相关 CVE 经源码路径核对在言及固定表结构与查询路径中不可达。发布物采用免费 `SHA256SUMS` 校验与固定摘要引导脚本，未购买 Apple Developer ID 公证或 Windows EV 代码签名。

## 安装

### 方式一：正式发布的一行安装（推荐）

以下命令安装已发布的 `v0.1.1`。全新便携实例默认免邮箱密码，已有账号实例继续使用原登录方式。

**macOS（Apple Silicon）与 Linux：**

```sh
curl -fsSL "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.sh" | sh
```

**Windows 10 / 11 x64（PowerShell 5.1 或 PowerShell 7）：**

```powershell
irm "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.ps1" | iex
```

引导脚本固定对应发布版本的 `SHA256SUMS` 与安装入口摘要，仅允许 HTTPS（含重定向），并逐文件核验包内 `PACKAGE-FILES.sha256`。安装依次显示下载、校验、初始化和服务启动进度；首次服务就绪可能等待约一分钟。

### 方式二：离线或可审计两行安装（维护者候选包 / 线下分发）

当你已通过可信渠道取得对应平台的发行目录（包含 `SHA256SUMS`、安装包与安装脚本）及清单摘要 `MANIFEST_SHA256` 时：

**macOS / Linux：**

```sh
sh install-local.sh --manifest "$MANIFEST" --manifest-sha256 "$MANIFEST_SHA256"
```

**Windows（PowerShell）：**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\install-local.ps1 `
  -Manifest "$MANIFEST" -ManifestSha256 "$MANIFEST_SHA256"
```

- 随包已内置 Python 3.12.16 与开源字体，**默认无需传 `--python-bin` / `-PythonBin`**；仅在需要覆写为自定义解释器时才传该参数。
- 离线安装包位于其他目录时，可附加 `--package /path/to/package.tar.gz`（Windows 为 `-Package C:\path\to\yanji-windows-x86_64.zip`）。
- `v0.1.1` 全新便携实例默认不询问账号；已有账号实例及显式账号模式保留管理员登录。显式账号模式可传 `--email admin@example.com --password-file "$PRIVATE_PASSWORD_FILE"`（Windows 为 `-Email` 与 `-PasswordFile`），密码文件须为当前用户私有的单行普通文件。
- `v0.1.1` 的自动化安装可附加 `--no-browser`（PowerShell 安装器为 `-NoBrowser`），只跳过打开浏览器，安装、服务启动和访问保护照常执行；交互安装默认自动打开工作台。
- 首次启动会等待 Web、worker 与调度器心跳全部就绪（调度器心跳按分钟边界触发，最多等待约 60 秒）。完成后终端会打印本机访问地址（`http://127.0.0.1:<端口>`）与该实例的管理命令路径。

默认安装目录：
- **macOS**：`~/Library/Application Support/Yanji`（下分 `program` 与 `instance`）
- **Linux**：`${XDG_DATA_HOME:-$HOME/.local/share}/yanji`
- **Windows**：`%LOCALAPPDATA%\Yanji`

可用 `--program-home` / `--instance` / `--port`（Windows 为 `-ProgramHome` / `-Instance` / `-Port`）指定独立目录和回环端口；多个实例可共享同一 `program` 目录，但必须使用各自独立的 `instance` 目录和端口。

### macOS 手动通过浏览器下载的说明

通过终端 `curl` 下载的文件不会附加隔离属性。仅当你手动在 Safari / Chrome 等浏览器中下载 `.tar.gz` 并解压，且 macOS Gatekeeper 拦截未签名二进制时，才需对下载或解压目录执行一次：

```sh
xattr -dr com.apple.quarantine /path/to/yanji-dir
```

### 报告渲染与可选扩展能力

随包 Python 3.12.16 与开源字体开箱支持全部 11 项 GEO 技能的 Markdown / HTML / DOCX 渲染（含 `explainer-builder` 的 WOFF2 字体子集化，以及 `content-refiner` 与 `title-optimizer` 基于 ReportLab 的 PDF 导出）。其余技能的可选 WeasyPrint PDF 渲染与扫描件 PDF 文本提取（Poppler `pdftotext`）在缺少对应系统库时会自动降级并明确提示，不影响核心工作台与 DOCX/HTML/MD 交付；`doctor` 命令会列出当前解释器与各项探测结果。

## 日常操作

将下例中的 `YANJI` 设为安装完成时打印的实例管理程序路径（macOS/Linux 为 `<instance>/yanji`，Windows 为 `<instance>\yanji.cmd`）。Web 仅监听本机回环地址 `127.0.0.1`，三个服务由当前用户的后台管理器统一托管。直接运行不带参数的 `yanji` / `yanji.cmd` 会输出帮助并以 0 退出。

**macOS / Linux：**

```sh
"$YANJI" start
"$YANJI" open  # v0.1.1 起可用：在浏览器打开工作台
"$YANJI" status
"$YANJI" logs
"$YANJI" doctor
"$YANJI" stop
"$YANJI" restart
"$YANJI" reset-password --email admin@example.com  # 仅传统账号模式；本机单人模式使用 open
```

**Windows（PowerShell）：**

```powershell
& "$YANJI" start
& "$YANJI" open  # v0.1.1 起可用：在浏览器打开工作台
& "$YANJI" status
& "$YANJI" logs
& "$YANJI" doctor
& "$YANJI" stop
& "$YANJI" restart
& "$YANJI" reset-password --email admin@example.com  # 仅传统账号模式；本机单人模式使用 open
```

`v0.1.1` 的 `yanji open` 通过当前系统用户私有的本地启动文件向浏览器交接短时授权；系统浏览器打开命令只接收本地文件地址，授权票据不写入安装日志或进程参数。无人值守运行可使用 `"$YANJI" start --no-browser`（Windows 管理命令为 `& "$YANJI" start --no-browser`），访问鉴权仍然保留。

`v0.1.1` 同时按公共 JS/CSS 的文件内容摘要更新浏览器缓存版本，修复便携包保留相同文件时间或替换同大小文件后仍加载旧界面的问题。私有启动与缓存回归自动化通过不代替各平台原生浏览器验收。

`logs` 显示近期服务生命周期记录。实例目录包含 `.env`、加密模型凭据、SQLite 数据库与私有报告，按当前用户私有权限保护。正常停止会等待在途短作业自然结束（最长 570 秒）；遇到作业硬超时，管理器先终止超时进程树并确认退出，再在事务内核对数据库保留记录、落账失败并拉起继任 worker。已发出但结果未知的模型请求不会自动重发，请按工作台状态核对。

### 备份、恢复、升级与可选 Magpie 授权

```sh
# macOS / Linux（Windows 将 "$YANJI" 替换为 & "$YANJI"）
"$YANJI" backup --output "$NEW_BACKUP_FILE"
"$YANJI" restore --from "$NEW_BACKUP_FILE" --instance "$NEW_INSTANCE_DIR" --port 8102
"$YANJI" upgrade --manifest "$NEW_MANIFEST" --manifest-sha256 "$NEW_MANIFEST_SHA256"
"$YANJI" magpie --tenant=ID
```

- 备份目标须为实例目录外的新文件；恢复目标须为空目录或尚不存在的路径，恢复后保留原管理员、加密密钥、持久化数据与私有报告。
- 升级先核验清单与候选包，随后加维护门暂停写入、备份原实例、切换并执行迁移；维护门期间仅放行健康检查与真实后台心跳，失败时保留恢复备份并在未开放写入前自动回滚。
- `v0.1.1` 的本机单人模式可在网页点击「连接本机 Magpie」；`magpie --tenant=ID` 保留用于传统账号模式或部署者授权。安装、启停与 `doctor` 不会主动探测 Magpie 或调用推理。

## 从源码构建候选包与合并发布目录

构建机需要 Python 3.11+、Git、Go 1.27.4、Node 22/npm，以及与 `composer.lock` 匹配的 PHP/Composer。开发者原有 Docker Compose 与测试流程（见 [CONTRIBUTING.md](CONTRIBUTING.md) 与 [INSTALL.md](INSTALL.md)）保持不变。

### 1. 构建平台运行时与随包 Python / 字体

- **随包 Python 3.12.16 与开源字体**（四平台统一入口，依据 `licenses/portable-python.json` 固定摘要）：
  ```sh
  python3 scripts/runtime/build-portable-python.py --mode python --platform macos-arm64 --output "$PY_OUT" --cache-dir "$PY_CACHE"
  python3 scripts/runtime/build-portable-python.py --mode fonts --output "$PY_OUT" --cache-dir "$PY_CACHE"
  ```
- **Windows x64 无 Mercure/Vulcain FrankenPHP + PHP 8.5.13 ZTS 运行时**（含 Brotli `v1.2.1`、Go 活跃编译图许可采集与对应源码/重链接包）：
  ```powershell
  .\scripts\runtime\build-web-windows.ps1 -OutputDir D:\yanji-build\web-out -WorkDir D:\yanji-build\web-work
  ```
- **macOS / Linux Web 运行时**：源提交、依赖锁与工具链版本见 `licenses/portable-web.json`。构建 PHP 8.5.13 ZTS embed 与静态库并配置对应 `CGO_CFLAGS` / `CGO_LDFLAGS` / `WEB_LINKER` 后，使用 `sh scripts/runtime/build-web-go.sh "$FRANKENPHP_SOURCE" "$NEW_WEB_BINARY"` 与 `python3 scripts/runtime/collect-web-go-notices.py` 采集活跃 Go 依赖声明；NTS CLI 继续独立服务队列与调度器。

### 2. 组装单平台候选包

在干净源码目录构建生产前端（`npm ci --ignore-scripts && npm run build`）、收集前端许可（`scripts/collect-licenses.py npm`）、拉取锁定技能资产（`scripts/fetch-skills.py`）和生产 `vendor`（`composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader`）后：

```sh
python3 scripts/build-local-package.py --source "$SOURCE_TREE" --platform macos-arm64 \
  --vendor "$PRODUCTION_VENDOR" --assets "$FRONTEND_BUILD" --npm-licenses "$NPM_LICENSES" --skills "$SKILLS" \
  --cli "$CLI_ARCHIVE" --cli-sha256 "$CLI_SHA256" \
  --cli-source-kit "$CLI_SOURCE_RELINK" --cli-source-kit-sha256 "$CLI_SOURCE_RELINK_SHA256" \
  --web-runtime "$WEB_RUNTIME_ARCHIVE" --web-runtime-sha256 "$WEB_RUNTIME_SHA256" \
  --web-source-kit "$WEB_SOURCE_RELINK" --web-source-kit-sha256 "$WEB_SOURCE_RELINK_SHA256" \
  --python-runtime "$PY_ARCHIVE" --python-runtime-sha256 "$PY_SHA256" \
  --fonts "$FONTS_ARCHIVE" --fonts-sha256 "$FONTS_SHA256" \
  --output "$CANDIDATE_DIR"
```

打包器通过 `go version -m` 校验目标二进制的真实架构、`nomercure,novulcain` 标签和链接模块，核验 `build-manifest.json` 与对应源码包绑定、Python 平台与许可声明，并写入 `PACKAGE-FILES.sha256`、`release.json` 和 `SHA256SUMS`。若平台 CLI 归档不含 CA 证书包，另传 `--ca`、`--ca-sha256` 和 `--ca-notice`。

### 3. 合并多平台候选目录与生成引导脚本

合并一个或多个已验收的平台候选目录，生成统一 `SHA256SUMS` 与固定摘要的一行引导脚本（`get-yanji.sh` / `get-yanji.ps1`）：

```sh
python3 scripts/runtime/merge-release-dir.py \
  --input "$CANDIDATE_MACOS_ARM64" \
  --input "$CANDIDATE_WINDOWS_X64" \
  --base-url "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/<TAG>" \
  --output "$UNIFIED_RELEASE_DIR"
```

推送 `v*` 标签后，`.github/workflows/release.yml` 会在全量 `Tests` 通过后并行发布多架构 GHCR Docker 镜像、原生构建并验收 Windows x64 便携包（若仓库变量已配置已验证的 Unix 运行时清单 `YANJI_UNIX_RUNTIME_MANIFEST_URL` / `YANJI_UNIX_RUNTIME_MANIFEST_SHA256`，同时在原生 `macos-15` / `ubuntu-24.04-arm` / `ubuntu-24.04` 组装并验收对应 Unix 便携包），最终自动合并统一 `SHA256SUMS`、生成引导脚本并上传至 GitHub Release。
