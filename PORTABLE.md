# 本机运行言及（免 Docker 便携版）

> **v0.1.3 正式资产已发布（跨平台无感升级、智能体保活与视觉改版）。** 下方在线命令、仓库根目录一键入口（`install.cmd` / `install.ps1` / `install.sh`）与本地清单摘要均对应已发布的 `v0.1.3`（`SHA256SUMS` 摘要 `eddbb3fc6961ae6660b92e326ad303bbe769c5a8f5afa3fb8bf2816c3d0e5fc5`，构建源 `a9d84db7ca30c3201bc9dd872d9b70f551972866`）：在 `v0.1.2` 本机浏览器直开 `http://127.0.0.1:8000` 与纯 7-bit ASCII 引导基础上，支持已有旧版实例重复运行同一安装命令自动停写备份并跨版本平滑升级（失败自动回滚）、macOS/Linux 双 `fork` 关闭继承管道 FD 与 Windows 三级脱离宿主 Job Object 保活后台服务，以及素白与深松视觉、首次上手 3 步引导与分析概览升级。公开标准命令验收与真实 GUI 验收分别记录。

> **2026-11-25 v0.1.1 安装文件修复重发（历史）**：修复 macOS UTF-8 Shell 的远程下载变量边界；旧版安装在账号提示前中断、尚未生成实例密钥且默认数据库为空时，安装器会检查原配置与初始化/绑定状态，符合条件后接续本机免账号初始化；已有账号和数据保留。v0.1.0、v0.1.1 与 v0.1.2 历史资产保持不变。该次完整 CI：10 项发行任务全部成功，覆盖双架构测试/审计、四平台便携构建、Docker 镜像发布、资产发布和两场 Mac 公开入口验收（[该次修复重发流 #45594562303](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303)，[fresh 日志](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303/job/139153283994)、[interrupted 日志](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45594562303/job/139153284005)）。

免 Docker 便携包使用与 Docker 部署相同的 Laravel 应用、生产 Composer 依赖、已构建网页与锁定技能资产，并随包内置独立 **Python 3.12.16** 与开源报告字体（DejaVu Sans、Noto Serif CJK），普通用户无需预先安装 Docker、PHP 或 Python。程序与实例数据分离存放；安装完成时自动在系统浏览器打开工作台，日常可直接在本机浏览器访问 `http://127.0.0.1:<端口>`（若开启本地代理请将 `127.0.0.1` 设为直连绕过代理），也可双击桌面“言及工作台”入口或使用 `yanji open` 自动拉起已停服务并打开。支持第三方模型直连与可选本机 [Magpie](https://github.com/yetone/magpie)。

普通使用者和智能体采用相同的标准安装入口和本机打开方式，交付要求见 [通用智能体安装协议](docs/INSTALL-AGENT.md)。`v0.1.3` 已包含跨版本同入口自动备份升级、后台托管器脱离保活、本机 `127.0.0.1` 同源免票据直连、仓库根目录一键入口、稳定本机入口、`entry` 和 `onboarding`。公开标准安装入口已验证，真实 GUI 和特定智能体产品的实际交付仍未跑。

> 本轮 `v0.1.3` 正式资产、统一 `SHA256SUMS`（`eddbb3fc6961ae6660b92e326ad303bbe769c5a8f5afa3fb8bf2816c3d0e5fc5`）、平台便携安装包、对应源码/重链接材料及固定摘要引导脚本见 [GitHub Release v0.1.3](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/tag/v0.1.3)。构建源为 `a9d84db7ca30c3201bc9dd872d9b70f551972866`。

> **v0.1.2+ 本机单人模式**：全新便携实例无需邮箱、密码或手动登录，服务运行期间在本机任意浏览器访问 `http://127.0.0.1:<端口>` 即可通过同源回环校验建立或复用唯一本机工作区会话；双击桌面入口或运行 `yanji open` 可自动启动服务并打开浏览器。保留独立工作区、Cookie+Token 正常会话鉴权、防跨站校验、拒绝外部反代转发和仅本机回环访问。已有账号实例或显式账号模式保留原登录方式，不覆盖账号与数据。维护者已确认 Apple Silicon Mac 候选安装、更新和免登录体验；v0.1.1 首次发行的历史证据见 [发行流 #45572085735](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45572085735)（8 个任务全部通过）。

> **模型接入**：在「设置 → 模型配置」填写自己的模型 Key，或在本机单人模式点击「连接本机 Magpie」，检测已启动的服务并读取当前用途的模型目录。无需查工作区 ID 或执行授权命令。维护者已确认 Mac 候选服务检测和目录读取；当前来源截图仍为第三方直连，模型激活与真实推理未跑。使用者仍须选择、保存并明确启用模型。

## 支持平台与当前验证状态

下表及历史 v0.1.0 / v0.1.1 / v0.1.2 发行流保留历史验收记录；四平台完整 GUI、Windows 桌面手工点击与 Linux amd64 Docker 手工业务流程仍待验收。

先前版本在 Windows 原生 CI 已通过构建、完整冷安装与恢复，源码及打包后的入口夹具分别通过 2 项入口文件执行和 4 项已注册快捷方式执行，覆盖中文目录、首次与重复注册、完整参数和私有脚本的当前实例绑定（[原生验收流 #45665229256](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45665229256/job/139385482871)；v0.1.1 12 任务重发见 [#45737825566](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45737825566)；v0.1.2 12 任务发行见 [#45768018488](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45768018488)）。

本轮 `v0.1.3` [发行流 #45850087833](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833) 的 12 项任务全部通过，覆盖双架构测试/审计、四平台原生组包验收、双架构镜像、17 项正式资产发布，以及 macOS/Windows 各两项公开标准安装入口验收（[Mac 全新](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833/job/140034075059)、[Mac 中断接续](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833/job/140034074992)、[Windows 全新](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833/job/140034075043)、[Windows 中断接续](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833/job/140034074933)）。构建源为 `a9d84db7ca30c3201bc9dd872d9b70f551972866`。公开入口使用默认目录和 8000 端口，核对本次构建、只读交付状态、免账号 Cookie、工作区受保护 API，以及拒绝匿名和无 Cookie 绑定的令牌；不扩大为真实 GUI、特定智能体产品或模型推理验收。

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

以下命令安装已发布的 `v0.1.3`（若已克隆仓库，也可直接在仓库根目录双击 `install.cmd` 或运行 `.\install.ps1` / `sh install.sh`）。全新便携实例默认免邮箱密码，已安装旧版本时自动备份并平滑升级，已有账号实例继续使用原登录方式。

**macOS（Apple Silicon）与 Linux：**

```sh
curl -fsSL "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.3/get-yanji.sh" | sh
```

**Windows 10 / 11 x64（PowerShell 5.1 或 PowerShell 7）：**

```powershell
irm "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.3/get-yanji.ps1" | iex
```

引导脚本固定对应发布版本的 `SHA256SUMS` 与安装入口摘要，仅允许 HTTPS（含重定向），并逐文件核验包内 `PACKAGE-FILES.sha256`。安装依次显示下载、校验、初始化和服务启动进度；首次服务就绪可能等待约一分钟。

### 方式二：先下载正式安装文件，再本地标准安装

从同一 [GitHub Release v0.1.3](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/tag/v0.1.3) 下载三个文件，放在同一目录并保留原文件名：`SHA256SUMS`、当前系统的 `install-local.sh`（macOS/Linux）或 `install-local.ps1`（Windows），以及对应平台的 `yanji-<平台>.tar.gz`（Windows 为 `yanji-windows-x86_64.zip`）。无需手动解压安装包；安装器会根据本地清单找到同目录的平台包并校验，不会再下载该大包。也可由可信文件渠道完整转发这三个正式资产；只转发 GitHub 链接仍需从 GitHub 下载。仓库的 **Code → Download ZIP** 是源码，不能代替 Release 中的便携安装包。

进入下载目录，使用本轮正式清单摘要执行下方标准安装命令。不能混用不同版本的文件或摘要，也不能从未经独立核对的下载文件自行推导“可信摘要”。

**macOS / Linux：**

```sh
sh ./install-local.sh --manifest ./SHA256SUMS --manifest-sha256 eddbb3fc6961ae6660b92e326ad303bbe769c5a8f5afa3fb8bf2816c3d0e5fc5
```

**Windows（PowerShell）：**

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\install-local.ps1 `
  -Manifest .\SHA256SUMS -ManifestSha256 eddbb3fc6961ae6660b92e326ad303bbe769c5a8f5afa3fb8bf2816c3d0e5fc5
```

- 随包已内置 Python 3.12.16 与开源字体，**默认无需传 `--python-bin` / `-PythonBin`**；仅在需要覆写为自定义解释器时才传该参数。
- 离线安装包位于其他目录时，可附加 `--package /path/to/package.tar.gz`（Windows 为 `-Package C:\path\to\yanji-windows-x86_64.zip`）。
- 全新便携实例默认不询问账号；已有账号实例及显式账号模式保留管理员登录。显式账号模式可传 `--email admin@example.com --password-file "$PRIVATE_PASSWORD_FILE"`（Windows 为 `-Email` 与 `-PasswordFile`），密码文件须为当前用户私有的单行普通文件。
- 自动化安装可附加 `--no-browser`（PowerShell 安装器为 `-NoBrowser`），只跳过打开浏览器，安装、服务启动和访问保护照常执行；交互安装默认自动打开工作台。
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

`v0.1.2` 本机单人模式在服务运行期间支持直接在本机浏览器打开 `http://127.0.0.1:<端口>` 建立或复用会话（遇到本地代理软件转发 `127.0.0.1` 时会以 `proxy_detected` 拒绝并提示配置直连），同时保留 `yanji open` 与桌面快捷方式以在服务未运行时自动拉起服务并打开系统浏览器；系统浏览器打开命令不将凭据写入安装日志或进程参数。无人值守运行可使用 `"$YANJI" start --no-browser`（Windows 管理命令为 `& "$YANJI" start --no-browser`），访问鉴权仍然保留。

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

正式发行工作流 `.github/workflows/release.yml` 要求全量 `Tests` 与四平台原生构建、组包和验收全部成功，才能合并统一 `SHA256SUMS`、生成引导脚本并上传正式资产；Unix 运行时清单 `YANJI_UNIX_RUNTIME_MANIFEST_URL` / `YANJI_UNIX_RUNTIME_MANIFEST_SHA256` 必须配置并通过校验，缺失时明确失败，不跳过 Unix 平台后只发布 Windows 包。工作流还发布双架构 GHCR Docker 镜像，并在正式资产上传后安排 macOS 和 Windows 的公开标准命令验收，各覆盖全新安装与旧版空配置中断后的接续安装，检查本次构建身份、交付状态和受保护会话/API。本轮 `v0.1.3` 12 项发行任务全部通过，见 [发行流 #45850087833](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833)；公开入口自动化不替代真实 GUI 人工点击验收。
