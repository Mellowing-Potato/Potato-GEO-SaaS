# 安装言及

> **v0.1.3 已正式发布**：免 Docker 便携版与双架构 Docker 镜像均已更新至 `v0.1.3`（[发行流 #45850087833](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45850087833) 全部 12 项任务通过，覆盖双架构测试/审计、四平台便携构建、双架构 Docker 镜像、17 项资产发布及 macOS/Windows 四项标准公开安装入口验收）。公开入口自动化仅覆盖默认目录/端口、标准命令、工作台 Cookie 与受保护 API，不扩大为完整 GUI 或 Magpie 真实推理验收。Docker 继续使用管理员账号登录；便携本机单人模式保留已有账号和数据。

适用于人和编程助手。只操作你准备安装的本机 Docker；不要自行连接服务器。模型 Key 在网页「设置」中填写，不写进 `.env`、命令或安装记录。

Docker 自部署由使用者在自己的设备或服务器上管理，维护者不提供托管或收费服务；登录账号用于保护使用者自己的实例。

`v0.1.3` 已正式发布。免邮箱密码的本机单人流程用于便携版；本页 Docker 安装继续使用本地管理员账号登录。

免 Docker 的本机便携版支持 macOS Apple Silicon、Windows x64 与 Linux x86_64 / ARM64，随包内置 Python 与开源字体，见 [PORTABLE.md](PORTABLE.md) 和 [GitHub Release v0.1.3](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/tag/v0.1.3)；本页 Docker 安装、备份恢复与开发流程继续适用。

## 1. 准备

安装并启动 Docker Desktop（Mac / Windows），或 Docker Engine 和 Compose v2 及以上兼容版本（Linux）。首次构建会从公开源下载镜像、依赖和锁定版本的技能资产，需要联网和足够的磁盘空间。

在仓库目录执行：

```sh
docker info
docker compose version
```

成功时分别显示 Docker 运行信息和 Compose 版本。若无法连接，请先打开 Docker Desktop，等待它运行正常；不要切换到远程 Docker 环境来绕过错误。

## 2. 安装

最短路径，先克隆并进入仓库目录再运行（避免在主目录直接执行提示找不到 `scripts/install.sh`）：

```sh
git clone https://github.com/Mellowing-Potato/Potato-GEO-SaaS.git yanji
cd yanji
./scripts/install.sh
```

脚本不会覆盖已有 `.env`。第一次会构建镜像、启动 PostgreSQL / 网站 / worker / scheduler，并询问管理员邮箱和密码。密码输入不回显，不保存到配置文件。成功时显示「初始化完成」和「安装完成」。然后打开 http://localhost:8000 登录。

智能体可使用 `./scripts/install.sh --email=<管理员邮箱> --password=<管理员密码>`。参数不要写入脚本、记录或提交；通常推荐让使用者交互输入，避免密码留在命令历史中。重复运行脚本会保留已有管理员、数据和密钥，不重置密码。

需要自定义域名、端口或局域网访问时，先在没有 `.env` 的情况下执行 `cp .env.example .env`，按中文注释修改，再运行安装脚本。`APP_URL` 必须是浏览器实际访问的完整地址。默认仅绑定本机；公网入口需要由部署者配置 HTTPS 反向代理。

也可以分步运行（全新目录中没有 `.env` 时，Compose 使用相同的本机默认值）：

```sh
docker compose up -d --build
docker compose exec web php artisan yanji:install
docker compose restart web
docker compose ps
```

成功时 4 个服务均运行，数据库和网站健康。初始化前 worker / scheduler 会等待管理员创建；这时网站不能正常登录属于尚未完成初始化。管理员已经存在时，直接重复 `yanji:install` 会明确拒绝创建第二个管理员；使用安装脚本则会识别并跳过。

构建失败时检查公开下载源的联网情况、Docker 空间和构建错误，修复后重跑安装脚本。不要通过关闭出站保护、跳过资产哈希或改成模拟模型绕过错误。端口被占用时，同时修改 `.env` 中 `HTTP_PORT` 和 `APP_URL` 后再运行脚本。

## 3. 检查与使用

```sh
docker compose ps
docker compose exec web php artisan yanji:ready
docker compose exec web php artisan yanji:worker --check
docker compose logs --tail=50 worker scheduler
```

就绪检查成功返回 0，不输出账户或 Key。队列检查显示「队列配置检查通过」。worker 消费 `default,skills,publishing,tasks,knowledge,visibility,distribution,url-import`，使用数据库队列；不需要 Redis。最长任务超时目前为 540 秒，`DB_QUEUE_RETRY_AFTER` 必须更大，默认 600 秒，错误配置会阻止 worker 启动。

登录后在设置中分别配置文本、向量、视觉和豆包监测模型。没有 Key 会提示未配置，不会生成模拟结果。知识库需要 1024 维向量；有材料时不能换向量模型，需要由使用者先删除材料再重新导入。结果未知的模型调用不会自动重发。

豆包监测需要使用者在火山方舟控制台开通所选模型及联网搜索能力，选择支持 Responses 接口和 `web_search` 的模型，并在「豆包监测」中填写真实模型 ID 和 Key。联网搜索费用按你的火山方舟账户计算；普通文本模型 Key 不会自动成为监测配置。保存后通过页面测试连接，错误时根据实际返回处理，不填写模拟结果。

如需连接自己控制的内网模型服务，在 `.env` 的 `GEO_OUTBOUND_PRIVATE_TARGETS` 中填写精确的 `主机名:端口`，再运行安装脚本使配置生效；默认留空，不支持通配符。Docker Desktop 访问宿主机服务时可用 `host.docker.internal`，容器里的 `localhost` 指容器自身。不要关闭出站安全检查。

### 可选的 Magpie 工作区授权

[Magpie 项目与安装说明](https://github.com/yetone/magpie)。文本和视觉可以分别选择 Magpie 或第三方直连，向量和豆包监测仍独立配置。没有 Magpie 也可完整使用第三方直连。

`v0.1.1` 本机单人便携模式在「设置 → 模型配置」提供「连接本机 Magpie」：检测已启动的服务，连接当前工作区并读取当前用途的模型目录，无需手填工作区 ID 或重启。维护者已确认 Mac 候选的服务检测与目录读取；当前来源仍为第三方直连，本轮模型激活和真实推理未跑。以下命令用于传统账号模式或部署者授权；服务器实例的可信目标由部署者管理。

本机便携实例可以用下面的管理入口。将 `YANJI` 设为安装器显示的管理程序路径，将 `YANJI_WORKSPACE_ID` 设为该实例实际工作区 ID（当前登录接口响应的 `tenant.id`）；不要假定工作区 ID 为 1。

```sh
"$YANJI" magpie --tenant="$YANJI_WORKSPACE_ID"
```

探测和写授权分别询问，默认都为否。探测只向 `http://127.0.0.1:3425/api/hello` 发一次不带凭据的 GET，校验服务标识和版本，不读取模型目录或调用推理。明确同意后，才会在实例写锁内合并所选工作区的授权；保留其他工作区、目标别名与版本设置。遇到既有禁用目标、鉴权或地址限制冲突，拒绝自动修改，由部署者审核。

自动化脚本必须显式使用裸标志 `--probe`，需要写授权时同时提供 `--authorize`；不接受 `--probe=false` 等赋值写法。成功合并会失效当前实例的配置缓存，随后运行 `"$YANJI" restart`，再在网页中保存连接、读取目录、选定模型并激活。安装、启停和 `doctor` 不会自行探测 Magpie。Docker 的授权仍按下面的 Compose 配置方式维护。

Magpie 和 GEO 的后端需要运行在同一台电脑。由部署者在 `.env` 的 `GEO_MAGPIE_TARGETS` 中维护 JSON 字典，每项用连接别名标识，设置 `origin`（协议、主机和端口）、`tenant_ids`（允许使用的工作区 ID 数组）、`auth_mode`、`enabled` 和 `revision`。示例见 `.env.example`。原生 GEO 可用回环地址和 `loopback` 模式。Docker Desktop 候选地址为 `http://host.docker.internal:3425`，用 `gateway_key` 模式，并在 GEO 页面中填写 Magpie Gateway Key。端口应与用户启动的 Magpie 一致。使用其他 DNS 主机名时须明确列出允许的本机 IP（`addresses` 数组）。Linux Docker 及其他平台需核实宿主地址和 Magpie 监听方式。容器仅通过主机映射解析此地址时，配置 `addresses` 来固定该本机地址。

Web、Worker 和 Scheduler 使用同一授权配置。修改授权时更新 `revision`，让各进程重新读取环境。Compose 环境变量变动需要重建相应服务容器，由部署者安排。要撤销可达的目标，保留条目并设 `enabled=false`，让该地址继续受保留目标保护。原有 `GEO_OUTBOUND_PRIVATE_TARGETS` 用于常规内网目标。

### 使用 TUN / Fake-IP 网络代理时

如果公网网页导入或模型连接提示“内网地址或其他非公网可达地址”，先检查 Docker 的域名解析。Clash 等代理的 Fake-IP 模式可能返回 `198.18.0.0/15` 保留地址；应用会按非公网地址拒绝访问。不要把这个网段加入放行名单。

可以保留 TUN：让代理对所用的模型和网页域名返回真实地址，或给 Docker 配置能够返回真实公网地址的 DNS 服务。若使用 DNS over HTTPS，保留 TLS 证书验证。不要仅把普通公共 DNS 地址填入 Docker 后就认定修复，TUN 仍可能接管普通 DNS 查询。

如果已有一个 Docker 能访问的真实地址 DNS 服务，可在本机创建 `compose.dns.yml`，把下面变量设置为该服务地址。此文件是部署者的本机配置，不提交；它不改变应用的出站地址检查。

```yaml
services:
  web:
    dns: ["${YANJI_DNS_SERVER:?请填写可返回真实公网地址的 DNS 服务地址}"]
  worker:
    dns: ["${YANJI_DNS_SERVER:?请填写可返回真实公网地址的 DNS 服务地址}"]
  scheduler:
    dns: ["${YANJI_DNS_SERVER:?请填写可返回真实公网地址的 DNS 服务地址}"]
```

在 `.env` 中填写 `YANJI_DNS_SERVER`，安装或更新时运行 `COMPOSE_FILE=docker-compose.yml:compose.dns.yml ./scripts/install.sh`。后续 Compose 命令使用相同的 `COMPOSE_FILE`。通过页面重新执行一次连接测试或网页导入，确认真实完成；原失败记录应保留。

`docker compose restart` 保留数据。`docker compose down` 也保留命名卷。**不要执行 `docker compose down -v`、不要删除数据卷、不要运行 `key:generate` 或修改 APP_KEY。** APP_KEY 位于 `instance` 卷中的 `.env`，用于解密已保存的模型 Key；丢失后无法恢复这些凭据。容器可以重建，数据卷必须保留。

## 4. 备份

完整备份包括 PostgreSQL、`storage` 卷（材料、报告等）、`instance` 卷（APP_KEY）和本机 `.env`。备份包含私人内容和密钥，保存在受保护位置，不提交到 Git。以下命令在仓库目录运行，停写期间网站暂不可用：

```sh
umask 077
mkdir -p .backups
backup_dir=".backups/$(date +%Y%m%d-%H%M%S)"
mkdir "$backup_dir"
docker compose stop web worker scheduler
docker compose exec -T postgres pg_dump -U yanji -d yanji -Fc > "$backup_dir/database.dump"
docker compose run --rm --no-deps -T web tar -czf - -C /app storage instance > "$backup_dir/files.tgz"
if [ -f .env ]; then cp .env "$backup_dir/compose.env"; fi
docker compose start web worker scheduler
```

每条命令必须成功后再执行下一条。失败时保留已有备份，检查磁盘空间或容器日志；无论备份是否成功，都确认服务重新启动。成功时备份目录有非空 `database.dump`、`files.tgz`，若使用 `.env` 则还有 `compose.env`。将该目录另存到安全位置，并定期在独立实例验证恢复；仅有文件不代表备份可恢复。

## 5. 恢复

推荐在新目录和新 Compose 项目中恢复，避免覆盖正常实例。使用与备份相同版本的代码。在该目录准备备份文件，将下面 `backup_dir` 改成备份目录的绝对路径。恢复数据库会替换目标实例的现有数据，先确认这是恢复目标。

```sh
backup_dir=/absolute/path/to/backup
if [ -f "$backup_dir/compose.env" ]; then cp "$backup_dir/compose.env" .env; fi
docker compose build
docker compose up -d postgres
docker compose stop web worker scheduler
docker compose run --rm --no-deps -T web tar -xzf - -C /app < "$backup_dir/files.tgz"
docker compose exec -T postgres pg_restore -U yanji -d yanji --clean --if-exists --exit-on-error < "$backup_dir/database.dump"
docker compose up -d
```

若数据库仍在启动，等待 `docker compose ps` 显示健康后再恢复。不要运行初始化或生成新 APP_KEY。恢复后用原管理员登录，检查材料和报告能读取、已保存的模型配置仍能解密。新目录需使用不同项目名与端口，防止与旧实例共用卷或争抢端口；例如设置 `COMPOSE_PROJECT_NAME=yanji-restore`，并在 `.env` 同步调整 `HTTP_PORT` / `APP_URL`。

## 6. 升级

先完成并核实完整备份，阅读目标版本变更说明，然后将代码更新到目标发行版本。保留 `.env` 和现有项目名，执行：

```sh
docker compose build
docker compose stop web worker scheduler
docker compose run --rm --no-deps web php artisan migrate --force
docker compose up -d
```

成功时迁移完成，服务运行；用现有账户重新登录检查。迁移失败时停止升级，保留原数据和错误信息，不运行删除卷或清库命令。需要回退时，用升级前代码在独立实例恢复升级前的完整备份，不对已升级数据库直接执行旧代码。

技能资产由镜像构建按锁定提交下载并核对 496 个文件、注册表和 MIT 许可，不需要手动挂载。中文字体及 Python/PDF 依赖同样随镜像提供。平台实际验证结果见发行说明；未验证的平台不视为已支持。

## 浏览器草稿助手

在 Chrome 中登录本实例，打开「手动发布」→「浏览器发布助手与使用说明」，核对显示的实例地址，再下载安装包。解压后按页面说明加载到 Chrome。包内 README 标明实例地址、标识和版本；不要直接加载仓库中的扩展源码目录。

同一实例升级时，用完整新包覆盖原解压目录，在扩展管理页重新加载，再刷新言及页面。不同实例用不同解压目录；更换 APP_URL 后必须重新下载。保存前仍会核对目标平台的实际登录账号与内容版本。助手只保存草稿，公开发布由使用者在目标平台操作。
