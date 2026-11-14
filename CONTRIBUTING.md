# 参与言及

Issue 用于可复现的缺陷和功能建议，不提供免费部署咨询。先阅读 [README](README.md)、[安装文档](INSTALL.md) 和 [开发约定](AGENTS.md)。安全问题不要发公开 Issue，见 [SECURITY.md](SECURITY.md)。

## 提交问题

说明使用版本、系统与架构、部署方式、复现步骤、预期和实际结果。日志与截图先去除 Key、令牌、邮箱、个人资料和基础设施地址。示例只用 `example.com`、`sk-test-` 等明显假值。不要上传数据库、`.env`、用户材料或完整模型请求。

## 开发和验证

需要本机 Docker（含 Buildx）和 Python 3。默认安装路径见 INSTALL.md；不要对已有实例运行脚手架初始化或更换 APP_KEY。

```bash
python3 scripts/test-linux.py
python3 scripts/checks/privacy.py
python3 scripts/checks/c1.py
python3 scripts/checks/c2.py
python3 scripts/checks/c3.py
git diff --check
```

测试脚本在临时容器中安装锁定依赖，运行 SQLite、PostgreSQL、JS、字体和技能快照测试。SQLite/JS 禁网，PostgreSQL 仅使用隔离的测试网络。它只清理本次创建的测试容器与网络，不清理现有实例的数据卷。可以用 `--platform linux/arm64` 或 `--platform linux/amd64` 指定架构。

代码修改应包含有意义的回归验证。保留现有测试断言，不用模拟返回冒充真实模型验收，不重发结果未知的模型调用。涉及界面时同时检查窄屏、错误状态和其他工作区；涉及权限时检查跨工作区拒绝访问。开发记录仅放在忽略的 `.dev-records/`。

## Pull Request

小步修改，一个提交处理一个问题，写清原因、行为变化、验证结果和未验证事项。只新增数据库迁移，不能修改已发布的迁移。不要放松出站检查、租户隔离和私有文件授权。需要真实供应商验收时，使用自己的测试账号并自行承担费用，自动化测试不需要真实 Key。

引入上游代码或资产时，记录仓库、固定提交、文件、许可证及修改范围，保留版权和必要的 NOTICE。不要从可变的主分支直接拉取技能，不默认把上游新版本视为旧版本的许可。新增依赖需更新锁文件、许可清单和漏洞审计。提交贡献表示你有权按相应文件的许可证提供该贡献，不要求转让版权。

## 发行维护

GitHub 工作流只使用托管 runner；PR 不读取仓库 secrets。版本标签匹配 `v*` 时，发行流程在测试通过后构建并推送 amd64/arm64 镜像到 GHCR。维护者应先审核变更、完整历史隐私扫描、许可和真实验收记录，再决定是否推送版本标签。首次公开前需在仓库设置中启用私密漏洞报告。
