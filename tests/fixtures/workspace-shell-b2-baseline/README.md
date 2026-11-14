B2 的授权基线：全局“新建”菜单复用的两段原始点击处理函数正文。

摘自提交 `a435b01`（B2 `5b9ee44` 的父提交）里 `public/geo-entry.js` 的
`elements.newProjectButton` 处理函数，和 `public/geo-entry-content-library.js` 的
`elements.clNewArticleButton` 处理函数，逐字保存；文件末尾多一个换行，测试读取时去掉。原测试用
`git show aea293f:...` 读取，但该提交在仓库里已不存在，测试镜像里也没有 git。
