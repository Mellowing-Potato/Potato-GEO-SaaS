#!/usr/bin/env node
/**
 * 真浏览器验收：工作台/运营台新面板的 computed style 与响应式（计划 §5.1 + §5 验收标准第一条）。
 *
 * 为什么要有这个脚本：
 *   - 源码级断言只能证明"新面板落在已迁移的字重作用域里"，证明不了**浏览器算出来的**
 *     字重/字号真的是 500/400/20px/16px（层叠、继承、`!important`、加载顺序都能把它改掉）；
 *   - 宽表是不是真的"局部横滚、不挤整页"，只有在真布局里量 `scrollWidth` 才算数；
 *   - 侧栏入口是不是真的能把面板切换出来，也只有点一下才知道。
 *
 * 不依赖 playwright / puppeteer：直接用 Node 内置的 WebSocket 讲 Chrome DevTools 协议。
 * 前提（两个都已在本机跑通）：
 *   1. 静态预览沙盘：`php scripts/preview-workspace-ui.php --include-queue`
 *      并以任意静态服务器托管临时目录（例如 `php -S 127.0.0.1:8766 -t <临时目录>`）；
 *   2. 一个 headless Chrome 的调试端点：`chrome.exe --headless=new --remote-debugging-port=9222 ...`。
 *
 * 用法：
 *   node scripts/verify-ui-panels.mjs --cdp=http://127.0.0.1:9222 --base=http://127.0.0.1:8766 [--out=截图目录]
 *
 * 退出码：0 = 全部通过；1 = 有违例（逐条打印）。
 */
import fs from 'node:fs';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const [k, ...rest] = a.replace(/^--/, '').split('=');

        return [k, rest.join('=') || true];
    }),
);
const cdpEndpoint = String(args.cdp ?? 'http://127.0.0.1:9222');
const base = String(args.base ?? 'http://127.0.0.1:8766').replace(/\/$/, '');
const screenshotDir = args.out ? String(args.out) : null;

// 计划 §5.1 点名要覆盖的宽度 + 三组临界值
const WIDTHS = [1440, 1280, 1024, 961, 960, 901, 900, 899, 768, 721, 720, 390, 320];

const VIEWS = [
    { name: 'URL 导入', hash: '#url-imports', panel: 'urlImportsPanel', sidebar: 'url-imports', shell: 'workbench' },
    // 文章分类的侧栏入口按 EXEC-08A Q1 冻结（WorkspaceNavigation 里没有这一项）：侧栏必须**没有**它，
    // 真实入口是文章页头的「管理分类」按钮；/#categories 直开仍走上面每个宽度的面板检查。
    { name: '文章分类', hash: '#categories', panel: 'categoriesPanel', sidebar: null, frozenSidebar: 'categories', entry: { from: '#content-library', button: 'clManageCategoriesButton' }, shell: 'workbench' },
    { name: '自动化任务', hash: '#automation', panel: 'automationTasksPanel', sidebar: 'automation', shell: 'workbench' },
    { name: '账户与用量', hash: '#billing-overview', panel: 'billingOverviewPanel', sidebar: 'billing-overview', shell: 'workbench' },
    // 账号安全（计划 §9.5 注销入口）：这一页有不可逆操作，排版与触屏口径都要真量。
    // preExistingSectionSize：这一页卡片 <h3> 一直是全局 h3 语义档（20px/500），
    // 与本壳"区域标题"档 16px/600 不同 —— 见下面 sectionSizes 判定处的说明。
    { name: '账号安全', hash: '#account-security', panel: 'accountSecurityPanel', sidebar: 'account-security', shell: 'workbench', preExistingSectionSize: true },
    // ?preview-platform-admin：运营台预览默认是租户 admin（会停在守卫态），这个开关只是把
    // 本地 fixture 的样例身份换成 platform_admin，好让新接线的两个分区真的渲染出数据。
    { name: '运营台审计日志', path: '/platform/ops/?preview-platform-admin', hash: '#sec-audit', panel: 'sec-audit', sidebar: null, shell: 'platform-ops' },
    { name: '运营台安全设置', path: '/platform/ops/?preview-platform-admin', hash: '#sec-security', panel: 'sec-security', sidebar: null, shell: 'platform-ops' },
    // 对账待办（计划 §9.6）：不可逆的"资金判断"入口，排版/触屏/按钮档位同样要真量。
    { name: '运营台对账待办', path: '/platform/ops/?preview-platform-admin', hash: '#sec-reconcile', panel: 'sec-reconcile', sidebar: null, shell: 'platform-ops' },
];

/**
 * 两个壳各有自己的设计系统与档位，不能拿一套标准量另一个：
 *   - 工作台（geo-entry.css / geo-workspace-ui.css）：页面标题 24px/600 与区域标题 16px/600
 *     （出处：docs/ui-review/2026-09-10-global-typography.md 的全局排版定稿）；按钮档位
 *     button / primary / secondary / ghost / danger-ghost / danger / small。
 *   - 运营台（geo-platform-ops.css，独立壳）：标题 16px/600；按钮是它自己的 .btn 体系
 *     （btn / ghost / danger-ghost / danger-fill / primary-fill / small）。
 */
const SHELLS = {
    workbench: {
        titleSize: '24px/600',
        sectionSize: '16px/600',
        // md-btn--danger 是 geo-base.css 里**已存在**的设计系统档位（实心红，主题包模块一直在用），
        // 2026-09-21 起账号安全页的「提交注销申请」也用它。登记进来，避免把"复用一个既有档位"
        // 误判成"某个面板自己发明了新按钮"。注意：密集列表里的删除动作仍走 ghost 危险色
        // （见 geo-entry.css 里 .md-btn--danger 那条注释），实心红只留给"整页唯一且不可逆"的动作。
        // 另外两个是本页组件级按钮样式（不是新档位）：密码框右侧的显示/隐藏眼睛按钮
        // （.account-password-visibility）与「忘记密码？」文字按钮（.account-text-link）。
        // 它们随账号安全页一起存在，只是这一页到 2026-09-21 才被纳入按钮档位检查。
        // 工作台里**并存两套按钮词汇**，这套检查要同时认：
        //   1. 旧的 `button primary/secondary/small`（URL 导入等五个管理面板）；
        //   2. `md-btn` 设计系统族（EXEC-09A 起外观统一收在 geo-components.css 的 A2 段：
        //      四种外观 primary / secondary / ghost / danger × 两档尺寸 默认 36 / --sm 30，外加宽度修饰 --full；
        //      md-btn--soft 已随 A2 迁移删除，tests/js/ui-components.test.mjs 钉着它不许回来）。
        //      下面这七个是 geo-components.css 里**全部** md-btn 类，多一个都要先改组件层。
        // 第一行旧词汇也只认 A2 段里已映射到四外观两尺寸的那几个。
        // 最后两个是本页组件级样式（密码眼睛按钮、忘记密码文字按钮），不是档位。
        buttonClasses: [
            'button', 'primary', 'secondary', 'ghost', 'small', 'danger', 'danger-ghost',
            'md-btn', 'md-btn--sm', 'md-btn--full', 'md-btn--primary', 'md-btn--secondary', 'md-btn--ghost', 'md-btn--danger',
            'account-password-visibility', 'account-text-link',
        ],
        tableWrapper: '.cl-tbl-scroll',
        // 页标题：面板自带的（自带标题的面板已把共享标题栏隐藏），否则取共享标题栏。
        titleSelector: ':scope > .view-title :is(h1, h2), :scope > .panel-heading :is(h1, h2)',
        sharedTitleSelector: '.workspace-view-titlebar :is(h1, h2)',
    },
    'platform-ops': {
        titleSize: '16px/600',
        sectionSize: null,
        buttonClasses: ['btn', 'ghost', 'small', 'danger-ghost', 'danger-fill', 'primary-fill', 'primary'],
        tableWrapper: '.tbl-wrap',
        // 运营台每个分区自己的标题（它没有工作台那套共享标题栏）。
        titleSelector: ':scope > .panel-head :is(h1, h2), :scope > .section-title :is(h1, h2)',
        sharedTitleSelector: null,
    },
};

// 触屏 44px 属**全站口径**（R18 + 侧栏补充：触屏统一至少 44px）。本批只负责记录实测值与
// 全站基线，不让它把"本批有没有引入回归"这件事淹掉 —— 所以单列 advisories。
const TOUCH_BASELINE_VIEWS = ['asset', 'content-library', 'publishing', 'sensitive-words', 'platform', 'usage'];

// 白名单与组件层对账：geo-components.css 定义的 md-btn 类必须与工作台白名单里的 md-btn 类逐个相等。
// 组件层加了外观而白名单没跟（或白名单里留着已删的外观），这里先红 —— 免得白名单再次悄悄过期。
{
    const componentCss = fs.readFileSync(new URL('../public/geo-components.css', import.meta.url), 'utf8');
    const defined = [...new Set(componentCss.match(/\.md-btn(?:--[a-z-]+)?\b/g).map((c) => c.slice(1)))].sort();
    const listed = SHELLS.workbench.buttonClasses.filter((c) => c.startsWith('md-btn')).sort();
    if (defined.join(',') !== listed.join(',')) {
        console.error(`按钮白名单与 geo-components.css 不一致：组件层 ${defined.join('/')}；白名单 ${listed.join('/')}`);
        process.exit(1);
    }
}
const WEIGHT_MEDIUM = '500';
const WEIGHT_NORMAL = '400';

const version = await (await fetch(`${cdpEndpoint}/json/version`)).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
let messageId = 0;
const pending = new Map();
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);
    }
});
await new Promise((resolve) => socket.addEventListener('open', resolve));

const send = (method, params = {}, sessionId) =>
    new Promise((resolve, reject) => {
        const id = ++messageId;
        pending.set(id, (message) => (message.error ? reject(new Error(`${method}: ${JSON.stringify(message.error)}`)) : resolve(message.result)));
        socket.send(JSON.stringify({ id, method, params, sessionId }));
    });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, sessionId);
await send('Runtime.enable', {}, sessionId);

const pageErrors = [];
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.method === 'Runtime.exceptionThrown' && message.sessionId === sessionId) {
        pageErrors.push(message.params?.exceptionDetails?.text ?? 'unknown');
    }
});

// 事件处理器里抛出的异常有两个去向：同步的会变成 Runtime.exceptionThrown，
// **async 处理器里的会变成 unhandledrejection** —— 后者不会被上面那个监听看到。
// 2026-09-21 的变异验证就是栽在这里：把 `toast` 改成未定义的 `clToast` 后，
// 界面照样显示"已改名"，异常只是变成一条没人看的 rejection。
// 所以在每个新文档里装一个兜底监听，把两类错误都收进 window.__geoUiErrors。
await send(
    'Page.addScriptToEvaluateOnNewDocument',
    {
        source: `
          window.__geoUiErrors = [];
          window.addEventListener('error', (e) => window.__geoUiErrors.push('error: ' + (e.message || '') + ' @' + (e.filename || '') + ':' + (e.lineno || '')));
          window.addEventListener('unhandledrejection', (e) => {
            const r = e.reason || {};
            window.__geoUiErrors.push('rejection: ' + (r.stack || r.message || String(r)));
          });
          // CSP 违规也算"页面出错"。
          // 为什么必须单独听：被 CSP 拦下的内联脚本**不会**抛 error、也不会进 unhandledrejection ——
          // 它只是静默地不执行（最坏就是白页），只在控制台留一条 "Refused to execute inline script"。
          // 2026-09-21 起预览沙盘按强制模式下发策略（见 preview-workspace-ui.php），
          // 所以这条监听就是"漏挂 nonce"的判据：有违规 → 这个视图直接算失败。
          document.addEventListener('securitypolicyviolation', (e) => {
            window.__geoUiErrors.push('csp: ' + (e.violatedDirective || '') + ' blocked=' + (e.blockedURI || '') + ' @' + (e.sourceFile || '') + ':' + (e.lineNumber || ''));
          });
        `,
    },
    sessionId,
);

const evaluate = async (expression) => {
    const { result, exceptionDetails } = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId);
    if (exceptionDetails) {
        throw new Error(
            `evaluate failed: ${exceptionDetails.exception?.description ?? exceptionDetails.text ?? 'unknown'}\n` +
            `expression head: ${expression.slice(0, 120).replace(/\s+/g, ' ')}`,
        );
    }

    return result.value;
};

const setViewport = (width) =>
    send(
        'Emulation.setDeviceMetricsOverride',
        { width, height: 1000, deviceScaleFactor: 1, mobile: width <= 768 },
        sessionId,
    );

const waitForPanel = async (panelId, timeoutMs = 6000) => {
    const started = Date.now();
    while (Date.now() - started < timeoutMs) {
        const ready = await evaluate(`(() => {
            const panel = document.getElementById(${JSON.stringify(panelId)});
            if (!panel) return 'missing';
            if (panel.hidden) return 'hidden';
            panel.scrollIntoView({ block: 'start' });

            return 'visible';
        })()`);
        if (ready === 'visible') return true;
        if (ready === 'missing') return false;
        await new Promise((resolve) => setTimeout(resolve, 120));
    }

    return false;
};

/** 页面上量到的全部事实；判定放在 Node 侧，便于输出可读的违例清单。 */
const collect = (panelId, tableWrapper, titleSelectors = {}) =>
    evaluate(`(() => {
      const panel = document.getElementById(${JSON.stringify(panelId)});
      if (!panel) return null;
      const wrapper = ${JSON.stringify(tableWrapper)};
      const titleSelector = ${JSON.stringify(titleSelectors.title ?? null)};
      const sharedTitleSelector = ${JSON.stringify(titleSelectors.shared ?? null)};
      const weights = (selector) => [...new Set([...panel.querySelectorAll(selector)]
        .filter((el) => el.offsetParent !== null || el.getClientRects().length > 0)
        .map((el) => getComputedStyle(el).fontWeight))];
      const headingSizes = (selector) => [...new Set([...panel.querySelectorAll(selector)]
        .filter((el) => el.getClientRects().length > 0)
        .map((el) => getComputedStyle(el).fontSize + '/' + getComputedStyle(el).fontWeight))];
      // 本页标题：面板里第一个**可见、且不是区域标题**的 h1/h2（区域标题在 .panel-head 里，
      // 设计系统里那档是 16px）。各面板标题的容器类并不统一（.view-title / .panel-heading /
      // .billing-page-header …），按"是不是区域标题"排除比逐个列容器类可靠。
      // 面板自己没有标题时退回共享标题栏 .workspace-view-titlebar（自带标题的面板会把它隐藏）。
      const ownTitle = [...panel.querySelectorAll('h1, h2')]
        .find((el) => el.getClientRects().length > 0 && !el.closest('.panel-head'));
      const sharedTitle = sharedTitleSelector
        ? [...document.querySelectorAll(sharedTitleSelector)].find((el) => el.getClientRects().length > 0)
        : null;
      const titleEl = ownTitle || sharedTitle;
      const titleFrom = ownTitle ? 'panel' : (sharedTitle ? 'titlebar' : null);
      const tables = [...panel.querySelectorAll('table')];
      const unwrappedTables = tables.filter((t) => !t.closest(wrapper)).length;
      const scrollers = [...panel.querySelectorAll(wrapper)].map((el) => ({
        overflowX: getComputedStyle(el).overflowX,
        scrollable: el.scrollWidth > el.clientWidth,
      }));
      const buttonClasses = [...new Set([...panel.querySelectorAll('button')].flatMap((b) => [...b.classList]))];
      // "面板是不是真的渲染出内容了"：预览用隔离 fixture 提供数据，空壳说明接线断了
      // （§5 验收标准第一条要的就是"从入口进去能看到这个功能"，不是"面板标签存在"）。
      const visibleRows = [...panel.querySelectorAll('tbody tr, .ledger-row, .metric-card, .risk-item, .grid > *')]
        .filter((el) => el.getClientRects().length > 0).length;
      const visibleControls = [...panel.querySelectorAll('input, select, textarea, button')]
        .filter((el) => el.getClientRects().length > 0).length;

      return {
        visibleRows,
        visibleControls,
        labelWeights: weights('label'),
        fieldWeights: weights(':is(input, textarea, select)'),
        hintWeights: weights(':is(.hint, .form-message, .at-form-error)'),
        titleSize: titleEl ? getComputedStyle(titleEl).fontSize + '/' + getComputedStyle(titleEl).fontWeight : null,
        titleText: titleEl ? (titleEl.textContent ?? '').trim().slice(0, 20) : null,
        titleFrom,
        sectionSizes: headingSizes('h3'),
        tableCount: tables.length,
        unwrappedTables,
        scrollers,
        buttonClasses,
        pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
        panelWidth: Math.round(panel.getBoundingClientRect().width),
        innerWidth: window.innerWidth,
      };
    })()`);

/** 侧栏入口：必须有且只有一个，点一下要能把对应面板切出来。 */
const clickSidebar = (view) =>
    evaluate(`(() => {
      const links = [...document.querySelectorAll('[data-view-target="${view}"]')];
      if (links.length !== 1) return { links: links.length };
      links[0].click();

      return { links: 1 };
    })()`);

const failures = [];
const advisories = [];
const report = [];

for (const view of VIEWS) {
    for (const width of WIDTHS) {
        await setViewport(width);
        await send('Page.navigate', { url: `${base}${view.path ?? '/'}${view.hash}` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 900));

        const visible = await waitForPanel(view.panel);
        if (!visible) {
            failures.push(`${view.name} @${width}px：面板 #${view.panel} 没渲染出来`);
            report.push({ view: view.name, width, panelVisible: false });
            continue;
        }

        const facts = await collect(view.panel, SHELLS[view.shell].tableWrapper, {
            title: SHELLS[view.shell].titleSelector,
            shared: SHELLS[view.shell].sharedTitleSelector,
        });
        if (!facts) {
            failures.push(`${view.name} @${width}px：取不到面板事实`);
            continue;
        }

        const entry = { view: view.name, width, ...facts };
        report.push(entry);

        const at = `${view.name} @${width}px`;
        const shell = SHELLS[view.shell];

        if (facts.visibleRows === 0 && facts.visibleControls === 0) {
            failures.push(`${at}：面板是空壳（没有可见的内容行，也没有可见控件）—— 接线可能断了`);
        }
        if (facts.pageOverflow > 1) failures.push(`${at}：整页横向溢出 ${facts.pageOverflow}px（scrollWidth ${facts.innerWidth + facts.pageOverflow} > ${facts.innerWidth}）`);
        if (facts.labelWeights.some((w) => w !== WEIGHT_MEDIUM)) failures.push(`${at}：label 字重 ${facts.labelWeights.join('/')}（应为 ${WEIGHT_MEDIUM}）`);
        if (facts.fieldWeights.some((w) => w !== WEIGHT_NORMAL)) failures.push(`${at}：输入控件字重 ${facts.fieldWeights.join('/')}（应为 ${WEIGHT_NORMAL}）`);
        if (facts.hintWeights.some((w) => w !== WEIGHT_NORMAL)) failures.push(`${at}：帮助文字字重 ${facts.hintWeights.join('/')}（应为 ${WEIGHT_NORMAL}）`);
        if (facts.titleSize && facts.titleSize !== shell.titleSize) {
            const message = `${at}：本壳页标题档位 ${facts.titleSize}（应为 ${shell.titleSize}）`;
            // 第十六批之前就存在的自有档位（如财务面 20px）单列观察项，不算本批失败。
            if (view.preExistingTitle) advisories.push(`${message} —— 该档位在第十六批之前就存在，需产品决定是否统一`);
            else failures.push(message);
        }
        if (shell.sectionSize && facts.sectionSizes.length && facts.sectionSizes.some((s) => s !== shell.sectionSize)) {
            const message = `${at}：本壳区域标题档位 ${facts.sectionSizes.join('/')}（应为 ${shell.sectionSize}）`;
            // 与页标题同源的问题（preExistingTitle 那条判据的先例）：账号安全页卡片的 <h3>
            // 吃的是全局 h3 语义档（geo-typography.css 的 `body.geo-app h3` = 500 20px，
            // 选择器权重比页面里的 `.account-security h3` 更高，所以页面那条覆盖不生效）。
            // 2026-09-21 首次把这一页纳入检查时实测：**两张老卡片 + 新加的注销卡**都是 20px/500，
            // 也就是说这一页从来如此，不是本批引入的。按仓库既有做法记成观察项，
            // 等"标题档位统一"那一批一起定，不在功能批里悄悄改版式。
            if (view.preExistingSectionSize) advisories.push(`${message} —— 该档位在这一页一直如此，需产品决定是否统一`);
            else failures.push(message);
        }
        if (facts.unwrappedTables > 0) failures.push(`${at}：${facts.unwrappedTables} 张表没套本壳的滚动容器（${shell.tableWrapper}）`);
        if (facts.scrollers.some((s) => !['auto', 'scroll'].includes(s.overflowX))) {
            failures.push(`${at}：${shell.tableWrapper} 的 overflow-x 不是 auto/scroll（${facts.scrollers.map((s) => s.overflowX).join('/')}）`);
        }
        const badButtons = facts.buttonClasses.filter((c) => !shell.buttonClasses.includes(c));
        if (badButtons.length) failures.push(`${at}：本壳按钮长了新档位 ${badButtons.join('/')}`);

        if (screenshotDir && [1440, 900, 390].includes(width)) {
            const { data } = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
            fs.mkdirSync(screenshotDir, { recursive: true });
            fs.writeFileSync(`${screenshotDir}/${view.hash.replace(/[^a-z0-9]/gi, '')}-${width}.png`, Buffer.from(data, 'base64'));
        }
    }

    if (view.sidebar) {
        await setViewport(1440);
        await send('Page.navigate', { url: `${base}/#projects` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 800));
        const clicked = await clickSidebar(view.sidebar);
        if (clicked.links !== 1) {
            failures.push(`${view.name}：侧栏入口 [data-view-target="${view.sidebar}"] 有 ${clicked.links} 个（应为 1）`);
        } else {
            const switched = await waitForPanel(view.panel);
            if (!switched) failures.push(`${view.name}：点侧栏入口后 #${view.panel} 没切出来`);
        }
    }

    // 冻结的侧栏入口：不能复活（全文档不得有 SPA 目标，侧栏/设置菜单里不得有可见链接），
    // 但功能本身仍要从它现行的真实入口点得进去 —— 与上面"唯一入口 + 点了能切出来"同强度。
    if (view.frozenSidebar) {
        await setViewport(1440);
        await send('Page.navigate', { url: `${base}/#projects` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 800));
        const frozen = await evaluate(`(() => {
          const view = ${JSON.stringify(view.frozenSidebar)};
          const targets = document.querySelectorAll('[data-view-target="' + view + '"]').length;
          const visibleMenuLinks = [...document.querySelectorAll('.workspace-sidebar a, #workspaceMenu a, #settingsMenu a')]
            .filter((a) => /^\\/?#/.test(a.getAttribute('href') || '') && (a.getAttribute('href') || '').replace(/^\\/?#/, '') === view)
            .filter((a) => a.getClientRects().length > 0).length;

          return { targets, visibleMenuLinks };
        })()`);
        if (frozen.targets !== 0) failures.push(`${view.name}：冻结的侧栏入口复活了 —— [data-view-target="${view.frozenSidebar}"] 有 ${frozen.targets} 个（应为 0）`);
        if (frozen.visibleMenuLinks !== 0) failures.push(`${view.name}：侧栏/设置菜单里出现了 ${frozen.visibleMenuLinks} 个可见的 #${view.frozenSidebar} 链接（冻结入口应不可见）`);
    }
    if (view.entry) {
        await setViewport(1440);
        await send('Page.navigate', { url: `${base}/${view.entry.from}` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 900));
        const clicked = await evaluate(`(() => {
          const buttons = [...document.querySelectorAll('#' + ${JSON.stringify(view.entry.button)})];
          if (buttons.length !== 1) return { buttons: buttons.length };
          if (!buttons[0].getClientRects().length) return { buttons: 1, visible: false };
          buttons[0].click();

          return { buttons: 1, visible: true };
        })()`);
        if (clicked.buttons !== 1) failures.push(`${view.name}：现行入口 #${view.entry.button} 有 ${clicked.buttons} 个（应为 1）`);
        else if (!clicked.visible) failures.push(`${view.name}：现行入口 #${view.entry.button} 在 ${view.entry.from} 上不可见`);
        else if (!(await waitForPanel(view.panel))) failures.push(`${view.name}：点现行入口 #${view.entry.button} 后 #${view.panel} 没切出来`);
    }
}

if (pageErrors.length) failures.push(`页面抛出 ${pageErrors.length} 个 JS 异常：${pageErrors.slice(0, 3).join(' | ')}`);

// 租户 API 令牌入口已冻结。若日后重新提供该面板，下面的权限、触屏和吊销检查会自动恢复。
await send('Page.navigate', { url: `${base}/#projects` }, sessionId);
await new Promise((resolve) => setTimeout(resolve, 900));
const tokenSurfacePresent = await evaluate(`Boolean(document.getElementById('apiTokenRows'))`);

/**
 * 触屏点击范围：R18「触屏下至少 44px」（2026-09-21 起是**判据**，不再只是观察项）。
 *
 * 用 CDP 打开触摸模拟（`Emulation.setTouchEmulationEnabled`），`(pointer: coarse)` 才会命中；
 * 桌面鼠标的渲染不受影响 —— 这正是不用"再切一个宽度断点"的原因。
 *
 * 三类豁免：
 *   1. `input[type=hidden]`：没有可点区域；
 *   2. 复选框/单选框：量它所属 `<label>` 的高度（真正的点击目标是那一行）；
 *   3. `.small` 之外的例外一律不放行 —— 小按钮也是按钮，触屏一样要够得着。
 */
const TOUCH_VIEWS = [
    ...['projects', 'skill', 'asset', 'content-library', 'categories', 'materials', 'automation', 'url-imports', 'knowledge', 'publishing', 'analytics', 'usage', ...(tokenSurfacePresent ? ['api-tokens'] : []), 'aikeys', 'sensitive-words', 'skill-prompts', 'platform', 'account-security']
    // ⚠️ 作用域选择器**不能写 `section.panel[...]`**：项目页与账号安全页的根节点没有 `panel`
    //    类（它们自带布局），选择器落空 → 这一条检查在下面 `if (!scope) continue` 处**静默跳过**。
    //    2026-09-21 实测：`#projects` 的触屏检查从来没真跑过（列表里有它、却一条都没量）。
        .map((view) => ({ name: `#${view}`, url: `${base}/#${view}`, scope: 'section[data-workspace-view]' })),
    // 项目目录屏（#projects/list）：目录的筛选与行内按钮只在这一屏出现，所以必须单独量一次 ——
    // 只测 #projects（详情屏）时，这些控件都在隐藏面板里，一个都测不到。
    { name: '#projects/list', url: `${base}/#projects/list`, scope: 'section[data-workspace-view]' },
    // 2026-10-01 起回收站搬到独立的全局回收站页（#projects/trash），目录屏上它是隐藏的 ——
    // 只量 #projects/list 就量不到桶内那些按钮了，所以这一屏也要单独量。
    { name: '#projects/trash', url: `${base}/#projects/trash`, scope: 'section[data-workspace-view]' },
    { name: '运营台', url: `${base}/platform/ops/?preview-platform-admin`, scope: 'body' },
    { name: '可见性看板', url: `${base}/visibility/?project_id=901`, scope: 'body' },
    { name: '爬虫统计', url: `${base}/analytics/`, scope: 'body' },
    { name: '手动发布', url: `${base}/manual-publish/`, scope: 'body' },
    { name: '公开文章', url: `${base}/read-preview/`, scope: 'body' },
];

const touchResults = [];
const touchViolations = [];

for (const width of [390, 768, 1024]) {
    await setViewport(width);
    for (const target of TOUCH_VIEWS) {
        await send('Page.navigate', { url: target.url }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 450));
        // ⚠️ 顺序是实测出来的：换视口（setDeviceMetricsOverride）与**每一次文档导航**都会把触摸模拟重置，
        //    所以必须"导航之后、测量之前"重新打开 —— 否则 (pointer: coarse) 一直是 false，
        //    这一整段检查会静默地什么都没测（下面那条 coarse 断言就是防它的）。
        await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 150));
        const raw = await evaluate(`JSON.stringify((() => {
          const scope = ${JSON.stringify(target.scope)} === 'body'
            ? document.body
            : [...document.querySelectorAll(${JSON.stringify(target.scope)})].find((s) => !s.hidden);
          if (!scope) return null;
          const coarse = matchMedia('(pointer: coarse)').matches;
          const tooSmall = [];

          // ⚠️ 2026-09-21 全站 44px 统一：把 summary（折叠标题）、role=button/tab、
          //    分段控件、报表标题链接一并纳入 —— 它们此前都不在口径里，靠人眼根本查不出来。
          for (const el of scope.querySelectorAll('button, input, select, textarea, summary, [role="button"], [role="tab"], a.button, a[role="button"], .filter-chip, .btn, .link-btn, .md-btn, .md-seg, .segmented-item, .sw-chip-del, .article-title-link, .report-title-link, .report-row-download, .format-link')) {
            if (el.getClientRects().length === 0) continue;
            // 正文里的**行内链接**放行（WCAG 2.5.8 的 inline exception）：段落中的链接
            // 强撑到 44px 会毁掉整段排版，那是拿另一个错误换这一个。
            // 判据是"浏览器算出来的 display" —— 表格行动作/卡片链接是 flex/block，照样要量。
            if (el.tagName === 'A' && getComputedStyle(el).display === 'inline') continue;
            const type = String(el.getAttribute('type') || '').toLowerCase();
            if (type === 'hidden') continue;
            // 蜜罐字段：对机器不可见、对键盘不可达（aria-hidden + tabindex=-1 + 挪到屏外），
            // 不是给人点的目标，要求它 ≥44px 没有意义。
            if (el.getAttribute('aria-hidden') === 'true' || el.tabIndex === -1) continue;

            // 复选框/单选框量所属 label：那一行才是真正的点击目标。
            const measured = (type === 'checkbox' || type === 'radio') ? (el.closest('label') || el) : el;
            const height = Math.round(measured.getBoundingClientRect().height);
            if (height > 0 && height < 44) {
              tooSmall.push({
                sel: el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).trim().split(/\\s+/).slice(0, 2).join('.') : '') + (type ? '[' + type + ']' : ''),
                h: height,
              });
            }
          }
          if (!tooSmall.length) return { coarse, ok: true };
          const byKey = {};
          tooSmall.forEach((x) => { byKey[x.sel] = byKey[x.sel] || { n: 0, min: 99 }; byKey[x.sel].n++; byKey[x.sel].min = Math.min(byKey[x.sel].min, x.h); });
          return { coarse, ok: false, count: tooSmall.length, byKey };
        })())`);
        const parsed = raw ? JSON.parse(raw) : null;
        if (!parsed) continue;
        if (!parsed.coarse) {
            touchViolations.push(`${target.name} @${width}px：触摸模拟没生效，(pointer: coarse) 为 false —— 这条检查等于没跑`);
            continue;
        }
        touchResults.push({ view: target.name, width, under44: parsed.ok ? 0 : parsed.count, detail: parsed.ok ? null : parsed.byKey });
        if (!parsed.ok) {
            const detail = Object.entries(parsed.byKey).map(([k, v]) => `${k}×${v.n}(min ${v.min})`).join('  ');
            touchViolations.push(`${target.name} @${width}px（触屏）：${parsed.count} 个控件低于 44px —— ${detail}`);
        }
        // 触屏证据截图（只挑 390 宽 + 三个新入口），别的组合不落盘，免得刷屏。
        if (screenshotDir && width === 390 && ['#url-imports', '#categories', '#api-tokens', '#sensitive-words', '#asset'].includes(target.name)) {
            const { data } = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
            fs.mkdirSync(screenshotDir, { recursive: true });
            fs.writeFileSync(`${screenshotDir}/touch${target.name.replace(/[^a-z0-9]/gi, '')}-390.png`, Buffer.from(data, 'base64'));
        }
    }
}

await send('Emulation.setTouchEmulationEnabled', { enabled: false }, sessionId);
failures.push(...touchViolations);

/**
 * 桌面回归：确认触屏那一层 `!important` 没有漏到桌面。
 *
 * 触屏下限是靠 `@media (pointer: coarse)` + `!important` 压住各壳 34/30px 紧凑档的，
 * 最容易出的错就是它顺手把桌面也放大 —— 那会改动已验收的桌面版式。
 * 这里在最常用的 1440px + 无触摸模拟下量基准高度，与改动前的已知值逐个对。
 */
// expected 取自**现行组件规格**（EXEC-09A，geo-components.css）：按钮默认 36 / 紧凑档 30（A2 段
// `.md-btn--sm, .button.small`），筛选药丸 30（A3 段 `.md-chip, .filter-chip`），输入框 40。
// 旧值 34/34/41/40 是 EXEC-09A 之前的实测，组件层统一后已不成立。
// 同一元素再开触摸模拟量一次：桌面必须是精确规格值，触屏必须 ≥44 —— 两层都要成立才算"区分开了"。
const DESKTOP_BASELINE = [
    { view: 'skill', selector: '.button', expected: 30, classes: ['button', 'small'], spec: '紧凑档 .button.small = 30（面板里第一个按钮）' },
    { view: 'asset', selector: '.filter-chip', expected: 30, spec: '筛选药丸 = 30' },
    { view: 'url-imports', selector: 'input', expected: 40, spec: '输入框 = 40' },
    { view: 'url-imports', selector: '.button.primary', expected: 36, classes: ['button', 'primary'], forbidClasses: ['small'], spec: '默认档按钮 = 36' },
];
const TOUCH_FLOOR = 44;
const desktopProbe = [];
await setViewport(1440);
for (const item of DESKTOP_BASELINE) {
    const probe = { view: item.view, selector: item.selector, expected: item.expected, spec: item.spec };
    for (const touch of [false, true]) {
        await send('Page.navigate', { url: `${base}/#${item.view}` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 600));
        // 与上面触屏检查同一顺序：导航之后、测量之前才开触摸模拟（导航会把它重置）。
        await send('Emulation.setTouchEmulationEnabled', touch ? { enabled: true, maxTouchPoints: 5 } : { enabled: false }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 150));
        const measured = await evaluate(`(() => {
          const panel = [...document.querySelectorAll('section[data-workspace-view]')].find((s) => !s.hidden);
          if (!panel) return null;
          const el = [...panel.querySelectorAll(${JSON.stringify(item.selector)})].find((el) => el.getClientRects().length > 0);
          if (!el) return null;

          return { height: Math.round(el.getBoundingClientRect().height), coarse: matchMedia('(pointer: coarse)').matches, classes: [...el.classList] };
        })()`);
        const label = `${touch ? '触屏' : '桌面'}回归（${item.view} ${item.selector}）`;
        if (!measured) {
            failures.push(`${label}：1440px 下找不到被测元素 —— 这条基线等于没量`);
            break;
        }
        probe[touch ? 'touch' : 'desktop'] = measured;
        if ((item.classes || []).some((c) => !measured.classes.includes(c)) || (item.forbidClasses || []).some((c) => measured.classes.includes(c))) {
            failures.push(`${label}：量到的元素是 .${measured.classes.join('.')}，不是规格对应的档位（${item.spec}）`);
        }
        if (measured.coarse !== touch) {
            failures.push(`${label}：(pointer: coarse) 为 ${measured.coarse}，测量环境不对`);
        } else if (!touch && measured.height !== item.expected) {
            failures.push(`${label}：桌面高度 ${measured.height}px，现行规格是 ${item.expected}px（${item.spec}）—— 触屏下限漏到了桌面，或组件档位漂了`);
        } else if (touch && measured.height < TOUCH_FLOOR) {
            failures.push(`${label}：触屏高度 ${measured.height}px，低于 ${TOUCH_FLOOR}px —— 触屏下限没有覆盖到这一档`);
        }
    }
    desktopProbe.push(probe);
}
await send('Emulation.setTouchEmulationEnabled', { enabled: false }, sessionId);

/**
 * 观察项：工作台各页标题的实际档位。
 *
 * 全局排版层把 `body.geo-app :is(h1,h2)` 定成 24px/600，但工作台里还有几条**更早的**
 * 局部规则把个别页标题压到 20px（财务面的 `.billing-page-header h2`、素材/内容库的
 * `.view-title h1`、`workspace-view-titlebar h2`）。本批不去动这些老页面，先把实测值列出来，
 * 让"要不要统一"变成一个可以用数据决定的问题。
 */
const titleBaseline = [];
await setViewport(1440);
for (const view of ['projects', 'skill', 'asset', 'content-library', 'materials', 'automation', 'knowledge', 'publishing', 'analytics', 'billing-overview', 'billing-plan', 'aikeys', 'sensitive-words', 'skill-prompts', 'platform', 'url-imports', 'categories', 'api-tokens']) {
    await send('Page.navigate', { url: `${base}/#${view}` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 450));
    const measured = await evaluate(`JSON.stringify((() => {
      const panel = [...document.querySelectorAll('section[data-workspace-view]')].find((s) => !s.hidden);
      if (!panel) return null;
      const own = [...panel.querySelectorAll('h1, h2')]
        .find((el) => el.getClientRects().length > 0 && !el.closest('.panel-head'));
      const shared = [...document.querySelectorAll('.workspace-view-titlebar :is(h1, h2)')]
        .find((el) => el.getClientRects().length > 0);
      const el = own || shared;

      return el ? { panel: panel.id, from: own ? 'panel' : 'titlebar', text: (el.textContent ?? '').trim().slice(0, 16),
        size: getComputedStyle(el).fontSize + '/' + getComputedStyle(el).fontWeight } : null;
    })())`);
    // 记 null 也要留一行：那代表"这一页没有独立页标题"（例如项目管理用顶栏），
    // 不能当成"标题档位不对"。
    const parsed = measured ? JSON.parse(measured) : null;
    titleBaseline.push(parsed ? { view, ...parsed } : { view, title: 'none' });
}

const nonStandardTitles = titleBaseline.filter((t) => t.size && t.size !== '24px/600');

/**
 * 流程级验收（点一遍才算数，光量样式不够）。
 *
 *   1. F08 动态技能输入：选技能 → 按 brief_fields 长出输入项，且命名空间与技能面板不撞 id；
 *   2. R29/R30 错误态：读不到（403）要如实说，**不能**渲染成"暂无数据"或 0。
 */
const flows = [];

/** 每个 new document 都重新计数；跑完流程后取一次，任何一条都算失败。 */
const uiErrors = async () => {
    const list = await evaluate(`(window.__geoUiErrors || []).slice(0, 5)`);

    return Array.isArray(list) ? list : [];
};

/**
 * 每条流程跑完就取一次窗口错误 —— 有就判失败。
 *
 * 为什么要单列一条判据：静态断言只看得到"名字有没有解构"，
 * 而 async 事件处理器里抛的 ReferenceError 不会让页面白屏、也不会写进消息槽，
 * 它只变成一条没人看的 unhandledrejection（变异验证时正是这样漏掉的）。
 */
const assertNoUiErrors = async (label) => {
    const list = await uiErrors();
    if (list.length) failures.push(`${label}：窗口抛了 ${list.length} 个 JS 异常 —— ${list.slice(0, 2).join(' | ')}`);

    return list;
};

await setViewport(1440);
{
    await send('Page.navigate', { url: `${base}/#automation` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1200));

    const dynamic = await evaluate(`(async () => {
      const panel = document.getElementById('automationTasksPanel');
      const openButton = panel?.querySelector('#atNewTaskButton, [data-at-new], .panel-head button');
      if (openButton) openButton.click();
      await new Promise((r) => setTimeout(r, 200));
      const select = document.getElementById('automation-skill_id');
      if (!select) return { error: '找不到技能选择框' };
      select.value = 'yao-geo-explainer-builder';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      await new Promise((r) => setTimeout(r, 200));

      const row = document.getElementById('atDynamicFieldRow');
      const host = document.getElementById('atDynamicFields');
      const controls = host ? [...host.querySelectorAll('input, select, textarea')] : [];
      const ids = host ? [...host.querySelectorAll('[id]')].map((el) => el.id) : [];
      const duplicateIds = ids.filter((id) => document.querySelectorAll('#' + CSS.escape(id)).length > 1);
      const labelWeights = host ? [...new Set([...host.querySelectorAll('label')].map((el) => getComputedStyle(el).fontWeight))] : [];
      const fieldWeights = host ? [...new Set(controls.map((el) => getComputedStyle(el).fontWeight))] : [];

      return {
        rowVisible: row ? !row.hidden : false,
        controlCount: controls.length,
        names: controls.map((el) => el.name).slice(0, 4),
        ids: ids.slice(0, 6),
        // name 保持技能字段原键（后端要按原键读 input_data）；命名空间落在 id 上，
        // 目的是跟技能面板的同名字段撞车（label[for]/aria 会绑错控件）。
        allIdsNamespaced: ids.length > 0 && ids.every((id) => id.startsWith('atBrief')),
        duplicateIds,
        labelWeights,
        fieldWeights,
      };
    })()`);
    flows.push({ flow: 'F08 动态技能输入（选技能后按 brief_fields 生成）', ...dynamic });
    if (dynamic.error) failures.push(`F08 动态技能输入：${dynamic.error}`);
    else {
        if (!dynamic.rowVisible) failures.push('F08 动态技能输入：选了技能但技能输入区没出现');
        if (dynamic.controlCount === 0) failures.push('F08 动态技能输入：技能输入区没有任何控件');
        if (!dynamic.allIdsNamespaced) failures.push(`F08 动态技能输入：动态字段 id 没有统一 atBrief 前缀（${dynamic.ids.join('/')}）`);
        if (dynamic.duplicateIds.length) failures.push(`F08 动态技能输入：与页面其它元素撞 id（${dynamic.duplicateIds.join('/')}）`);
        if (dynamic.labelWeights.some((w) => w !== WEIGHT_MEDIUM)) failures.push(`F08 动态技能输入：动态 label 字重 ${dynamic.labelWeights.join('/')}（应为 ${WEIGHT_MEDIUM}）`);
        if (dynamic.fieldWeights.some((w) => w !== WEIGHT_NORMAL)) failures.push(`F08 动态技能输入：动态输入控件字重 ${dynamic.fieldWeights.join('/')}（应为 ${WEIGHT_NORMAL}）`);
    }
    await assertNoUiErrors('F08 动态技能输入');
}

for (const item of [
    // 消息槽的真实 id（geo-entry.js 里 elements 映射到的 DOM）：#uiMessage / #catMessage / #tokMessage
    { view: 'url-imports', rows: 'urlImportRows', message: 'uiMessage', emptyText: '还没有导入记录' },
    { view: 'categories', rows: 'categoryRows', message: 'catMessage', emptyText: '还没有分类' },
    { view: 'api-tokens', rows: 'apiTokenRows', message: 'tokMessage', emptyText: '还没有令牌' },
]) {
    if (item.view === 'api-tokens' && !tokenSurfacePresent) {
        flows.push({ flow: `403 错误态：${item.view}`, status: 'not-applicable', reason: '租户令牌面板已冻结' });
        continue;
    }
    // 每项使用独立页面地址，避免只变更 hash 时保留上一个视图的请求状态。
    await send('Page.navigate', { url: `${base}/?preview-forbidden=${item.view}#${item.view}` }, sessionId);
    let state;
    const deadline = Date.now() + 8000;
    do {
        state = await evaluate(`(() => {
      const rows = document.getElementById(${JSON.stringify(item.rows)});
      const message = document.getElementById(${JSON.stringify(item.message)});
      const text = (rows?.textContent ?? '') + ' ' + (message?.textContent ?? '');

      return {
        panelPresent: Boolean(rows && message),
        text: text.trim().slice(0, 80),
        saysForbidden: text.includes('没有') && text.includes('权限'),
        showsEmptyState: text.includes(${JSON.stringify(item.emptyText)}),
        dangerVariant: message?.getAttribute('data-variant') === 'danger',
      };
        })()`);
        if (state.saysForbidden && state.dangerVariant) break;
        await new Promise((resolve) => setTimeout(resolve, 150));
    } while (Date.now() < deadline);
    flows.push({ flow: `403 错误态：${item.view}`, ...state });
    if (!state.panelPresent) failures.push(`403 错误态（${item.view}）：面板的记录或消息节点不存在`);
    if (!state.saysForbidden) failures.push(`403 错误态（${item.view}）：没有如实说明"没有权限"（实际文案：${state.text}）`);
    if (state.showsEmptyState) failures.push(`403 错误态（${item.view}）：把读不到渲染成了"暂无数据"——违反 R29/R30`);
    if (!state.dangerVariant) failures.push(`403 错误态（${item.view}）：权限提示没有按 danger 档渲染（视觉上看不出这是失败）`);
}
await assertNoUiErrors('403 错误态（权限读不到时）');

/**
 * 提交类流程：证明"搬走的绑定还活着"。
 *
 * URL 导入的表单 submit 与列表刷新随 §7 拆分搬到了 geo-entry-management.js，
 * 光看 DOM 指纹证明不了"点提交还有反应"——这里真填真提交，检查**请求真的发出去**、
 * 面板给了对客反馈、列表能渲染。
 *
 * ⚠️ 预览沙盘对写请求是**故意拦掉**的（返回"这是本地样式预览，未提交或执行任何真实操作"），
 * 所以"提交成功后回读列表"这一步在沙盘里跑不到 —— 只作为观察项记录，不当失败判据。
 * 真正的回读路径由契约测试（源码级）与拆分前后的 DOM/API 指纹共同保证。
 */
await setViewport(1440);
{
    await send('Page.navigate', { url: `${base}/#url-imports` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1400));
    const submit = await evaluate(`(async () => {
      const before = (window.__uiPreviewRequests || []).length;
      const form = document.getElementById('urlImportForm');
      const input = form?.querySelector('[name="url"]');
      if (!form || !input) return { error: '找不到 URL 导入表单' };
      const retry = document.querySelector('#urlImportRows [data-url-import-retry]');
      if (!retry) return { error: '失败的 URL 导入记录没有重新提交入口' };
      const retryUrl = retry.dataset.urlImportRetry;
      retry.click();
      const retryFilled = input.value === retryUrl && document.activeElement === input;
      const retryPosted = (window.__uiPreviewRequests || []).slice(before).some((r) => r.method === 'POST' && r.path === '/api/v1/url-imports');
      input.value = 'https://example.com/preview-submit-check';
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await new Promise((r) => setTimeout(r, 600));
      const after = (window.__uiPreviewRequests || []).slice(before);

      return {
        retryFilled,
        retryPosted,
        posted: after.some((r) => r.method === 'POST' && r.path === '/api/v1/url-imports'),
        reloaded: after.filter((r) => r.method === 'GET' && r.path === '/api/v1/url-imports').length >= 1,
        message: (document.getElementById('urlImportFormMessage')?.textContent || '').trim().slice(0, 40),
        rows: document.getElementById('urlImportRows')?.querySelectorAll('tr').length ?? 0,
      };
    })()`);
    flows.push({ flow: '提交 URL 导入（表单 submit + 回读列表）', ...submit });
    if (submit.error) failures.push(`提交 URL 导入：${submit.error}`);
    else {
        if (!submit.retryFilled) failures.push('失败 URL 重新提交：地址未填回表单并聚焦');
        if (submit.retryPosted) failures.push('失败 URL 重新提交：点击入口就发起了新请求，应由用户确认提交');
        if (!submit.posted) failures.push('提交 URL 导入：表单提交后没有发出 POST /api/v1/url-imports —— 绑定可能没随拆分搬过来');
        if (submit.message === '') failures.push('提交 URL 导入：提交后没有任何对客反馈');
        if (submit.rows === 0) failures.push('提交 URL 导入：列表没有渲染任何行');
    }
    await assertNoUiErrors('提交 URL 导入');
}

/**
 * 三个"只在交互时才跑到"的流程 —— 2026-09-21 拆分时踩的坑全在这几条路上：
 *   · 知识库详情：文档列表渲染（状态徽标 / 时间 / 失败原因）—— 漏解构 `formatDate` 时这里才炸；
 *   · 文章分类改名 / API 令牌吊销：表单与行内动作的反馈 —— 漏解构 `toast`（旧名 clToast）时这里才炸。
 * 指纹只跑首屏，覆盖不到这些路径，所以必须真点一遍。
 */
await setViewport(1440);
{
    await send('Page.navigate', { url: `${base}/#knowledge` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1600));
    const detail = await evaluate(`(async () => {
      const before = (window.__uiPreviewRequests || []).length;
      const item = document.querySelector('#kbList .kb-item, #kbList [data-kb-id]');
      if (!item) return { error: '知识库列表里没有可点的条目' };
      item.click();
      await new Promise((r) => setTimeout(r, 900));
      const after = (window.__uiPreviewRequests || []).slice(before);
      const docs = document.getElementById('kbDocList');
      const text = (docs?.textContent || '').replace(/\\s+/g, ' ').trim();

      return {
        detailVisible: !document.getElementById('kbDetail')?.hidden,
        docRows: docs ? docs.querySelectorAll('.history-item').length : 0,
        asksDocuments: after.some((r) => /knowledge-bases\\/\\d+\\/documents$/.test(r.path)),
        hasBadge: /可检索|解析中|失败/.test(text),
        hasDate: /2026\\/09|09-1|09\\/1|2026-09/.test(text),
      };
    })()`);
    flows.push({ flow: '知识库详情：打开并渲染文档列表', ...detail });
    if (detail.error) failures.push(`知识库详情：${detail.error}`);
    else {
        if (!detail.detailVisible) failures.push('知识库详情：点了列表项但详情没打开（openKnowledgeBase 没接上）');
        if (!detail.asksDocuments) failures.push('知识库详情：没有回读文档列表（loadKbDocuments 没接上）');
        if (detail.docRows === 0) failures.push('知识库详情：文档列表一行都没渲染');
        if (!detail.hasBadge) failures.push('知识库详情：文档状态徽标没渲染（KB_DOC_STATUS_* 或渲染路径断了）');
        if (!detail.hasDate) failures.push('知识库详情：文档时间没渲染（formatDate 没解构时会在这里炸）');
    }
    await assertNoUiErrors('知识库详情：打开并渲染文档列表');
}

{
    await send('Page.navigate', { url: `${base}/#categories` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1600));
    const rename = await evaluate(`(async () => {
      const btn = document.querySelector('#categoryRows [data-cat-rename]');
      if (!btn) return { error: '分类列表里没有改名按钮' };
      btn.click();
      await new Promise((r) => setTimeout(r, 300));
      const dialog = document.querySelector('dialog.geo-ui-dialog[open]');
      if (!dialog) return { error: '改名没有弹出工作台原生对话框' };
      const input = dialog.querySelector('#workspaceDialogValue');
      if (input) input.value = '改名样例';
      dialog.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await new Promise((r) => setTimeout(r, 700));

      return {
        dialogOpened: true,
        feedback: (document.getElementById('catMessage')?.textContent || '').trim().slice(0, 60),
        feedbackVariant: document.getElementById('catMessage')?.getAttribute('data-variant') || '',
        toast: (document.getElementById('clToast')?.textContent || '').trim().slice(0, 40),
      };
    })()`);
    flows.push({ flow: '文章分类改名：对话框 + 反馈（覆盖 toast）', ...rename });
    if (rename.error) failures.push(`文章分类改名：${rename.error}`);
    else {
        if (!rename.dialogOpened) failures.push('文章分类改名：没有走工作台原生对话框');
        if (rename.feedback === '') failures.push('文章分类改名：确认后没有任何反馈（toast 没解构时会在这里抛错）');
        if (!rename.toast) failures.push('文章分类改名：确认后没有浮层提示（动作反馈落不到用户眼前）');
    }
    await assertNoUiErrors('文章分类改名');
}

if (tokenSurfacePresent) {
    await send('Page.navigate', { url: `${base}/#api-tokens` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1600));
    const revoke = await evaluate(`(async () => {
      const btn = document.querySelector('#apiTokenRows [data-token-revoke]');
      if (!btn) return { error: '令牌列表里没有吊销按钮' };
      btn.click();
      await new Promise((r) => setTimeout(r, 300));
      const dialog = document.querySelector('dialog.geo-ui-dialog[open]');
      if (!dialog) return { error: '吊销没有弹出确认对话框' };
      dialog.querySelector('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await new Promise((r) => setTimeout(r, 700));

      return {
        dialogOpened: true,
        feedback: (document.getElementById('tokMessage')?.textContent || '').trim().slice(0, 60),
        toast: (document.getElementById('clToast')?.textContent || '').trim().slice(0, 40),
      };
    })()`);
    flows.push({ flow: 'API 令牌吊销：确认 + 反馈（覆盖 toast）', ...revoke });
    if (revoke.error) failures.push(`API 令牌吊销：${revoke.error}`);
    else {
        if (revoke.feedback === '') failures.push('API 令牌吊销：确认后没有任何反馈（toast 没解构时会在这里抛错）');
        if (!revoke.toast) failures.push('API 令牌吊销：确认后没有浮层提示（动作反馈落不到用户眼前）');
    }
    await assertNoUiErrors('API 令牌吊销');
} else {
    flows.push({ flow: 'API 令牌吊销', status: 'not-applicable', reason: '租户令牌面板已冻结' });
}

await assertNoUiErrors('流程回归（全部跑完后的窗口）');

/**
 * 内容库抽屉（第五刀搬走的交互）：行内「查看」→ 抽屉打开 → 详情回读 → 关闭并把焦点还给触发按钮。
 *
 * 焦点回归是这一刀刻意保住的语义：抽屉的 `clDrawerState.trigger` 现在由主文件声明、
 * 两个文件共用（技能提示词抽屉也在用同一个抽屉零件）。拆错成"各自一份变量"时，
 * 抽屉照样开关，只是关掉之后焦点掉回 body —— 只有真点一遍才看得见。
 */
{
    await send('Page.navigate', { url: `${base}/#content-library` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1800));
    const drawer = await evaluate(`(async () => {
      const before = (window.__uiPreviewRequests || []).length;
      const btn = document.querySelector('#clRows [data-action="view"]');
      if (!btn) return { error: '内容库列表里没有可打开的条目（夹具没渲染出行）' };
      btn.focus();
      btn.click();
      await new Promise((r) => setTimeout(r, 900));
      const mask = document.getElementById('clDrawerMask');
      const body = document.getElementById('clDrawerBody');
      const after = (window.__uiPreviewRequests || []).slice(before);
      const opened = mask ? !mask.hidden : false;
      const text = (body?.textContent || '').replace(/\\s+/g, ' ').trim();
      document.getElementById('clDrawerClose')?.click();
      await new Promise((r) => setTimeout(r, 300));

      return {
        opened,
        asksDetail: after.some((r) => /^\\/api\\/v1\\/articles\\/\\d+$/.test(r.path)),
        bodyChars: text.length,
        closedAgain: mask ? mask.hidden : false,
        focusReturned: document.activeElement === btn,
      };
    })()`);
    flows.push({ flow: '内容库抽屉：打开 → 详情回读 → 关闭并归还焦点', ...drawer });
    if (drawer.error) failures.push(`内容库抽屉：${drawer.error}`);
    else {
        if (!drawer.opened) failures.push('内容库抽屉：点「查看」没有打开抽屉（clOpenDrawerById 没接上）');
        if (!drawer.asksDetail) failures.push('内容库抽屉：打开后没有回读文章详情（GET /api/v1/articles/{id}）');
        if (drawer.bodyChars < 40) failures.push(`内容库抽屉：抽屉正文没渲染（实际 ${drawer.bodyChars} 字）`);
        if (!drawer.closedAgain) failures.push('内容库抽屉：点关闭后遮罩没藏起来');
        if (!drawer.focusReturned) failures.push('内容库抽屉：关闭后焦点没回到触发按钮（clDrawerState.trigger 没共用同一份）');
    }
await assertNoUiErrors('内容库抽屉');
}

/**
 * 结果页（第十刀搬走的报告渲染）：`#result/<报告编号>` 直开 → 报告正文 + 三个页签 → 切页签。
 *
 * 为什么必须真跑：指纹那 30 个视图里没有带参数的结果页，而这一刀搬走的正是它的渲染器。
 * 2026-09-21 实测踩过一次（`Assignment to constant variable`：把渲染上下文 `activeTableHeaders`
 * 从 GEO 解构出来成了 const，代码里却在对它赋值）—— 表现是整页只剩一句「打不开这份报告」，
 * 既不抛到窗口、也不进指纹。所以这条流程按"页面真的渲染出报告"来判。
 */
{
    await send('Page.navigate', { url: `${base}/#result/101` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 2200));
    const result = await evaluate(`(async () => {
      const panel = document.getElementById('resultPanel');
      const before = (window.__uiPreviewRequests || []).length;
      const tabs = [...document.querySelectorAll('#resultPanel .result-tab, #resultPanel [role=tab]')];
      const title = (panel?.querySelector('h1, h2')?.textContent || '').trim().slice(0, 30);
      const firstLen = (panel?.innerHTML || '').length;
      const target = tabs.find((tab) => tab.dataset.resultZone && tab.dataset.resultZone !== 'overview');
      if (target) { target.click(); await new Promise((r) => setTimeout(r, 500)); }

      return {
        renders: firstLen > 2000,
        chars: firstLen,
        title,
        asksReport: (window.__uiPreviewRequests || []).slice(before).length >= 0,
        tabCount: tabs.length,
        tabLabels: tabs.map((tab) => tab.textContent.trim().slice(0, 6)),
        switched: target ? target.getAttribute('aria-selected') === 'true' || target.classList.contains('is-active') : null,
        afterSwitchLen: (panel?.innerHTML || '').length,
      };
    })()`);
    flows.push({ flow: '结果页：直开报告 + 三页签切换', ...result });
    if (!result.renders) failures.push(`结果页：直开 #result/<编号> 没渲染出报告（正文 ${result.chars} 字）`);
    if (!result.title) failures.push('结果页：没有页标题');
    if (result.tabCount < 2) failures.push(`结果页：页签只有 ${result.tabCount} 个（概览/问题清单/完整报告应有 2-3 个）`);
    if (result.switched === false) failures.push('结果页：点页签后 aria-selected 没切过去');
    if (result.afterSwitchLen < 500) failures.push('结果页：切页签后正文变空了');
await assertNoUiErrors('结果页');
}

/**
 * 项目簇（第十一刀搬走的那一整域）：项目目录 → 选项目（详情 + 阶段轨 + 档案）→ 新建面板开合。
 *
 * 指纹跑的是"每一屏渲染成什么样"，搬走的这些交互（目录、选择、阶段轨、新建面板）它盖不到。
 */
{
    await send('Page.navigate', { url: `${base}/#projects` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 2200));
    const project = await evaluate(`(async () => {
      // 侧栏「项目工作台」（data-view-target="projects"）现在进的是工作台，不是目录；
      // 项目目录的真实入口是侧栏项目卡展开后的「全部项目」（#projectCardAllProjects → #projects/list）。
      // 入口按用户路径点：先展开项目卡，入口必须唯一且可见，不能"找不到就跳过"。
      document.getElementById('projectNavToggle')?.click();
      await new Promise((r) => setTimeout(r, 300));
      const allProjectsEntries = [...document.querySelectorAll('#projectCardAllProjects')];
      const directoryEntryVisible = allProjectsEntries.length === 1 && allProjectsEntries[0].getClientRects().length > 0;
      if (!directoryEntryVisible) return { error: '侧栏项目卡展开后没有唯一可见的「全部项目」入口（#projectCardAllProjects 有 ' + allProjectsEntries.length + ' 个）' };
      allProjectsEntries[0].click();
      await new Promise((r) => setTimeout(r, 700));
      const directoryHash = location.hash;
      const directory = document.getElementById('projectDirectoryPanel');
      const rows = [...document.querySelectorAll('#directoryProjectList [data-project-id]')];
      const directoryShown = directory ? !directory.hidden : false;
      if (!rows.length) return { error: '项目目录里没有任何项目', directoryShown };
      rows[0].click();
      await new Promise((r) => setTimeout(r, 900));
      const detail = document.getElementById('projectDetail');
      const detailShown = detail ? !detail.hidden : false;
      const title = (document.getElementById('detailTitle')?.textContent || '').trim().slice(0, 24);
      // EXEC-10B: verify the rendered six-stage drawers and canceled action,
      // rather than the removed stageActions container's source length.
      const dossierLen = document.getElementById('pjDossier')?.innerHTML.length ?? 0;
      const railHost = Boolean(document.getElementById('pjStageRail'));
      const stageButtons = [...document.querySelectorAll('#pjStageRail [data-project-stage]')];
      const drawerChecks = [];
      const stageWrites = () => (window.__uiPreviewRequests || []).filter(request => request.method === 'POST' && /\\/projects\\/\\d+\\/stages\\/(?:\\d+\\/advance|rollback)$/.test(request.path)).length;
      let currentSkills = 0;
      let currentAdvance = false;
      let confirmCanceled = false;
      let canceledStageWrites = null;
      for (const button of stageButtons) {
        const stage = Number(button.dataset.projectStage);
        document.querySelector('#pjStageRail [data-project-stage="' + stage + '"]')?.click();
        await new Promise((r) => setTimeout(r, 250));
        const mask = document.getElementById('projectStageDrawerMask');
        const drawerTitle = document.getElementById('projectStageDrawerTitle')?.textContent || '';
        drawerChecks.push({ stage, open: Boolean(mask && !mask.hidden), titleMatches: drawerTitle.includes('阶段 ' + (stage + 1)) });
        const advance = document.querySelector('[data-project-stage-advance]');
        if (advance && !advance.hidden && advance.getClientRects().length) {
          currentAdvance = true;
          currentSkills = document.querySelectorAll('#projectStageDrawerBody [data-project-stage-skill]').length;
          const writesBefore = stageWrites();
          advance.click();
          await new Promise((r) => setTimeout(r, 100));
          const cancel = document.querySelector('dialog.geo-ui-dialog[open] [data-dialog-cancel]');
          confirmCanceled = Boolean(cancel);
          cancel?.click();
          await new Promise((r) => setTimeout(r, 100));
          canceledStageWrites = stageWrites() - writesBefore;
        }
        mask?.click();
        await new Promise((r) => setTimeout(r, 100));
      }
      const queue = document.getElementById('projectHomeQueue');
      const emptyQueue = document.getElementById('projectHomeQueueEmpty');
      const queueRendered = Boolean(queue && queue.getClientRects().length && queue.children.length && queue.textContent.trim())
        || Boolean(emptyQueue && emptyQueue.getClientRects().length && emptyQueue.textContent.includes('现在没有需要你处理的事'));
      const activityRows = [...document.querySelectorAll('#projectHomeActivity .tl-item')];
      const activityContentRendered = activityRows.some(row => row.getClientRects().length && row.querySelector('.tl-text')?.textContent.includes('解释型文章（本地样例）'));
      // 回收站已独立成全局页（#projects/trash，见下一段）；这里只留删除说明的位置核对。
      const deleteCopy = (document.querySelector('.dossier-foot p')?.textContent || '');

      const newButton = document.getElementById('newProjectButton');
      newButton?.click();
      await new Promise((r) => setTimeout(r, 500));
      const createPanel = document.getElementById('createProjectPanel');
      const createShown = createPanel ? !createPanel.hidden : false;
      document.getElementById('cancelCreateProjectButton')?.click();
      await new Promise((r) => setTimeout(r, 300));

      return {
        directoryShown,
        rowCount: rows.length,
        detailShown,
        title,
        dossierLen,
        railHost,
        stageButtonCount: stageButtons.length,
        drawerChecks,
        currentSkills,
        currentAdvance,
        confirmCanceled,
        canceledStageWrites,
        queueRendered,
        activityCount: activityRows.length,
        activityContentRendered,
        directoryHash,
        deleteCopyHasTrash: deleteCopy.includes('回收站'),
        // 期满不可恢复的口径（用户口径 2026-09-21「7 天之后无法恢复」）现在写在删除说明的既有位置。
        deleteCopyHasExpiry: deleteCopy.includes('超过保留期后无法恢复'),
        createShown,
        createClosed: createPanel ? createPanel.hidden : false,
      };
    })()`);
    flows.push({ flow: '项目簇：目录 → 选项目（详情/阶段轨）→ 新建面板开合', ...project });
    if (project.error) failures.push(`项目簇：${project.error}`);
    else {
        if (!project.directoryShown) failures.push('项目簇：点侧栏项目卡「全部项目」没有打开项目目录');
        if (project.directoryHash !== '#projects/list') failures.push(`项目簇：点「全部项目」后地址是「${project.directoryHash}」（应为 #projects/list）`);
        if (project.rowCount < 1) failures.push('项目簇：项目目录里一行都没渲染');
        if (!project.detailShown) failures.push('项目簇：选了项目但详情没显示');
        if (!project.title) failures.push('项目簇：详情没有项目标题');
        if (!project.railHost) failures.push('项目簇：阶段轨容器（#pjStageRail）不见了');
        if (project.dossierLen < 50) failures.push(`项目簇：项目档案没渲染（实际 ${project.dossierLen} 字）`);
        if (project.stageButtonCount !== 6) failures.push(`项目簇：阶段按钮应有6个（实际 ${project.stageButtonCount} 个）`);
        if (!project.drawerChecks?.every(drawer => drawer.open && drawer.titleMatches)) failures.push('项目簇：没有逐段打开对应阶段抽屉');
        if (project.currentSkills < 1) failures.push('项目簇：当前阶段抽屉没有技能入口');
        if (!project.currentAdvance) failures.push('项目簇：当前阶段抽屉没有推进/完成入口');
        if (!project.queueRendered) failures.push('项目簇：接下来做什么清单或规定空状态没渲染');
        if (project.activityCount !== 1 || !project.activityContentRendered) failures.push('项目簇：当前项目的样例动态正文没有完整渲染');
        if (!project.confirmCanceled || project.canceledStageWrites !== 0) failures.push('项目簇：推进确认取消后仍发出阶段写请求，或没有确认框');
        if (!project.deleteCopyHasTrash) failures.push('项目簇：删除项目的说明没有提回收站（客户会以为点了就永久没）');
        if (!project.deleteCopyHasExpiry) failures.push('项目簇：删除项目的说明没有写"超过保留期后无法恢复"');
        if (!project.createShown) failures.push('项目簇：点「新建项目」没有打开创建面板');
        if (!project.createClosed) failures.push('项目簇：点「取消」后创建面板没关');
    }
    await assertNoUiErrors('项目簇');

    // 回收站（计划 §9.5；2026-10-01 起是全局回收站页 #projects/trash，由侧栏底部「回收站」进入）。
    // 从真实入口点进去：页面要切出来，桶内条目要渲染，保留期要写明，每条都要写清还能恢复到哪天。
    // 夹具按真实 /api/v1/trash 的返回形状给了三条，所以"零条"在这里是异常（真的空桶渲染的是另一句话）。
    await send('Page.navigate', { url: `${base}/#projects/list` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 2200));
    const trash = await evaluate(`(async () => {
      const entries = [...document.querySelectorAll('#globalTrashNav')];
      const entryVisible = entries.length === 1 && entries[0].getClientRects().length > 0;
      if (!entryVisible) return { error: '侧栏底部没有唯一可见的「回收站」入口（#globalTrashNav 有 ' + entries.length + ' 个）' };
      entries[0].click();
      await new Promise((r) => setTimeout(r, 900));
      const section = document.getElementById('projectTrashSection');
      const rows = [...document.querySelectorAll('#projectTrashList .project-trash-row')];
      const restorable = rows.filter((row) => row.querySelector('[data-trash-restore]'));
      const trashNote = (document.querySelector('#projectTrashSection .project-trash-note')?.textContent || '');

      return {
        hash: location.hash,
        sectionShown: Boolean(section && !section.hidden && section.getClientRects().length),
        trashRows: [...document.querySelectorAll('#projectTrashList [data-trash-restore]')].length,
        trashRetention: (document.getElementById('projectTrashRetention')?.textContent || '').trim(),
        trashNoteHasRecover: trashNote.includes('恢复'),
        trashNoteHasRetention: trashNote.includes('保留'),
        restorableRows: restorable.length,
        rowsWithDeadline: restorable.filter((row) => /还有 \\d+ 天可恢复/.test(row.textContent) && row.textContent.includes('恢复截止')).length,
        takedownRetry: document.querySelectorAll('#projectTrashList [data-trash-takedown]').length,
      };
    })()`);
    flows.push({ flow: '项目簇：侧栏「回收站」→ 全局回收站页', ...trash });
    if (trash.error) failures.push(`项目簇：${trash.error}`);
    else {
        if (!trash.sectionShown) failures.push('项目簇：点侧栏「回收站」后回收站页没切出来');
        if (trash.hash !== '#projects/trash') failures.push(`项目簇：点「回收站」后地址是「${trash.hash}」（应为 #projects/trash）`);
        if (trash.trashRows < 1) failures.push('项目簇：回收站里一条桶内项目都没渲染');
        if (!/^\d+$/.test(trash.trashRetention)) failures.push(`项目簇：回收站没写明保留天数（实际「${trash.trashRetention}」）`);
        if (!trash.trashNoteHasRecover) failures.push('项目簇：回收站说明里没有"可恢复"');
        if (!trash.trashNoteHasRetention) failures.push('项目簇：回收站说明里没有写保留期');
        if (trash.rowsWithDeadline !== trash.restorableRows) failures.push(`项目簇：${trash.restorableRows} 条可恢复条目里只有 ${trash.rowsWithDeadline} 条写了"还有 N 天可恢复 · 恢复截止"（过期期限没交代）`);
        if (trash.takedownRetry < 1) failures.push('项目簇：夹具里有外站没撤下来的条目，但回收站没给"重试撤稿"入口');
    }
    await assertNoUiErrors('项目簇：回收站');
}

/**
 * 账户注销（计划 §9.5）：`#account-security` 的注销卡片 —— 状态回读 → 表单可用 → 逐字确认被拦。
 *
 * 指纹里没有 account-security 这一屏（那 30 个视图是工作台主流程），而注销是**不可逆操作**，
 * 所以这里真点一遍：状态读到"未申请"就显示表单；填错确认文案必须被前端当场拦下、
 * 不许把一次误触直接送到后端。夹具只演示这一态，提交动作不写任何状态。
 */
{
    await send('Page.navigate', { url: `${base}/#account-security` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 2400));
    const deletion = await evaluate(`(async () => {
      const panel = document.querySelector('section[data-workspace-view="account-security"]');
      const form = document.getElementById('geoDeletionForm');
      const confirmInput = document.getElementById('geoDeletionConfirm');
      const badge = document.getElementById('geoDeletionStatus');
      const graceDays = (document.getElementById('geoDeletionGraceDays')?.textContent || '').trim();
      const cardText = (panel?.textContent || '');
      const formShown = form ? !form.hidden : false;

      // 逐字确认写错时必须被前端拦住（拦不住就等于一次误触直接提交）
      if (confirmInput) {
        confirmInput.value = '注销';
        form?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await new Promise((r) => setTimeout(r, 300));
      }
      const message = (document.getElementById('geoDeletionMessage')?.textContent || '').trim();

      return {
        panelShown: panel ? !panel.hidden : false,
        badge: (badge?.textContent || '').trim(),
        formShown,
        graceDays,
        hasRecoverCopy: cardText.includes('恢复账户'),
        hasIrreversibleCopy: cardText.includes('无法恢复'),
        blockedWrongText: message.includes('逐字输入'),
      };
    })()`);
    flows.push({ flow: '账户注销：状态回读 + 表单 + 逐字确认拦截', ...deletion });
    if (!deletion.panelShown) failures.push('账户注销：账号安全面板没切出来');
    if (!deletion.formShown) failures.push('账户注销：状态读完仍是"未申请"，但注销表单没显示');
    if (deletion.graceDays !== '15') failures.push(`账户注销：冷静期天数没有从服务端回读（实际「${deletion.graceDays}」）`);
    if (!deletion.hasRecoverCopy) failures.push('账户注销：页面上没有"期内登录可恢复账户"的说明');
    if (!deletion.hasIrreversibleCopy) failures.push('账户注销：页面上没有"逾期无法恢复"的说明');
    if (!deletion.blockedWrongText) failures.push('账户注销：确认文案写错时没有被前端拦住');
    await assertNoUiErrors('账户注销');
}

/**
 * 标题档位全量扫描（2026-09-21 统一批）。
 *
 * 为什么单独一趟：上面的主循环只覆盖 8 个面板（12 个宽度 × 8 已经要跑几分钟），
 * 而档位漂移恰恰发生在**没进主循环**的那些页面上 —— 实测发布管理 / 可见性看板 / 账号安全的
 * `<h3>` 一直被全局语义档压成 20px/500，页面里那条"16px/600"的规则因为权重低一格从未生效。
 * 一趟 = 1 个宽度 × 全部工作台视图，成本可控，覆盖到每一页。
 *
 * 例外（写在册上，不是漏检）：素材页那个 12px 的 h3 是**报表列表的列头**，
 * 语义上根本不是区域标题；那是下一批的语义清理（换成列头语义或改为 div），
 * 不在本批把它硬拽成 16px —— 那会让列头比它统领的内容还大。
 */
const TYPO_SWEEP_VIEWS = [
    'projects', 'skill', 'asset', 'content-library', 'categories', 'materials', 'automation',
    'url-imports', 'knowledge', 'publishing', 'analytics', 'billing-overview',
    'usage', ...(tokenSurfacePresent ? ['api-tokens'] : []),
    'sensitive-words', 'skill-prompts', 'platform', 'account-security', 'personal-settings',
];
const TYPO_TITLE_EXPECT = '24px/600';
const TYPO_SECTION_EXPECT = '16px/600';
const TYPO_SECTION_EXCEPTIONS = Object.freeze({
    asset: '报表列表的列头（12px/500）：语义上不是区域标题，不在本批硬拽；下一批做语义清理',
});
const typoSweep = [];
{
    await setViewport(1440);
    for (const view of TYPO_SWEEP_VIEWS) {
        await send('Page.navigate', { url: `${base}/#${view}` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 900));
        const facts = await evaluate(`(() => {
          const panel = [...document.querySelectorAll('section[data-workspace-view]')].find((s) => !s.hidden);
          if (!panel) return { view: ${JSON.stringify(view)}, status: 'no-panel' };
          const titlebar = document.querySelector('.workspace-view-titlebar');
          const titlebarHidden = titlebar ? titlebar.hidden : true;
          let titleEl = panel.querySelector(':scope > .view-title :is(h1, h2), :scope > .panel-heading :is(h1, h2), :scope > .panel-head :is(h1, h2), :scope > .section-title :is(h1, h2)');
          let titleFrom = 'panel';
          if (!titleEl && !titlebarHidden) { titleEl = titlebar.querySelector('h1, h2'); titleFrom = 'titlebar'; }
          const title = titleEl
            ? { from: titleFrom, size: getComputedStyle(titleEl).fontSize + '/' + getComputedStyle(titleEl).fontWeight, text: (titleEl.textContent || '').trim().slice(0, 16) }
            : null;
          const sections = [...new Set([...panel.querySelectorAll('h3')].filter((e) => e.getClientRects().length)
            .map((e) => getComputedStyle(e).fontSize + '/' + getComputedStyle(e).fontWeight))];
          // 内联配置（带 nonce 的脚本）有没有真的执行：CSP 强制模式下漏签会被静默拦下，
          // 而取值恰好与兜底一致时页面看不出区别 —— 所以断言这个标记，别靠"看起来正常"。
          const entryConfig = document.documentElement.dataset.entryConfig || '(未设置)';
          return { view: ${JSON.stringify(view)}, status: 'ok', title, sections, entryConfig };
        })()`);
        typoSweep.push(facts);
        if (facts.status !== 'ok') continue;
        if (facts.title && facts.title.size !== TYPO_TITLE_EXPECT) {
            failures.push(`标题档位（${view}）：页标题 ${facts.title.size}（应为 ${TYPO_TITLE_EXPECT}）— ${facts.title.text}`);
        }
        const bad = facts.sections.filter((s) => s !== TYPO_SECTION_EXPECT);
        if (bad.length && !TYPO_SECTION_EXCEPTIONS[view]) {
            failures.push(`标题档位（${view}）：区域标题 ${bad.join('/')}（应为 ${TYPO_SECTION_EXPECT}）`);
        }
        if (bad.length && TYPO_SECTION_EXCEPTIONS[view]) {
            advisories.push(`标题档位（${view}）：${bad.join('/')} —— ${TYPO_SECTION_EXCEPTIONS[view]}`);
        }
        if (facts.entryConfig !== 'ready') {
            failures.push(`内联配置（${view}）：<html data-entry-config> 是 ${facts.entryConfig} —— 带 nonce 的内联脚本没执行（CSP 强制模式下漏签就是这个表现）`);
        }
    }
}

/**
 * 「本次登录撤销了注销申请」的落地提示（计划 §9.5）。
 *
 * 后端在登录响应里带 `account_restored`，两条登录路径各自把事实写进 sessionStorage；
 * 这里把那一格先塞好再进工作台，验证**真的会提示、提示完就清**——
 * 只测"键写进去了"不够，客户要看的是那句话；不清键则会出现"刷新一下又弹一遍"的假提示。
 */
{
    await send('Page.navigate', { url: `${base}/#account-security` }, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 1600));
    await evaluate(`sessionStorage.setItem('geo_account_restored', '2026-09-21T10:00:00Z')`);
    // ⚠️ 必须 reload 而不是"再 navigate 一次同一个 hash"：同 URL 只带 hash 属于同文档导航，
    //    脚本不会重新执行，这一条就会假绿（2026-09-21 实测踩过：提示没出、标记也没清）。
    await send('Page.reload', {}, sessionId);
    await new Promise((resolve) => setTimeout(resolve, 2000));
    const restored = await evaluate(`(() => {
      const toast = document.getElementById('clToast');
      return {
        toast: (toast?.textContent || '').trim(),
        visible: Boolean(toast && toast.classList.contains('show')),
        keyLeft: sessionStorage.getItem('geo_account_restored'),
      };
    })()`);
    flows.push({ flow: '账户恢复提示：登录撤销注销申请后提示一次并清标记', ...restored });
    if (!/账户已恢复/.test(restored.toast)) failures.push(`账户注销：登录撤销申请后没有提示"账户已恢复"（实际「${restored.toast}」）`);
    if (!restored.visible) failures.push('账户注销：恢复提示没有显示出来（toast 未进入 show 状态）');
    if (restored.keyLeft !== null) failures.push('账户注销：恢复提示标记没有清掉 —— 刷新会重复弹提示');
    await assertNoUiErrors('账户恢复提示');
}

// U6：独立应用页与客户公开页分别核对身份、资产和窄屏整页宽度。
const standalonePages = [
    { name: 'analytics', path: '/analytics/', product: true },
    { name: 'visibility', path: '/visibility/?project_id=901', product: true },
    { name: 'manual-publish', path: '/manual-publish/', product: true },
    { name: 'workbench', path: '/workbench/', product: true },
    { name: 'public-article', path: '/read-preview/', product: false },
];
const standaloneResults = [];
for (const page of standalonePages) {
    for (const width of [1440, 1024, 768, 390, 320]) {
        await setViewport(width);
        await send('Page.navigate', { url: `${base}${page.path}` }, sessionId);
        await new Promise((resolve) => setTimeout(resolve, 650));
        const facts = await evaluate(`(() => {
            const logo = document.querySelector('img.yanji-logo');
            return {
                title: document.title,
                overflow: document.documentElement.scrollWidth - innerWidth,
                logoPresent: Boolean(logo),
                logoLoaded: Boolean(logo?.complete && logo?.naturalWidth),
            };
        })()`);
        standaloneResults.push({ page: page.name, width, ...facts });
        if (facts.overflow > 1) failures.push(`独立页面（${page.name} @${width}px）：整页横向溢出 ${facts.overflow}px`);
        if (page.product && !facts.logoLoaded) failures.push(`独立页面（${page.name} @${width}px）：言及标志未加载`);
        if (!page.product && facts.logoPresent) failures.push(`公开页面（${page.name} @${width}px）：客户页面被应用标志覆盖`);
        if (screenshotDir && [1440, 390].includes(width)) {
            const { data } = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
            fs.writeFileSync(`${screenshotDir}/standalone-${page.name}-${width}.png`, Buffer.from(data, 'base64'));
        }
    }
}

const summary = {
    base,
    widths: WIDTHS,
    views: VIEWS.map((v) => v.name),
    checks: report.length,
    failures: failures.length,
    failureList: failures,
    flows,
    standalone: standaloneResults,
    touchTargets: {
        checked: touchResults.length,
        widths: [390, 768, 1024],
        violations: touchViolations.length,
    },
    desktopBaseline: desktopProbe,
    advisories: {
        pageTitleBaseline: titleBaseline,
        pageTitlesNot24px: nonStandardTitles,
        typographySweep: typoSweep,
        note: '页标题档位已在 2026-09-21 统一为 24px/600；触屏 44px 已按 pointer:coarse 补齐并转为判据。',
        notes: advisories,
    },
        samples: report.filter((r) => r.width === 1440 || r.width === 390).map((r) => ({
            view: r.view,
            width: r.width,
            rows: r.visibleRows,
            controls: r.visibleControls,
            title: r.titleText ? `${r.titleText} ${r.titleSize}` : null,
            labelWeights: r.labelWeights,
        fieldWeights: r.fieldWeights,
        hintWeights: r.hintWeights,
        titleSizes: r.titleSizes,
        sectionSizes: r.sectionSizes,
        pageOverflow: r.pageOverflow,
        tables: `${r.tableCount}${r.unwrappedTables ? '（未包 ' + r.unwrappedTables + '）' : ''}`,
    })),
};

console.log(JSON.stringify(summary, null, 2));

if (args.json) fs.writeFileSync(String(args.json), JSON.stringify({ summary, report }, null, 2));

await send('Target.closeTarget', { targetId });
socket.close();
process.exit(failures.length ? 1 : 0);
