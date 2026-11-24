# 版本变化

## v0.1.0（2026-11-24）— 首个正式自部署与免 Docker 便携版

已通过 [GitHub Release v0.1.0](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/releases/tag/v0.1.0)（发布流 [#45369285542](https://github.com/Mellowing-Potato/Potato-GEO-SaaS/actions/runs/45369285542)）发布四平台免 Docker 便携包、对应源码/重链接材料、统一 `SHA256SUMS` 与双架构 Docker 镜像（`linux/amd64` + `linux/arm64`）；版本号记录在 [VERSION](VERSION)。

- 新增免 Docker 四平台便携运行时（`macos-arm64`、`windows-x86_64`、`linux-x86_64`、`linux-aarch64`），内置自建无 AGPL FrankenPHP 1.13.6 + PHP 8.5.13 + Python 3.12.16 + 开源字体，提供固定摘要的一键引导脚本 `get-yanji.sh` / `get-yanji.ps1` 与 `yanji` / `yanji.cmd` 托管命令。
- 本地邮箱和密码登录、初始化管理员、独立工作区与会话管理。
- 工作区分别配置文本、视觉、1024 维向量和豆包监测模型（支持第三方直连与可选本机 Magpie）；Key 加密保存。
- 直接接入模型供应商，记录调用次数、tokens、耗时与结果；未知结果不自动重发。
- Docker 包含网站、后台任务、定时任务和 PostgreSQL/pgvector；安装脚本支持重复执行。
- 浏览器草稿助手按实例地址生成；知乎纯文本草稿完成真实同步验收。
- 修复模型配置表单标签错位、保存一个配置影响其他未保存配置的问题；改善豆包连接测试提示。
- 保留项目、知识材料、技能报告、内容、渠道、分析、线索和主题包功能。
- 提供 Apache-2.0 许可、上游和依赖声明、四平台对应源码/重链接材料、贡献与安全说明、公共 CI 定义。
- 更新 Symfony、Vite、pip、Brotli（`v1.2.1`）、PDF 解析和渲染依赖以修复已知漏洞。WeasyPrint 70 与 pypdf 7.0.1 属于安全修复升级，已重新验证报告生成。

已验证范围和未完成事项见 [README](README.md#已验证与待验证)。未承诺所有外部平台账号或付费模型均通过真实验收；Linux amd64 Docker 完整手工业务流程及 Windows 本机桌面浏览器手工复核仍待补充。
