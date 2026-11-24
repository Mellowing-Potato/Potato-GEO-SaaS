# 本机运行言及（免 Docker 便携版）

免 Docker 便携包使用与 Docker 部署相同的 Laravel 应用、生产 Composer 依赖、已构建网页与锁定技能资产，并随包内置独立 **Python 3.12.16** 与开源报告字体（DejaVu Sans、Noto Serif CJK），普通用户无需预先安装 Docker、PHP 或 Python。程序与实例数据分离存放；浏览器打开本地地址即可使用工作台，支持第三方模型直连与可选本机 [Magpie](https://github.com/yetone/magpie)。

> 正式 GitHub Release 下载包将在维护者合并并推送 `v*` 标签后由发布工作流生成，届时同步提供统一 `SHA256SUMS` 与固定摘要的一行/两行安装入口。当前版本仍为 `v0.1.0（未发布）`，不会伪造已发布下载链接。

## 支持平台与当前验证状态

| 平台 | 运行时与随包资产 | 当前验证状态 |
| --- | --- | --- |
| **macOS Apple Silicon (`macos-arm64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **真机实测通过**：无宿主机 Python 冷安装、`yanji` 帮助与 `doctor`、私有静态 404 护栏、501 作业轮换、2 秒硬超时接替、热停排水、备份恢复、缓存篡改拒绝、11 技能渲染、真实浏览器登录/工作台/`#aikeys`/建项端到端 |
| **Windows x64 (`windows-x86_64`)** | 自建无 Mercure/Vulcain `frankenphp.exe`（Brotli 1.2.1）+ PHP 8.5.13 ZTS + 随包 Python 3.12.16 + 开源字体 | **原生 `windows-2025` CI 完整通过**：全套构建、11 技能渲染矩阵（仅随包 Python 与字体）、中文空格路径冷安装（`install-local.ps1`）、`yanji.cmd` 帮助与 `doctor`、私有静态 404 与公开联接对照、501 作业轮换、2 秒硬超时接替、热停排水、备份恢复与篡改拒绝（本机桌面浏览器手工点击与真实付费模型调用未在此阶段实测） |
| **Linux arm64 (`linux-aarch64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **原生架构通过**：arm64 VM 冷安装/404/超时/恢复；`ubuntu-24.04-arm` 原生 CI 全量双库测试与随包 Python / WOFF2 子集化 |
| **Linux x64 (`linux-x86_64`)** | 自建 PHP 8.5.13 CLI + 无 Mercure/Vulcain FrankenPHP + 随包 Python 3.12.16 + 开源字体 | **原生 CI + 仿真通过**：`ubuntu-24.04` 原生双库测试与随包 Python / WOFF2 子集化已通过；完整冷安装此前经 Rosetta 仿真验收，原生 x86_64 实机冷安装待发布前补齐 |
| **Intel Mac (`macos-x64`)** | 不纳入首批发布范围 | 未构建、未验证 |

> 同步说明：14 项底层 C 依赖已做公开 CVE 核对；Brotli 已在 `licenses/portable-web.json` 与 Windows Web 构建中升级至 `v1.2.1`；SQLite 3.45.2 之后的相关 CVE 经源码路径核对在言及固定表结构与查询路径中不可达。发布物采用免费 `SHA256SUMS` 校验与固定摘要引导脚本，未购买 Apple Developer ID 公证或 Windows EV 代码签名。

## 安装

### 方式一：正式发布后的一行安装（推荐）

正式发布标签（例如 `v0.1.0`）上传 GitHub Releases 后，将命令中的 `<TAG>` 替换为实际发布标签即可：

**macOS（Apple Silicon）与 Linux：**

```sh
curl -fsSL "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/<TAG>/get-yanji.sh" | sh
```

**Windows 10 / 11 x64（PowerShell 5.1 或 PowerShell 7）：**

```powershell
irm "https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/<TAG>/get-yanji.ps1" | iex
```

引导脚本固定了对应发布版本的 `SHA256SUMS` 与安装脚本摘要，仅允许 HTTPS（含重定向），并在安装前逐文件核验包内 `PACKAGE-FILES.sha256`。

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
- 交互终端中会提示输入首位管理员邮箱与密码；无人值守可另传 `--email admin@example.com --password-file "$PRIVATE_PASSWORD_FILE"`（Windows 为 `-Email` 与 `-PasswordFile`），密码文件须为当前用户私有的单行普通文件。
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
"$YANJI" status
"$YANJI" logs
"$YANJI" doctor
"$YANJI" stop
"$YANJI" restart
"$YANJI" reset-password --email admin@example.com
```

**Windows（PowerShell）：**

```powershell
& "$YANJI" start
& "$YANJI" status
& "$YANJI" logs
& "$YANJI" doctor
& "$YANJI" stop
& "$YANJI" restart
& "$YANJI" reset-password --email admin@example.com
```

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
- `magpie --tenant=ID` 为可选工作区授权入口；安装、启停与 `doctor` 不会主动探测 Magpie 或调用推理。

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
