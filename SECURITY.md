# 安全问题

请在本项目 GitHub 仓库进入 **Security → Advisories → Report a vulnerability**，使用私密漏洞报告。不要在公开 Issue、PR、截图或日志中披露可利用细节、模型 Key、令牌或个人信息。

报告请包含受影响版本、部署方式、复现步骤、影响范围和经过脱敏的最小示例。租户之间的数据访问、私有报告下载、出站请求绕过、凭据泄露及未知调用自动重复等问题均属于安全报告范围。维护者会按影响评估修复，不承诺固定响应时限。

当前项目尚处于首个公开版本准备阶段。公开仓库建立后，维护者必须先启用 GitHub 私密漏洞报告；若没有看到报告入口，请只提交“请启用私密漏洞报告”的普通 Issue，不附漏洞细节。设置方法见 [GitHub 官方说明](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/configure-vulnerability-reporting/configure-for-a-repository)。

目前只维护最新发行版本；更新前按 INSTALL.md 备份数据库、文件和 APP_KEY。公网实例应使用 HTTPS。模型 Key 在网页配置并加密保存，备份和实例密钥仍需由运营者妥善保管。
