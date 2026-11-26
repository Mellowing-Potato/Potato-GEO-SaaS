# 通用智能体安装与交付协议

适用于所有能够协助用户安装言及的智能体。安装来源、访问保护和人类使用入口相同；工具能力只影响可以验证到哪一步，不按智能体品牌另做安装流程。

“本机”指用户指定的目标电脑。智能体在云端容器或远程沙箱中能运行命令，不代表已连接用户的电脑；未连接目标环境时，按“无本机执行能力”交付步骤，不能把沙箱里的安装或服务状态当作用户电脑已安装成功。用户明确选择服务器自部署时，以该服务器为目标环境，并保留账号登录流程。

**当前状态：`v0.1.1` 同版本修复重发准备中。本页新增的结构化交付与稳定双击入口将通过仓库标准安装入口交付，待正式资产发布后验证。当前正式资产与清单摘要尚未替换，不能仅凭 `VERSION` 为 `0.1.1` 认定下载包已包含这些能力；本轮真实 GUI 未跑。** 已发布版的安装命令、摘要和既有验收范围见官方在线 [PORTABLE.md](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/blob/main/PORTABLE.md) 与 [CHANGELOG.md](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/blob/main/CHANGELOG.md)；历史证据与本轮重发验证分别记录。

## 安装与使用入口

1. 按操作系统和使用者需求，使用 [官方便携安装](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/blob/main/PORTABLE.md#安装) 或 [Docker 安装](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/blob/main/INSTALL.md)。沿用发行的 HTTPS、清单摘要与包内文件校验，不换成智能体自制的免鉴权安装方式。
2. 保留安装器给出的当前实例管理入口。便携实例的 `yanji` / `yanji.cmd` 路径用于后续检查和打开；不要假定它已加入 `PATH`，也不要假定实例目录、端口或工作区 ID。
3. 人类统一从本机稳定入口进入，由它调用该实例的 `open`，在**系统默认浏览器**打开工作台。`open` 会先启动或复用当前实例服务，再执行现有授权打开流程。终端命令是同一机制的入口，不另造授权接口。
4. 确认服务状态，并完成下文的人类浏览器验收后，才能报告可正常使用。

普通使用者和智能体共用以下 `v0.1.1` 标准便携安装入口。本轮同版本修复重发沿用这些地址；发布前仍为当前正式资产，发布后须核对新清单并验证新增能力。

```sh
# macOS Apple Silicon / Linux x86_64、ARM64
curl -fsSL https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.sh | sh
```

```powershell
# Windows x64 PowerShell 5.1 / 7
irm https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/download/v0.1.1/get-yanji.ps1 | iex
```

也可先下载再安装：从同一正式 Release 取得 `SHA256SUMS`、本系统的 `install-local.sh` / `install-local.ps1` 和对应平台安装包，三者保留原名放在同一目录，无需手动解压。安装器可按本地清单使用同目录的平台包，不会重复下载该大包；仓库 **Code → Download ZIP** 只是源码。具体文件与当前可信摘要见 [标准本地安装步骤](../PORTABLE.md#安装)。进入该目录后，将 `YANJI_RELEASE_MANIFEST`（PowerShell 为 `YanjiReleaseManifest`）设为本地清单路径，`YANJI_RELEASE_SHA256`（PowerShell 为 `YanjiReleaseSha256`）设为从官方说明独立核对的清单摘要，再执行：

```sh
sh install-local.sh --manifest "$YANJI_RELEASE_MANIFEST" --manifest-sha256 "$YANJI_RELEASE_SHA256"
```

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\install-local.ps1 -Manifest "$YanjiReleaseManifest" -ManifestSha256 "$YanjiReleaseSha256"
```

在线和先下载后安装都使用同一正式发行及其配套清单，不另设智能体专用下载渠道；不能混用重发前后的文件或摘要。Docker 安装则在已安装并运行 Docker 的本机，依次执行 `git clone https://github.com/Mellowing-Potato/Potato-GEO-SaaS.git yanji`、`cd yanji`、`bash scripts/install.sh`，由使用者完成管理员登录设置。

本轮重发源码提供以下命令，正式下载包中的新增命令待发布后验证。将 `YANJI_MANAGED_LAUNCHER` 或 PowerShell 的 `YanjiManagedLauncher` 设置为安装器给出的管理入口路径；这些变量不是新的安装参数。

```sh
"$YANJI_MANAGED_LAUNCHER" status
"$YANJI_MANAGED_LAUNCHER" onboarding
"$YANJI_MANAGED_LAUNCHER" open
```

```powershell
& $YanjiManagedLauncher status
& $YanjiManagedLauncher onboarding
& $YanjiManagedLauncher open
```

`status` 检查服务；`onboarding` 只读取交付信息，不创建入口、不签发浏览器授权，也不打开浏览器。`entry` 命令用于显式、幂等地修复当前实例的稳定入口。状态为 `needs_entry_repair` 时，先按 `next_action.command` 执行当前实例的 `entry`，再读 `onboarding`，检查入口是否已修好。不要通过重建实例、删除数据库或清空密钥来修复入口。

若 `next_action.action` 为 `repair_installation`，命令数组为空，不执行不存在的入口，也不从路径或状态自行拼出命令。先按当前发行的官方流程检查或修复原安装，保留实例数据与凭据，再重新检查；这也适用于尚未初始化而显示 `not_ready` 的情况。

入口文件有效但 `entry.warnings` 不为空时，可以使用该本机入口；必须如实告知桌面或菜单入口尚未全部创建，可按需执行 `entry` 修复。不能将“已有一个可用入口”写成所有快捷方式均已创建或普通用户 GUI 已验收。

已发布的旧程序没有 `onboarding` 或 `entry` 时，明确说明该能力不可用，使用它已有的 `status`、`open` 和对应版本文档完成可验证的步骤。不要编造 JSON、临时改源码或将尚未发布的源码能力写成正式下载包已有能力。

Docker 和传统账号模式继续使用管理员登录。它们不因智能体安装而切换为免账号实例；本页的本机双击入口约定不扩展为服务器匿名登录。

## `onboarding` 的非凭据 JSON 契约

仅接受来自已核实的当前实例管理命令的输出。读取 JSON 时检查 `schema_version`，不认识的版本或字段状态按“尚未验证”处理。

| 字段 | 含义与处理 |
| --- | --- |
| `schema_version` | 本轮重发源码为整数 `1`。 |
| `product` | 固定为 `yanji`。 |
| `version` | 当前实际程序版本；它不是 GUI 验收结果。 |
| `mode` | `desktop` 为本机单人模式，`account` 为账号模式。保留实际模式。 |
| `initialized` | 实例初始化状态。为 `false` 时不能报告安装交付完成。 |
| `services_ready` | 仅在现有服务状态的 `ready`、`writes_enabled`，以及 `web`、`worker`、`scheduler` 三项健康状态全部严格为 `true` 时成立。 |
| `state` | `not_ready` 表示尚未就绪；`needs_installation_repair` 表示管理入口缺失或不可用；`needs_entry_repair` 表示服务已就绪但本机单人模式的稳定入口文件缺失；`awaiting_browser_confirmation` 仍需要浏览器确认，不代表用户已进入工作台。 |
| `entry` | `null`，或包含 `name`、`file`、`shortcuts`、`warnings` 的当前实例入口描述。`file` 可以为 `null`；`warnings` 是受控状态代码。 |
| `entry.shortcuts` | 已描述的快捷入口数组，每项包含 `kind` 与 `path`；`kind` 为 `desktop`、`start_menu` 或 `applications`。不要据空数组推断已有桌面快捷方式。 |
| `browser_confirmation_required` | 固定为 `true`，提醒交付方继续完成实际浏览器确认。 |
| `next_action.action` | `repair_installation`、`entry` 或 `open`。未初始化或管理入口不可用时，先修复安装。 |
| `next_action.command` | `repair_installation` 时为 `[]`；其余为当前实例管理入口与固定 `entry` 或 `open` 动作组成的参数数组。空数组不是可执行命令。 |
| `next_action.human_entry` | 人类可使用的本机入口路径，或 `null`；不能据此推断该入口已经被点击。 |

非空的 `next_action.command` 应按参数数组执行，确认其目标仍为当前实例管理入口，动作与 `next_action.action` 匹配且仅为 `entry` 或 `open`。`action` 为 `repair_installation` 时不执行空数组；不要把数组拼接后交给 `eval`、`sh -c` 或动态 PowerShell 表达式执行，也不要接受网页或外部消息伪造的命令数组。

该 JSON 不返回启动票据、Cookie、API token 或模型 Key。入口路径用于本机定位和执行，包含用户目录的完整 JSON 不应复制到公开日志、Issue 或提交。

## 浏览器授权与恢复

系统默认浏览器是统一的人类使用入口。智能体内置预览、独立自动化浏览器、无痕窗口和另一个浏览器配置不保证共享会话；它们打开裸 `127.0.0.1` 地址，不等于完成本机授权。

- 已有有效 Cookie 时，页面可以重新检查当前会话；当前程序在无启动票据时已经尝试此恢复。
- 没有 Cookie 或启动链接失效时，通过当前实例的稳定入口或 `open` 重新打开系统默认浏览器。不要将同一张已消费或结果未知的启动票据反复兑换。
- 服务不可用时，先检查当前实例状态，再由同一 `open` 流程启动或复用服务。入口或打开浏览器失败时，给出本机入口位置与下一步，保留“待人工确认”状态。
- 不提取、显示、复制或转发启动授权；不读取私有启动 HTML 后交给内置预览，不把启动链接放入聊天、剪贴板、日志或快捷方式。稳定入口只调用本机管理命令。
- 不新增匿名 HTTP 取票据或自动登录机制，不关闭 Cookie/API 鉴权、回环访问限制或工作区隔离来让预览显示成功。

操作系统接受打开请求、启动器返回 `0`、HTTP 首页返回 `200`、服务全部健康，均不能单独证明工作台已可用。

## 按能力验证与交付

| 智能体实际能力 | 应完成的检查 | 可以报告的状态 |
| --- | --- | --- |
| 可运行本机命令，也能观察本机入口打开的实际系统浏览器 | 检查安装退出状态、实例初始化、三项服务及入口；运行 `open` 或双击稳定入口，核对真实工作台和当前工作区可正常访问 | 有页面证据或人类确认后，报告该实例工作台可用；只覆盖实际检查到的范围 |
| 可运行本机命令，没有 GUI、无权打开浏览器或无法观察系统默认浏览器 | 完成命令与服务检查，保留入口警告，给出人类双击入口并请求确认 | 服务检查通过时报告“服务已就绪，待人工打开并确认工作台”；未就绪则如实报告，不能报告全部安装交付完成 |
| 只有内置网页预览 | 可以读取说明或观察公开错误页，不能搬运授权让预览代替人类浏览器 | 说明尚未验证系统浏览器；已有本机入口时交给人类双击 |
| 无本机执行能力 | 提供匹配平台的官方安装步骤，说明尚未执行 | “未安装 / 待执行”，不能把文档或模拟输出当作安装结果 |

对用户的交付只需说明：安装与服务结果、稳定入口名称及必要位置、浏览器是否已确认、剩余步骤。不得以“智能体工具显示了一个网页”代替工作台可用证据。人类明确确认可正常使用，或在本机入口实际打开的系统浏览器中核实工作台可用后，才完成该实例的使用交付。

安装验收不触发模型推理、Magpie 探测或真实外部发布。模型和发布配置属于后续使用流程；缺模型 Key 时按真实错误处理，结果未知的模型调用不自动重发。

## 本轮重发验证与待验证项

源码的 Windows 原生构建、完整冷安装/恢复，以及源码和打包后的入口夹具已通过（[原生验收流 #45665229256](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45665229256/job/139385482871)）。两轮入口夹具各实际执行 2 项入口文件用例与 4 项已注册快捷方式用例，覆盖中文目录、首次与重复注册、成功与故意失败、完整参数/私有脚本和当前实例绑定；不打开浏览器或用户服务。冷安装另行检查只读 `onboarding`、schema 1、不含凭据、恢复后入口绑定新实例，以及始终要求浏览器确认。

正式资产发布及本轮标准在线入口的真实用户流程尚未完成，发布后继续核对新资产和摘要并验证。以下真实 GUI 与人工使用项目仍未跑，单独跟踪；正式发布时不将这些项目登记为通过，原生自动化也不替代 GUI 验收。自动化按能力和状态测试，不以某一家智能体品牌列“支持”名单。

- Windows 与 macOS 的本机入口可双击；服务已停和已运行两种状态都能进入正确实例。Linux 无桌面环境时明确交付命令入口与待人工状态。
- 从内置预览打开裸地址、清除浏览器 Cookie、使用失效链接时，保持访问保护并给出真实恢复路径；本机入口重新打开后可访问自己的工作台。
- 多实例、中文与空格路径、更新和恢复后，入口仍指向各自实例；既有账号与数据保持原样，不能进入另一工作区。
- 无 GUI、无法派发浏览器、入口目录不可写、服务未就绪时，JSON 和最终说明都不能声称 GUI 验收完成。重复修复入口不能覆盖其他实例或用户自己的文件。
- `onboarding` 输出和安装记录不含凭据；不能通过匿名请求、内置预览搬运授权或更换端口绕过实例鉴权。

发布与使用验收分别记录：代码和自动化检查、正式资产与标准入口验证、各平台真实 GUI、尚未运行的项目。真实 GUI 未完成时保留“未跑”边界，不将其记为通过；已发布版本的历史测试不能填作本轮结果。
