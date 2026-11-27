(() => {
  let workspaceDialogPending = null;
  function workspaceDialogContext() {
    return JSON.stringify([window.location.hash, state.session, state.selectedProject?.id, state.selectedKbId, state.selectedEkId, state.selectedChannelId, state.selectedAnalysisId]);
  }
  function requestWorkspaceDialog(kind, message, defaultValue = "") {
    // A second activation must not queue another destructive action.
    if (workspaceDialogPending) return Promise.resolve(null);
    const context = workspaceDialogContext();
    const dialog = document.createElement("dialog");
    if (typeof dialog.showModal !== "function") {
      return Promise.resolve(kind === "confirm" ? window.confirm(message) : kind === "prompt" ? window.prompt(message, defaultValue) : window.alert(message));
    }
    const previousFocus = document.activeElement;
    dialog.className = "geo-ui-dialog";
    dialog.setAttribute("aria-labelledby", "workspaceDialogTitle");
    dialog.innerHTML = '<form><h2 id="workspaceDialogTitle"></h2><p id="workspaceDialogMessage"></p><label for="workspaceDialogValue" id="workspaceDialogLabel" hidden></label><input id="workspaceDialogValue" type="text" hidden><div class="geo-ui-dialog-actions"><button type="button" data-dialog-cancel>取消</button><button type="submit" data-dialog-accept></button></div></form>';
    const heading = dialog.querySelector("h2");
    const body = dialog.querySelector("p");
    const label = dialog.querySelector("label");
    const input = dialog.querySelector("input");
    const cancel = dialog.querySelector("[data-dialog-cancel]");
    const accept = dialog.querySelector("[data-dialog-accept]");
    heading.textContent = kind === "confirm" ? "确认操作" : kind === "prompt" ? "填写信息" : "提示";
    if (kind === "prompt") {
      body.hidden = true;
      label.hidden = input.hidden = false;
      label.textContent = message;
      input.value = defaultValue;
    } else {
      body.textContent = message;
      dialog.setAttribute("aria-describedby", "workspaceDialogMessage");
    }
    cancel.hidden = kind === "notice";
    accept.textContent = kind === "confirm" ? "确认继续" : kind === "prompt" ? "确认" : "知道了";
    document.body.appendChild(dialog);
    let resolveResult;
    workspaceDialogPending = new Promise(resolve => { resolveResult = resolve; });
    const result = workspaceDialogPending;
    let settled = false;
    function finish(value) {
      if (settled) return;
      settled = true;
      const unchanged = workspaceDialogContext() === context;
      window.removeEventListener("hashchange", onNavigate);
      dialog.close();
      dialog.remove();
      workspaceDialogPending = null;
      if (previousFocus?.isConnected && unchanged) previousFocus.focus();
      resolveResult(unchanged ? value : null);
    }
    function onNavigate() { finish(null); }
    window.addEventListener("hashchange", onNavigate);
    dialog.addEventListener("cancel", event => { event.preventDefault(); finish(null); });
    dialog.addEventListener("close", () => finish(null));
    cancel.addEventListener("click", () => finish(null));
    dialog.querySelector("form").addEventListener("submit", event => {
      event.preventDefault();
      finish(kind === "prompt" ? input.value : true);
    });
    dialog.showModal();
    (kind === "prompt" ? input : kind === "confirm" ? cancel : accept).focus();
    return result;
  }
  async function confirmWorkspaceAction(message) { return (await requestWorkspaceDialog("confirm", message)) === true; }
  function promptWorkspaceValue(message, defaultValue = "") { return requestWorkspaceDialog("prompt", message, defaultValue); }
  async function showWorkspaceNotice(message) {
    if (workspaceDialogPending) await workspaceDialogPending;
    return requestWorkspaceDialog("notice", message);
  }
  const config = window.GEO_ENTRY_CONFIG || {};
  // 内联配置是否真的执行了 —— 挂一个可观测标记（CSP 强制模式下的关键判据）。
  //
  // 为什么需要它：`window.GEO_ENTRY_CONFIG` 由**带 nonce 的内联脚本**注入。CSP 转强制之后，
  // 万一那个脚本漏挂 nonce，它会被浏览器**静默拦下**（不抛错、只少一段逻辑），而今天的配置取值
  // 与代码兜底值恰好一致 —— 页面照样能跑，人眼与"有没有报错"都看不出来（2026-09-21 排查确认）。
  // 于是把它变成一个**能被断言的事实**：真浏览器判据逐个视图断言 `<html data-entry-config="ready">`，
  // 漏签就会变成 "fallback" 当场报红；生产上 DevTools 里也能一眼看到。
  document.documentElement.dataset.entryConfig = window.GEO_ENTRY_CONFIG ? "ready" : "fallback";
  const stateIds = ["connectingState", "signedOutState", "errorState", "workspaceState"];
  let sessionReady = false;
  let projectsReady = false;
  let restoringProjects = null;
  let bootstrapPending = false;
  const projectViews = ["projects", "skill", "asset", "content-library", "publishing", "knowledge", "skill-prompts"];
  const viewLoads = new Map();
  // 视图加载序号（切项目时要复位）：项目块也要写它，所以用共享对象而不是裸变量。
  const viewLoadState = { active: null };
  const state = {
    session: null,
    selectedProject: null,
    // 项目管理页的「推进」列现在只画一张阶段卡。默认画当前阶段；点阶段轨上的
    // 圆点可以临时翻看别的阶段，翻看时这里记的是那一格的 stage_index。
    pjPeekStage: null,
    // 「AI 辅助建议」两张表单当前绑定的项目 id。切项目时用它判断要不要硬覆盖表单，
    suggestFormProjectId: null,
    projects: [],
    analyses: [],
    selectedAnalysisId: null,
    articles: [],
    selectedArticleId: null,
    contentLibrary: [],
    clAllArticles: [],
    // 回收站的条数不在全集里，单独一条请求拿。null = 还没问过（角标留空，
    clRecycledTotal: null,
    // 已加载的内容文章数量；若尚未拉完，角标显示 +，不混入报告数量。
    clAllTotal: null,
    clAllTruncated: false,
    clRecycledTruncated: false,
    clActiveTab: "all",
    clContentType: "",
    clSearch: "",
    clDrawerArticle: null,
    // Automation Tasks (AT0-AT4)
    atTasks: [],
    atRuns: [],
    atSkills: [],
    atErrorNo: "",
    atRunsErrorNo: "",
    assetSourceType: "all",
    assetSearch: "",
    // 历史报告分页（步骤 4）：接口每页 20 条，改前前端从不传 page，第 21 条起够不到。
    articlePage: 1,
    articleLastPage: 1,
    skills: [],
    selectedSkill: null,
    // null = 用户还没手动点过筛选标签，此时跟着项目的当前阶段走（见 effectiveSkillStage()）。
    skillStage: null,
    skillContext: "strategy",
    skillReportReference: null,
    skillWorkspaceMode: "diagnosis",
    skillExecutions: [],
    selectedExecutionId: null,
    // 最后一次渲进 #skillExecutionResult 的执行详情，供技能加载完成后补渲「下一步」卡。
    lastRenderedExecution: null,
    // Team
    teamMembers: [],
    // AI Keys
    aiKeys: [],
    aiPresets: {},
    knowledgeModelStatus: null,
    // Knowledge
    knowledgeBases: [],
    selectedKbId: null,
    kbDocuments: [],
    // B7/§7：知识库上传归属项目列表（打开知识库详情时加载）
    kbProjects: [],
    // Enterprise Knowledge (EK) — tenant-level
    ekProjects: [],
    ekLoaded: false,
    selectedEkId: null,
    ekDetail: null,
    ekRevisions: [],
    // Sensitive Words (SW) — tenant-level
   sensitiveWords: [],
   sensitiveWordsLoaded: false,
    // 2026-09-21 工作台功能补齐（计划 §5）：URL 导入 / 文章分类 / API 令牌 / 账户中心概览
    urlImports: [],
    categories: [],
    apiTokenScopes: [],
    apiTokenDefaultExpiresAt: "",
    accountSummary: null,
    // Skill Prompts (SP) — tenant-level overrides by skill slug
    skillPrompts: [],
    coveredPrompts: {},
    skillPromptsLoaded: false,
    // Analytics
    analyticsData: null,
    analyticsTrends: null,
    analyticsModelUsage: null,
    analyticsByProject: null,
    analyticsDistribution: null,
    analyticsDays: 30,
    analyticsScope: "project",
    analyticsTab: "trends",
    dashboardActivity: [],
    // Publishing
    channels: [],
    selectedChannelId: null,
    channelSecretKeyId: null,
    projectChannels: [],
    // Platform
    platformData: null,
    platformDays: 30,
    platformTab: "overview",
    platformTenants: null,
    platformCommercial: null,
    platformHealth: null,
    platformOperations: null,
    isAdmin: false,
    activeView: "projects",
    lastWorkView: "projects",
    activeViewParam: "",          // 带参路由的参数段，如 #result/41 的 "41"；无参数时是空串
    resultArticle: null,          // 结果页当前打开的那份报告（整条 article）
    resultZone: "",               // 结果页当前页签：overview / questions / full
    analysisRunning: false,
    // 素材库（Materials）— P0 Curate UI
    materialsType: "title",
    materialsLists: { title: [], keyword: [], image: [], author: [] },
    materialsEditing: null,
    matSubmitInFlight: false,
    matGenerateInFlight: false,
    // P1 消费加深：技能面板显式选料，随 brief 提交
    skillMaterialRefs: [],
    // 付费技能入队请求的前端互斥锁。按钮 disabled 只能挡鼠标，挡不住回车、
    // 脚本重复派发 submit，或慢网下的第二次提交事件；真正的网络重放保护还要靠
    skillSubmitInFlight: false,
    // 线索表单（单 8）管理台
    leadTab: "submissions",
    leadFilters: { status: "", form_id: "", date_from: "", date_to: "", search: "" },
    leadPage: 1,
    leadRows: [],
    leadMeta: null,
    leadForms: [],
    leadFormsLoaded: false,
    leadEditingFormId: null,
    leadDrawerRow: null,
    leadAnalyticsQuery: { lead_preset: "30d", lead_date_from: "", lead_date_to: "", lead_form_id: "" },
    leadFormSubmitInFlight: false,
    leadDrawerSubmitInFlight: false,
  };
  const executionPollers = new Map();
  // 2026-09-21 §7 第九刀：技能执行历史的请求序号随技能工作区搬进了 geo-entry-skills.js，
  // 但主文件切项目时要复位/停轮询，所以这里用"共享对象"而不是裸变量（拆成两份会各算各的）。
  const skillExecutionsState = { request: 0 };

  const renderStructuredReportHtml = (...args) => globalThis.GEO?.renderStructuredReportHtml?.(...args);
  const reportZoneSlice = (...args) => globalThis.GEO?.reportZoneSlice?.(...args);
  const reportZoneHasContent = (...args) => globalThis.GEO?.reportZoneHasContent?.(...args);
  const hasReportFieldValue = (...args) => globalThis.GEO?.hasReportFieldValue?.(...args);
  const slideReportTo = (...args) => globalThis.GEO?.slideReportTo?.(...args);
  const currentSliderIndex = (...args) => globalThis.GEO?.currentSliderIndex?.(...args);
  const syncReportSliderState = (...args) => globalThis.GEO?.syncReportSliderState?.(...args);
  const renderExecutionGlance = (...args) => globalThis.GEO?.renderExecutionGlance?.(...args);
  const skillExecutionText = (...args) => globalThis.GEO?.skillExecutionText?.(...args);
  const skillStructuredText = (...args) => globalThis.GEO?.skillStructuredText?.(...args);
  const copySkillExecution = (...args) => globalThis.GEO?.copySkillExecution?.(...args);
  const downloadSkillExecution = (...args) => globalThis.GEO?.downloadSkillExecution?.(...args);
  const downloadSkillFormat = (...args) => globalThis.GEO?.downloadSkillFormat?.(...args);
  const isArticleContentAsset = (...args) => globalThis.GEO?.isArticleContentAsset?.(...args);
  const isStructuredResultAsset = (...args) => globalThis.GEO?.isStructuredResultAsset?.(...args);
  const hasStructuredResultKeys = (...args) => globalThis.GEO?.hasStructuredResultKeys?.(...args);
  const loadArticles = (...args) => globalThis.GEO?.loadArticles?.(...args);
  const reloadArticles = (...args) => globalThis.GEO?.reloadArticles?.(...args);
  const renderAssetNextPanel = (...args) => globalThis.GEO?.renderAssetNextPanel?.(...args);
  const articleKindLabel = (...args) => globalThis.GEO?.articleKindLabel?.(...args);
  const applyAssetSearch = (...args) => globalThis.GEO?.applyAssetSearch?.(...args);
  const clearAssetSearch = (...args) => globalThis.GEO?.clearAssetSearch?.(...args);
  const selectArticle = (...args) => globalThis.GEO?.selectArticle?.(...args);
  const renderArticleResult = (...args) => globalThis.GEO?.renderArticleResult?.(...args);
  const copyArticle = (...args) => globalThis.GEO?.copyArticle?.(...args);
  const downloadArticle = (...args) => globalThis.GEO?.downloadArticle?.(...args);

  const loadProjects = (...args) => globalThis.GEO?.loadProjects?.(...args);
  const recentProjectStorageKey = (...args) => globalThis.GEO?.recentProjectStorageKey?.(...args);
  const rememberRecentProject = (...args) => globalThis.GEO?.rememberRecentProject?.(...args);
  const renderProjectDirectory = (...args) => globalThis.GEO?.renderProjectDirectory?.(...args);
  const showProjectDirectory = (...args) => globalThis.GEO?.showProjectDirectory?.(...args);
  const createProject = (...args) => globalThis.GEO?.createProject?.(...args);
  const upsertProject = (...args) => globalThis.GEO?.upsertProject?.(...args);
  const renderProjectDetail = (...args) => globalThis.GEO?.renderProjectDetail?.(...args);
  const cancelProjectNavigation = (...args) => globalThis.GEO?.cancelProjectNavigation?.(...args);
  const openCreateProjectPanel = (...args) => globalThis.GEO?.openCreateProjectPanel?.(...args);
  const closeCreateProjectPanel = (...args) => globalThis.GEO?.closeCreateProjectPanel?.(...args);
  const openWorkspaceView = (...args) => globalThis.GEO?.openWorkspaceView?.(...args);
  const openResultPage = (...args) => globalThis.GEO?.openResultPage?.(...args);
  const openAnalysisWorkspace = (...args) => globalThis.GEO?.openAnalysisWorkspace?.(...args);
  const startAnalysisFromProject = (...args) => globalThis.GEO?.startAnalysisFromProject?.(...args);
  const saveSettings = (...args) => globalThis.GEO?.saveSettings?.(...args);
  const deleteSelectedProject = (...args) => globalThis.GEO?.deleteSelectedProject?.(...args);

  // 2026-09-21 §7 第十一刀：项目簇搬去了 geo-entry-projects.js（同一套路：主文件留同名
  // 薄转发，实现在拆出文件末尾挂回 window.GEO）。
  // 2026-09-21 §7 第十刀：结果页三页签与资产视图搬去了 geo-entry-reports.js，
  // 上面这组薄转发与第九刀同一套路（主文件保留同名函数，实现在拆出文件末尾挂回 GEO）。
  // 2026-09-21 §7 第九刀：技能工作区搬去了 geo-entry-skills.js。主文件里仍有二十多处
  // 在用它（结果页的「下一步」卡、路由重绘、启动绑定、切项目停轮询…），这里保留同名
  // **薄转发**：实现由拆出文件挂回 window.GEO，主文件不再留第二份实现。
  // ⚠️ 这些名字只在运行期被调用（拆出文件加载之后），所以可选链是安全的；
  //    tests/js/entry-split-scope.test.mjs 会检查"被转发的名字确实有人挂回 GEO"。
  const analysisDisplayName = (...args) => globalThis.GEO?.analysisDisplayName?.(...args);
  const applySkillContext = (...args) => globalThis.GEO?.applySkillContext?.(...args);
  const articleDisplayTitle = (...args) => globalThis.GEO?.articleDisplayTitle?.(...args);
  const effectiveSkillStage = (...args) => globalThis.GEO?.effectiveSkillStage?.(...args);
  const fieldControl = (...args) => globalThis.GEO?.fieldControl?.(...args);
  const isTerminalExecutionStatus = (...args) => globalThis.GEO?.isTerminalExecutionStatus?.(...args);
  const loadSkills = (...args) => globalThis.GEO?.loadSkills?.(...args);
  const normalizeFieldValue = (...args) => globalThis.GEO?.normalizeFieldValue?.(...args);
  const openContentTools = (...args) => globalThis.GEO?.openContentTools?.(...args);
  const openExistingArticles = (...args) => globalThis.GEO?.openExistingArticles?.(...args);
  const renderSkillPendingStrip = (...args) => globalThis.GEO?.renderSkillPendingStrip?.(...args);
  const renderSkillSuggestions = (...args) => globalThis.GEO?.renderSkillSuggestions?.(...args);
  const renderSkillWorkspace = (...args) => globalThis.GEO?.renderSkillWorkspace?.(...args);
  const reportContinuationMarkup = (...args) => globalThis.GEO?.reportContinuationMarkup?.(...args);
  const runSelectedSkill = (...args) => globalThis.GEO?.runSelectedSkill?.(...args);
  const scrollToWithHeaderOffset = (...args) => globalThis.GEO?.scrollToWithHeaderOffset?.(...args);
  const selectRecommendedSkill = (...args) => globalThis.GEO?.selectRecommendedSkill?.(...args);
  const setSkillWorkspaceMode = (...args) => globalThis.GEO?.setSkillWorkspaceMode?.(...args);
  const stageLabel = (...args) => globalThis.GEO?.stageLabel?.(...args);
  const stopAllExecutionPollers = (...args) => globalThis.GEO?.stopAllExecutionPollers?.(...args);
  const suggestSkillCompetitors = (...args) => globalThis.GEO?.suggestSkillCompetitors?.(...args);
  const suggestSkillKeywords = (...args) => globalThis.GEO?.suggestSkillKeywords?.(...args);
  const workflowText = (...args) => globalThis.GEO?.workflowText?.(...args);
  const analysisPollers = new Map();
  const lastProjectKeyPrefix = "geo_last_project_id";

  const $ = (id) => document.getElementById(id);
  const elements = {
    loginLink: $("loginLink"),
    errorLoginLink: $("errorLoginLink"),
    retryButton: $("retryButton"),
    errorRetryButton: $("errorRetryButton"),
    workspaceMenu: $("workspaceMenu"),
    workspaceMenuLinks: Array.from(document.querySelectorAll("[data-view-target]")),
    workspaceViews: Array.from(document.querySelectorAll("[data-workspace-view]")),
    workspaceViewEyebrow: $("workspaceViewEyebrow"),
    workspaceViewTitle: $("workspaceViewTitle"),
    workspaceViewDescription: $("workspaceViewDescription"),
    // 独立结果页（批 B 第 1 步搭壳，第 2 步接正文渲染）
    resultPageTitle: $("resultPageTitle"),
    resultPageSnapshotNote: $("resultPageSnapshotNote"),
    resultPageMeta: $("resultPageMeta"),
    resultPageTabs: $("resultPageTabs"),
    resultPageActions: $("resultPageActions"),
    resultPageMessage: $("resultPageMessage"),
    resultPageBody: $("resultPageBody"),
    userLabel: $("userLabel"),
    accountLink: $("accountLink"),
    reloadProjectsButton: $("reloadProjectsButton"),
    newProjectButton: $("newProjectButton"),
    cancelCreateProjectButton: $("cancelCreateProjectButton"),
    createProjectPanel: $("createProjectPanel"),
    createProjectForm: $("createProjectForm"),
    createProjectMessage: $("createProjectMessage"),
    projectDetail: $("projectDetail"),
    projectDetailMessage: $("projectDetailMessage"),
    detailTitle: $("detailTitle"),
    settingsForm: $("settingsForm"),
    settingsMessage: $("settingsMessage"),
    openAnalysisButton: $("openAnalysisButton"),
    deleteProjectButton: $("deleteProjectButton"),
    pcContext: $("pcContext"),
    pcName: $("pcName"),
    pcMeta: $("pcMeta"),
    pjStageRail: $("pjStageRail"),
    pjDossier: $("pjDossier"),
    pjCreateRail: $("pjCreateRail"),
    pjCreateRailCaption: $("pjCreateRailCaption"),
    editProjectButton: $("editProjectButton"),
    settingsWrap: $("settingsWrap"),
    cancelEditProjectButton: $("cancelEditProjectButton"),
    runAnalysisPanelButton: $("runAnalysisPanelButton"),
    skillPanel: $("skillPanel"),
    skillDiagnosisBlock: $("skillDiagnosisBlock"),
    skillWorkspaceTabs: $("skillWorkspaceTabs"),
    skillToolsPanel: $("skillToolsPanel"),
    skillContextBackLink: $("skillContextBackLink"),
    reloadSkillsButton: $("reloadSkillsButton"),
    skillMessage: $("skillMessage"),
    skillCatalogHeading: $("skillCatalogHeading"),
    skillFilters: $("skillFilters"),
    skillList: $("skillList"),
    skillBriefPanel: $("skillBriefPanel"),
    skillExecutionResult: $("skillExecutionResult"),
    // ⚠️ skillExecutionHistory / reloadExecutionsButton 的登记已删：
    skillPendingStrip: $("skillPendingStrip"),
    openAllAnalysesButton: $("openAllAnalysesButton"),
    openAllSkillReportsButton: $("openAllSkillReportsButton"),
    assetPanel: $("assetPanel"),
    assetMessage: $("assetMessage"),
    assetNextPanel: $("assetNextPanel"),
    assetSourceFilter: $("assetSourceFilter"),
    assetSearchForm: $("assetSearchForm"),
    assetSearchInput: $("assetSearchInput"),
    clearAssetSearchButton: $("clearAssetSearchButton"),
    assetHistory: $("assetHistory"),
    assetResult: $("assetResult"),
    reloadAssetsButton: $("reloadAssetsButton"),
    analysisPanel: $("analysisPanel"),
    analysisMessage: $("analysisMessage"),
    analysisHistory: $("analysisHistory"),
    analysisResult: $("analysisResult"),
    errorMessage: $("errorMessage"),
    // Team
    teamPanel: $("teamPanel"),
    teamList: $("teamList"),
    teamMessage: $("teamMessage"),
    inviteTeamMemberButton: $("inviteTeamMemberButton"),
    teamInviteForm: $("teamInviteForm"),
    inviteForm: $("inviteForm"),
    cancelInviteButton: $("cancelInviteButton"),
    inviteMessage: $("inviteMessage"),
    reloadTeamButton: $("reloadTeamButton"),
    // AI Keys
    aikeysPanel: $("aikeysPanel"),
    aikeyList: $("aikeyList"),
    aikeysMessage: $("aikeysMessage"),
    addAiKeyButton: $("addAiKeyButton"),
    aikeeyForm: $("aikeyForm"),
    createAiKeyForm: $("createAiKeyForm"),
    cancelAiKeyButton: $("cancelAiKeyButton"),
    aiKeyCreateMessage: $("aiKeyCreateMessage"),
    reloadAiKeysButton: $("reloadAiKeysButton"),
    // Knowledge
    knowledgePanel: $("knowledgePanel"),
    kbList: $("kbList"),
    knowledgeMessage: $("knowledgeMessage"),
    createKnowledgeBaseButton: $("createKnowledgeBaseButton"),
    kbCreateForm: $("kbCreateForm"),
    createKbForm: $("createKbForm"),
    cancelKbButton: $("cancelKbButton"),
    kbCreateMessage: $("kbCreateMessage"),
    reloadKnowledgeBasesButton: $("reloadKnowledgeBasesButton"),
    kbDetail: $("kbDetail"),
    kbDetailTitle: $("kbDetailTitle"),
    kbDetailMessage: $("kbDetailMessage"),
    kbBackButton: $("kbBackButton"),
    kbDeleteButton: $("kbDeleteButton"),
    kbUploadForm: $("kbUploadForm"),
    kbUploadMessage: $("kbUploadMessage"),
    kbIngestUrlForm: $("kbIngestUrlForm"),
    kbIngestMessage: $("kbIngestMessage"),
    kbDocList: $("kbDocList"),
    reloadKbDocsButton: $("reloadKbDocsButton"),
    kbSearchTestButton: $("kbSearchTestButton"),
    // Enterprise Knowledge (EK)
    ekMessage: $("ekMessage"),
    ekHost: $("ekHost"),
    ekRows: $("ekRows"),
    ekErrorNo: $("ekErrorNo"),
    ekRetryButton: $("ekRetryButton"),
    ekNewButton: $("ekNewButton"),
    ekEmptyNewButton: $("ekEmptyNewButton"),
    ekReloadButton: $("ekReloadButton"),
    ekCreateForm: $("ekCreateForm"),
    ekCreateFormEl: $("ekCreateFormEl"),
    ekCancelCreateButton: $("ekCancelCreateButton"),
    ekCreateMessage: $("ekCreateMessage"),
    ekDetail: $("ekDetail"),
    ekDetailTitle: $("ekDetailTitle"),
    ekDetailStatus: $("ekDetailStatus"),
    ekDetailMessage: $("ekDetailMessage"),
    ekBackButton: $("ekBackButton"),
    ekDeleteButton: $("ekDeleteButton"),
    ekDraftEditor: $("ekDraftEditor"),
    ekAutosaveStatus: $("ekAutosaveStatus"),
    ekValidateButton: $("ekValidateButton"),
    ekGenerateButton: $("ekGenerateButton"),
    ekPublishButton: $("ekPublishButton"),
    ekRevisionsButton: $("ekRevisionsButton"),
    ekValidationPanel: $("ekValidationPanel"),
    ekPublishedInfo: $("ekPublishedInfo"),
    ekSourcesPanel: $("ekSourcesPanel"),
    ekAddSourceForm: $("ekAddSourceForm"),
    ekAddSourceMessage: $("ekAddSourceMessage"),
    ekSegmentTabs: $("kbSegmentTabs"),
    // Sensitive Words (SW)
    swPanel: $("sensitiveWordsPanel"),
    swList: $("swList"),
    swMessage: $("swMessage"),
    swAddFormEl: $("swAddFormEl"),
    swAddMessage: $("swAddMessage"),
    swReloadButton: $("swReloadButton"),
    // Skill Prompts (SP)
    spPanel: $("skillPromptsPanel"),
    spList: $("spList"),
    spMessage: $("spMessage"),
    spReloadButton: $("spReloadButton"),
    // URL 导入（UI）/ 文章分类（CAT）— 2026-09-21 工作台功能补齐
    // ⛔ API 令牌（TOK）登记已删：2026-09-22 用户口径 —— 租户不得创建/吊销令牌。
    urlImportsPanel: $("urlImportsPanel"),
    urlImportForm: $("urlImportForm"),
    urlImportRows: $("urlImportRows"),
    urlImportMessage: $("uiMessage"),
    urlImportFormMessage: $("urlImportFormMessage"),
    urlImportSubmitButton: $("urlImportSubmitButton"),
    urlImportsReloadButton: $("uiReloadButton"),
    categoriesPanel: $("categoriesPanel"),
    categoryForm: $("categoryForm"),
    categoryRows: $("categoryRows"),
    categoryMessage: $("catMessage"),
    categoryFormMessage: $("categoryFormMessage"),
    categorySubmitButton: $("categorySubmitButton"),
    categoriesReloadButton: $("catReloadButton"),
    accountSummaryReload: $("accountSummaryReload"),
    accountSummaryMessage: $("accountSummaryMessage"),
    accountSummaryGrid: $("accountSummaryGrid"),
    accountSummaryLedger: $("accountSummaryLedger"),
    atDynamicFieldRow: $("atDynamicFieldRow"),
    atDynamicFields: $("atDynamicFields"),
    // Analytics
    analyticsPanel: $("analyticsPanel"),
    analyticsMessage: $("analyticsMessage"),
    analyticsSummary: $("analyticsSummary"),
    analyticsContent: $("analyticsContent"),
    analyticsDaysSelect: $("analyticsDaysSelect"),
    reloadAnalyticsButton: $("reloadAnalyticsButton"),
    activityList: $("activityList"),
    // Publishing
    publishingPanel: $("publishingPanel"),
    publishingMessage: $("publishingMessage"),
    channelList: $("channelList"),
    addChannelButton: $("addChannelButton"),
    channelForm: $("channelForm"),
    createChannelForm: $("createChannelForm"),
    cancelChannelButton: $("cancelChannelButton"),
    channelCreateMessage: $("channelCreateMessage"),
    reloadChannelsButton: $("reloadChannelsButton"),
    channelDetail: $("channelDetail"),
    channelDetailTitle: $("channelDetailTitle"),
    channelDetailMessage: $("channelDetailMessage"),
    channelHealthButton: $("channelHealthButton"),
    channelLogsButton: $("channelLogsButton"),
    channelRewriteButton: $("channelRewriteButton"),
    channelDeleteButton: $("channelDeleteButton"),
    channelBackButton: $("channelBackButton"),
    channelHealthResult: $("channelHealthResult"),
    channelLogsResult: $("channelLogsResult"),
    channelRewriteResult: $("channelRewriteResult"),
    // ⛔ 渠道密钥 / Agent 包 / 信标插件的按钮与结果槽登记已删（2026-09-22 用户口径：
    //    租户只做积分展示，不得出现 key 与令牌的创建/删除入口）。后端端点保留。
    // 阶段 4 分类/标签同步不需要凭据，留在租户侧。
    channelTaxonomyButton: $("channelTaxonomyButton"),
    channelTaxonomyResult: $("channelTaxonomyResult"),
    // 站点文件 (robots.txt / sitemap)
    publishingSiteFilesResult: $("publishingSiteFilesResult"),
    generateSitemapButton: $("generateSitemapButton"),
    robotsTxtButton: $("robotsTxtButton"),
    // 项目阶段推进 / 回退 / 时间线
    // AI 辅助建议（2026-09-04 起长在项目管理页的「项目档案」列，不在技能页）
    // ⚠️ skillSuggestSection 的登记已删：它此前唯一的消费者是 applySkillContext()
    skillSuggestResult: $("skillSuggestResult"),
    // 两个推荐各自的结果槽。共用一个容器时，后点的那个会把先点的结果抹掉。
    skillSuggestKeywordsResult: $("skillSuggestKeywordsResult"),
    skillSuggestCompetitorsResult: $("skillSuggestCompetitorsResult"),
    suggestKeywordsForm: $("suggestKeywordsForm"),
    suggestCompetitorsForm: $("suggestCompetitorsForm"),
    // 知识库统计
    kbStats: $("kbStats"),
    // 订阅订单支付二维码
    orderQrResult: $("orderQrResult"),
    // 项目渠道绑定 (B6.4)
    projectChannelBinding: $("projectChannelBinding"),
    projectChannelMessage: $("projectChannelMessage"),
    projectChannelList: $("projectChannelList"),
    projectChannelSaveMessage: $("projectChannelSaveMessage"),
    reloadProjectChannelsButton: $("reloadProjectChannelsButton"),
    saveProjectChannelsButton: $("saveProjectChannelsButton"),
    // Platform
    platformPanel: $("platformPanel"),
    platformMessage: $("platformMessage"),
    platformContent: $("platformContent"),
    platformDaysSelect: $("platformDaysSelect"),
    reloadPlatformButton: $("reloadPlatformButton"),
    platformAdminSection: $("platformAdminSection"),
    // 单 7 手动发布：外链到自持 session 的独立壳页 /manual-publish。
    manualPublishMenuLink: $("manualPublishMenuLink"),
    // 单 10 主题包生成：同构外链，壳页 /theme-package。
    themePackageMenuLink: $("themePackageMenuLink"),
    // Content Library (CL0-CL5)
    contentLibraryPanel: $("contentLibraryPanel"),
    clTabs: $("clTabs"),
    clSearchInput: $("clSearchInput"),
    clMessage: $("clMessage"),
    clTruncNote: $("clTruncNote"),
    clHost: $("clHost"),
    clRows: $("clRows"),
    clErrorNo: $("clErrorNo"),
    clRetryButton: $("clRetryButton"),
    clNewArticleButton: $("clNewArticleButton"),
    clEmptyGoSkill: $("clEmptyGoSkill"),
    clEmptyBackAll: $("clEmptyBackAll"),
    clDrawer: $("clDrawer"),
    clDrawerMask: $("clDrawerMask"),
    clDrawerTitle: $("clDrawerTitle"),
    clDrawerSub: $("clDrawerSub"),
    clDrawerBody: $("clDrawerBody"),
    clDrawerFoot: $("clDrawerFoot"),
    clDrawerClose: $("clDrawerClose"),
    // Automation Tasks (AT0-AT4)
    automationTasksPanel: $("automationTasksPanel"),
    atMessage: $("atMessage"),
    atHost: $("atHost"),
    atRows: $("atRows"),
    atErrorNo: $("atErrorNo"),
    atRetryButton: $("atRetryButton"),
    atNewTaskButton: $("atNewTaskButton"),
    atFormPanel: $("atFormPanel"),
    atForm: $("atForm"),
    atCancelFormButton: $("atCancelFormButton"),
    atProjectHint: $("atProjectHint"),
    atFormError: $("atFormError"),
    atSubmitButton: $("atSubmitButton"),
    atRunsHost: $("atRunsHost"),
    atRunsRows: $("atRunsRows"),
    atRunsErrorNo: $("atRunsErrorNo"),
    atRunsRetryButton: $("atRunsRetryButton"),
  };

  function showState(id) {
    stateIds.forEach((stateId) => {
      const node = $(stateId);
      if (node) node.hidden = stateId !== "workspaceState";
    });
    sessionReady = id === "workspaceState";
    syncWorkspaceAvailability();
    if (!sessionReady) {
      $("workspaceLoadNotice").hidden = false;
      $("workspaceLoadMessage").textContent = id === "errorState"
        ? elements.errorMessage.textContent : "正在验证登录状态…";
      $("workspaceLoadRetry").hidden = id !== "errorState";
      $("workspaceLoadLogin").hidden = id !== "errorState";
    }
  }

  function syncWorkspaceAvailability() {
    const workspace = $("workspaceState");
    workspace.dataset.sessionReady = String(sessionReady);
    workspace.querySelector(".workspace-topbar").inert = !sessionReady;
    workspace.querySelector(".workspace-sidebar").inert = !sessionReady;
    elements.workspaceViews.forEach(node => {
      const waiting = !sessionReady || (!projectsReady && projectViews.includes(node.dataset.workspaceView));
      node.inert = waiting;
      node.dataset.waitingForProject = String(sessionReady && waiting);
    });
    // Prevent creating/switching projects while the initial list is still being restored.
    if (elements.newProjectButton) elements.newProjectButton.disabled = !projectsReady;
    if ($("projectNavChildren")) $("projectNavChildren").inert = !projectsReady;
  }

  async function restoreWorkspaceProjects() {
    if (restoringProjects) return restoringProjects;
    projectsReady = false;
    syncWorkspaceAvailability();
    $("workspaceLoadNotice").hidden = false;
    $("workspaceLoadMessage").textContent = "正在加载项目，可先使用其他菜单。";
    $("workspaceLoadRetry").hidden = true;
    $("workspaceLoadLogin").hidden = true;
    restoringProjects = (async () => {
      try {
        await loadProjects();
        projectsReady = true;
        $("workspaceLoadNotice").hidden = true;
        syncWorkspaceAvailability();
        if (projectViews.includes(state.activeView)) setActiveWorkspaceView(currentWorkspaceRoute());
        else void globalThis.GEO?.loadProjectTrash?.();
        if (currentWorkspaceRoute() === "projects/new") {
          openCreateProjectPanel();
        }
      } catch (error) {
        $("workspaceLoadMessage").textContent = `项目加载失败：${error.message || "请稍后重试。"}`;
        $("workspaceLoadRetry").hidden = false;
      } finally {
        restoringProjects = null;
      }
    })();
    return restoringProjects;
  }

  function loadWorkspaceViewData(view, param) {
    viewLoadState.active = null;
    $("workspaceViewLoadNotice").hidden = true;
    if (!sessionReady || (!projectsReady && projectViews.includes(view))) return;
    const projectId = state.selectedProject?.id;
    const key = JSON.stringify([recentProjectStorageKey(), projectViews.includes(view) ? projectId : null, view, param]);
    viewLoadState.active = key;
    if (viewLoads.has(key)) {
      $("workspaceViewLoadNotice").hidden = false;
      $("workspaceViewLoadMessage").textContent = "正在加载当前页面…";
      $("workspaceViewLoadRetry").hidden = true;
      return;
    }
    const tasks = [];
    if (view === "skill" && projectId) tasks.push(loadAnalyses(projectId));
    // 2026-09-21 §7 拆分：技能目录与执行历史的加载器注册在 geo-entry-skills.js，
    if (view === "asset" && projectId) tasks.push(loadArticles(projectId));
    if (["personal-settings", "account-security"].includes(view)) tasks.push(loadPersonalAccount());
    if (view === "automation") tasks.push(loadAutomationTasks(), loadTaskRuns());
    if (view === "knowledge") tasks.push(loadKnowledgeBases());
    if (view === "aikeys") tasks.push(loadAiKeys());
    if (view === "result") tasks.push(renderResultPage(param));
    if (view === "sensitive-words" && !state.sensitiveWordsLoaded) tasks.push(loadSensitiveWords());
    if (view === "skill-prompts" && !state.skillPromptsLoaded) tasks.push(loadSkillPrompts());
    // 2026-09-21 工作台功能补齐（计划 §5）：新增三视图的数据加载。
    // URL 导入与分类是账户级列表（不分项目），每次进页面都回读，状态才不会停在旧快照。
    // 2026-09-21 §7 拆分：这三页的加载器注册在 geo-entry-management.js，主文件只按视图取用。
    // 走 window.GEO（而不是闭包里的 const）：契约测试会把个别函数抽出来在沙箱里跑，
    globalThis.GEO?.runViewLoaders?.(view, tasks);
    if (view === "analytics") tasks.push(loadAnalytics());
    if (!tasks.length) return;
    $("workspaceViewLoadNotice").hidden = false;
    $("workspaceViewLoadMessage").textContent = "正在加载当前页面…";
    $("workspaceViewLoadRetry").hidden = true;
    const pending = Promise.allSettled(tasks).then(results => {
      if (viewLoadState.active !== key || !sessionReady) return;
      const failed = results.find(result => result.status === "rejected" || result.value === false);
      $("workspaceViewLoadNotice").hidden = !failed;
      if (failed) {
        $("workspaceViewLoadMessage").textContent = `部分数据加载失败：${failed.reason?.message || "请重试，或查看页面中的错误提示。"}`;
        $("workspaceViewLoadRetry").hidden = false;
      }
    }).finally(() => viewLoads.delete(key));
    viewLoads.set(key, pending);
  }

  const defaultWorkspaceView = "projects";
  const workspaceViewAliases = {
    settings: "personal-settings",
    security: "account-security",
    projectWorkspacePanel: "projects",
    projectDetail: "projects",
    balance: "subscription",
    analysisPanel: "skill",
    analysis: "skill",
    skillPanel: "skill",
    assetPanel: "asset",
    teamPanel: "team",
    aikeysPanel: "aikeys",
    knowledgePanel: "knowledge",
    sensitiveWordsPanel: "sensitive-words",
    skillPromptsPanel: "skill-prompts",
    urlImportsPanel: "url-imports",
    categoriesPanel: "categories",
    analyticsPanel: "analytics",
    publishingPanel: "publishing",
    subscriptionPanel: "subscription",
    platformPanel: "platform",
    automationTasksPanel: "automation",
  };
  const workspaceViewMeta = {
    aikeys: { eyebrow: "设置", title: "模型配置", description: "配置当前工作区的模型服务。" },
    "personal-settings": { eyebrow: "设置", title: "个人设置", description: "管理你在言及的个人资料。" },
    "account-security": { eyebrow: "设置", title: "账号安全", description: "管理登录密码与登录状态。" },
    projects: {
      eyebrow: "项目",
      title: "项目管理",
      description: "项目档案与阶段推进；切换项目用左上角的项目卡。",
    },
    analysis: {
      eyebrow: "分析诊断",
      title: "诊断与路线",
      description: "先做一次项目综合体检，得到整体诊断、关键发现和下一步建议。",
    },
    skill: {
      eyebrow: "分析诊断",
      title: "诊断与路线",
      description: "先做意图挖掘找问题，再做综合诊断看现状，然后按阶段用单项技能落地。",
    },
    asset: {
      eyebrow: "分析诊断",
      title: "历史报告",
      description: "查找并阅读当前项目的诊断、路线与监测报告。",
    },
    "content-library": {
      eyebrow: "内容",
      title: "文章",
      description: "当前项目的可发布文章；按需要处理的紧急度排序，发布前的人工关卡都在这里。",
    },
    "materials": {
      eyebrow: "内容",
      title: "素材",
      description: "管理标题、关键词、图片和作者，在内容创作时选择使用。",
    },
    team: {
      eyebrow: "设置",
      title: "团队管理",
      description: "管理团队成员、邀请和角色权限。",
    },
    knowledge: {
      eyebrow: "设置",
      title: "企业知识库",
      description: "管理 RAG 知识库与企业知识档案：上传文档/URL，或把企业知识整理成档案、生成并发布到知识库。",
    },
    analytics: {
      eyebrow: "项目",
      title: "项目总览",
      description: "当前项目的文章、技能执行与渠道发布统计。",
    },
    publishing: {
      eyebrow: "增长",
      title: "发布管理",
      description: "管理发布渠道、查看分发状态和日志。",
    },
    usage: { eyebrow: "用量", title: "用量明细", description: "模型调用次数与 tokens。" },
    platform: {
      eyebrow: "平台管理",
      title: "运营面板",
      description: "当前工作区运营概览与审计，另提供系统健康和设置。",
    },
    // 2026-09-21 工作台功能补齐（计划 §5）：三个新视图的自带页头文案。
    // 三页都在 selfTitledViews 名单里（面板自带 panel-heading），这份 meta 只在兜底态用得上。
    "url-imports": {
      eyebrow: "内容",
      title: "URL 导入",
      description: "粘贴网页地址，抓取正文并生成当前项目的文章草稿。",
    },
    categories: {
      eyebrow: "内容",
      title: "文章分类",
      description: "维护当前账户下的文章分类，用于归类与筛选。",
    },
    automation: {
      eyebrow: "增长",
      title: "自动化任务",
      description: "安排内容生成时间，按需设置自动发布与分发，并查看运行记录。",
    },
    leads: {
      eyebrow: "增长",
      title: "线索表单",
      description: "建表单收集访客线索、跟进到成交。表单页对访客公开无需登录，线索只有本租户可见。",
    },
    // 结果页自带页头（见 selfTitledViews），这份 meta 只在没参数的兜底态用得上。
    result: {
      eyebrow: "分析诊断",
      title: "报告",
      description: "单份报告的阅读页。",
    },
  };

  // 一人一租户（2026-08-31 老板拍板）：角色系统冻结，团队管理入口对客户关闭。
  //
  // 光把 blade 里的侧栏链接设成 hidden 还不够 —— 地址栏直接敲 /#team 照样能把面板放出来，
  // 因为 setActiveWorkspaceView() 是拿 window.location.hash 喂进来的（见页面启动那两处）。
  // 把冻结视图在这里折回默认视图，入口才算真的关上。
  //
  // 团队写接口也已关闭，读取接口仅供指派人下拉选择当前用户。
  // 不能通过恢复菜单来重新启用团队功能；账号归属必须保持独立。
  // 工作区仍是内部隔离边界，客户不再管理或命名它。
  const FROZEN_WORKSPACE_VIEWS = ["team", "workspace-settings"];
  const SETTINGS_VIEWS = ["personal-settings", "account-security", "sensitive-words", "skill-prompts",
    "usage", "aikeys"];
  // ═══════════════════════════════════════════════
  // 带参路由（执行入口 / 独立结果页重构 · 批 B 第 1 步，2026-09-05）
  // ═══════════════════════════════════════════════
  // hash 从「只有视图名」升级成「视图名[/参数]」：
  //   #asset       → 视图 asset，无参数（现有 21 个视图全走这条，行为一字不变）
  //   #result/41   → 视图 result，参数 "41"（结果页看 41 号报告）
  // 参数只认第一段，多余的斜杠丢掉（#result/41/x → 41）。
  //
  // ⚠️ 视图名本身绝不能带斜杠。现有视图名都是 kebab-case（billing-overview 这种），
  //    新加视图沿用即可；一旦视图名里出现 "/"，它会被当成分隔符、视图名被截断成前半截，
  //    normalizeWorkspaceView 找不到面板就静默折回项目管理页 —— 不报错，很难查。
  function parseWorkspaceHash(value) {
    const raw = String(value || "").replace(/^#/, "");
    const slash = raw.indexOf("/");
    if (slash < 0) return { view: raw, param: "" };
    return { view: raw.slice(0, slash), param: raw.slice(slash + 1).split("/")[0] };
  }

  // state.activeView 只存视图名，参数另存在 state.activeViewParam。
  // ⚠️ 想「按当前状态重放一次」的地方（renderProjectDetail 就是）必须用这个函数拼回去，
  //    直接把 state.activeView 传进 setActiveWorkspaceView 会把参数抹成空 ——
  //    地址栏还是 #result/41，内部状态却已经不知道是 41 号了。
  function currentWorkspaceRoute() {
    return state.activeViewParam ? `${state.activeView}/${state.activeViewParam}` : state.activeView;
  }

  function expandWorkspaceAlias(key) {
    return workspaceViewAliases[key] || key || defaultWorkspaceView;
  }

  function normalizeWorkspaceView(value) {
    const view = expandWorkspaceAlias(parseWorkspaceHash(value).view);
    if (FROZEN_WORKSPACE_VIEWS.includes(view)) return defaultWorkspaceView;
    const exists = elements.workspaceViews.some((node) => node.dataset.workspaceView === view);
    return exists ? view : defaultWorkspaceView;
  }

  function setActiveWorkspaceView(value, { updateHash = false } = {}) {
    const view = normalizeWorkspaceView(value);
    const parsed = parseWorkspaceHash(value);
    if (parsed.view === "workspace-settings" || (view === "projects" && parsed.param === "settings" && projectsReady && !state.selectedProject)) {
      const url = new URL(window.location.href);
      url.hash = `#${defaultWorkspaceView}`;
      history.replaceState(null, "", url);
      if (parsed.view === "projects") return setActiveWorkspaceView(defaultWorkspaceView);
    }
    // 视图被折回默认视图时（视图名不存在、或已冻结），参数必须一起丢掉：
    const param = expandWorkspaceAlias(parsed.view) === view ? parsed.param : "";
    const meta = workspaceViewMeta[view] || workspaceViewMeta[defaultWorkspaceView];
    const settingsMode = SETTINGS_VIEWS.includes(view);
    const sidebar = document.getElementById("workspaceSidebar");
    if (sidebar) sidebar.dataset.navMode = settingsMode ? "settings" : "work";
    if (!settingsMode) state.lastWorkView = param ? `${view}/${param}` : view;
    state.activeView = view;
    state.activeViewParam = param;
    if (view === "content-library") {
      state.clContentType = ["explainer", "comparison", "ranking", "general"].includes(param) ? param : "";
      const typeFilter = document.getElementById("clContentType");
      if (typeFilter) typeFilter.value = state.clContentType;
    }
    elements.workspaceViews.forEach((node) => {
      const isActive = node.dataset.workspaceView === view;
      node.hidden = !isActive;
      node.classList.toggle("is-active", isActive);
      node.setAttribute("aria-hidden", isActive ? "false" : "true");
    });
    elements.workspaceMenuLinks.forEach((link) => {
      const isActive = link.dataset.viewTarget === view;
      link.setAttribute("aria-current", isActive ? "page" : "false");
    });
    if (view === "projects" && param === "trash") {
      document.querySelector('[data-view-target="projects"]')?.setAttribute("aria-current", "false");
      document.getElementById("globalTrashNav")?.setAttribute("aria-current", "page");
    } else {
      document.getElementById("globalTrashNav")?.setAttribute("aria-current", "false");
    }
    const activeLink = elements.workspaceMenuLinks.find(link => link.getAttribute("aria-current") === "page" && !link.hidden);
    const activeItems = activeLink?.closest(".workspace-menu-items");
    if (activeItems?.hidden) {
      activeItems.hidden = false;
      activeItems.parentElement.querySelector(".workspace-menu-group")?.setAttribute("aria-expanded", "true");
    }
    window.requestAnimationFrame?.(() => activeLink?.scrollIntoView?.({ block: "nearest" }));
    if (elements.workspaceViewEyebrow) elements.workspaceViewEyebrow.textContent = meta.eyebrow;
    if (elements.workspaceViewTitle) elements.workspaceViewTitle.textContent = meta.title;
    document.title = `${view === "projects" && param === "settings" ? "项目设置" : meta.title} · ${document.documentElement.dataset.brandName || "言及"}`;
    if (elements.workspaceViewDescription) elements.workspaceViewDescription.textContent = meta.description;
    // ⚠️ 「刷新」原本住在 titlebar 里。项目管理这一页现在隐藏 titlebar，
    if (elements.reloadProjectsButton) elements.reloadProjectsButton.hidden = view !== "projects";
    if (view === "skill") applySkillContext();
    // 自带页头的视图隐藏共享 titlebar，避免标题重复堆叠。
    // 财务五视图是原有的（T8 页头行）；「项目管理」2026-09-04 加入 ——
    // 它的 titlebar 写的是「项目管理」，而侧栏已经高亮同一项、面板头又写着项目名，
    // 同一件事说三遍，而且 chrome 那份字号最大（老板原话：
    // 「项目旁边写着大 N 多号的项目管理」）。
    // ⚠️ 加视图进这个名单前先确认它**自己有页头**，否则那一页会没有标题。
    //    项目管理有两个：选中项目时是 .pj-panel-head 的项目名，
    const selfTitledViews = [
      "usage",
      "projects",
      "content-library", "materials", // Both have their own view-title and primary action.
      "automation", "knowledge", "aikeys", "sensitive-words", "skill-prompts",
      // 2026-09-21：两页各自带 panel-heading（标题在自己面板里），进名单避免共享 titlebar 再写一遍标题。
      "url-imports", "categories",
      "analytics", "publishing", "leads", "platform",
      "result",   // 2026-09-05：结果页的页头写的是报告标题本身，共享 titlebar 会变成第二个标题
    ];
    const titlebar = document.getElementById("workspaceViewTitlebar");
    if (titlebar) titlebar.hidden = selfTitledViews.includes(view);
    const directory = document.getElementById("projectDirectoryPanel");
    const editor = document.getElementById("projectEditorViews");
    const trashPage = document.getElementById("projectTrashSection");
    const settingsPage = document.getElementById("projectSettingsSection");
    if (directory) directory.hidden = !(view === "projects" && param === "list");
    if (editor) editor.hidden = view === "projects" && ["list", "trash", "settings"].includes(param);
    if (trashPage) trashPage.hidden = !(view === "projects" && param === "trash");
    if (settingsPage) settingsPage.hidden = !(view === "projects" && param === "settings");
    if (elements.openAnalysisButton && view === "projects" && param !== "settings") elements.openAnalysisButton.hidden = false;
    const projectContext = document.getElementById("activeProjectContext");
    if (projectContext) projectContext.hidden = !state.selectedProject || !["skill", "asset", "result", "content-library", "publishing"].includes(view);
    syncWorkspaceAvailability();
    loadWorkspaceViewData(view, param);
    // 带参路由：地址栏写回来的必须是「视图 + 参数」，否则从 #result/41 走一趟
    if (updateHash) {
      const target = param ? `#${view}/${param}` : `#${view}`;
      if (window.location.hash !== target) {
        const url = new URL(window.location.href);
        url.hash = target;
        history.pushState(null, "", url);
      }
    }
  }

  // ═══════════════════════════════════════════════
  // 独立结果页（执行入口 / 独立结果页重构 · 批 B）
  // ═══════════════════════════════════════════════
  // 入口：#result/<报告编号>。编号是历史报告那套 article id
  // （GET /api/v1/projects/{id}/articles，综合诊断与技能报告归档后都是一条 article）。
  //
  // 页签按数据有无动态生成（见 reportZoneHasContent）—— 不是给某个技能写死三个页签，
  // 所以别的技能接进来不用改这里：它有问题表就多一个页签，没有就少一个。
  //
  // ⚠️ 这一页不封顶。报告正文原来在执行入口里显示，太长会把页面撑到两万多像素，
  //    所以 2026-09-04 给它加过一层「收到一屏 + 展开全文」的封顶。正文搬来这一页之后
  //    封顶已经整套删掉了 —— 这里本来就是读全文的地方，再收一次就是在读文章的地方
  //    装了个折叠。别再往回加。

  // 请求序号：报告切得快时，先发的请求可能后回来。只认最后一次。
  let resultPageSeq = 0;

  async function renderResultPage(articleId) {
    const id = Number(articleId) || 0;
    const seq = ++resultPageSeq;

    if (!id) {
      state.resultArticle = null;
      showResultPageNotice("没有指定报告", "地址里缺少报告编号。请从「历史报告」里点开一份报告。");
      return;
    }

    // 同一份报告来回切页签时不重复请求。
    if (state.resultArticle && Number(state.resultArticle.id) === id) {
      renderResultPageLoaded(state.resultArticle);
      return;
    }

    showResultPageNotice("正在打开报告…", `报告编号 ${id}`);
    try {
      const article = await api(`/api/v1/articles/${id}`);
      // 请求回来时人可能已经翻到别的报告 / 别的页面了，晚到的响应不许覆盖当前内容。
      if (seq !== resultPageSeq) return;
      state.resultArticle = article;
      state.resultZone = "overview";
      renderResultPageLoaded(article);
    } catch (error) {
      if (seq !== resultPageSeq) return;
      state.resultArticle = null;
      // 🔴 显式报错，不静默回落到空页或假数据（防走偏规程）。
      // 中文说清是哪一份打不开，后面原样附上服务端原因 —— 报错原文要留着，
      // 排查的时候「Article not found」和「无权访问」是两件完全不同的事。
      const cause = String(error?.message || "").trim();
      showResultPageNotice(
        "打不开这份报告",
        cause ? `报告编号 ${id}：${cause}` : `报告编号 ${id} 加载失败，请稍后重试。`,
      );
    }
  }

  function showResultPageNotice(title, detail) {
    window.GeoReportReader?.reset();
    resetReportOptions(false);
    const continuation = document.getElementById("resultPageContinuation");
    if (continuation) continuation.innerHTML = "";
    if (elements.resultPageTitle) elements.resultPageTitle.textContent = title;
    if (elements.resultPageMeta) elements.resultPageMeta.textContent = detail || "";
    if (elements.resultPageTabs) {
      elements.resultPageTabs.hidden = true;
      elements.resultPageTabs.innerHTML = "";
    }
    if (elements.resultPageBody) elements.resultPageBody.innerHTML = "";
    if (elements.resultPageActions) elements.resultPageActions.hidden = true;
    if (elements.resultPageSnapshotNote) elements.resultPageSnapshotNote.hidden = true;
    setMessage(elements.resultPageMessage, "");
  }

  function renderResultPageLoaded(article) {
    state.selectedArticleId = article.id;
    resetReportOptions(true);
    const continuation = document.getElementById("resultPageContinuation");
    if (continuation) continuation.innerHTML = reportContinuationMarkup(article);
    renderAssetNextPanel();
    if (Number(article.project_id) === Number(state.selectedProject?.id)) {
      globalThis.GEO?.ensureAssetReportList?.(article.project_id);
    }
    const back = document.getElementById("resultPageBack");
    if (back) {
      back.href = clIsArticle(article) ? "#content-library" : "#asset";
      back.textContent = clIsArticle(article) ? "返回文章列表" : "返回历史报告";
    }
    const data = resultReportData(article);
    if (elements.resultPageTitle) {
      elements.resultPageTitle.textContent = articleDisplayTitle(article);
    }
    const businessTime = value => {
      const date = value ? new Date(value) : null;
      return date && !Number.isNaN(date.valueOf())
        ? date.toLocaleString("zh-CN", { timeZone: "Asia/Shanghai" }) : "未记录";
    };
    if (elements.resultPageMeta) elements.resultPageMeta.textContent = [
      article.project?.name || state.selectedProject?.name || "",
      articleKindLabel(article), `生成/归档 ${businessTime(article.created_at)}`,
      `最后编辑 ${businessTime(article.updated_at)}`,
      data?.question_bank ? `采样 ${data.sampling_date || "未采样"}` : "",
      statusLabel(article.status || "-"),
    ].filter(Boolean).join(" · ");
    if (elements.resultPageActions) elements.resultPageActions.hidden = false;
    if (elements.resultPageSnapshotNote) {
      elements.resultPageSnapshotNote.hidden = clIsArticle(article);
      elements.resultPageSnapshotNote.textContent = "这是生成时的报告快照，官网与下载状态变化后不会自动更新。需要当前结论，请重新运行相应分析。";
    }
    setMessage(elements.resultPageMessage, "");

    if (!data) {
      // 结构化数据取不到时不装作有报告：说清楚是什么情况，并把正文原样交出去。
      if (elements.resultPageTabs) {
        elements.resultPageTabs.hidden = true;
        elements.resultPageTabs.innerHTML = "";
      }
      if (elements.resultPageBody) {
        elements.resultPageBody.innerHTML = articleBodyBlock(article, articleReadableBody(article));
        window.GeoReportReader?.mount({ outline: false });
      }
      return;
    }

    const zones = RESULT_ZONES.filter((zone) => reportZoneHasContent(data, zone.id));
    if (!zones.length) {
      if (elements.resultPageTabs) {
        elements.resultPageTabs.hidden = true;
        elements.resultPageTabs.innerHTML = "";
      }
      if (elements.resultPageBody) {
        elements.resultPageBody.innerHTML = `<p class="muted">这份报告没有可显示的内容。</p>`;
        window.GeoReportReader?.reset();
      }
      return;
    }

    // 记住的页签在这份报告里不存在时，退回第一个（换报告后「问题清单」可能就没了）。
    const active = zones.some((zone) => zone.id === state.resultZone) ? state.resultZone : zones.some(zone => zone.id === "full") ? "full" : zones[0].id;
    state.resultZone = active;

    if (elements.resultPageTabs) {
      // 只有一个分区时不显示页签条：一个页签的页签条是纯噪音。
      elements.resultPageTabs.hidden = zones.length < 2;
      elements.resultPageTabs.innerHTML = zones.map((zone) => `
        <button type="button" class="result-tab" role="tab"
          data-result-zone="${zone.id}" aria-selected="${zone.id === active ? "true" : "false"}">
          ${escapeHtml(zone.label)}
        </button>`).join("");
    }
    renderResultZone(data, active);
  }

  function renderResultZone(data, zone) {
    if (!elements.resultPageBody) return;
    // 「完整报告」渲染整份未过滤的 payload —— 和历史报告页看到的是同一份东西。
    const payload = zone === "full" ? data : reportZoneSlice(data, zone);
    if (zone === "overview" && Array.isArray(data.sections)) {
      const summarySections = data.sections.filter(section => /摘要|结论|关键发现/.test(String(section?.title || ""))).slice(0, 2);
      if (summarySections.length) payload.sections = summarySections;
    }
    const keyOrder = RESULT_ZONES.find((item) => item.id === zone)?.keyOrder;
    // omitTitle：页头那行大字就是报告标题，正文里不能再印一遍。
    const facts = globalThis.GEO?.intentReportFacts?.(data);
    const overviewStats = zone === "overview" && facts?.verified
      ? `<div class="report-overview-totals"><div><span>完整问题清单</span><strong>${facts.total}</strong><small>条</small></div><div><span>P0 优先意图</span><strong>${facts.p0}</strong><small>条</small></div></div>` : "";
    elements.resultPageBody.innerHTML = overviewStats + renderStructuredReportHtml(payload, {
      keyOrder,
      omitTitle: true,
      hideToc: true,
      reader: true,
    });
    window.GeoReportReader?.mount({ outline: zone === "full" });
  }

  function resetReportOptions(loaded) {
    for (const id of ["reportUseOptions", "reportManagement"]) {
      const details = document.getElementById(id);
      if (details) { details.open = false; details.hidden = !loaded; }
    }
    if (elements.assetResult) elements.assetResult.innerHTML = "";
  }

  // 报告的结构化数据有两个来源：综合诊断存在 article.structured，
  // 内容类技能把整份 JSON 存进 body_markdown（外面常包一层 ```json 围栏）。
  // 两条都试，都没有就返回 null，由调用方走「按正文显示」那条路。
  function resultReportData(article) {
    const chunks = parseReportChunks(article?.body_markdown);
    if (chunks) return globalThis.GEO?.normalizeIntentReport?.(Object.assign({}, ...chunks.parts.map(part => part.value)))
      || Object.assign({}, ...chunks.parts.map(part => part.value));
    const candidate = structuredBodyCandidate(String(article?.body_markdown || "").trim());
    if (candidate) {
      const parsed = tryParseJsonValue(candidate);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) return globalThis.GEO?.normalizeIntentReport?.(parsed) || parsed;
    }
    const structured = tryParseJsonValue(article?.structured)
      || (article?.structured && typeof article.structured === "object" ? article.structured : null);
    if (structured && !Array.isArray(structured)
      && Object.keys(structured).some((key) => !REPORT_SKIP_KEYS.has(key) && hasReportFieldValue(structured[key]))) {
      return globalThis.GEO?.normalizeIntentReport?.(structured) || structured;
    }
    return null;
  }

  function parseReportChunks(body) {
    const text = String(body || "");
    const marker = /<!--\s*chunk:[\s\S]*?-->/g;
    const matches = [...text.matchAll(marker)];
    if (!matches.length) return null;
    const parts = matches.map((match, index) => {
      const start = match.index + match[0].length;
      const end = index + 1 < matches.length ? matches[index + 1].index : text.length;
      const raw = text.slice(start, end);
      const value = tryParseJsonValue(raw.trim());
      return { marker: match[0], raw, value };
    });
    if (parts.some(part => !part.value || typeof part.value !== "object" || Array.isArray(part.value))) return null;
    return { preamble: text.slice(0, matches[0].index), parts };
  }

  function openResultEditor() {
    window.GeoReportEditor?.open(state.resultArticle, {
      state, api, tryParseJsonValue, structuredBodyCandidate, parseReportChunks,
      escapeHtml, fieldLabel, statusLabel, setMessage, validationMessage, clToast,
      renderResultPageLoaded,
    });
  }
  function resultEditorDirty() { return window.GeoReportEditor?.dirty() || false; }
  function closeResultEditor(force = false) { return window.GeoReportEditor?.close(force) ?? true; }
  function guardResultEditorNavigation() { return window.GeoReportEditor?.guard() ?? true; }

  function accountLoginUrl() {
    const target = new URL(config.accountLoginUrl || "/login", window.location.href);
    target.searchParams.set("redirect_to", window.location.href);
    return target.toString();
  }

  function token() {
    return sessionStorage.getItem("geo_token") || "";
  }

  function pickToken(payload) {
    return payload?.accessToken || payload?.access_token || payload?.token || "";
  }

  function tenantSubdomain(tenant = state.session?.tenant) {
    return String(tenant?.subdomain || "").trim();
  }

  function projectSelectionStorageKey() {
    return `${lastProjectKeyPrefix}:${tenantSubdomain() || "default"}`;
  }

  function rememberedProjectId() {
    try {
      const value = Number(localStorage.getItem(projectSelectionStorageKey()) || 0);
      return Number.isFinite(value) && value > 0 ? value : null;
    } catch (error) {
      return null;
    }
  }

  function rememberProject(project) {
    if (!project?.id) return;
    try {
      localStorage.setItem(projectSelectionStorageKey(), String(project.id));
      localStorage.setItem(`geo_last_project_summary:${tenantSubdomain() || "default"}`, JSON.stringify({ id: project.id, name: project.name || "", current_stage: project.current_stage ?? null, stage_label: STAGE_FULL_LABELS[project.current_stage] }));
      rememberRecentProject(project);
    } catch (error) {
      // Ignore storage failures; the API remains the source of truth.
    }
    // A standalone feature page carries project_id back into the workbench.
    // Keep that URL in sync when the user later switches projects.
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.has("project_id")) {
        url.searchParams.set("project_id", String(project.id));
        history.replaceState(null, "", url);
      }
    } catch (error) {
      // URL synchronisation is optional; the selected project is already saved.
    }
  }
  function forgetRememberedProject() {
    try {
      localStorage.removeItem(projectSelectionStorageKey());
      localStorage.removeItem(`geo_last_project_summary:${tenantSubdomain() || "default"}`);
    } catch (error) {
      // Ignore storage failures.
    }
  }

  function authHeaders(extra = {}) {
    const headers = {
      Accept: "application/json",
      Authorization: `Bearer ${token()}`,
      ...extra,
    };
    const subdomain = tenantSubdomain();
    if (subdomain) headers["X-Tenant"] = subdomain;
    return headers;
  }

  // B4 修复（docs/GEO-会话身份绑定-工兵执行书-2026-08-31.md §四之三）：
  // 本批给 token 加了有效期 + 会话绑定，中途 401 会从「几乎不发生」变成「正常现象」。
  // 401 时先拿 cookie 重换一张 token（reverifyOnce，并发共用同一次核验）再原样重试一次；
  // 重换也失败 = 没登录（不是没权限）→ 跳登录页，绝不竖「无访问权限」墙。
  // 每个请求最多重试一次：isRetry=true 表示已是「核验后重试」，再 401 就是真没权限，不再核验。
  async function api(path, options = {}) {
    const knowledgeRequest = /^\/api\/v1\/(knowledge-bases|enterprise-knowledge|knowledge-legacy|generation-preferences)(\/|\?|$)/.test(path);
    const projectId = knowledgeRequest ? state.selectedProject?.id : null;
    const knowledgeScope = knowledgeRequest ? `${recentProjectStorageKey()}:${projectId}` : null;
    if (knowledgeRequest && path !== "/api/v1/knowledge-legacy") {
      if (!projectId) throw new Error("请先创建或选择项目，再管理知识资料。");
      options = { ...options, headers: { ...options.headers, "X-Project-Id": String(projectId) } };
    }
    try {
      const body = await apiWithSessionRetry(path, options, false);
      if (knowledgeRequest && knowledgeScope !== `${recentProjectStorageKey()}:${state.selectedProject?.id}`) {
        throw Object.assign(new Error("项目已切换，请在当前项目继续操作。"), { knowledgeContextChanged: true });
      }
      return body;
    } catch (error) {
      if (knowledgeRequest && knowledgeScope !== `${recentProjectStorageKey()}:${state.selectedProject?.id}`) {
        throw Object.assign(new Error("项目已切换，请在当前项目继续操作。"), { knowledgeContextChanged: true });
      }
      if (error?.name === "TimeoutError") throw new Error("数据读取超时，请重试。");
      throw error;
    }
  }

  // 付费写请求必须把一次用户动作绑定到一个稳定键。这个值在调用 api() 前生成，
  // 随 options 原样进入 apiWithSessionRetry；即使中途 401 换 token 后重试，仍是同一键，
  // 服务端幂等中间件会回放第一次成功响应，不会再建一条执行、再扣一次点。
  function newIdempotencyKey(prefix) {
    const uuid = globalThis.crypto?.randomUUID?.()
      || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    return `${prefix}-${uuid}`;
  }

  // 网络断在“服务端已接收、浏览器没收到响应”这个时间窗时，下一次相同提交必须
  // 复用原键。否则服务端看见的是一条新请求，仍可能再跑模型、再扣一次点。
  // 只有明确终态才清除；时间流逝不能证明供应商没有收到请求。

  function idempotencyFingerprintSlot(fingerprint) {
    let first = 2166136261;
    let second = 5381;
    for (let index = 0; index < fingerprint.length; index += 1) {
      const code = fingerprint.charCodeAt(index);
      first = Math.imul(first ^ code, 16777619) >>> 0;
      second = (Math.imul(second, 33) ^ code) >>> 0;
    }
    return `${fingerprint.length.toString(36)}-${first.toString(36)}-${second.toString(36)}`;
  }

  function pendingIdempotencyRequest(prefix, payload, { onlyExisting = false } = {}) {
    const fingerprint = JSON.stringify(payload || {});
    const fingerprintSlot = idempotencyFingerprintSlot(fingerprint);
    const ownerUserId = Number(state.session?.user?.id);
    const ownerTenantId = Number(state.session?.tenant?.id);
    if (!Number.isSafeInteger(ownerUserId) || ownerUserId < 1 || !Number.isSafeInteger(ownerTenantId) || ownerTenantId < 1) throw new Error('登录身份未确认，本次未发送付费请求。');
    const namespace = `geo_pending_idempotency:${tenantSubdomain() || "default"}:${prefix}:`;
    // storage key 只保存不可逆的短指纹，不把用户填写的问题、品牌或关键词写进 key。
    const legacyStorageKey = `${namespace}${fingerprintSlot}`;
    const storageKey = `${namespace}${ownerTenantId}:${ownerUserId}:${fingerprintSlot}`;
    let previousRaw, legacyRaw;
    try { legacyRaw = sessionStorage.getItem(legacyStorageKey); previousRaw = sessionStorage.getItem(storageKey); }
    catch (_) { throw new Error('无法读取原付费请求编号，本次未发送；请先核对原任务。'); }
    if (legacyRaw !== null) throw new Error('旧版原付费请求缺少账号归属，本次未发送；请先核对原任务。');
    if (previousRaw !== null) {
      let previous;
      try { previous = JSON.parse(previousRaw); } catch (_) { /* 原键可能已发送，不能自动换键。 */ }
      if (typeof previous?.key !== 'string' || !previous.key || previous.fingerprint !== fingerprintSlot || previous.owner_user_id !== ownerUserId || previous.owner_tenant_id !== ownerTenantId) {
        throw new Error('原付费请求编号记录损坏或账号不符，本次未发送；请先核对原任务。');
      }
      return { key: previous.key, storageKey };
    }
    if (onlyExisting) return null;

    const request = { key: newIdempotencyKey(prefix), storageKey };
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        key: request.key,
        owner_user_id: ownerUserId,
        owner_tenant_id: ownerTenantId,
        // 只保存短指纹；用户填写的主题、关键词和补充说明不进入浏览器存储。
        fingerprint: fingerprintSlot,
        created_at: Date.now(),
      }));
      if (JSON.parse(sessionStorage.getItem(storageKey) || 'null')?.key !== request.key) {
        throw new Error('stored request key mismatch');
      }
    } catch (_) {
      throw new Error('无法保存本次付费请求编号，本次未发送；请检查浏览器存储后重试。');
    }
    return request;
  }

  function clearPendingIdempotencyRequest(request) {
    if (!request?.storageKey || !request?.key) return;
    try {
      const current = JSON.parse(sessionStorage.getItem(request.storageKey) || "null");
      if (current?.owner_user_id !== Number(state.session?.user?.id) || current?.owner_tenant_id !== Number(state.session?.tenant?.id)) return;
      if (!current?.key || String(current.key) === String(request.key)) {
        sessionStorage.removeItem(request.storageKey);
      }
    } catch (_) {
      // 存储不可用不影响已经完成的服务端请求。
    }
  }

  function retainPendingIdempotencyRequest(error) {
    const action = String(error?.body?.action || "");
    const status = Number(error?.status);
    return !Number.isFinite(status)
      || status >= 500
      || status === 408
      || status === 425
      || status === 429
      || error?.responseParseFailed === true
      || [
        "idempotency_request_in_progress",
        "idempotency_result_pending",
        "idempotency_protection_unavailable",
      ].includes(action);
  }

  async function apiWithSessionRetry(path, options, isRetry) {
    const originalOwner = state.session ? `${state.session.tenant?.id}:${state.session.user?.id}` : null;
    const response = await fetch(path, {
      credentials: "include",
      cache: "no-store",
      // Only reads are bounded here. Paid executions/uploads retain their existing lifecycle.
      signal: String(options.method || "GET").toUpperCase() === "GET" ? AbortSignal.timeout(20000) : undefined,
      ...options,
      headers: authHeaders(options.headers || {}),
    });
    // 2026-08-11：这里原来是 `await response.json().catch(() => ({}))` ——
    // 解析不了就静默当成空对象。于是「HTTP 200 但响应体不是 JSON」会一路走进
    // 成功分支。实际翻过车：传 19MB 文件时 PHP 在 Laravel 启动前就报警告并把
    // 响应头以 200 发出，界面显示「文档已上传」，而文件根本没入库。
    const raw = await response.text();
    if (originalOwner && originalOwner !== `${state.session?.tenant?.id}:${state.session?.user?.id}`) throw Object.assign(new Error('登录账号已切换，原请求结果待核验。'), { identityChanged: true });
    // 2026-08-17：服务端给每个 /api 响应发一个请求编号（AttachRequestId 生成、
    // StampRequestId 贴头），出错时显示给用户，支持侧拿它 grep 日志就能定位。
    const requestId = response.headers.get("X-Request-Id") || "";
    let body = {};
    let parsed = true;
    if (raw.trim() !== "") {
      try {
        body = JSON.parse(raw);
      } catch (_) {
        parsed = false;
      }
    }
    if (!response.ok) {
      if (response.status === 401 && !isRetry) {
        await reverifyOnce();
        return apiWithSessionRetry(path, options, true);
      }
      const error = new Error(body.message || `请求失败，状态码 ${response.status}`);
      error.status = response.status;
      error.body = body;
      error.requestId = requestId;
      throw error;
    }
    if (!parsed) {
      const error = new Error("服务器返回了无法识别的内容，请重试；若反复出现请联系管理员。");
      error.status = response.status;
      error.body = { raw: raw.slice(0, 500) };
      error.requestId = requestId;
      // 2xx 响应体损坏时，服务端可能已经完成付费动作；重试必须复用原幂等键。
      error.responseParseFailed = true;
      throw error;
    }
    return body;
  }

  // 401 自愈：用 cookie 重新换一张 token（POST /api/v1/auth/session-login），
  // 不 reload 页面（reload 会让「重试刚才那一次请求」失去意义）。
  // 并发的 401 共用同一个 promise，只打一次核验接口；重换也失败 = 没登录 → 跳登录页。
  let reverifyPromise = null;
  async function reverifyOnce() {
    if (!reverifyPromise) {
      reverifyPromise = (async () => {
        const response = await fetch(config.sessionLoginEndpoint || "/api/v1/auth/session-login", {
          method: "POST",
          credentials: "include",
          signal: AbortSignal.timeout(15000),
          headers: { Accept: "application/json" },
        });
        if (!response.ok) throw new Error("relogin failed");
        const payload = await response.json().catch(() => ({}));
        const t = pickToken(payload);
        if (!t) throw new Error("relogin failed");
        const identityChanged = state.session && (
          Number(payload?.user?.id) !== Number(state.session?.user?.id)
          || Number(payload?.tenant?.id) !== Number(state.session?.tenant?.id)
        );
        persistSession(payload);
        if (identityChanged) {
          // Never replay an old account's write under a newly authenticated account.
          $("workspaceState").hidden = true;
          window.location.replace(window.location.href);
          throw Object.assign(new Error("登录状态已变化，正在重新加载。"), { identityChanged: true });
        }
        state.session = payload;
      })().catch((err) => {
        if (!err.identityChanged) window.location.href = accountLoginUrl();
        throw err;
      }).finally(() => {
        // 这里只合并同一波并发 401。一次核验结束后必须释放；否则以后新的
        // 会话过期会复用已经完成的旧 Promise，重试仍带旧 token。
        reverifyPromise = null;
      });
    }
    return reverifyPromise;
  }

  // 错误编号统一出口。拿不到编号只有一种可能：请求压根没走到应用层（断网、
  // 网关 502 之类），这时候如实说，不再像以前那样前端 Math.random() 编一个
  // 服务端查无此号的假编号糊弄人。
  function errorNoText(error) {
    const id = error && typeof error.requestId === "string" ? error.requestId : "";
    return id || "无（请求未到达服务器，多为网络或网关问题）";
  }

  function fillErrorNo(node, error) {
    if (!node) return;
    node.textContent = errorNoText(error);
  }

  function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
  }

  // 用户操作失败的提示必须停留到用户看见为止。此前 216 处 setMessage 挤在 22 个
  // 显示槽里、外加 3 个 2.5 秒轮询器也往同一批槽写，最后写的赢 → 点击报错闪一下
  // 就被下一轮状态播报冲掉，用户从界面上只能看到「点了没反应」，真正的原因只在
  // DevTools 里（2026-08-19 验收实证：重新分析的 409 就这样消失）。
  // 规矩：sticky 写入（用户操作的结果）在保护期内不许被非 sticky 写入（轮询播报）
  // 覆盖；sticky 之间可以互相覆盖，这样下一次用户操作照样能改写提示。
  const MESSAGE_HOLD_MS = 8000;

  function setMessage(node, message, variant = "neutral", options = {}) {
    if (!node) return;
    const sticky = options.sticky === true;
    const now = Date.now();
    // 清空是「把这个槽复位」的指令（切项目、重新渲染表单都靠它），必须无条件生效，
    if (!message) {
      node.textContent = "";
      node.dataset.variant = variant;
      node.dataset.holdUntil = "0";
      return;
    }
    if (!sticky && Number(node.dataset.holdUntil || 0) > now) return;
    node.textContent = message;
    node.dataset.variant = variant;
    node.dataset.holdUntil = sticky ? String(now + MESSAGE_HOLD_MS) : "0";
  }

  async function withBusyButton(button, busyText, action) {
    if (!button) return action();
    const idleText = button.textContent;
    button.disabled = true;
    button.textContent = busyText;
    try {
      return await action();
    } finally {
      button.disabled = button.closest("form")?.dataset.locked === "true";
      button.textContent = idleText;
    }
  }

  function setAnalysisActionsDisabled(disabled) {
    [
      elements.openAnalysisButton,
      elements.runAnalysisPanelButton,
      ...Array.from(document.querySelectorAll("[data-start-project-analysis]")),
      ...Array.from(document.querySelectorAll('[data-asset-next="analysis"]')),
    ].filter(Boolean).forEach((button) => {
      button.disabled = disabled;
      button.setAttribute("aria-disabled", disabled ? "true" : "false");
    });
  }

  function lineList(value) {
    return String(value || "")
      .split(/\r?\n|,/)
      .map((item) => item.trim())
      .filter(Boolean);
  }

  function listText(value) {
    if (!Array.isArray(value)) return "";
    return value.join("\n");
  }

  function formPayload(form) {
    const data = new FormData(form);
    return {
      name: String(data.get("name") || "").trim(),
      brand_name: String(data.get("brand_name") || "").trim() || null,
      brand_url: String(data.get("brand_url") || "").trim() || null,
      product_description: String(data.get("product_description") || "").trim() || null,
      target_market: String(data.get("target_market") || "").trim() || null,
      target_keywords: lineList(data.get("target_keywords")),
      competitors: lineList(data.get("competitors")),
      goals: String(data.get("goals") || "").trim() || null,
    };
  }

  function displayUser(payload) {
    const user = payload?.account_user || payload?.user || {};
    return user.name || (user.phone || "").replace(/^(\d{3})\d+(\d{4})$/, "$1****$2") || user.email || "用户";
  }

  function syncAccountHeader(payload) {
    if (elements.deleteProjectButton) elements.deleteProjectButton.hidden = !["admin", "platform_admin"].includes(payload?.user?.role);
    const user = displayUser(payload);
    const workspaceCode = String(payload?.tenant?.subdomain || payload?.user?.tenant?.subdomain || "");
    const workspaceName = payload?.tenant?.name || payload?.user?.tenant?.name || workspaceCode;
    const workspaceText = workspaceName ? `工作区 ${workspaceName}` : "";
    const sidebar = document.getElementById("workspaceSidebar");
    if (sidebar) sidebar.dataset.platformAdmin = String(payload?.user?.role === "platform_admin");
    if (elements.platformAdminSection) elements.platformAdminSection.hidden = payload?.user?.role !== "platform_admin";
    elements.userLabel.textContent = user;
    elements.userLabel.title = user;
    const workspaceIdentity = document.getElementById("workspaceIdentity");
    if (workspaceIdentity) {
      workspaceIdentity.textContent = workspaceText;
      workspaceIdentity.hidden = !workspaceText;
    }
    elements.accountLink.href = "#personal-settings";
    const securityLink = document.getElementById("accountSecurityLink");
    if (securityLink) securityLink.href = "#account-security";
    const email = (payload?.account_user || payload?.user || {}).email || "";
    const phoneLabel = document.getElementById("accountMenuPhone");
    if (phoneLabel) phoneLabel.textContent = email;
    const identity = document.getElementById("accountMenuIdentity");
    if (identity) identity.textContent = user;
    const menuWorkspace = document.getElementById("accountMenuWorkspace");
    if (menuWorkspace) {
      menuWorkspace.textContent = workspaceText;
      menuWorkspace.hidden = !workspaceText;
    }
    document.getElementById("accountMenuButton")?.setAttribute("aria-label", `${user}${workspaceText ? `，${workspaceText}` : ""}，用户菜单`);
  }

  let accountHasPassword = true;
  let accountPasswordMode = "change";
  let accountPasswordBusy = false;

  function renderAccountPassword() {
    document.querySelectorAll("[data-password-toggle]").forEach(button => setAccountPasswordVisibility(button, false));
    accountPasswordMode = "change";
    $("geoPasswordForm").hidden = false;
    $("passwordCardTitle").textContent = "修改密码";
    $("geoPasswordHint").textContent = "修改后其他设备会退出登录。忘记密码可从登录页申请重置。";
    $("geoCurrentPasswordField").hidden = false;
    $("geoCurrentPassword").disabled = false;
    $("geoCurrentPassword").required = true;
    $("saveGeoPassword").textContent = "保存新密码";
    $("toggleGeoPasswordReset").hidden = false;
  }

  async function loadPersonalAccount() {
    if (config.desktopMode) return;
    const body = await api("/api/v1/account/profile");
    const user = body.user;
    $("personalName").value = user.name || "";
    $("personalEmail").value = user.email || "";
    accountHasPassword = Boolean(body.has_password);
    renderAccountPassword();
    $("geoPasswordStatus").textContent = body.has_password ? "已设置" : "未设置";
    $("geoPasswordStatus").className = `md-badge ${body.has_password ? "md-badge--success" : "md-badge--neutral"}`;
    if (state.activeView === "account-security") {
      const sessions = await api("/api/v1/account/sessions");
      renderAccountSessions(sessions.data || []);
    }
  }

  function renderAccountSessions(rows) {
    const otherCount = rows.filter(row => !row.current).length;
    $("geoSessionSummary").textContent = otherCount ? `另有 ${otherCount} 个有效登录，可将它们统一退出。` : "当前没有其他有效登录。";
    $("revokeGeoSessions").hidden = otherCount === 0;
    $("geoSessions").innerHTML = [...rows].sort((a, b) => Number(b.current) - Number(a.current)).map(row => `
      <div class="account-session-row">
        <div class="account-session-label"><span class="account-session-dot" aria-hidden="true"></span><div><strong>${row.current ? "当前登录" : "其他登录"}</strong><p>登录建立时间：${escapeHtml(formatAccountSessionDate(row.created_at))}</p></div>${row.current ? '<span class="md-badge md-badge--success">使用中</span>' : ''}</div>
        <details class="account-session-expiry"><summary>登录有效期</summary><p>有效至 ${escapeHtml(formatAccountSessionDate(row.expires_at))}<br>此后需要重新登录。</p></details>
      </div>`).join("") || '<p class="account-muted">暂无有效登录记录，请刷新重试。</p>';
  }

  function formatAccountSessionDate(value) {
    const date = new Date(value);
    if (!value || !Number.isFinite(date.getTime())) return "时间暂不可用";
    return new Intl.DateTimeFormat("zh-CN", { year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hour12: false }).format(date);
  }

  function initAccountMenu() {
    const menu = document.getElementById("accountMenu");
    const trigger = document.getElementById("accountMenuButton");
    if (!menu || !trigger) return;
    document.addEventListener("pointerdown", event => {
      if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
    document.addEventListener("focusin", event => {
      if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
    menu.addEventListener("keydown", event => {
      if (event.key === "Escape" && menu.open) {
        event.preventDefault();
        menu.open = false;
        trigger.focus();
      }
    });
    elements.accountLink?.addEventListener("click", () => { menu.open = false; });
    window.addEventListener("hashchange", () => { menu.open = false; });
  }

  document.getElementById("accountLogoutButton")?.addEventListener("click", async event => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      await api("/api/v1/auth/logout", { method: "POST" });
      ["geo_token", "geo_user", "geo_tenant"].forEach(key => sessionStorage.removeItem(key));
      globalThis.GEO?.discardEnterpriseDrafts?.();
      globalThis.GEO?.closeKnowledgePreview?.();
      document.getElementById("workspaceState").hidden = true;
      window.location.replace(config.accountLoginUrl + "?redirect_to=" + encodeURIComponent(window.location.origin + "/"));
    } catch (error) { button.disabled = false; showWorkspaceNotice(error.message || "退出未完成，请重试。"); }
  });

  function persistSession(payload) {
    const accessToken = pickToken(payload);
    if (!accessToken) throw new Error("GEO 登录凭证缺失，请重新登录。");
    sessionStorage.setItem("geo_token", accessToken);
    sessionStorage.setItem("geo_user", JSON.stringify(payload.user || null));
    sessionStorage.setItem("geo_tenant", JSON.stringify(payload.tenant || null));
    // 本次登录撤销了注销申请（计划 §9.5）：交给 geo-entry-account.js 落地成提示并清掉。
    // 主文件只负责"把事实带过去"，不在 5300 行里再长一段账户文案。
    if (payload?.account_restored) sessionStorage.setItem("geo_account_restored", payload.account_restored_at || "1");
  }

  function statusLabel(status) {
    const labels = {
      active: "进行中",
      pending: "待处理",
      running: "执行中",
      billing_pending: "原请求待核验",
      completed: "已完成",
      failed: "失败",
      cancelled: "已取消",
      draft: "草稿",
      editing: "编辑中",
      approved: "已通过",
      published: "已发布", withdrawn: "已撤下", takedown: "已撤下",
      ready: "已就绪",
      not_ready: "未就绪",
      not_integrated: "未接入",
      no_usage: "暂无用量",
      unknown: "原模型请求待核验",
      paused: "已暂停",
      paid: "已支付",
      expired: "已过期",
      trialing: "试用中",
      submitted: "已提交",
      processing: "处理中",
      issued: "已开具",
      rejected: "已驳回",
    };
    return labels[String(status || "").toLowerCase()] || status || "-";
  }

  function sourceTypeLabel(value) {
    const labels = {
      geo_analysis: "综合诊断",
      skill_execution: "技能产物",
      manual: "手动创建",
      draft: "草稿",
    };
    return labels[String(value || "").toLowerCase()] || value || "手动创建";
  }

  function formatLabel(value) {
    const labels = {
      markdown: "正文",
      html: "网页",
      docx: "文档",
      pdf: "便携文档",
    };
    return labels[String(value || "").toLowerCase()] || value;
  }

  function formatNumber(value) {
    if (value === null || value === undefined || value === "") return "-";
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString("zh-CN") : String(value);
  }

  function firstFailedCheck(checks = []) {
    return checks.find((check) => check && check.ok === false) || null;
  }

  // 阶段完整标签（与后端 WorkflowStage 对齐）
  const STAGE_FULL_LABELS = {
    0: "研究拓词", 1: "策略诊断", 2: "内容生产",
    3: "渠道发布", 4: "效果监测", 5: "迭代优化",
  };
  // ═══════════════════════════════════════════════
  // Structured Report Renderer — shape-driven HTML
  // Never JSON.stringify, never card-wall
  // ═══════════════════════════════════════════════

  const SECTION_LABELS = {
    content_assets: "内容资产",
    evidence_gaps: "证据缺口",
    compliance_boundaries: "合规边界",
    core_answer: "核心回答",
    analysis_summary: "分析摘要",
    research_basis: "研究依据",
    definitions: "关键定义",
    principles: "核心原理",
    steps: "操作步骤",
    selection_criteria: "选择标准",
    misconceptions: "常见误区",
    faq: "常见问答",
    glossary: "术语表",
    brand_insertions: "品牌植入建议",
    sources: "参考来源",
    article_sections: "文章正文",
    entity_map: "实体关系",
    problem_type_map: "问题类型",
    audience_scenarios: "受众场景",
    evidence_assessment: "证据评估",
    data_availability: "数据可得性",
    data_source_plan: "数据来源计划",
    fact_verification_matrix: "事实核查",
    decision_paths: "决策路径",
    schema_recommendations: "结构化数据建议",
    measurement_plan: "效果衡量",
    content_gap_opportunities: "内容缺口机会",
    pre_publish_checks: "发布前检查",
    platform_notes: "平台注意事项",
    compliance_notes: "合规提示",
    pending_confirmations: "待确认事项",
    key_values: "关键数据",
    brand_scenarios: "品牌场景",
    conclusion: "结论",
    notice: "提示",
    limitations: "局限性",
    question_bank: "问题清单",
    title_candidates: "候选标题",
    compliance_checks: "合规检查",
    structure_map: "结构映射",
    self_review: "自我审查",
    scoring_matrix: "评分矩阵",
    follow_up_chains: "追问链",
    prompt_library: "提示词库",
    data_sources: "数据来源",
    ai_sampling_plan: "AI 采样计划",
    calibration_actions: "校准动作",
    content_topics: "内容主题",
    faq_bank: "常见问答库",
    knowledge_base_entries: "知识库条目",
    evidence_sources: "证据来源",
    action_roadmap: "行动路线",
    method_basis: "方法依据",
    authority_map: "权威参考",
    question_coverage: "问题覆盖",
    baseline: "基线验证",
    crawl_inventory: "抓取清单",
    entity_profile: "品牌实体",
    product_map: "产品地图",
    technical_audit: "技术诊断",
    competitors: "竞品分析",
    scores: "评分",
    opportunities: "机会地图",
    repairs: "修复清单",
    offsite_plan: "站外方案",
    risk_register: "风险登记",
    summary_cards: "关键指标",
    sections: "报告正文",
    title: "标题",
    topic: "主题",
    subtitle: "副标题",
    brand_name: "品牌名称",
    company_name: "公司名称",
    target_brand: "目标品牌",
    target_audience: "目标受众",
    use_case: "使用场景",
    analysis_date: "分析日期",
    overall_score: "就绪评分",
    project: "项目",
    summary: "摘要",
    meta: "元信息",
    method_summary: "方法摘要",
    scenario_selection: "场景选择",
    platform_adaptation: "平台适配",
    key_findings: "关键发现",
    recommendations: "优化建议",
    next_actions: "下一步行动",
    next_steps: "后续步骤",
    intent_clusters: "意图聚类",
    dimensions: "评估维度",
    phases: "阶段规划",
    schema_markup: "结构化标记（Schema）",
    // 执行路线图（yao-geo-roadmap）字段。此前整份报告 17 个小节标题未登记，
    // 被 fieldLabel 兜底成 "north star metrics" 这类英文原文直接显示给客户。
    executive_summary: "执行摘要",
    diagnosis_bridge: "诊断桥接",
    analysis_dimensions: "分析维度",
    evidence_maturity: "证据成熟度",
    north_star_metrics: "北极星指标",
    source_basis: "来源依据",
    target_platforms: "目标平台",
    report: "报告",
    project_packages: "项目包",
    platform_actions: "平台化行动",
    roadmap: "路线图",
    governance_plan: "治理方案",
    monitoring_plan: "监测方案",
    risk_plan: "风险方案",
    resource_budget: "资源预算",
    data_acquisition: "数据获取",
    real_data_plan: "真实数据方案",
    // 全景诊断 / 解释型文章的漏登记字段
    diagnostic_questions: "诊断问题",
    sensitive_domain: "敏感领域",
    example: "示例",
    // 运行时注入字段
    brand_url: "品牌官网",
    report_date: "报告日期",
  };

  const REPORT_SKIP_KEYS = new Set([
    "slug", "prepared_by", "skill_id", "output_stem", "generated_at",
    "max_brand_mentions", "brand_insertion_allowed",
    "model_usage", "available_formats", "report_markdown",
    "points_deduction_status", "points_deduction_failure_reason",
    "table_headers", "section_meanings", "report_integrity", "sampling_date",
    // 渲染模板用的别名，值与 brand_name / brand_url 完全相同
    // （SkillAgentRunnerService::1237-1242 成对注入）。不跳过就会让客户
    // 在报告里看到品牌名和网址各出现两次，其中一份还是英文标签。
    "brand", "website",
  ]);

  // EXEC-07 F2: meta → definition list; summary/… → point list (instead of guessed header table)
  const REPORT_PAIR_KEYS = new Set(["meta"]);
  const REPORT_POINT_KEYS = new Set(["summary", "key_findings", "recommendations", "next_actions", "next_steps"]);
  // 长报告折叠闸：**按位置**，不按字段名（批 B 第 4 步，2026-09-05）。
  //
  // 原来这里是一份「常开字段名白名单」（ALWAYS_OPEN_REPORT_KEYS）。它的病是结构性的：
  // 白名单只认登记过的名字，新技能的字段一个都不命中，整份报告会 0 可见内容全折叠
  // —— EXEC-07 复核实测标题优化 19 节全进折叠条。当时的补救是加一道「命中 0 就退回
  // 短报告模式」的硬闸，等于用一个补丁盖住白名单必然会漏的事实；每接一个新技能
  // 就得回来补一次名字，而漏补不报错。
  //
  // 换成位置规则之后，「常开」= 排在最前面的 N 节，与字段叫什么完全无关：
  //   · 任何技能、任何字段名，都保证有 N 节是打开的 —— 零可见内容从结构上不可能发生，
  //     所以那道硬闸也一起删了；
  //   · 谁排在前面由 keyOrder / SECTION_LABELS 顺序决定，那是「阅读顺序」该管的事，
  //     不该由折叠逻辑再管一遍。
  const OPEN_SECTION_COUNT = 6;
  const LONG_REPORT_SECTION_THRESHOLD = 10;

  // 报告的阅读顺序：先说结论和要做什么，再说依据和明细。
  // SECTION_LABELS 的顺序是登记顺序（谁先被加进来谁在前），不是阅读顺序 ——
  // 照它排，「结论」会排在「关键发现」前面，等于先甩结论再补依据。
  const REPORT_READING_ORDER = [
    "notice", "core_answer", "analysis_summary", "summary", "executive_summary",
    "key_findings", "recommendations", "next_actions", "next_steps",
    "scores", "conclusion",
    "refined_article", "title_candidates", "question_bank",
  ];
  // EXEC-07 F1: table header authority, set per render from payload's table_headers.

  const REPORT_DL_KEYS = new Set(["faq", "glossary", "definitions", "principles"]);
  const REPORT_TABLE_KEYS = new Set([
    "selection_criteria", "research_basis", "sources", "brand_insertions",
    "fact_verification_matrix", "data_source_plan", "entity_map",
    "schema_recommendations", "measurement_plan", "content_gap_opportunities",
    "question_bank", "title_candidates", "compliance_checks", "structure_map",
    "self_review", "scoring_matrix", "follow_up_chains", "prompt_library",
    "data_sources", "ai_sampling_plan", "calibration_actions", "content_topics",
    "faq_bank", "knowledge_base_entries", "evidence_sources", "action_roadmap",
    "method_basis", "authority_map", "question_coverage", "baseline",
    "crawl_inventory", "entity_profile", "product_map", "technical_audit",
    "competitors", "scores", "opportunities", "repairs", "offsite_plan",
    "risk_register", "audience_scenarios", "brand_scenarios", "decision_paths",
    "evidence_assessment",
  ]);
  const REPORT_OL_KEYS = new Set(["steps", "pre_publish_checks"]);
  const REPORT_NOTE_KEYS = new Set(["misconceptions", "compliance_notes", "pending_confirmations"]);
  // Machine-readable markup fields — rendered verbatim as code blocks, never as prose.
  const REPORT_CODE_KEYS = new Set(["schema_markup", "json_ld", "jsonld", "html_snippet"]);

  // Secondary sections (evidence / method / compliance / appendix) — collapsed by default.
  // Append-only. Unknown keys (absent from SECTION_LABELS) are also treated as secondary.
  const SECONDARY_REPORT_KEYS = new Set([
    "research_basis", "sources", "data_sources", "evidence_sources",
    "evidence_assessment", "fact_verification_matrix", "method_basis",
    "method_summary", "glossary", "compliance_notes", "compliance_checks",
    "limitations", "self_review", "risk_register", "pending_confirmations",
    "prompt_library", "crawl_inventory", "platform_notes", "schema_markup",
  ]);

  // ═══════════════════════════════════════════════
  // 报告分区表（执行入口 / 独立结果页重构 · 批 B 第 2 步，2026-09-05）
  const REPORT_ZONE_OF = {
    // ── 概览：一眼看完「结论是什么、接下来做什么」──
    // ⚠️ brand_url / report_date 故意**不**列进概览：渲染器的 meta 行只认下面这几个字段，
    //    没列进 meta 行的会掉下来变成一个叫「品牌官网」的小节挂在概览末尾。
    title: "overview", topic: "overview", subtitle: "overview",
    brand_name: "overview", company_name: "overview", target_brand: "overview",
    target_audience: "overview", use_case: "overview", analysis_date: "overview",
    notice: "overview", overall_score: "overview", summary_cards: "overview",
    core_answer: "overview", analysis_summary: "overview", summary: "overview", limitations: "overview",
    executive_summary: "overview", conclusion: "overview", scores: "overview",
    key_findings: "overview", recommendations: "overview",
    next_actions: "overview", next_steps: "overview",
    // ── 问题清单：可以直接拿去做事的问题表 ──
    question_bank: "questions", question_coverage: "questions",
    diagnostic_questions: "questions", follow_up_chains: "questions",
    faq: "questions", faq_bank: "questions",
    intent_clusters: "questions", problem_type_map: "questions",
  };

  // 渲染器要靠这两个字段决定表头和小节说明，它们不是内容、是渲染上下文。
  // ⚠️ 每个页签的切片都必须带上，否则「概览」「问题清单」两页的表格会退回猜表头
  //    （EXEC-07 F1 修掉的正是这个病，别让它从分区这条路回来）。
  const REPORT_ZONE_CARRY = ["table_headers", "section_meanings"];

  // 页眉信息：只有这些的话，概览页等于一张空页。判空时不算作内容。
  const REPORT_ZONE_CHROME_KEYS = new Set([
    "title", "topic", "subtitle", "brand_name", "company_name", "target_brand",
    "target_audience", "use_case", "analysis_date", "brand_url", "report_date",
  ]);

  // 页签定义与顺序。「完整报告」是默认区，只要报告有内容就一定在。
  //
  // keyOrder 是**阅读顺序**，和 SECTION_LABELS 的登记顺序是两码事。
  // 不给概览指定顺序的话，SECTION_LABELS 会把「结论」排在「关键发现」前面——
  // 概览变成先甩结论再补依据，正好反了。列进来的字段没有值就自动跳过。
  const RESULT_ZONES = [
    {
      id: "overview",
      label: "概览",
      keyOrder: REPORT_READING_ORDER,
    },
    {
      id: "questions",
      label: "问题清单",
      keyOrder: ["question_bank", "question_coverage", "diagnostic_questions", "intent_clusters", "follow_up_chains", "faq", "faq_bank"],
    },
    // 完整报告渲染整份未过滤的 payload；顺序用统一的阅读顺序，
    // 排在前面的 N 节同时也就是长报告模式下常开的那几节（见 OPEN_SECTION_COUNT）。
    { id: "full", label: "完整报告", keyOrder: REPORT_READING_ORDER },
  ];

  // ═══════════════════════════════════════════════
  // Article Publishing + Review workflow
  // ═══════════════════════════════════════════════

  function publicationStatusLabel(status) {
    const labels = {
      queued: "排队中",
      pending: "待发布",
      publishing: "发布中",
      published: "已发布", withdrawn: "已撤下", takedown: "已撤下",
      failed: "失败",
    };
    return labels[String(status || "").toLowerCase()] || status || "-";
  }

  function publicationStatusVariant(status) {
    const normalized = String(status || "").toLowerCase();
    if (normalized === "published") return "success";
    if (normalized === "failed" || normalized === "withdrawn" || normalized === "takedown") return "danger";
    if (normalized === "publishing" || normalized === "pending" || normalized === "queued") return "warning";
    return "neutral";
  }

  async function renderArticlePublish(article) {
    const container = document.getElementById("articlePublishPanel");
    if (!container) return;
    const status = String(article.status || "draft");

    const reviewActions = [];
    if (status === "draft" || status === "editing") {
      reviewActions.push('<button class="button secondary small" type="button" data-publish-action="submit-review">提交审核</button>');
    } else if (status === "pending") {
      reviewActions.push('<button class="button primary small" type="button" data-publish-action="approve">通过审核</button>');
      reviewActions.push('<button class="button secondary small" type="button" data-publish-action="request-changes">退回修改</button>');
    }

    container.innerHTML = `
      <section class="asset-publish-block">
        <div class="panel-heading compact-heading">
          <div><h3>发布与审核</h3></div>
          <span class="badge" data-variant="${publicationStatusVariant(article.status)}">${escapeHtml(statusLabel(article.status))}</span>
        </div>
        <div class="publish-review-actions">
          ${reviewActions.length ? reviewActions.join("") : `<span class="muted">${status === "approved" ? "已通过，可发布到渠道。" : status === "published" ? "已发布。" : "无需审核操作。"}</span>`}
        </div>
        <div class="publish-row">
          <select id="publishChannelSelect" class="small">
            <option value="">选择发布渠道…</option>
          </select>
          <button class="button primary small" type="button" id="publishArticleButton">发布到渠道</button>
        </div>
        <p class="form-message" id="publishMessage"></p>
        <div class="publication-list" id="publicationList"><p class="muted">暂无发布记录。</p></div>
      </section>
    `;

    container.querySelectorAll("[data-publish-action]").forEach((btn) => {
      btn.addEventListener("click", () => handleReviewAction(btn.dataset.publishAction, article));
    });
    const publishBtn = container.querySelector("#publishArticleButton");
    publishBtn?.addEventListener("click", () => publishArticle(article));

    await Promise.allSettled([populatePublishChannels(), loadArticlePublications(article.id)]);
  }

  async function populatePublishChannels() {
    const select = document.getElementById("publishChannelSelect");
    if (!select) return;
    try {
      const body = await api("/api/v1/publishing/channels");
      const channels = (body.data || body || []).filter((ch) => ch.publish_ready && ch.type !== "hosted_page");
      select.innerHTML = '<option value="">选择发布渠道…</option>' + channels.map((ch) => `<option value="${Number(ch.id)}">${escapeHtml(ch.name || "未命名")}（${escapeHtml(globalThis.GEO?.publishingChannelTypeLabel?.(ch.type))}）</option>`).join("");
    } catch (error) {
      select.innerHTML = '<option value="">加载渠道失败</option>';
    }
  }

  async function publishArticle(article) {
    const select = document.getElementById("publishChannelSelect");
    const message = document.getElementById("publishMessage");
    const channelId = select ? Number(select.value) : null;
    if (!channelId) {
      if (message) setMessage(message, "请先选择一个发布渠道。", "danger");
      return;
    }
    setMessage(message, "正在推入发布队列...");
    try {
      await api(`/api/v1/articles/${article.id}/publish-async`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ channel_id: channelId }),
      });
      setMessage(message, "发布任务已推入队列，正在同步状态。", "success");
      await loadArticlePublications(article.id);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function loadArticlePublications(articleId) {
    const list = document.getElementById("publicationList");
    if (!list) return;
    try {
      const body = await api(`/api/v1/articles/${articleId}/publications`);
      const publications = body.data || body || [];
      if (publications.length === 0) {
        list.innerHTML = '<p class="muted">暂无发布记录。</p>';
        return;
      }
      list.innerHTML = publications.map((pub) => {
        const variant = publicationStatusVariant(pub.status);
        const channelName = pub.channel?.name || `渠道 #${pub.channel_id || "?"}`;
        const retry = pub.status === "failed"
          ? `<button class="button secondary small" type="button" data-retry-publication="${pub.id}">重试</button>`
          : "";
        const remote = pub.status === "published" && pub.channel?.type !== "hosted_page" && pub.remote_url
          ? `<a class="button secondary small" href="${escapeHtml(pub.remote_url)}" target="_blank" rel="noopener">查看</a>`
          : "";
        return `
          <div class="publication-item">
            <strong>${escapeHtml(channelName)}</strong>
            <span class="badge" data-variant="${variant}">${escapeHtml(publicationStatusLabel(pub.status))}</span>
            <span class="muted">${escapeHtml(formatDate(pub.updated_at || pub.created_at))}</span>
            <div class="publication-actions">${remote}${retry}</div>
          </div>
        `;
      }).join("");
      list.querySelectorAll("[data-retry-publication]").forEach((btn) => {
        btn.addEventListener("click", () => retryPublication(articleId, Number(btn.dataset.retryPublication)));
      });
      const pending = publications.some((pub) => ["queued", "pending", "publishing"].includes(String(pub.status)));
      if (pending) {
        window.setTimeout(() => loadArticlePublications(articleId), 3000);
      }
    } catch (error) {
      list.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "加载发布记录失败")}</p>`;
    }
  }

  async function retryPublication(articleId, publicationId) {
    try {
      await api(`/api/v1/articles/${articleId}/publications/${publicationId}/retry`, { method: "POST" });
      await loadArticlePublications(articleId);
    } catch (error) {
      const message = document.getElementById("publishMessage");
      if (message) setMessage(message, validationMessage(error), "danger");
    }
  }

  async function handleReviewAction(action, article) {
    const message = document.getElementById("publishMessage");
    try {
      if (action === "submit-review") {
        await api(`/api/v1/articles/${article.id}/submit-review`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}),
        });
        setMessage(message, "已提交审核。", "success");
      } else if (action === "approve") {
        await api(`/api/v1/articles/${article.id}/approve`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}),
        });
        setMessage(message, "已通过审核。", "success");
      } else if (action === "request-changes") {
        const notes = await promptWorkspaceValue("退回修改原因（必填）：");
        if (notes === null) return;
        if (!notes.trim()) { setMessage(message, "退回原因不能为空。", "danger"); return; }
        await api(`/api/v1/articles/${article.id}/request-changes`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ review_notes: notes.trim() }),
        });
        setMessage(message, "已退回修改。", "success");
      }
      const refreshed = await api(`/api/v1/articles/${article.id}`);
      renderArticleResult(refreshed);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  function articleStructuredReport(report) {
    const parsed = tryParseJsonValue(report);
    const data = parsed || (report && typeof report === "object" ? report : null);
    if (!data || typeof data !== "object" || Array.isArray(data)) return "";

    const hasContent = Object.keys(data).some((key) =>
      !REPORT_SKIP_KEYS.has(key) && hasReportFieldValue(data[key])
    );
    if (!hasContent) return "";

    return `<section class="structured-report">${renderStructuredReportHtml(data)}</section>`;
  }

  // 取"整段正文就是一份 JSON"时的 JSON 文本。内容技能真实写库的样子是 ```json 围栏包裹，
  // 前面有时还带 <!-- chunk: xxx --> 标记，并不以 { 开头——只判首字符会漏掉全部真实数据。
  // 分块正文（正文里还夹着后续 chunk 标记）不在此列，交给既有可读化路径，别动它。
  function structuredBodyCandidate(body) {
    let text = String(body || "").replace(/^\s*(?:<!--[\s\S]*?-->\s*)*/, "");
    const fence = text.match(/^```[a-zA-Z]*[ \t]*\r?\n([\s\S]*)$/);
    if (fence) text = fence[1];
    text = text.replace(/```\s*$/, "").trim();
    if (!/^[\[{]/.test(text)) return "";
    if (text.includes("<!--")) return "";
    return text;
  }

  // 正文渲染块：正文若是结构化 JSON（内容技能会把整份 JSON 存进 body_markdown），
  // 解析后走结构化报告渲染器；看着像 JSON 但解析失败时给错误态，绝不裸 dump 原文。
  function articleBodyBlock(article, text) {
    const body = String(article.body_markdown || "").trim();
    const candidate = structuredBodyCandidate(body);
    if (candidate) {
      const parsed = tryParseJsonValue(candidate);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
        const reportHtml = articleStructuredReport(parsed);
        if (reportHtml) return `<h4>正文</h4>${reportHtml}`;
      }
      if (!parsed) return `<h4>正文</h4>${brokenBodyNotice(candidate)}`;
    }
    return text ? `<h4>正文</h4><pre class="execution-output">${escapeHtml(text)}</pre>` : `<p class="muted">暂时没有正文。</p>`;
  }

  // 生成被截断（写到一半停了）和纯格式错误要分开说：截断时"再生成一次"多半还是截断，
  // 得让用户知道是内容超了单次生成上限，而不是让他反复空烧积分。
  function brokenBodyNotice(candidate) {
    const truncated = !/[}\]]\s*$/.test(candidate);
    if (truncated) {
      return `<p class="muted">这份内容没有生成完整，结果在中途被截断了。请重新生成一次；如果同一篇反复截断，说明它超出了单次生成上限，请联系我们处理。</p>`;
    }
    return `<p class="muted">这份内容的生成结果格式有误（结构化数据无法解析）。请重新生成一次；如果反复出现，请联系我们处理。</p>`;
  }

  // 结构化正文转可读 markdown：供复制/下载/编辑使用，避免把 JSON 原文交给用户。
  function structuredBodyMarkdown(parsed) {
    const lines = [];
    const title = readableText(parsed.title || parsed.topic || parsed.report_title || "");
    if (title) lines.push(`# ${title}`);
    Object.keys(parsed).forEach((key) => {
      if (["title", "topic", "report_title"].includes(key) || REPORT_SKIP_KEYS.has(key)) return;
      if (!hasReportFieldValue(parsed[key])) return;
      const label = SECTION_LABELS[key] || fieldLabel(key);
      const value = parsed[key];
      if (typeof value === "string" || typeof value === "number") {
        lines.push(`## ${label}\n${readableText(value)}`);
        return;
      }
      const items = readableList(value);
      if (items.length) {
        lines.push(`## ${label}\n${items.map((item) => `- ${item}`).join("\n")}`);
        return;
      }
      const text = readableText(value);
      if (text) lines.push(`## ${label}\n${text}`);
    });
    return lines.join("\n\n");
  }

  function articleReadableBody(article) {
    const body = String(article.body_markdown || "");
    const bodyStructured = parseStructuredResultFromText(body);
    if (bodyStructured && typeof bodyStructured === "object" && hasStructuredResultKeys(bodyStructured)) {
      return skillStructuredText(bodyStructured);
    }
    if (bodyStructured && typeof bodyStructured === "object" && !Array.isArray(bodyStructured)) {
      const markdown = structuredBodyMarkdown(bodyStructured);
      if (markdown) return markdown;
    }

    const report = normalizeReport(article.structured || {});
    if (!shouldUseReadableArticleBody(article)) return body || fallbackArticleStructuredText(report);

    return buildReportMarkdown(
      { id: article.skill_execution_id || article.id, project_id: article.project_id },
      report,
      report.model_usage || {},
    );
  }

  function shouldUseReadableArticleBody(article) {
    const body = String(article.body_markdown || "");
    const report = normalizeReport(article.structured || {});
    if (!hasReportContent(report)) return false;
    if (!body) return true;
    if (parseStructuredResultFromText(body)) return true;
    return looksLikeEscapedStructuredText(body);
  }

  function articleSummaryText(article) {
    const summary = String(article?.summary || "").trim();
    if (!summary) return "";

    const structured = parseStructuredResultFromText(summary);
    if (structured && typeof structured === "object" && hasStructuredResultKeys(structured)) {
      return structuredSummaryText(structured);
    }

    const bodyStructured = parseStructuredResultFromText(article?.body_markdown || "");
    if ((/[{\[]|\\u[0-9a-fA-F]{4}/.test(summary)) && bodyStructured && hasStructuredResultKeys(bodyStructured)) {
      return structuredSummaryText(bodyStructured);
    }

    return summary;
  }

  function structuredSummaryText(structured) {
    return readableText(
      structured.summary
      || structured.analysis
      || structured.result
      || structured.conclusion
      || structured.recommendation
      || "",
    );
  }

  function fallbackArticleStructuredText(report) {
    if (!hasReportContent(report)) return "";
    return buildReportMarkdown({}, report, report.model_usage || {}).trim();
  }

  function hasReportContent(report) {
    return Boolean(
      report.summary
      || readableList(report.key_findings).length
      || readableList(report.recommendations).length
      || readableList(report.next_actions).length
      || (report.overall_score !== null && report.overall_score !== undefined),
    );
  }

  function looksLikeEscapedStructuredText(text) {
    return /\\u[0-9a-fA-F]{4}/.test(text)
      || /\{\\"(?:finding|recommendation|action|priority|phase|summary)/.test(text)
      || Boolean(tryParseJsonValue(text));
  }

  async function runAnalysis() {
    state.skillContext = "strategy";
    setSkillWorkspaceMode("diagnosis");
    setActiveWorkspaceView("analysis", { updateHash: true });
    window.setTimeout(() => scrollToWithHeaderOffset(elements.skillDiagnosisBlock), 80);
    if (!state.selectedProject) {
      setMessage(elements.analysisMessage, "请先在项目管理中创建或选择一个项目，再执行综合诊断。", "danger");
      return;
    }
    if (state.analysisRunning) return;
    state.analysisRunning = true;
    setAnalysisActionsDisabled(true);
    setMessage(elements.analysisMessage, `正在通过 AI 模型 执行综合诊断...`);
    try {
      const body = await api(`/api/v1/projects/${state.selectedProject.id}/analyses`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ notes: "从言及工作台执行。" }),
      });
      const analysis = body.analysis || {};
      if (isTerminalExecutionStatus(analysis.status)) {
        // 同步模式：结果已就绪，直接展示。
        setMessage(elements.analysisMessage, "");
        await loadAnalyses(state.selectedProject.id, analysis.id);
        await loadArticles(state.selectedProject.id, analysis.article_id);
      } else {
        // 异步模式：已入队(202)，展示占位记录并轮询直到终态。可离开本页。
        setMessage(elements.analysisMessage, "综合诊断已在后台执行，完成后会自动展示结果，可以先离开本页。", "success");
        await loadAnalyses(state.selectedProject.id, analysis.id);
        pollAnalysis(state.selectedProject.id, analysis.id);
      }
    } catch (error) {
      if (error.status === 409 && error.body?.action === "analysis_already_running") {
        // 后端这条 message 是英文（'An analysis is already in progress for this project.'），
        // 此前落到下面的兜底分支被原样弹给用户。并发保护本身是对的，说清楚即可。
        setMessage(
          elements.analysisMessage,
          "这个项目已经有一次综合诊断在执行中，等它跑完再开始新的。左侧最新那条记录会自动更新状态。",
          "warning",
          { sticky: true },
        );
      } else if (error.body?.action === "model_service_call_failed") {
        setMessage(elements.analysisMessage, error.message || "模型服务调用失败，请稍后重试。", "danger", { sticky: true });
      } else {
        setMessage(elements.analysisMessage, error.message || "分析失败。", "danger", { sticky: true });
      }
    } finally {
      state.analysisRunning = false;
      setAnalysisActionsDisabled(false);
    }
  }

  function pollAnalysis(projectId, analysisId) {
    if (!analysisId) return;
    stopPollingAnalysis(analysisId);

    const tick = async () => {
      try {
        const body = await api(`/api/v1/projects/${projectId}/analyses/${analysisId}`);
        const analysis = body.analysis || body;

        if (isTerminalExecutionStatus(analysis.status)) {
          stopPollingAnalysis(analysisId);
          if (analysis.status === "completed") {
            setMessage(elements.analysisMessage, "");
            await loadAnalyses(projectId, analysis.id);
            await loadArticles(projectId, analysis.article_id);
          } else if (analysis.status === "cancelled") {
            // A 部分：取消后的终态提示，不是失败。
            setMessage(elements.analysisMessage, "综合诊断已取消，没有产出结果。", "warning", { sticky: true });
            await loadAnalyses(projectId, analysis.id);
          } else {
            setMessage(elements.analysisMessage, analysis.error_message || "综合诊断失败，请稍后重试。", "danger", { sticky: true });
            await loadAnalyses(projectId, analysis.id);
          }
        } else {
          // statusLabel 本身已经带「中」（running→执行中、pending→待处理），
          // 这里再拼一个「中」会写出「执行中中」。
          setMessage(elements.analysisMessage, `综合诊断${statusLabel(analysis.status || "running")}，完成后会自动展示结果...`);
        }
      } catch (error) {
        stopPollingAnalysis(analysisId);
        setMessage(elements.analysisMessage, error.message || "无法查询分析状态。", "danger", { sticky: true });
      }
    };

    const timer = window.setInterval(tick, 2500);
    analysisPollers.set(analysisId, timer);
    tick();
  }

  function stopPollingAnalysis(analysisId) {
    const timer = analysisPollers.get(analysisId);
    if (timer) {
      window.clearInterval(timer);
      analysisPollers.delete(analysisId);
    }
  }

  // A 部分：取消全景分析。confirm 文案按当前状态如实说明扣费效果（pending 不扣费 /
  // running 正在跑的那一段照扣、后续段不再发起），状态从轮询结果里拿，不写死一句。
  // 200 后立刻回写点击处（按钮禁用 + 状态「已取消」），不依赖下一轮轮询；
  // 422 时按后端 message 提示并刷新一次状态（大概率是刚好跑完了）。
  async function cancelAnalysis(analysis) {
    if (!analysis?.id || !state.selectedProject) return;
    const status = String(analysis.status || "");
    const confirmText = status === "pending"
      ? "取消后本次分析不会调用模型。确定取消？"
      : "已经开始生成，当前模型请求可能继续完成，后续段不再发起。确定取消？";
    if (!await confirmWorkspaceAction(confirmText)) return;

    const projectId = state.selectedProject.id;
    const analysisId = analysis.id;
    const button = elements.analysisResult?.querySelector('[data-analysis-action="cancel"]');
    try {
      const body = await api(`/api/v1/projects/${projectId}/analyses/${analysisId}/cancel`, { method: "POST" });
      stopPollingAnalysis(analysisId);
      // 回写点击处：按钮当场禁用 + 状态当场显示「已取消」。
      if (button) {
        button.disabled = true;
        button.textContent = "已取消";
      }
      const statusLine = elements.analysisResult?.querySelector(".inline-result");
      if (statusLine) {
        statusLine.textContent = "这次综合诊断已取消，没有产出结果。";
        // 与下一轮重渲染（终态 cancelled 分支）保持同一个色号，免得闪一下红再变黄。
        statusLine.dataset.variant = "warning";
      }
      setMessage(elements.analysisMessage, body.message || "已取消分析。", "success");
      await loadAnalyses(projectId, analysisId);
    } catch (error) {
      setMessage(elements.analysisMessage, error.message || "取消失败。", "danger", { sticky: true });
      if (error.status === 422) {
        // 大概率是刚好跑完（completed），刷新一次状态让界面走到终态分支。
        try {
          const body = await api(`/api/v1/projects/${projectId}/analyses/${analysisId}`);
          stopPollingAnalysis(analysisId);
          renderAnalysisResult(body.analysis || body);
          await loadAnalyses(projectId, analysisId);
        } catch (_) {
          // 刷新失败保持现状，原轮询仍在跑
        }
      }
    }
  }

  async function loadAnalyses(projectId, selectId = null) {
    const scope = recentProjectStorageKey();
    const body = await api(`/api/v1/projects/${projectId}/analyses`);
    if (scope !== recentProjectStorageKey() || Number(state.selectedProject?.id) !== Number(projectId)) return;
    state.analyses = body.data || [];
    renderAnalysisHistory();

    const target = selectId || state.analyses[0]?.id;
    if (target) {
      await selectAnalysis(target);
    } else {
      renderAnalysisEmptyState();
    }
  }

  // 🔴 2026-09-04：这一行改前没有空值保护，而 renderSkillExecutionHistory() 第一行就有，
  //    两者不一致。「分析记录」列表撤到历史报告页之后 #analysisHistory 不在 DOM 里了，
  //    没有这句守卫，loadAnalyses() / selectAnalysis() 一调用就抛 TypeError，
  //    整个综合诊断区不渲染（loadAnalyses 在 Promise.allSettled 里，报错还会被吞掉）。
  //    函数体其余保留：它还负责 aria-current，将来列表复用时不用重写。
  function renderAnalysisHistory() {
    if (!elements.analysisHistory) return;
    elements.analysisHistory.innerHTML = "";
    if (state.analyses.length === 0) {
      elements.analysisHistory.innerHTML = `<div class="empty">还没有分析记录。</div>`;
      return;
    }
    state.analyses.forEach((analysis) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "history-item";
      button.setAttribute("aria-current", state.selectedAnalysisId === analysis.id ? "true" : "false");
      button.innerHTML = `
        <strong>${escapeHtml(analysisDisplayName(analysis))}</strong>
        <span>${escapeHtml(statusLabel(analysis.status))} · ${escapeHtml(formatDate(analysis.created_at))}</span>
      `;
      button.addEventListener("click", () => selectAnalysis(analysis.id));
      elements.analysisHistory.appendChild(button);
    });
  }

  function renderAnalysisEmptyState() {
    if (elements.runAnalysisPanelButton) elements.runAnalysisPanelButton.hidden = true;
    if (elements.analysisResult) {
      elements.analysisResult.innerHTML = `
        <div class="md-empty">
          <strong class="md-empty-title">还没有综合诊断</strong>
          <p>综合诊断是项目的整体体检，完成后可以在这里查看结论和完整报告。</p>
          <button class="button primary small" type="button" data-start-project-analysis>执行综合诊断</button>
        </div>
      `;
      elements.analysisResult.querySelector("[data-start-project-analysis]")?.addEventListener("click", runAnalysis);
    }
    setMessage(elements.analysisMessage, "");
  }

  async function selectAnalysis(analysisId) {
    if (!state.selectedProject) return;
    const projectId = state.selectedProject.id;
    const scope = recentProjectStorageKey();
    const body = await api(`/api/v1/projects/${projectId}/analyses/${analysisId}`);
    if (scope !== recentProjectStorageKey() || Number(state.selectedProject?.id) !== Number(projectId)) return;
    state.selectedAnalysisId = analysisId;
    renderAnalysisHistory();
    renderAnalysisResult(body.analysis);
  }

  function renderAnalysisResult(analysis) {
    if (elements.runAnalysisPanelButton) elements.runAnalysisPanelButton.hidden = false;
    // 还没跑完的分析没有任何产物，此前照样往下渲染 → 「待处理」的记录右边会同时
    // 出现「报告未生成」「暂无结构化内容」「分析结果已生成；当前没有可用的 GEO 扣点
    // 记录」，最后一句还跟状态自相矛盾（2026-08-19 验收实证）。这些文案都是给终态
    if (!isTerminalExecutionStatus(analysis.status)) {
      // A 部分：pending/running 时渲染「取消分析」按钮（结果区标题右侧，样式沿用
      // 单项技能那套 button danger small）。终态（completed/failed/cancelled）不渲染；
      // 同步执行路径没有取消入口，属预期，不是漏做。
      const cancellable = ["pending", "running"].includes(String(analysis.status || ""));
      elements.analysisResult.innerHTML = `
        <div class="report-heading">
          <div>
            <h3>${escapeHtml(analysisDisplayName(analysis))}</h3>
            <p class="muted">${escapeHtml(formatDate(analysis.created_at))}</p>
          </div>
          <div class="report-actions">
            ${cancellable ? `<button class="button danger small" type="button" data-analysis-action="cancel">取消分析</button>` : ""}
          </div>
        </div>
        <p class="muted">
          这次综合诊断${escapeHtml(statusLabel(analysis.status))}，还没有产出结果。完成后本页会自动刷新，可以先离开。
        </p>
      `;
      elements.analysisResult
        .querySelector('[data-analysis-action="cancel"]')
        ?.addEventListener("click", () => cancelAnalysis(analysis));
      setMessage(elements.analysisMessage, `综合诊断${statusLabel(analysis.status)}，完成后会自动展示结果...`);
      return;
    }

    // 取消 / 失败的记录同样没有任何产物，不能往下走那套报告外壳：那段会渲染出「复制」
    // 「下载报告正文」两个按钮、「暂无结构化内容」，外加一句「分析结果已生成；当前没有
    // 可用的 GEO 扣点记录」——对一条被用户取消掉的分析说「结果已生成」，与状态直接打架。
    // 这正是 2026-08-19 给非终态记录修过的那一类矛盾文案，cancelled 落地时又开了同一个口。
    if (analysis.status === "cancelled" || analysis.status === "failed") {
      const isCancelled = analysis.status === "cancelled";
      // 已经发出去的模型调用照常计费（runner 是唯一记账人，每次调用一条 model_call_logs）。
      // 取消只挡住「还没发起的那几段」，不退已经跑掉的钱，界面要如实说，别让用户以为白跑。
      const callCount = Array.isArray(analysis.model_call_logs) ? analysis.model_call_logs.length : 0;
      const billingLine = callCount > 0
        ? `${isCancelled ? "取消前" : "失败前"}已经发起 ${callCount} 次模型调用，用量已记录。`
        : "本次没有发起模型调用。";
      elements.analysisResult.innerHTML = `
        <div class="report-heading">
          <div>
            <h3>${escapeHtml(analysisDisplayName(analysis))}</h3>
            <p class="muted">${escapeHtml(formatDate(analysis.created_at))}</p>
          </div>
        </div>
        <p class="md-alert ${isCancelled ? "md-alert--warning" : "md-alert--danger"}">
          ${isCancelled
            ? "这次综合诊断已取消，没有产出报告。"
            : `这次综合诊断失败了，没有产出报告。${analysis.error_message ? escapeHtml(analysis.error_message) : "可以重新发起一次。"}`}
        </p>
        <p class="muted">${escapeHtml(billingLine)}</p>
      `;
      setMessage(elements.analysisMessage, "");
      return;
    }

    const rawOutput = analysis.report || analysis.output_data?.analysis || {};
    const output = normalizeReport(rawOutput);
    const model = analysis.model_usage || rawOutput.model_usage || analysis.output_data?.model_usage || analysis.output_data?.model_call || {};
    const markdown = analysis.report_markdown || buildReportMarkdown(analysis, output, model);
    const formats = Array.isArray(analysis.available_formats) ? analysis.available_formats : [];
    const downloadButtons = formats.length
      ? formats.map((format) => `<button class="button secondary small" type="button" data-report-format="${escapeHtml(format)}">下载${escapeHtml(formatLabel(format))}</button>`).join("")
      : `<button class="button secondary small" type="button" data-report-action="download">下载报告正文</button>`;
    elements.analysisResult.innerHTML = `
      <div class="report-heading">
        <div>
          <h3>${escapeHtml(analysisDisplayName(analysis))}</h3>
          <p class="muted">${escapeHtml(formatDate(analysis.created_at))} <span class="md-badge md-badge--success">已完成</span></p>
        </div>
        <div class="report-actions">
          <button class="button ${analysis.article_id ? "primary" : "secondary"} small" type="button" data-analysis-next-action="article" ${analysis.article_id ? "" : "disabled"}>${analysis.article_id ? "查看完整报告" : "报告未生成"}</button>
          <button class="button secondary small" type="button" data-report-action="copy">复制</button>
          ${downloadButtons}
        </div>
      </div>
      ${reportGlanceBlock(rawOutput, 0)}
      <button class="md-link" type="button" data-analysis-next-action="skill">去专项功能挑一个技能 →</button>
    `;
    elements.analysisResult
      .querySelector('[data-report-action="copy"]')
      ?.addEventListener("click", () => copyReport(markdown));
    elements.analysisResult
      .querySelector('[data-report-action="download"]')
      ?.addEventListener("click", () => downloadReport(analysis, markdown));
    elements.analysisResult.querySelectorAll("[data-report-format]").forEach((button) => {
      button.addEventListener("click", () => downloadReportFormat(analysis, button.dataset.reportFormat, markdown));
    });
    elements.analysisResult.querySelectorAll("[data-analysis-next-action]").forEach((button) => {
      button.addEventListener("click", () => {
        const action = button.dataset.analysisNextAction;
        if (action === "article") {
          openArticleFromAnalysis(Number(analysis.article_id));
        } else if (action === "skill") {
          scrollToSkillCatalog();
        }
      });
    });
    // 终态里还有 failed / cancelled，此前一律报「已完成」。
    if (analysis.status !== "completed") {
      setMessage(
        elements.analysisMessage,
        analysis.error_message || `这次综合诊断${statusLabel(analysis.status)}，没有产出可用结果。`,
        "danger",
        { sticky: true },
      );
      return;
    }

    setMessage(elements.analysisMessage, "");
  }

  // ═══════════════════════════════════════════════
  // 执行入口上的「一眼摘要」（批 B 第 3 步，2026-09-05）
  // ═══════════════════════════════════════════════
  // 执行入口原来把整份报告直接铺在页面上，一份全景诊断能把页面撑到两万多像素，
  // 而入口上真正要回答的只有三件事：跑完没有、大意是什么、去哪看全文。
  // 报告正文搬去独立结果页（#result/<报告编号>），这里只留摘要 + 一个入口。
  //
  // ⚠️ 归档只在成功时发生。失败 / 进行中 / 已取消的执行没有 article_id，
  //    也就没有结果页可去 —— 这时候必须如实说「没有归档」，不能给一个点了 404 的链接。
  //    这两支在各自的分支里已经处理，这里只兜「成功了但没拿到编号」这种意外。
  //
  // articleId 传 0 表示「只要摘要、不要按钮」：完整报告入口由报告标题行承接。
  function reportGlanceBlock(data, articleId) {
    const id = Number(articleId) || 0;
    const parsed = (data && typeof data === "object" && !Array.isArray(data)) ? data : {};
    const summary = reportOneLineSummary(parsed);
    const summaryHtml = summary
      ? `<p class="report-glance-text">${escapeHtml(summary)}</p>`
      : "";
    const linkHtml = id
      ? `<button class="button primary small" type="button" data-open-result="${id}">查看完整报告</button>`
      : "";
    return summaryHtml || linkHtml ? `<div class="report-glance">${summaryHtml}${linkHtml}</div>` : "";
  }

  // 摘要取报告自己写的那句话，按「越靠前越像结论」的顺序挑第一个字符串字段。
  // ⚠️ 只接受字符串：这些字段有时是数组，摊平了会变成一长串分号（EXEC-07 F2 治过的
  //    「一大坨分号串」）。数组形态就当没有摘要，让人去看全文。
  // 兜底行：正文可能是格式化 JSON，第一行是个孤零零的 "{"。摘要位置只收像人话的句子，
  // 收不到就交给上层显示「点开看全文」——空着比摆个括号强。
  function glanceFallbackLine(text) {
    const raw = String(text || "").trim();
    if (!raw) return "";
    const first = raw.split(/\n+/)[0].trim();
    // 纯标点的一行：格式化 JSON 的第一行就是个孤零零的括号。
    if (/^[[\]{}(),:;"'`]+$/.test(first)) return "";
    // 一眼就是 JSON 键值对的行：正文被压成一行时会撞上这种。
    if (/^[[\]{}]?\s*"[^"]+"\s*:/.test(first)) return "";
    return first;
  }

  // ⚠️ subtitle 排在最后：它是「这份报告在做什么」而不是结论，只有真的什么都没有时才用它
  //    （意图挖掘这类技能就只有 subtitle，缺了它摘要位置会空着）。
  function reportOneLineSummary(parsed) {
    parsed = globalThis.GEO?.normalizeIntentReport?.(parsed) || parsed;
    const fields = ["core_answer", "analysis_summary", "summary", "executive_summary", "conclusion", "subtitle"];
    for (const field of fields) {
      const value = parsed[field];
      if (typeof value !== "string") continue;
      const text = readableText(value).trim();
      if (text) return text;
    }
    return "";
  }


  // 2026-09-05：原来这里是把人送到「历史报告」页、再在那边选中这一条。
  // 现在报告有自己的一页，直接去 #result/<编号> —— 少一次列表加载，
  // 地址也能直接分享/收藏到具体某一份报告。
  function openArticleFromAnalysis(articleId) {
    openResultPage(articleId);
  }

  // 2026-09-04 拆版式 · 步骤 2：诊断与路线页上的两个「全部…记录 →」。
  // 两个历史列表撤到历史报告页之后，这是从本页去看全量记录的唯一入口，
  // 所以过去要**带着来源筛选**过去，别让人到了那边再自己找。
  // ⚠️ 触发它的两个按钮绝不能带 data-view-target：那个属性是给侧栏菜单用的，
  //    挂在别处会让点击被吞掉、主区变白且不报错。
  function openAssetsFiltered(sourceType) {
    state.assetSourceType = sourceType;
    state.assetSearch = "";
    state.selectedArticleId = null;
    if (elements.assetSearchInput) elements.assetSearchInput.value = "";
    setActiveWorkspaceView("asset", { updateHash: true });
    if (state.selectedProject) loadArticles(state.selectedProject.id);
  }

  // 2026-09-05：原名 openSkillWorkspaceFromAnalysis()，干的是「切到技能页 + 滚回页顶」。
  // 两件事都是错的：综合诊断报告（#analysisResult）全站只长在诊断与路线页里
  //（welcome.blade.php 里 id="analysisResult" 只有一处，就在 #skillPanel 内），
  // 所以那句 setActiveWorkspaceView("skill") 100% 是切到你已经在的这一页；
  // 而 scrollTo({top:0}) 把人推向页顶——技能表单在页面下半截，等于把人推得**更远**。
  // 现在按按钮字面意思做：留在本页，滚到下面的「单项技能」。
  function scrollToSkillCatalog() {
    setSkillWorkspaceMode("tools");
    setMessage(elements.skillMessage, "请根据综合诊断里的关键发现，选择内容生产、页面审计或效果监测等技能继续执行。");
    scrollToWithHeaderOffset(elements.skillCatalogHeading || elements.skillFilters);
  }

  function resultSection(title, items) {
    const list = readableList(items);
    if (list.length === 0) return "";
    return `<h4>${escapeHtml(title)}</h4><ul>${list.map((item) => `<li>${escapeHtml(item)}</li>`).join("")}</ul>`;
  }

  function buildReportMarkdown(analysis, report, model) {
    const output = normalizeReport(report);
    return [
      "# 综合诊断报告",
      "",
      "## 综合结论",
      output.summary || "暂无综合结论。",
      "",
      output.overall_score !== null && output.overall_score !== undefined ? `## 综合评分\n${output.overall_score} / 100\n` : "",
      markdownSection("关键发现", output.key_findings),
      markdownSection("优化建议", output.recommendations),
      markdownSection("下一步行动", output.next_actions),
      `## 模型用量\nTokens：${formatNumber(model.total_tokens)}`,
    ].filter((part) => part !== "").join("\n");
  }

  function markdownSection(title, items) {
    const list = readableList(items);
    if (list.length === 0) return `## ${title}\n暂无。\n`;
    return [`## ${title}`, ...list.map((item) => `- ${item}`), ""].join("\n");
  }

  function normalizeReport(report) {
    const parsed = tryParseJsonValue(report);
    const source = parsed || (report && typeof report === "object" ? report : {});
    return {
      // EXEC-07 F8: a 2D-array summary is emitted as point bullets, not flattened with "；".
      summary: (Array.isArray(source.summary) && source.summary.length && Array.isArray(source.summary[0]))
        ? source.summary.map((row) => {
            if (!Array.isArray(row)) return `- ${readableText(row)}`;
            const head = readableText(row[0]);
            const rest = row.slice(1).map((c) => readableText(c)).filter(Boolean).join("；");
            return `- ${head ? `**${head}**` : ""}${rest ? `：${rest}` : ""}`;
          }).join("\n")
        : readableText(source.summary || source.analysis || source.result || source.conclusion || source.recommendation || source.estimated_impact || ""),
      key_findings: firstReadableList(
        source.key_findings,
        source.findings,
        source.critical_issues,
        source.top_opportunities,
        source.intent_clusters,
        source.dimensions,
        source.issues,
        source.knowledge_gaps,
      ),
      recommendations: firstReadableList(
        source.recommendations,
        source.suggestions,
        source.quick_wins,
        source.schema_recommendations,
        source.recommended_sections,
        source.recommendation,
      ),
      next_actions: readableList(source.next_actions || source.next_steps || []),
      overall_score: source.overall_score ?? source.score ?? null,
      model_usage: source.model_usage || {},
    };
  }

  function firstReadableList(...values) {
    for (const value of values) {
      const items = readableList(value);
      if (items.length) return items;
    }
    return [];
  }

  function readableList(value) {
    const parsed = tryParseJsonValue(value);
    const source = parsed ?? value;
    if (Array.isArray(source)) {
      return source.map((item) => readableText(item)).filter(Boolean);
    }
    const text = readableText(source);
    return text ? [text] : [];
  }

  function readableText(value) {
    const parsed = tryParseJsonValue(value);
    if (parsed !== null && parsed !== value) {
      return readableText(parsed);
    }
    if (value === null || value === undefined) return "";
    if (Array.isArray(value)) {
      return value.map((item) => readableText(item)).filter(Boolean).join("；");
    }
    if (typeof value === "object") {
      return readableObject(value);
    }
    return String(value).trim();
  }

  function readableObject(value) {
    const entries = Object.entries(value || {}).filter(([, item]) => item !== null && item !== undefined && item !== "");
    if (entries.length === 0) return "";

    const headlineKeys = ["finding", "recommendation", "action", "task", "title", "summary", "phase", "name", "keyword", "section"];
    const headlineEntry = entries.find(([key]) => headlineKeys.includes(String(key)));
    const parts = [];
    if (headlineEntry) parts.push(readableText(headlineEntry[1]));

    entries.forEach(([key, item]) => {
      if (headlineEntry && key === headlineEntry[0]) return;
      const text = readableText(item);
      if (!text) return;
      parts.push(`${fieldLabel(key)}：${text}`);
    });

    return parts.join("；");
  }

  function parseStructuredResultFromText(value) {
    if (!value) return null;
    const direct = tryParseJsonValue(value);
    if (direct && typeof direct === "object") return direct;

    const text = String(value).trim();
    const objectCandidate = extractBracketedJson(text, "{", "}");
    if (objectCandidate) {
      const parsed = tryParseJsonValue(objectCandidate);
      if (parsed && typeof parsed === "object" && hasStructuredResultKeys(parsed)) return parsed;
    }

    const arrayCandidate = extractBracketedJson(text, "[", "]");
    if (arrayCandidate) {
      const parsed = tryParseJsonValue(arrayCandidate);
      if (parsed && typeof parsed === "object") return parsed;
    }

    return null;
  }

  function extractBracketedJson(text, open, close) {
    const start = text.indexOf(open);
    const end = text.lastIndexOf(close);
    if (start < 0 || end <= start) return "";
    return text.slice(start, end + 1).trim();
  }

  function tryParseJsonValue(value) {
    if (typeof value !== "string") return null;
    const text = extractJsonCandidate(value);
    if (!text || !/^[\[{]/.test(text)) return null;
    try {
      return JSON.parse(text);
    } catch (error) {
      return null;
    }
  }

  function extractJsonCandidate(value) {
    const text = value.trim();
    const fenced = text.match(/```(?:json|JSON)?\s*([\s\S]*?)\s*```/);
    if (fenced) return fenced[1].trim();
    return text;
  }

  function fieldLabel(key) {
    const labels = {
      detail: "说明",
      details: "说明",
      reason: "原因",
      expected_impact: "预期影响",
      impact: "影响",
      priority: "优先级",
      tasks: "任务",
      skills: "关联技能",
      keywords: "关键词",
      search_volume: "搜索量",
      intent: "意图",
      competition: "竞争度",
      score: "评分",
      trend: "趋势",
      status: "状态",
      focus: "重点",
      schema: "结构化数据",
      elements: "页面元素",
      source: "来源",
      evidence: "依据",
      question: "问题",
      answer: "回答",
      term: "术语",
      definition: "定义",
      paper: "文献",
      finding: "发现",
      design_rule: "设计规则",
      title: "标题",
      explanation: "说明",
      action: "操作",
      check: "检查点",
      criterion: "标准",
      how_to_judge: "如何判断",
      suitable_for: "适用于",
      risk: "风险",
      mistake: "误区",
      correction: "纠正",
      position: "位置",
      suggestion: "建议",
      label: "名称",
      locator: "定位",
      usage: "用途",
      value: "值",
      note: "备注",
      name: "名称",
      flow: "流程",
      allocation: "分配",
      type: "类型",
      scenario: "场景",
      platform_fit: "平台适配",
      why_it_works: "为何有效",
      rewrite_advice: "改写建议",
      intent_match: "意图匹配",
      entity_clarity: "实体清晰度",
      differentiation: "差异化",
      citation_potential: "引用潜力",
      compliance: "合规性",
      freshness: "时效性",
      keyword: "关键词",
      module: "模块",
      project_date: "项目日期",
      region: "地区",
      audience: "受众",
      target_platforms: "目标平台",
      article_types: "文章类型",
      usable_data: "可用数据",
      missing_data: "缺失数据",
      safe_conclusion: "安全结论",
      next_action: "下一步",
      standalone_rewrite: "独立改写",
      retrieval_rewrite: "检索改写",
      evidence_query: "证据查询",
      content_asset: "内容",
      compliance_level: "合规等级",
      question_id: "问题编号",
      cluster: "聚类",
      intent_type: "意图类型",
      business_value: "商业价值",
      ai_answer_probability: "AI 答案触发概率",
      content_gap: "内容缺口",
      brand_insert_space: "品牌植入空间",
      evidence_availability: "证据可得性",
      competition_difficulty: "竞争难度",
      conversation_extension: "对话延展价值",
      decision_stage_value: "决策阶段价值",
      platform_coverage: "平台覆盖度",
      compliance_risk: "合规风险",
      weighted_score: "加权评分",
      chain_id: "追问链编号",
      root_question_id: "起始问题编号",
      parent_question_id: "上级问题编号",
      follow_up_level: "追问层级",
      context_dependency: "上下文依赖",
      follow_up_question: "追问问题",
      prompt_id: "提示词编号",
      platform: "平台",
      prompt: "提示词",
      record_fields: "记录字段",
      source_id: "来源编号",
      source_type: "来源类型",
      provider: "提供方",
      access_status: "接入状态",
      record_count: "记录数量",
      date_range: "日期范围",
      calibration_use: "校准用途",
      sample_id: "采样编号",
      brand_mentioned: "是否提及品牌",
      cited_sources: "引用来源",
      risk_flags: "风险提示",
      action_id: "行动编号",
      signal: "校准信号",
      affected_dimensions: "影响维度",
      current_status: "当前状态",
      method: "方法",
      owner: "负责人",
      topic_id: "选题编号",
      source_question: "来源问题",
      asset_type: "内容类型",
      evidence_need: "证据需求",
      faq_id: "问答编号",
      answer_boundary: "回答边界",
      target_asset: "目标内容",
      entry_id: "条目编号",
      entry_type: "条目类型",
      covered_questions: "覆盖问题",
      required_materials: "所需资料",
      level: "等级",
      use: "用途",
      url: "链接",
      phase: "阶段",
      task: "任务",
      deliverable: "交付成果",
      acceptance: "验收标准",
    };
    const normalized = String(key).trim().toLowerCase().replace(/[\s-]+/g, "_");
    return Object.hasOwn(labels, normalized) ? labels[normalized] : String(key).replace(/_/g, " ");
  }

  async function copyReport(markdown) {
    await copyText(markdown);
    setMessage(elements.analysisMessage, "报告已复制。", "success");
  }

  async function copyText(text) {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        fallbackCopy(text);
      }
    } catch (error) {
      fallbackCopy(text);
    }
  }

  function fallbackCopy(text) {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand("copy");
    textarea.remove();
  }

  function downloadReport(analysis, markdown) {
    downloadText(`geo-analysis-${analysis.project_id}-${analysis.id}.md`, markdown);
    setMessage(elements.analysisMessage, "报告正文已下载。", "success");
  }

  async function downloadReportFormat(analysis, format, fallbackMarkdown) {
    try {
      const baseUrl = analysis.download_url || `/api/v1/projects/${analysis.project_id}/analyses/${analysis.id}/download`;
      const response = await fetch(`${baseUrl}/${encodeURIComponent(format)}`, {
        credentials: "include",
        headers: authHeaders({ Accept: "*/*" }),
      });
      if (!response.ok) throw new Error(`暂时没有 ${formatLabel(format)} 文件。`);
      const blob = await response.blob();
      downloadBlob(`geo-analysis-${analysis.project_id}-${analysis.id}.${formatExtension(format)}`, blob);
      setMessage(elements.analysisMessage, `${formatLabel(format)} 已下载。`, "success");
    } catch (error) {
      if (format === "markdown" && fallbackMarkdown) {
        downloadReport(analysis, fallbackMarkdown);
        return;
      }
      setMessage(elements.analysisMessage, error.message || `无法下载 ${formatLabel(format)}。`, "danger");
    }
  }

  function formatExtension(format) {
    return format === "markdown" ? "md" : format || "txt";
  }

  function downloadText(filename, text) {
    downloadBlob(filename, new Blob([text], { type: "text/markdown;charset=utf-8" }));
  }

  function downloadBlob(filename, blob) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function setFormValue(form, name, value) {
    const field = form?.elements?.[name];
    if (field) field.value = value;
  }

  function validationMessage(error) {
    const errors = error.body?.errors || {};
    const firstKey = Object.keys(errors)[0];
    if (firstKey) return errors[firstKey][0] || error.message;
    return error.message || "请求失败。";
  }

  function formatMoneyFen(value, currency = "CNY") {
    const amount = Number(value || 0) / 100;
    const normalizedCurrency = String(currency || "CNY").toUpperCase();
    if (normalizedCurrency === "CNY") return `¥${amount.toLocaleString("zh-CN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    return `${amount.toLocaleString("zh-CN", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${normalizedCurrency}`;
  }

  function formatDate(value) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat("zh-CN", {
      timeZone: "Asia/Shanghai",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: false,
      hourCycle: "h23",
    }).format(date);
  }

  function formatKnowledgeDate(value) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return "-";
    return new Intl.DateTimeFormat("zh-CN", {
      year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit", second: "2-digit",
      hour12: false, timeZoneName: "short",
    }).format(date);
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // ═══════════════════════════════════════════════
  // 共享浮层提示 / 防抖（各视图与拆出的块都用；拆出块从 window.GEO 取）
  // ═══════════════════════════════════════════════

  function clDebounce(fn, ms) {
    let timer = null;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), ms);
    };
  }

  let clToastTimer = null;
  function clToast(message, variant = "neutral") {
    let node = document.getElementById("clToast");
    if (!node) {
      node = document.createElement("div");
      node.id = "clToast";
      node.className = "cl-toast";
      node.setAttribute("role", "status");
      document.body.appendChild(node);
    }
    node.textContent = message;
    node.dataset.variant = variant;
    node.classList.add("show");
    clearTimeout(clToastTimer);
    clToastTimer = setTimeout(() => node.classList.remove("show"), 2800);
  }

  function clIsArticle(article) {
    if (isStructuredResultAsset(article)) return false;
    const sourceType = String(article.source_type || "").toLowerCase();
    if (sourceType === "geo_analysis") return false;
    if (sourceType === "skill_execution") return isArticleContentAsset(article);

    const ct = String(article.content_type || "").toLowerCase();
    const managed = ["explainer", "comparison", "ranking", "general"].includes(ct);
    return managed || isArticleContentAsset(article);
  }

  // 抽屉的焦点回归元素：内容库抽屉与技能提示词抽屉共用。
  // 2026-09-21 §7 拆分后内容库住 geo-entry-content-library.js，两边都通过
  // window.GEO.clDrawerState 读写同一个对象（拆成两个变量会让 Esc 焦点回归失灵）。
  const clDrawerState = { trigger: null };


  // ═══════════════════════════════════════════════
  // Automation Tasks (AT0-AT4)
  // 严格照 §1 端点对账表；失败一律 error/占位态，绝不 fixture。
  // ═══════════════════════════════════════════════

  const AT_CADENCE_LABELS = { hourly: "每小时", daily: "每天", weekly: "每周", monthly: "每月" };
  const AT_RUN_STATUS = {
    pending: ["排队中", "neutral"],
    running: ["运行中", "warning"],
    billing_pending: ["原请求待核验", "warning"],
    succeeded: ["成功", "success"],
    failed: ["失败", "danger"],
    skipped: ["跳过", "neutral"],
  };

  function atToast(message, variant = "neutral") {
    clToast(message, variant);
  }

  function atFormatDateTime(value) {
    if (!value) return "—";
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const targetDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const diffDays = Math.round((startToday - targetDay) / 86400000);
    const hhmm = `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
    if (diffDays === 0) return `今天 ${hhmm}`;
    if (diffDays === -1) return `明天 ${hhmm}`;
    if (diffDays === 1) return `昨天 ${hhmm}`;
    return `${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")} ${hhmm}`;
  }

  function atSetState(name) {
    const host = elements.atHost;
    if (!host) return;
    host.querySelectorAll("[data-state]").forEach((node) => { node.hidden = node.dataset.state !== name; });
  }

  function atSetRunsState(name) {
    const host = elements.atRunsHost;
    if (!host) return;
    host.querySelectorAll("[data-state]").forEach((node) => { node.hidden = node.dataset.state !== name; });
  }

  function atStatusBadge(task) {
    if (task.status === "paused") return `<span class="badge" data-variant="neutral">已暂停</span>`;
    return `<span class="badge" data-variant="success">运行中</span>`;
  }

  function atFlagBadges(task) {
    const pub = task.auto_publish
      ? `<span class="badge" data-variant="success">自动发布</span>`
      : `<span class="badge" data-variant="neutral">不发布</span>`;
    const dist = task.auto_distribute
      ? `<span class="badge" data-variant="success">自动分发</span>`
      : `<span class="badge" data-variant="neutral">不分发</span>`;
    return `<div class="at-flags">${pub}${dist}</div>`;
  }

  function atRowHtml(task) {
    const cadence = AT_CADENCE_LABELS[task.cadence] || task.cadence || "每天";
    const next = atFormatDateTime(task.next_run_at);
    const skillLabel = escapeHtml(task.skill_id || "—");
    const query = task.query ? escapeHtml(task.query) : "未指定主题";
    const actions = [`<button class="button ghost small" type="button" data-at-action="run" data-at-id="${task.id}">立即运行</button>`];
    if (task.status === "paused") {
      actions.push(`<button class="button small" type="button" data-at-action="resume" data-at-id="${task.id}">恢复</button>`);
    } else {
      actions.push(`<button class="button ghost small" type="button" data-at-action="pause" data-at-id="${task.id}">暂停</button>`);
    }
    actions.push(`<button class="button danger-ghost small" type="button" data-at-action="delete" data-at-id="${task.id}">删除</button>`);
    return `<tr data-at-id="${task.id}">
      <td><div class="cl-cell-main">${escapeHtml(task.name || "未命名任务")}</div><div class="cl-cell-sub">技能：${skillLabel} · ${query}</div></td>
      <td><div class="cl-cell-sub">${cadence} · ${next}</div></td>
      <td>${atFlagBadges(task)}</td>
      <td>${atStatusBadge(task)}</td>
      <td class="cl-cell-actions">${actions.join("")}</td>
    </tr>`;
  }

  async function loadAutomationTasks() {
    if (!elements.automationTasksPanel) return;
    atSetState("loading");
    try {
      const body = await api("/api/v1/tasks");
      state.atTasks = Array.isArray(body?.data) ? body.data : [];
      renderAutomationTasks();
    } catch (error) {
      fillErrorNo(elements.atErrorNo, error);
      atSetState("error");
    }
  }

  function renderAutomationTasks() {
    if (!elements.atRows) return;
    const list = state.atTasks.slice();
    if (list.length === 0) { atSetState("empty"); return; }
    elements.atRows.innerHTML = list.map(atRowHtml).join("");
    atSetState("populated");
  }

  function atApplyTask(task) {
    if (!task || !task.id) return;
    const idx = state.atTasks.findIndex((t) => String(t.id) === String(task.id));
    if (idx >= 0) state.atTasks[idx] = { ...state.atTasks[idx], ...task };
    else state.atTasks.push(task);
    renderAutomationTasks();
  }

  async function atRowsClick(event) {
    const btn = event.target.closest("[data-at-action]");
    if (!btn) return;
    const id = btn.dataset.atId;
    const action = btn.dataset.atAction;
    if (action === "run") return atRunNow(id, btn);
    if (action === "pause") return atPause(id, btn);
    if (action === "resume") return atResume(id, btn);
    if (action === "delete") return atDelete(id);
  }

  async function atRunNow(id, btn) {
    try {
      await withBusyButton(btn, "入队中…", () => api(`/api/v1/tasks/${id}/run`, { method: "POST" }));
      atToast("已入队执行。", "success");
      // 异步入队，不期待即时结果；稍后重读列表（不伪造运行结果）
      setTimeout(() => loadAutomationTasks(), 1200);
    } catch (error) {
      atToast(error.message || "立即运行失败。", "danger");
    }
  }

  async function atPause(id, btn) {
    try {
      const body = await withBusyButton(btn, "暂停中…", () => api(`/api/v1/tasks/${id}/pause`, { method: "POST" }));
      atApplyTask(body?.data && body.data.id ? body.data : { id, status: "paused" });
      atToast("已暂停。", "success");
    } catch (error) {
      atToast(error.message || "暂停失败。", "danger");
    }
  }

  async function atResume(id, btn) {
    try {
      const body = await withBusyButton(btn, "恢复中…", () => api(`/api/v1/tasks/${id}/resume`, { method: "POST" }));
      atApplyTask(body?.data && body.data.id ? body.data : { id, status: "active" });
      atToast("已恢复。", "success");
    } catch (error) {
      atToast(error.message || "恢复失败。", "danger");
    }
  }

  async function atDelete(id) {
    if (!await confirmWorkspaceAction("确定删除这条自动化任务吗？删除后不再自动执行，已生成的文章不受影响。")) return;
    try {
      await api(`/api/v1/tasks/${id}`, { method: "DELETE" });
      state.atTasks = state.atTasks.filter((t) => String(t.id) !== String(id));
      atToast("已删除。", "success");
      renderAutomationTasks();
    } catch (error) {
      atToast(error.message || "删除失败。", "danger");
    }
  }

  async function atOpenForm() {
    if (!elements.atFormPanel) return;
    if (!state.selectedProject) {
      setMessage(elements.atMessage, "请先在左上角选择一个项目，再新建自动化任务。", "warning");
      return;
    }
    setMessage(elements.atMessage, "");
    await atEnsureSkills();
    elements.atFormPanel.hidden = false;
    const projInput = elements.atForm?.querySelector('[name="project_id_display"]');
    if (projInput) projInput.value = `${state.selectedProject.name || state.selectedProject.id}（#${state.selectedProject.id}）`;
    if (elements.atFormError) elements.atFormError.textContent = "";
    // 技能下拉每次重填后同步一次动态输入区，避免上次的选项与字段对不上。
    atRenderDynamicFields();
  }

  async function atEnsureSkills() {
    if (!state.atSkills.length) {
      try {
        const body = await api(config.skillsEndpoint || "/api/v1/skills");
        state.atSkills = body.data || [];
      } catch (error) {
        state.atSkills = [];
      }
    }
    const select = elements.atForm?.querySelector('[name="skill_id"]');
    if (select) {
      select.innerHTML = `<option value="">请选择技能…</option>`
        + state.atSkills.map((s) => `<option value="${escapeHtml(s.id)}">${escapeHtml(s.name || s.scene_description || s.id)}</option>`).join("");
    }
  }

  /**
   * 技能动态输入（2026-09-21 工作台功能补齐，计划 §5）。
   *
   * 此前自动化任务只提交 name/skill_id/cadence/query/auto_* —— 技能的必填输入
   * （主题、目标关键词、地区…）根本传不进去，任务到点只能拿默认值跑，产出质量完全不受控。
   * 现在按所选技能的 brief_fields 动态生成输入项，随任务存进 input_data
   * （TaskController::store 接受 input_data；TaskOrchestrationService 用它构造简报）。
   */
  function atSelectedSkill() {
    const skillId = elements.atForm?.querySelector('[name="skill_id"]')?.value || "";
    if (!skillId) return null;
    return state.atSkills.find((item) => String(item.id) === String(skillId)) || null;
  }

  function atRenderDynamicFields() {
    const host = elements.atDynamicFields;
    const row = elements.atDynamicFieldRow;
    if (!host || !row) return;
    const skill = atSelectedSkill();
    const fields = Array.isArray(skill?.brief_fields) ? skill.brief_fields : [];
    if (!skill || fields.length === 0) {
      host.innerHTML = "";
      row.hidden = true;
      return;
    }
    host.innerHTML = fields.map((field) => fieldControl(skill, field, "atBrief")).join("");
    row.hidden = false;
  }

  /**
   * 收集动态输入。空字符串不提交 —— 提交空值会把技能自己的默认值覆盖掉；
   * 列表型字段（种子关键词、竞品…）按 lineList 拆成数组，与技能执行表单同一口径。
   */
  function atDynamicInputData() {
    const host = elements.atDynamicFields;
    if (!host) return null;
    const controls = Array.from(host.querySelectorAll("[name]"));
    const data = {};
    controls.forEach((control) => {
      const value = normalizeFieldValue(control, control.value);
      const isEmpty = Array.isArray(value) ? value.length === 0 : value === "";
      if (isEmpty) return;
      data[control.name] = value;
    });
    return Object.keys(data).length ? data : null;
  }

  async function atSubmitForm(event) {
    event.preventDefault();
    const form = elements.atForm;
    if (!form) return;
    const nameEl = form.querySelector('[name="name"]');
    const skillEl = form.querySelector('[name="skill_id"]');
    const name = (nameEl?.value || "").trim();
    const skillId = skillEl?.value || "";
    if (!state.selectedProject) {
      if (elements.atFormError) elements.atFormError.textContent = "请先选择项目。";
      return;
    }
    if (!name) { if (elements.atFormError) elements.atFormError.textContent = "请填写任务名称。"; return; }
    if (!skillId) { if (elements.atFormError) elements.atFormError.textContent = "请选择技能。"; return; }
    const payload = {
      name,
      skill_id: skillId,
      project_id: state.selectedProject.id,
      cadence: form.querySelector('[name="cadence"]')?.value || "daily",
      auto_publish: Boolean(form.querySelector('[name="auto_publish"]')?.checked),
      auto_distribute: Boolean(form.querySelector('[name="auto_distribute"]')?.checked),
    };
    const q = (form.querySelector('[name="query"]')?.value || "").trim();
    if (q) payload.query = q;
    const inputData = atDynamicInputData();
    if (inputData) payload.input_data = inputData;
    const submitBtn = elements.atSubmitButton;
    try {
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = "创建中…"; }
      const body = await api("/api/v1/tasks", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      if (body?.data && body.data.id) atApplyTask(body.data);
      atToast("任务已创建。", "success");
      form.reset();
      atRenderDynamicFields(); // reset() 清空技能选择 → 动态输入区一并收起
      if (elements.atFormPanel) elements.atFormPanel.hidden = true;
      // 写后回读：确保列表出现真实新任务
      await loadAutomationTasks();
    } catch (error) {
      if (elements.atFormError) elements.atFormError.textContent = error.message || "创建失败。";
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = "创建任务"; }
    }
  }

  function atRunResultBadge(run) {
    const [label, variant] = AT_RUN_STATUS[run.status] || ["未知", "neutral"];
    return `<span class="badge" data-variant="${variant}">${label}</span>`;
  }

  function atRunOutput(run) {
    const meta = run.metadata && typeof run.metadata === "object" ? run.metadata : {};
    const title = run.article_title || meta.article_title;
    if (title) {
      const pub = meta.auto_published ? " · 已发布" : "";
      return `<div class="cl-cell-sub">《${escapeHtml(title)}》${pub}</div>`;
    }
    // 无 BT2 产出标题 → 只显状态词，不脑补
    return `<div class="cl-cell-sub">${escapeHtml(AT_RUN_STATUS[run.status]?.[0] || run.status || "—")}</div>`;
  }

  function atRunTaskName(run) {
    if (run.task?.name) return run.task.name;
    const t = state.atTasks.find((x) => String(x.id) === String(run.task_id));
    return t?.name || (run.task_id ? `任务 #${run.task_id}` : "—");
  }

  function atRunRowHtml(run) {
    const taskName = atRunTaskName(run);
    const time = atFormatDateTime(run.started_at) + (run.finished_at ? ` → ${atFormatDateTime(run.finished_at)}` : "");
    // X2: 积分列。历史 run 无 points_cost 字段、扣费失败 run 为 0，一律显「—」，不脑补 0。
    const meta = run.metadata && typeof run.metadata === "object" ? run.metadata : {};
    const tokensCell = formatNumber(meta.token_usage);
    return `<tr>
      <td><div class="cl-cell-main">${escapeHtml(taskName)}</div></td>
      <td>${atRunResultBadge(run)}</td>
      <td>${atRunOutput(run)}</td>
      <td><div class="cl-cell-sub">${tokensCell}</div></td>
      <td><div class="cl-cell-sub">${time}</div></td>
    </tr>`;
  }

  async function loadTaskRuns() {
    if (!elements.atRunsHost) return;
    atSetRunsState("loading");
    try {
      const body = await api("/api/v1/tasks/runs");
      state.atRuns = Array.isArray(body?.data) ? body.data : [];
      renderTaskRuns();
    } catch (error) {
      // 404 = 集合端点尚未上线（BT1）→ 诚实占位；其余（403/500 等）是真实错误，进 error 态
      if (error.status === 404) { atSetRunsState("placeholder"); return; }
      fillErrorNo(elements.atRunsErrorNo, error);
      atSetRunsState("error");
    }
  }

  function renderTaskRuns() {
    if (!elements.atRunsRows) return;
    const list = state.atRuns.slice();
    if (list.length === 0) { atSetRunsState("empty"); return; }
    elements.atRunsRows.innerHTML = list.map(atRunRowHtml).join("");
    atSetRunsState("populated");
  }

  function initAutomationTasks() {
    elements.atNewTaskButton?.addEventListener("click", atOpenForm);
    elements.atCancelFormButton?.addEventListener("click", () => {
      if (elements.atFormPanel) elements.atFormPanel.hidden = true;
      if (elements.atFormError) elements.atFormError.textContent = "";
    });
    elements.atForm?.addEventListener("submit", atSubmitForm);
    elements.atRetryButton?.addEventListener("click", loadAutomationTasks);
    elements.atRunsRetryButton?.addEventListener("click", loadTaskRuns);
    elements.atRows?.addEventListener("click", atRowsClick);
  }

  // ═══════════════════════════════════════════════
  // Team Management
  // ═══════════════════════════════════════════════

  async function loadTeam() {
    setMessage(elements.teamMessage, "正在加载团队成员...");
    try {
      const body = await api("/api/v1/team/");
      state.teamMembers = body.data || body || [];
      renderTeam();
      setMessage(elements.teamMessage, `共 ${state.teamMembers.length} 名成员。`);
    } catch (error) {
      if (error.status === 403) {
        setMessage(elements.teamMessage, "当前角色不能管理团队。", "danger");
      } else {
        setMessage(elements.teamMessage, error.message || "无法加载团队信息。", "danger");
      }
    }
  }

  function renderTeam() {
    if (!elements.teamList) return;
    elements.teamList.innerHTML = "";
    if (state.teamMembers.length === 0) {
      elements.teamList.innerHTML = '<div class="empty">还没有团队成员。</div>';
      return;
    }
    state.teamMembers.forEach((member) => {
      const roleLabel = member.role === "admin" ? "管理员" : member.role === "editor" ? "编辑" : "成员";
      const item = document.createElement("div");
      item.className = "history-item team-member-item";
      item.innerHTML = `
        <strong>${escapeHtml(member.name || member.phone || "未命名")}</strong>
        <span>${escapeHtml(roleLabel)} · ${escapeHtml(member.phone || member.email || "-")}</span>
        ${member.role !== "admin" ? `<div class="team-member-actions">
          <button class="button secondary small" type="button" data-team-action="role" data-user-id="${escapeHtml(member.id)}">变更角色</button>
          <button class="button danger small" type="button" data-team-action="remove" data-user-id="${escapeHtml(member.id)}">移除</button>
        </div>` : ""}
      `;
      elements.teamList.appendChild(item);
    });
    elements.teamList.querySelectorAll("[data-team-action]").forEach((btn) => {
      btn.addEventListener("click", () => handleTeamAction(btn.dataset.teamAction, Number(btn.dataset.userId)));
    });
  }

  async function inviteTeamMember(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector("#inviteMessage");
    const data = new FormData(form);
    setMessage(message, "正在发送邀请...");
    try {
      await api("/api/v1/team/invite", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ phone: String(data.get("phone") || "").trim(), role: String(data.get("role") || "member") }),
      });
      setMessage(message, "邀请已发送。", "success");
      form.reset();
      if (elements.teamInviteForm) elements.teamInviteForm.hidden = true;
      await loadTeam();
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function handleTeamAction(action, userId) {
    if (action === "remove") {
      if (!await confirmWorkspaceAction("确定移除这名成员吗？")) return;
      try {
        await api(`/api/v1/team/${userId}`, { method: "DELETE" });
        setMessage(elements.teamMessage, "成员已移除。", "success");
        await loadTeam();
      } catch (error) {
        setMessage(elements.teamMessage, error.message || "移除失败。", "danger");
      }
    } else if (action === "role") {
      const newRole = await promptWorkspaceValue("输入新角色（admin / editor / member）：", "editor");
      if (!newRole) return;
      try {
        await api(`/api/v1/team/${userId}/role`, {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ role: newRole }),
        });
        setMessage(elements.teamMessage, "角色已变更。", "success");
        await loadTeam();
      } catch (error) {
        setMessage(elements.teamMessage, error.message || "角色变更失败。", "danger");
      }
    }
  }

  // ═══════════════════════════════════════════════
  // AI Key Management
  // ═══════════════════════════════════════════════

  async function loadAiKeys(resetSlot = null) {
    setMessage(elements.aikeysMessage, "正在加载 AI Key...");
    try {
      const body = await api("/api/v1/ai-keys");
      state.aiKeys = body.data || [];
      state.aiPresets = body.presets || {};
      renderAiKeys(resetSlot);
      setMessage(elements.aikeysMessage, `共 ${state.aiKeys.length} 个 Key。`);
    } catch (error) {
      if (error.status === 403) {
        setMessage(elements.aikeysMessage, "当前角色不能管理 AI Key。", "danger");
      } else {
        setMessage(elements.aikeysMessage, error.message || "无法加载 AI Key。", "danger");
      }
    }
  }

  function renderAiKeys(resetSlot = null) {
    if (!elements.aikeyList) return;
    elements.aikeyList.querySelectorAll("[data-model-slot]").forEach((form) => {
      // A saved slot must not erase Keys or connection proofs being edited elsewhere.
      if (typeof resetSlot === "string" && form.dataset.modelSlot !== resetSlot) return;
      const key = state.aiKeys.find((row) => row.slot === form.dataset.modelSlot);
      form.reset();
      delete form.dataset.verificationToken;
      if (key) form.elements.provider.value = key.provider;
      const preset = state.aiPresets[form.elements.provider.value] || {};
      form.elements.base_url.value = key?.base_url || preset.base_url || "";
      form.elements.model.value = key?.model || "";
      form.elements.label.value = key?.label || "";
      form.elements.json_mode.checked = key?.json_mode ?? !!preset.json_mode;
      form.querySelector("[data-custom-json]").hidden = form.elements.provider.value !== "custom";
      form.querySelector("[data-model-status]").textContent = key
        ? `${key.is_active ? "已启用" : "已停用"} · ${key.key_preview}` : "未配置";
      form.querySelectorAll("[data-aikey-action]").forEach((button) => {
        button.hidden = !key;
        button.dataset.keyId = key?.id || "";
        if (button.dataset.aikeyAction === "toggle") button.textContent = key?.is_active ? "停用" : "启用";
      });
    });
    globalThis.GEO?.syncModelConfigUi?.();
  }

  function modelConfigurationPayload(form) {
    const data = new FormData(form);
    return {
      slot: form.dataset.modelSlot,
      label: String(data.get("label") || "").trim(),
      provider: String(data.get("provider") || ""),
      base_url: String(data.get("base_url") || "").trim(),
      model: String(data.get("model") || "").trim(),
      api_key: String(data.get("api_key") || "").trim(),
      json_mode: data.get("json_mode") === "on",
      verification_token: form.dataset.verificationToken || null,
    };
  }

  async function createAiKey(event) {
    event.preventDefault();
    const form = event.currentTarget;
    await submitModelConfiguration(form, false);
  }

  async function submitModelConfiguration(form, testing) {
    if (!form.reportValidity() || form.dataset.busy === "true") return;
    const message = form.querySelector("[data-model-message]");
    form.dataset.busy = "true";
    form.querySelectorAll("button").forEach((button) => { button.disabled = true; });
    setMessage(message, testing ? "正在测试连接…" : "正在保存…");
    try {
      const body = await api(testing ? "/api/v1/ai-keys/test" : "/api/v1/ai-keys", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify(modelConfigurationPayload(form)),
      });
      if (testing) {
        if (body.verification_token) form.dataset.verificationToken = body.verification_token;
        setMessage(message, body.message || "连接测试通过。", "success");
      } else {
        form.elements.api_key.value = "";
        await loadAiKeys(form.dataset.modelSlot);
        setMessage(message, "模型配置已保存。", "success");
      }
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    } finally {
      delete form.dataset.busy;
      form.querySelectorAll("button").forEach((button) => { button.disabled = false; });
    }
  }

  async function handleAiKeyAction(action, keyId) {
    const slot = state.aiKeys.find((row) => String(row.id) === String(keyId))?.slot;
    if (action === "toggle") {
      try {
        await api(`/api/v1/ai-keys/${keyId}/toggle`, { method: "PATCH" });
        setMessage(elements.aikeysMessage, "状态已切换。", "success");
        await loadAiKeys(slot);
      } catch (error) {
        setMessage(elements.aikeysMessage, error.message || "切换失败。", "danger");
      }
    } else if (action === "delete") {
      if (!await confirmWorkspaceAction("确定删除这个 Key 吗？")) return;
      try {
        await api(`/api/v1/ai-keys/${keyId}`, { method: "DELETE" });
        setMessage(elements.aikeysMessage, "Key 已删除。", "success");
        await loadAiKeys(slot);
      } catch (error) {
        setMessage(elements.aikeysMessage, error.message || "删除失败。", "danger");
      }
    }
  }

  // ═══════════════════════════════════════════════
  // Knowledge Base (RAG)
  // ═══════════════════════════════════════════════

  // 知识库文档的状态文案与徽标档位（KB 区块用；企业知识库那块也从 window.GEO 取同一份）。
  const KB_DOC_STATUS_LABELS = {
    pending: "排队中",
    processing: "解析中",
    ready: "已解析",
    failed: "失败",
    deleting: "清理未完成",
  };

  const KB_DOC_STATUS_VARIANTS = {
    pending: "neutral",
    processing: "info",
    ready: "success",
    failed: "danger",
    deleting: "warning",
  };

  async function loadKnowledgeBases() {
    $("knowledgeProjectName").textContent = state.selectedProject?.name || "尚未选择项目";
    $("createKnowledgeBaseButton").disabled = !state.selectedProject?.id;
    $("ekNewButton").disabled = !state.selectedProject?.id;
    if (!state.selectedProject?.id) {
      setMessage(elements.knowledgeMessage, "请先创建或选择项目，再上传和整理资料。");
      elements.kbList.innerHTML = ""; elements.kbStats.innerHTML = "";
      return;
    }
    setMessage(elements.knowledgeMessage, "正在加载知识库...");
    try {
      const body = await api("/api/v1/knowledge-bases");
      state.knowledgeModelStatus = body.model_configuration || null;
      state.knowledgeBases = body.data || body || [];
      renderKnowledgeBases();
      void loadKnowledgeStats(); void loadLegacyKnowledge();
      if (document.querySelector('[data-kb-seg="enterprise"][aria-selected="true"]')) globalThis.GEO?.loadEnterpriseKnowledge?.();
      const embeddingConfigured = state.knowledgeModelStatus?.embedding_configured === true;
      setMessage(elements.knowledgeMessage, embeddingConfigured
        ? `当前项目共 ${state.knowledgeBases.length} 个知识库。`
        : "未配置向量模型，请先到设置中的模型配置填写，再导入材料。", embeddingConfigured ? "" : "warning");
      if (elements.ekPublishButton) elements.ekPublishButton.disabled = !embeddingConfigured;
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      state.knowledgeBases = [];
      if (elements.kbList) elements.kbList.innerHTML = '<div class="empty">知识库加载失败，请点击刷新重试。</div>';
      if (elements.kbStats) elements.kbStats.innerHTML = '';
      setMessage(elements.knowledgeMessage, "知识库加载失败，请稍后重试。", "danger");
    }
  }

  async function loadLegacyKnowledge() {
    try {
      const data = await api("/api/v1/knowledge-legacy");
      const rows = [...(data.bases || []).map(row => ({ ...row, kind: "base" })), ...(data.archives || []).map(row => ({ ...row, kind: "archive" }))];
      $("knowledgeLegacy").hidden = !rows.length;
      $("knowledgeLegacyList").replaceChildren(...rows.map(row => {
        const item = document.createElement("div"); item.className = "history-item";
        const label = document.createElement("strong"); label.textContent = row.name;
        const button = document.createElement("button"); button.type = "button"; button.className = "button secondary small"; button.textContent = "分配到当前项目";
        const message = document.createElement("p"); message.className = "muted"; message.setAttribute("role", "status");
        button.addEventListener("click", async () => {
          if (!await confirmWorkspaceAction(`将「${row.name}」中尚未归属的资料分配到「${state.selectedProject?.name}」？已有项目归属的文档将分别保留在原项目。`)) return;
          button.disabled = true;
          try {
            await api("/api/v1/knowledge-legacy/assign", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: row.id, kind: row.kind }) });
            state.ekLoaded = false; await loadKnowledgeBases();
          } catch (error) { message.textContent = error.message; button.disabled = false; }
        });
        item.append(label, button, message); return item;
      }));
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      $("knowledgeLegacy").hidden = false;
      $("knowledgeLegacyList").textContent = "历史资料读取失败，请点击刷新重试。";
    }
  }

  function renderKnowledgeBases() {
    if (!elements.kbList) return;
    elements.kbList.innerHTML = "";
    if (state.knowledgeBases.length === 0) {
      elements.kbList.innerHTML = '<div class="empty">当前项目还没有知识库。新建后可上传文档或添加网页资料，供本项目的内容生成参考。</div>';
      return;
    }
    state.knowledgeBases.forEach((kb) => {
      const item = document.createElement("button");
      item.type = "button";
      item.className = "history-item kb-item";
      item.innerHTML = `
        <strong>${escapeHtml(kb.name || "未命名知识库")}</strong>
        <span>${escapeHtml(kb.description || "无描述")} · ${formatNumber(kb.documents_count || 0)} 篇文档</span>
        ${kb.status === "deleting" ? '<span class="kb-doc-error">清理未完成，请进入知识库重试删除。</span>' : ""}
        <span>创建：${escapeHtml(formatKnowledgeDate(kb.created_at))}</span>
      `;
      item.addEventListener("click", () => globalThis.GEO?.openKnowledgeBase?.(kb.id));
      elements.kbList.appendChild(item);
    });
  }

  async function createKnowledgeBase(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = form.querySelector("#kbCreateMessage");
    const data = new FormData(form);
    setMessage(message, "正在创建...");
    try {
      const kb = await api("/api/v1/knowledge-bases", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: String(data.get("name") || "").trim(),
          description: String(data.get("description") || "").trim() || null,
        }),
      });
      setMessage(message, "知识库已创建。", "success");
      form.reset();
      if (elements.kbCreateForm) elements.kbCreateForm.hidden = true;
      await loadKnowledgeBases();
      if ((kb.data || kb).id) globalThis.GEO?.openKnowledgeBase?.((kb.data || kb).id);
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function loadKnowledgeStats() {
    if (!elements.kbStats) return;
    try {
      const stats = await api("/api/v1/knowledge-bases/stats");
      renderKnowledgeStats(stats || {});
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      elements.kbStats.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "统计加载失败")}</p>`;
    }
  }

  const KB_STAT_LABELS = {
    ready_documents: "可供参考的文档",
    processing_documents: "正在解析的文档",
    knowledge_bases_count: "知识库数量",
    document_count: "文档总数",
    chunk_count: "切片总数",
    total_documents: "文档总数",
    total_chunks: "内容片段数",
    storage_mb: "存储占用（MB）",
    total_tokens: "Token 总量",
    total_vectors: "向量总量",
    avg_chunk_size: "平均切片大小",
    storage_bytes: "存储占用(字节)",
    retrieval_count: "检索次数",
  };

  function renderKnowledgeStats(stats) {
    if (!elements.kbStats) return;
    const keys = Object.keys(stats).filter((k) => k !== "object");
    if (keys.length === 0) {
      elements.kbStats.innerHTML = '<p class="muted">暂无统计信息。</p>';
      return;
    }
    elements.kbStats.innerHTML = `
      <div class="kb-stats-grid">
        ${keys.map((key) => {
          const label = KB_STAT_LABELS[key] || key;
          const value = typeof stats[key] === "number" ? formatNumber(stats[key]) : escapeHtml(String(stats[key]));
          return `<div class="kb-stat-card"><span>${escapeHtml(label)}</span><strong>${value}</strong></div>`;
        }).join("")}
      </div>`;
  }


  // ═══════════════════════════════════════════════
  // Sensitive Words (SW) — tenant-level, 权限 article:edit
  // ═══════════════════════════════════════════════


  async function loadSensitiveWords() {
    setMessage(elements.swMessage, "正在加载用词规则…");
    try {
      const body = await api("/api/v1/sensitive-words");
      state.sensitiveWords = body.data || [];
      state.sensitiveWordsLoaded = true;
      renderSensitiveWords();
      setMessage(elements.swMessage, `已设置 ${state.sensitiveWords.length} 个自定义用词规则。`);
    } catch (error) {
      state.sensitiveWordsLoaded = true;
      if (error.status === 403) {
        setMessage(elements.swMessage, "当前账号没有管理用词规则的权限。", "danger");
      } else {
        setMessage(elements.swMessage, error.message || "无法加载用词规则，请点击刷新重试。", "danger");
      }
      if (elements.swList) elements.swList.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "加载失败")}</p>`;
    }
  }

  function renderSensitiveWords() {
    if (!elements.swList) return;
    if (state.sensitiveWords.length === 0) {
      elements.swList.innerHTML = '<div class="cl-state-tpl cl-state-empty"><h3>还没有自定义用词规则</h3><p>在上方填写词语并选择处理方式，再点击「添加规则」。平台统一规则仍然生效。</p></div>';
      return;
    }
    const wrap = document.createElement("div");
    wrap.className = "sw-chips";
    state.sensitiveWords.forEach((item) => {
      const chip = document.createElement("span");
      // 等级必须看得见：blocked 会真的挡住发布单进待发，warning 只是标记。
      // 两者外观一样的话，管理员分不清自己加的词到底拦不拦得住。
      const severity = item.severity === "blocked" ? "blocked" : "warning";
      chip.className = "sw-chip";
      chip.dataset.severity = severity;
      const sevLabel = severity === "blocked" ? "禁止发布" : "提醒修改";
      chip.title = item.suggestion ? `修改建议：${item.suggestion}` : (severity === "blocked" ? "出现此词时，需要修改内容后再发布。" : "出现此词时提示复核，不阻止发布。");
      chip.innerHTML = `<span class="sw-sev">${sevLabel}</span><span class="sw-word">${escapeHtml(item.word || "")}</span><button class="sw-chip-del" type="button" data-sw-del="${item.id}" aria-label="删除 ${escapeHtml(item.word || "")}">✕</button>`;
      wrap.appendChild(chip);
    });
    elements.swList.innerHTML = "";
    elements.swList.appendChild(wrap);
    elements.swList.querySelectorAll("[data-sw-del]").forEach((btn) => {
      btn.addEventListener("click", () => deleteSensitiveWord(Number(btn.dataset.swDel)));
    });
  }

  async function addSensitiveWords(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const message = elements.swAddMessage;
    const data = new FormData(form);
    const raw = String(data.get("words") || "").trim();
    if (raw === "") { setMessage(message, "请填写至少一个需要检查的词。", "danger"); return; }
    const hasSeparator = /[\n,，、;；]/.test(raw);
    const payload = hasSeparator ? { words: raw } : { word: raw };
    payload.severity = String(data.get("severity") || "warning");
    const suggestion = String(data.get("suggestion") || "").trim();
    if (suggestion !== "") payload.suggestion = suggestion;
    setMessage(message, "正在保存规则…");
    try {
      const res = await api("/api/v1/sensitive-words", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const inserted = res.inserted ?? (Array.isArray(res.data) ? res.data.length : 0);
      const updated = res.updated ?? 0;
      const skipped = res.skipped_duplicates ?? 0;
      // updated 要单独报：重复提交同一个词来「升级等级」是唯一的提级入口，
      // 混进「跳过」里会让人以为操作没生效。
      setMessage(message, `已添加 ${inserted} 个规则，更新 ${updated} 个；${skipped} 个相同规则无需重复添加。`, "success");
      form.reset();
      await loadSensitiveWords();
    } catch (error) {
      setMessage(message, validationMessage(error), "danger");
    }
  }

  async function deleteSensitiveWord(id) {
    if (!await confirmWorkspaceAction("移除此词的自定义规则？平台统一规则仍然生效。")) return;
    try {
      await api(`/api/v1/sensitive-words/${id}`, { method: "DELETE" });
      clToast("自定义用词规则已移除。", "success");
      await loadSensitiveWords();
    } catch (error) {
      clToast(error.message || "删除失败。", "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // Skill Prompts (SP) — tenant-level overrides by skill slug
  // ═══════════════════════════════════════════════

  async function loadSkillPrompts() {
    $("preferenceProjectName").textContent = state.selectedProject?.name || "尚未选择项目";
    if (!state.selectedProject?.id) {
      elements.spList.innerHTML = "";
      setMessage(elements.spMessage, "请先创建或选择项目，再设置生成偏好。");
      return;
    }
    setMessage(elements.spMessage, "正在加载生成偏好…");
    try {
      const skillsPromise = state.skills.length
        ? Promise.resolve({ data: state.skills })
        : api("/api/v1/skills");
      const [skillsRes, coveredRes] = await Promise.all([
        skillsPromise,
        api("/api/v1/generation-preferences"),
      ]);
      const skills = skillsRes.data || [];
      if (!state.skills.length) state.skills = skills;
      const covered = coveredRes.data || [];
      state.skillPrompts = covered;
      state.coveredPrompts = {};
      covered.forEach((p) => { state.coveredPrompts[p.skill_id] = p; });
      state.skillPromptsLoaded = true;
      renderSkillPrompts();
      setMessage(elements.spMessage, `当前项目可设置 ${skills.length} 项，已自定义 ${covered.length} 项。`);
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      state.skillPromptsLoaded = true;
      setMessage(elements.spMessage, error.message || "无法加载生成偏好，请点击刷新重试。", "danger");
      if (elements.spList) elements.spList.innerHTML = `<p class="inline-result" data-variant="danger">${escapeHtml(error.message || "加载失败")}</p>`;
    }
  }

  function renderSkillPrompts() {
    if (!elements.spList) return;
    const skills = state.skills || [];
    if (skills.length === 0) {
      elements.spList.innerHTML = '<div class="cl-state-tpl cl-state-empty"><h3>暂无可设置的生成偏好</h3><p>技能清单加载后，可分别设置表达方式。</p></div>';
      return;
    }
    const tbody = skills.map((skill) => {
      const cover = state.coveredPrompts[skill.id];
      const statusBadge = cover
        ? `<span class="badge" data-variant="success">已自定义</span>`
        : `<span class="badge" data-variant="neutral">使用默认</span>`;
      return `<tr>
        <td><strong>${escapeHtml(skill.name || skill.id)}</strong></td>
        <td>${statusBadge}</td>
        <td class="cl-cell-actions"><button class="button secondary small" type="button" data-sp-open="${escapeHtml(skill.id)}">${cover ? "修改偏好" : "设置偏好"}</button></td>
      </tr>`;
    }).join("");
    elements.spList.innerHTML = `<div class="cl-tbl-scroll"><table class="cl-tbl"><thead><tr><th>适用功能</th><th>当前设置</th><th class="cl-cell-actions">操作</th></tr></thead><tbody>${tbody}</tbody></table></div>`;
  }

  async function openSkillPromptDrawer(skillId) {
    state.clDrawerArticle = null; // 与文章抽屉隔离
    clDrawerState.trigger = document.activeElement;
    const skill = (state.skills || []).find((s) => String(s.id) === String(skillId));
    const skillName = skill ? (skill.name || skill.id) : skillId;
    if (elements.clDrawerTitle) elements.clDrawerTitle.textContent = `生成偏好 · ${skillName}`;
    if (elements.clDrawerSub) elements.clDrawerSub.textContent = "";
    if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = '<div class="cl2-loading">加载中…</div>';
    if (elements.clDrawerFoot) elements.clDrawerFoot.innerHTML = "";
    // ⚠️ 同上：开关在遮罩上。改之前这里只置了 clDrawer.hidden=false，
    if (elements.clDrawerMask) elements.clDrawerMask.hidden = false;
    elements.clDrawerClose?.focus();
    try {
      const body = await api(`/api/v1/generation-preferences/${encodeURIComponent(skillId)}`);
      renderSkillPromptDrawer(skill, body.data || {});
    } catch (error) {
      if (error.knowledgeContextChanged) return;
      if (error.status === 404) {
        renderSkillPromptDrawer(skill, null); // 正常业务态：未覆盖，显示默认
      } else {
        if (elements.clDrawerBody) elements.clDrawerBody.innerHTML = `<div class="cl-state-tpl cl-state-error"><div class="st-icon">!</div><h3>偏好加载失败</h3><p>${escapeHtml(error.message || "")}</p></div>`;
      }
    }
  }

  function renderSkillPromptDrawer(skill, prompt) {
    if (!elements.clDrawerBody) return;
    const skillId = skill ? skill.id : "";
    const skillName = skill ? (skill.name || skill.id) : skillId;
    const isCovered = !!prompt && prompt.id;
    const preferences = prompt?.preferences || {};
    const select = (name, label, values) => `<label class="span-2">${label}<select name="${name}">${Object.entries(values).map(([value, text]) => `<option value="${value}"${(preferences[name] || "default") === value ? " selected" : ""}>${text}</option>`).join("")}</select></label>`;
    const bodyHtml = `
      <p class="muted">当前项目：<strong>${escapeHtml(state.selectedProject?.name || "")}</strong></p>
      <p class="muted">这些偏好只用于本项目的「${escapeHtml(skillName)}」，从下次执行起生效，已有内容不会改变。与功能必需的结构或格式冲突时，优先遵循平台要求。</p>
      <form class="form-grid" id="spDrawerForm">
        ${select("tone", "表达语气", { default: "使用默认", professional: "专业严谨", plain: "通俗易懂", friendly: "亲切自然" })}
        ${select("structure", "内容结构", { default: "使用默认", conclusion_first: "先给结论，再展开解释", step_by_step: "按步骤说明", qa: "按问题与回答组织" })}
        ${select("length", "篇幅偏好", { default: "使用默认", concise: "简洁，减少重复", detailed: "详细，充分解释" })}
        <label class="span-2">
          特别要求（可选，最多 1000 字）
          <textarea name="requirements" rows="4" maxlength="1000" placeholder="例如：面向第一次来访的游客，解释专业词语，减少宣传口号。">${escapeHtml(preferences.requirements || "")}</textarea>
        </label>
      </form>
    `;
    elements.clDrawerBody.innerHTML = bodyHtml;
    if (elements.clDrawerFoot) {
      elements.clDrawerFoot.innerHTML = `
        <button class="button primary" type="button" data-sp-action="save">保存偏好</button>
        ${isCovered ? '<button class="button secondary" type="button" data-sp-action="reset">恢复默认</button>' : ""}
        <span class="spacer"></span>
        <button class="button ghost" type="button" id="spDrawerCloseBtn">关闭</button>
      `;
      elements.clDrawerFoot.querySelector("#spDrawerCloseBtn")?.addEventListener("click", (event) => globalThis.GEO?.clCloseDrawer?.(event));
      elements.clDrawerFoot.querySelector('[data-sp-action="save"]')?.addEventListener("click", () => saveSkillPrompt(skillId));
      elements.clDrawerFoot.querySelector('[data-sp-action="reset"]')?.addEventListener("click", () => resetSkillPrompt(skillId));
    }
  }

  async function saveSkillPrompt(skillId) {
    const form = document.getElementById("spDrawerForm");
    if (!form) return;
    const data = new FormData(form);
    if (!form.reportValidity()) return;
    const preferences = Object.fromEntries(["tone", "structure", "length", "requirements"].map(key => [key, String(data.get(key) || "").trim()]));
    const saveBtn = elements.clDrawerFoot?.querySelector('[data-sp-action="save"]');
    await withBusyButton(saveBtn, "保存中…", async () => {
      try {
        const res = await api(`/api/v1/generation-preferences/${encodeURIComponent(skillId)}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(preferences),
        });
        clToast("偏好已保存，将用于当前项目的后续执行。", "success");
        await loadSkillPrompts();
        const detail = res.data || {};
        const skill = (state.skills || []).find((s) => String(s.id) === String(skillId));
        renderSkillPromptDrawer(skill, detail);
      } catch (error) {
        clToast(validationMessage(error), "danger");
      }
    });
  }

  async function resetSkillPrompt(skillId) {
    if (!await confirmWorkspaceAction("将当前项目的这项生成偏好恢复默认？已有内容不会改变。")) return;
    try {
      await api(`/api/v1/generation-preferences/${encodeURIComponent(skillId)}`, { method: "DELETE" });
      clToast("已重置为默认。", "success");
      await loadSkillPrompts();
      const skill = (state.skills || []).find((s) => String(s.id) === String(skillId));
      renderSkillPromptDrawer(skill, null);
    } catch (error) {
      clToast(error.message || "重置失败。", "danger");
    }
  }

  // ═══════════════════════════════════════════════
  // Analytics / Dashboard
  // ═══════════════════════════════════════════════

  async function loadAnalytics() {
    const days = state.analyticsDays;
    elements.analyticsContent?.querySelector(".md-alert")?.remove();
    setMessage(elements.analyticsMessage, "正在加载数据...");
    const projectId = state.analyticsScope === "tenant" ? null : (state.selectedProject?.id || null);
    const projectParam = projectId ? `&project_id=${projectId}` : "";
    try {
      const [summary, trends, modelUsage, byProject, distribution, activity] = await Promise.allSettled([
        api(`/api/v1/analytics/summary?days=${days}${projectParam}`),
        api(`/api/v1/analytics/trends?days=${days}${projectParam}`),
        api(`/api/v1/analytics/model-usage?days=${days}${projectParam}`),
        api(`/api/v1/analytics/by-project?days=${days}`),
        api(`/api/v1/analytics/distribution?days=${days}${projectParam}`),
        api(`/api/v1/dashboard/activity?limit=10${projectParam}`),
      ]);
      if (summary.status !== "fulfilled") {
        state.analyticsData = null;
        if (elements.analyticsSummary) elements.analyticsSummary.innerHTML = "";
        if (elements.analyticsContent) elements.analyticsContent.innerHTML = "";
        throw summary.reason;
      }
      state.analyticsData = summary.status === "fulfilled" ? summary.value : null;
      state.analyticsTrends = trends.status === "fulfilled" ? trends.value : null;
      state.analyticsModelUsage = modelUsage.status === "fulfilled" ? modelUsage.value : null;
      state.analyticsByProject = byProject.status === "fulfilled" ? byProject.value : null;
      state.analyticsDistribution = distribution.status === "fulfilled" ? distribution.value : null;
      state.dashboardActivity = activity.status === "fulfilled" ? (activity.value.data || []) : [];
      renderAnalytics();
      renderDashboardActivity();
      setMessage(elements.analyticsMessage, "");
    } catch (error) {
      setMessage(elements.analyticsMessage, error.message || "无法加载分析数据。", "danger");
    }
  }

  function renderAnalytics() {
    if (!elements.analyticsSummary || !elements.analyticsContent) return;
    const tab = state.analyticsTab;
    // Render tab state
    document.querySelectorAll("[data-analytics-tab]").forEach((btn) => {
      const selected = btn.dataset.analyticsTab === tab;
      btn.setAttribute("aria-selected", selected ? "true" : "false");
      btn.classList.toggle("is-on", selected);
    });
    // Summary cards
    const m = state.analyticsData?.metrics || {};
    const scope = state.analyticsData?.article_scope || {};
    const scopeLabel = scope.project_id ? `当前项目 · 全时段` : `当前租户全部项目 · 全时段`;
    const articleLink = scope.project_id ? '<a class="md-link" href="#content-library" data-analytics-articles>查看文章 →</a>' : '<span>选择项目后可打开对应文章列表</span>';
    const scopeHost = elements.analyticsSummary.closest(".md-panel")?.querySelector(".analytics-scope");
    if (scopeHost) scopeHost.innerHTML = `<div class="filter-row md-segmented" role="group" aria-label="统计范围"><button class="md-seg" type="button" data-analytics-scope="project" aria-pressed="${scope.project_id ? 'true' : 'false'}" ${state.selectedProject ? '' : 'disabled'}>当前项目</button><button class="md-seg" type="button" data-analytics-scope="tenant" aria-pressed="${scope.project_id ? 'false' : 'true'}">租户全部</button></div>`;
    elements.analyticsSummary.innerHTML = `
      <div class="usage-cards">
        <div class="md-stat"><span class="st-label">文章 · 已发布 / 全部 <span>全时段</span></span><strong class="st-value">${formatNumber(m.articles_published || 0)} / ${formatNumber(m.articles_total ?? 0)}</strong><small class="st-delta">${articleLink}</small></div>
        <div class="md-stat"><span class="st-label">报告资产 <span>全时段</span></span><strong class="st-value">${formatNumber(m.report_assets_total ?? 0)}</strong></div>
        <div class="md-stat"><span class="st-label">技能执行 · 成功 / 全部 <span>近 ${escapeHtml(state.analyticsDays)} 天</span></span><strong class="st-value">${formatNumber(m.executions_success || 0)} / ${formatNumber(m.executions_total || 0)}</strong></div>
        <div class="md-stat"><span class="st-label">渠道发布 · 已公开 / 全部 <span>近 ${escapeHtml(state.analyticsDays)} 天</span></span><strong class="st-value">${formatNumber(m.publications_published || 0)} / ${formatNumber(m.publications_total || 0)}</strong></div>
        ${scope.project_id ? '' : `<div class="md-stat"><span class="st-label">活跃项目</span><strong class="st-value">${formatNumber(m.projects_active || 0)} / ${formatNumber(m.projects_total || 0)}</strong></div>`}
      </div>
      <p class="muted">运营数据：文章与报告资产分开统计；文章口径为${escapeHtml(scopeLabel)}，排除回收站与已删除项目。执行与渠道发布指标按下方日期范围统计；手动发布单另行记录。</p>
    `;
    elements.analyticsSummary.querySelector('[data-analytics-articles]')?.addEventListener('click', (event) => {
      event.preventDefault();
      state.clActiveTab = 'all';
      state.clSearch = '';
      if (elements.clSearchInput) elements.clSearchInput.value = '';
      openWorkspaceView('content-library');
    });
    scopeHost?.querySelectorAll('[data-analytics-scope]').forEach((button) => {
      button.addEventListener('click', () => {
        state.analyticsScope = button.dataset.analyticsScope;
        loadAnalytics();
      });
    });
    // Tab content
    if (tab === "trends") renderAnalyticsTrends();
    else if (tab === "models") renderAnalyticsModels();
    else if (tab === "projects") renderAnalyticsProjects();
    else if (tab === "distribution") renderAnalyticsDistribution();
  }

  function renderAnalyticsTrends() {
    const trends = state.analyticsTrends?.trends || {};
    const articles = trends.articles || [];
    const executions = trends.executions || [];
    if (articles.length === 0 && executions.length === 0) {
      elements.analyticsContent.innerHTML = '<p class="muted">暂无趋势数据。</p>';
      return;
    }
    let html = '<h4>文章创建趋势</h4>';
    if (articles.length) {
      html += '<div class="trend-list">';
      articles.slice(-14).forEach((d) => { html += `<div class="trend-item"><span>${escapeHtml(d.date)}</span><strong>${formatNumber(d.count)}</strong></div>`; });
      html += '</div>';
    }
    html += '<h4>执行趋势</h4>';
    if (executions.length) {
      html += '<div class="trend-list">';
      executions.slice(-14).forEach((d) => { html += `<div class="trend-item"><span>${escapeHtml(d.date)}</span><strong>${formatNumber(d.count)} (成功 ${formatNumber(d.success_count || 0)})</strong></div>`; });
      html += '</div>';
    }
    elements.analyticsContent.innerHTML = html;
  }

  function renderAnalyticsModels() {
    const data = state.analyticsModelUsage;
    if (!data?.by_model?.length) { elements.analyticsContent.innerHTML = '<p class="muted">暂无模型用量数据。</p>'; return; }
    const summary = data.summary || {};
    let html = `<div class="usage-cards">
      <div class="md-stat"><span class="st-label">总调用</span><strong class="st-value">${formatNumber(summary.total_calls || 0)}</strong></div>
      <div class="md-stat"><span class="st-label">总令牌</span><strong class="st-value">${formatNumber(summary.total_tokens || 0)}</strong></div>
    </div>`;
    html += '<h4>按模型</h4><div class="trend-list">';
    data.by_model.forEach((m) => {
      const successRate = m.total_calls > 0 ? ((m.completed_calls / m.total_calls) * 100).toFixed(1) : 0;
      html += `<div class="trend-item"><span>${escapeHtml(m.model || "-")}</span><strong>调用 ${formatNumber(m.total_calls)} · 令牌 ${formatNumber(m.total_tokens)} · 成功率 ${successRate}%</strong></div>`;
    });
    html += '</div>';
    elements.analyticsContent.innerHTML = html;
  }

  function renderAnalyticsProjects() {
    const data = state.analyticsByProject;
    if (!data) {
      elements.analyticsContent.innerHTML = '<p class="md-alert md-alert--danger">项目统计加载失败，请重试。</p>';
      return;
    }
    if (!data.projects?.length) { elements.analyticsContent.innerHTML = '<p class="muted">暂无项目统计数据。</p>'; return; }
    let html = '<div class="trend-list">';
    data.projects.forEach((p) => {
      html += `<div class="trend-item project-stat-item">
        <strong>${escapeHtml(p.project_name || "-")}</strong>
        <span>品牌：${escapeHtml(p.brand_name || "-")} · 文章 ${formatNumber(p.articles?.total || 0)}（全时段） · 执行 ${formatNumber(p.executions?.total || 0)} (成功率 ${p.executions?.success_rate || 0}%) · 发布 ${formatNumber(p.publications?.period || 0)}</span>
        <button class="md-btn md-btn--ghost md-btn--sm" type="button" data-analytics-project-articles="${Number(p.project_id)}">查看此项目文章</button>
      </div>`;
    });
    html += '</div>';
    elements.analyticsContent.innerHTML = html;
    elements.analyticsContent.querySelectorAll('[data-analytics-project-articles]').forEach((button) => {
      button.addEventListener('click', async () => {
        try {
          await globalThis.GEO?.selectProject?.(Number(button.dataset.analyticsProjectArticles));
          state.clActiveTab = 'all';
          state.clSearch = '';
          if (elements.clSearchInput) elements.clSearchInput.value = '';
          openWorkspaceView('content-library');
        } catch (error) {
          setMessage(elements.analyticsMessage, error.message || '无法打开文章列表。', 'danger');
        }
      });
    });
  }

  function renderAnalyticsDistribution() {
    const data = state.analyticsDistribution;
    if (!data?.metrics) {
      elements.analyticsContent.innerHTML = '<p class="md-alert md-alert--danger">分发数据加载失败，请重试。</p>';
      return;
    }
    const m = data.metrics;
    let html = `<div class="usage-cards">
      <div class="md-stat"><span class="st-label">渠道总数</span><strong class="st-value">${formatNumber(m.channels_total || 0)}</strong></div>
      <div class="md-stat"><span class="st-label">活跃渠道</span><strong class="st-value">${formatNumber(m.channels_active || 0)}</strong></div>
      <div class="md-stat"><span class="st-label">发布总数</span><strong class="st-value">${formatNumber(m.publications_total || 0)}</strong></div>
      <div class="md-stat"><span class="st-label">目标已公开</span><strong class="st-value">${formatNumber(m.publications_published || 0)}</strong></div>
      <div class="md-stat"><span class="st-label">仅被目标接受</span><strong class="st-value">${formatNumber(m.publications_accepted || 0)}</strong></div>
    </div>`;
    if (data.recent_publications?.length) {
      html += '<h4>最近发布</h4><div class="trend-list">';
      data.recent_publications.slice(0, 10).forEach((pub) => {
        const statusVariant = pub.status === "published" ? "success" : pub.status === "failed" ? "danger" : "warning";
        const label = ({ queued: '排队中', publishing: '执行中', accepted: '目标已接受，未确认公开', published: '目标已公开', failed: '失败', withdrawn: '已撤下' })[pub.status] || pub.status;
        const targetUrl = safeAnalyticsUrl(pub.remote_url);
        const target = pub.status === 'published' && targetUrl ? `<a class="md-link" href="${escapeHtml(targetUrl)}" target="_blank" rel="noopener noreferrer">核验目标 ↗</a>` : '';
        html += `<div class="trend-item"><span>${escapeHtml(pub.article_title || "-")} → ${escapeHtml(pub.channel_name || "-")} · v${escapeHtml(pub.article_version ?? '-')} ${target}</span><strong><span class="badge" data-variant="${statusVariant}">${escapeHtml(label)}</span></strong>${pub.last_error ? `<small>${escapeHtml(pub.last_error)}</small>` : ''}</div>`;
      });
      html += '</div>';
    }
    elements.analyticsContent.innerHTML = html;
  }

  function safeAnalyticsUrl(value) {
    try {
      const url = new URL(String(value || ''));
      return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  }

  // 文章页的页签高亮只由 clSelectTab 改，只写 state 会出现「列表是待审核、高亮还在全部」。
  function openPendingArticlesFromOverview() {
    state.clActiveTab = "pending";
    state.clSearch = "";
    if (elements.clSearchInput) elements.clSearchInput.value = "";
    globalThis.GEO?.clSelectTab?.("pending");
    openWorkspaceView("content-library");
  }

  function renderDashboardActivity() {
    if (!elements.activityList) return;
    if (state.dashboardActivity.length === 0) {
      elements.activityList.innerHTML = '<div class="md-empty"><h3 class="md-empty-title">暂无动态</h3><p>最近没有技能执行或待处理文章。</p></div>';
      return;
    }
    const rows = state.dashboardActivity.slice(0, 10).map((item) => {
      const typeLabel = item.type === "execution" ? "技能执行" : item.type === "review" ? "审稿" : "编辑";
      return `<tr><td>${escapeHtml(item.skill_name || item.title || "-")}</td><td>${escapeHtml(typeLabel)}</td><td>${escapeHtml(statusLabel(item.status || "-"))}</td><td>${escapeHtml(item.project_name || "-")}</td><td>${escapeHtml(formatDate(item.created_at || item.updated_at))}</td></tr>`;
    });
    elements.activityList.innerHTML = `<div class="md-tablewrap"><table class="md-table"><thead><tr><th>内容</th><th>类型</th><th>状态</th><th>项目</th><th>时间</th></tr></thead><tbody>${rows.join("")}</tbody></table></div>`;
  }

  // ═══════════════════════════════════════════════
  // Session bootstrap
  // ═══════════════════════════════════════════════


  async function bootstrap() {
    if (bootstrapPending) return;
    bootstrapPending = true;
    projectsReady = false;
    showState("connectingState");
    try {
      const response = await fetch(config.sessionLoginEndpoint || "/api/v1/auth/session-login", {
        method: "POST",
        credentials: "include",
        signal: AbortSignal.timeout(15000),
        headers: { Accept: "application/json" },
      });

      if (response.status === 401 || response.status === 419) {
        // An anonymous visit is normal, including returning from the login page
        // without signing in. The account page waits for user input, so this
        // redirect cannot create an automatic redirect loop on its own.
        sessionStorage.removeItem("geo_auth_redirect_at");
        window.location.replace(accountLoginUrl());
        return;
      }

      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || "GEO 登录会话建立失败。");

      sessionStorage.removeItem("geo_auth_redirect_at");
      persistSession(payload);
      state.session = payload;
      syncAccountHeader(payload);
      // 单 7：会话就绪、role 已知之后才决定手动发布入口的显隐。
      // 必须挂在 bootstrap 而不是某个视图的 load 里 —— 菜单项默认 hidden，
      // 只在切到对应视图时放出的话，一进工作台它永远是藏着的。
      globalThis.GEO?.applyManualPublishVisibility?.();
      showState("workspaceState");
      setActiveWorkspaceView(currentWorkspaceRoute());
      void restoreWorkspaceProjects();
    } catch (error) {
      elements.errorMessage.textContent = error?.name === "TimeoutError"
        ? "登录验证超时，请重试。" : (error.message || "言及工作台暂时无法连接。");
      showState("errorState");
    } finally {
      bootstrapPending = false;
    }
  }

  $("personalProfileForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    await withBusyButton($("savePersonalProfile"), "保存中", async () => {
      try {
        const body = await api("/api/v1/account/profile", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ name: $("personalName").value, email: $("personalEmail").value, current_password: $("profileCurrentPassword").value }) });
        $("profileCurrentPassword").value = "";
        state.session.user = body.user;
        state.session.account_user = { ...state.session.account_user, name: body.user.name, email: body.user.email };
        syncAccountHeader(state.session);
        $("personalProfileMessage").textContent = "GEO 个人资料已保存。";
      } catch (error) { $("personalProfileMessage").textContent = validationMessage(error); }
    });
  });
  function setAccountPasswordVisibility(button, visible) {
    const input = $(button.dataset.passwordToggle);
    input.type = visible ? "text" : "password";
    button.setAttribute("aria-pressed", String(visible));
    const label = document.querySelector(`label[for="${input.id}"]`).textContent;
    const action = `${visible ? "隐藏" : "显示"}${label}`;
    button.setAttribute("aria-label", action);
    button.title = action;
  }
  document.querySelectorAll("[data-password-toggle]").forEach(button => {
    button.addEventListener("click", () => setAccountPasswordVisibility(button, $(button.dataset.passwordToggle).type === "password"));
  });

  $("geoPasswordForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    if (accountPasswordBusy) return;
    if ($("geoNewPassword").value !== $("geoConfirmPassword").value) {
      $("geoPasswordMessage").textContent = "两次输入的新密码不一致，请重新确认。";
      $("geoConfirmPassword").focus();
      return;
    }
    accountPasswordBusy = true;
    $("saveGeoPassword").disabled = true;
    $("toggleGeoPasswordReset").disabled = true;
    $("saveGeoPassword").textContent = "保存中…";
    $("geoPasswordMessage").textContent = "";
    const mode = accountPasswordMode;
    try {
      const payload = { mode, password: $("geoNewPassword").value, password_confirmation: $("geoConfirmPassword").value };
      if (mode === "change") payload.current_password = $("geoCurrentPassword").value;
      const body = await api("/api/v1/account/password", { method: "PATCH", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) });
      persistSession(body); state.session = body;
      accountHasPassword = true;
      accountPasswordMode = "change";
      $("geoCurrentPassword").value = "";
      $("geoNewPassword").value = "";
      $("geoConfirmPassword").value = "";
      $("geoPasswordStatus").textContent = "已设置";
      $("geoPasswordStatus").className = "md-badge md-badge--success";
      $("geoPasswordMessage").textContent = `${mode === "setup" ? "密码已设置" : mode === "reset" ? "密码已重置" : "密码已修改"}，当前登录保持有效，其他登录已退出。`;
      try { renderAccountSessions((await api("/api/v1/account/sessions")).data || []); }
      catch { $("geoSecurityMessage").textContent = "密码已保存，登录记录暂时未能刷新，请稍后重试。"; }
    } catch (error) { $("geoPasswordMessage").textContent = validationMessage(error); }
    finally {
      accountPasswordBusy = false;
      $("saveGeoPassword").disabled = false;
      $("toggleGeoPasswordReset").disabled = false;
      renderAccountPassword(accountPasswordMode);
    }
  });
  $("toggleGeoPasswordReset")?.addEventListener("click", () => {
    window.location.assign('/login');
  });
  $("revokeGeoSessions")?.addEventListener("click", async () => {
    await withBusyButton($("revokeGeoSessions"), "处理中", async () => {
      try { await api("/api/v1/account/sessions/revoke", { method: "POST" }); await loadPersonalAccount(); $("geoSecurityMessage").textContent = "已退出其他登录，当前登录保持有效。"; }
      catch (error) { $("geoSecurityMessage").textContent = validationMessage(error); }
    });
  });

  const loginUrl = accountLoginUrl();
  initAccountMenu();
  if (elements.loginLink) elements.loginLink.href = loginUrl;
  if (elements.errorLoginLink) elements.errorLoginLink.href = loginUrl;
  $("workspaceLoadLogin").href = loginUrl;
  $("workspaceLoadRetry").addEventListener("click", () => sessionReady ? restoreWorkspaceProjects() : bootstrap());
  $("workspaceViewLoadRetry").addEventListener("click", () => loadWorkspaceViewData(state.activeView, state.activeViewParam));

  document.getElementById("openSettingsButton")?.addEventListener("click", () => setActiveWorkspaceView("personal-settings", { updateHash: true }));
  document.getElementById("settingsBackButton")?.addEventListener("click", () => setActiveWorkspaceView(state.lastWorkView || "projects", { updateHash: true }));
  window.GEO_SHELL.init(state, setActiveWorkspaceView, elements);
  document.getElementById("clManageCategoriesButton")?.addEventListener("click", () => {
    setActiveWorkspaceView("categories", { updateHash: true });
  });
  setActiveWorkspaceView(window.location.hash || defaultWorkspaceView);
  elements.workspaceMenuLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      event.preventDefault();
      const targetView = normalizeWorkspaceView(link.dataset.viewTarget || link.getAttribute("href"));
      document.getElementById("globalTrashNav")?.setAttribute("aria-current", "false");
      if (targetView === "skill") state.skillContext = "strategy";
      setActiveWorkspaceView(targetView, { updateHash: true });
      window.scrollTo({ top: 0, behavior: "smooth" });
    });

  });


  window.addEventListener("popstate", () => {
    if (!guardResultEditorNavigation()) return;
    setActiveWorkspaceView(window.location.hash || defaultWorkspaceView);
  });
  window.addEventListener("hashchange", () => {
    if (!guardResultEditorNavigation()) return;
    setActiveWorkspaceView(window.location.hash || defaultWorkspaceView);
  });

  elements.retryButton?.addEventListener("click", bootstrap);
  elements.errorRetryButton?.addEventListener("click", bootstrap);
  elements.reloadProjectsButton?.addEventListener("click", restoreWorkspaceProjects);
  function openNewProject() {
    cancelProjectNavigation();
    setActiveWorkspaceView("projects", { updateHash: true });
    openCreateProjectPanel();
  }
  elements.newProjectButton?.addEventListener("click", openNewProject);
  elements.cancelCreateProjectButton?.addEventListener("click", closeCreateProjectPanel);
  elements.reloadSkillsButton?.addEventListener("click", loadSkills);
  elements.openAllAnalysesButton?.addEventListener("click", () => openAssetsFiltered("geo_analysis"));
  elements.openAllSkillReportsButton?.addEventListener("click", () => openAssetsFiltered("skill_execution"));
  elements.suggestKeywordsForm?.addEventListener("submit", suggestSkillKeywords);
  elements.suggestCompetitorsForm?.addEventListener("submit", suggestSkillCompetitors);
  elements.reloadAssetsButton?.addEventListener("click", reloadArticles);
  elements.assetSourceFilter?.querySelectorAll("[data-source-type]").forEach((button) => {
    button.addEventListener("click", () => {
      state.assetSourceType = button.dataset.sourceType || "all";
      state.selectedArticleId = null;
      if (state.selectedProject) loadArticles(state.selectedProject.id);
    });
  });
  elements.assetSearchForm?.addEventListener("submit", applyAssetSearch);
  elements.clearAssetSearchButton?.addEventListener("click", clearAssetSearch);
  elements.createProjectForm?.addEventListener("submit", createProject);
  elements.settingsForm?.addEventListener("submit", saveSettings);
  elements.openAnalysisButton?.addEventListener("click", startAnalysisFromProject);
  elements.deleteProjectButton?.addEventListener("click", deleteSelectedProject);
  // 内容库 / 素材库的事件绑定在 geo-entry-content-library.js 里自绑：拆分文件在
  // geo-entry.js **之后**加载，在这里调只会是一个早于定义的空调用（看着像初始化了）。
  initAutomationTasks();
  document.getElementById("directoryProjectSearch")?.addEventListener("input", renderProjectDirectory);
  document.getElementById("directoryNewProject")?.addEventListener("click", () => { cancelProjectNavigation(); openCreateProjectPanel(); });
  document.getElementById("projectNavToggle")?.addEventListener("click", event => {
    const children = document.getElementById("projectNavChildren");
    children.hidden = !children.hidden;
    event.currentTarget.setAttribute("aria-expanded", String(!children.hidden));
    event.currentTarget.setAttribute("aria-label", children.hidden ? "展开项目列表" : "折叠项目列表");
  });
  window.addEventListener("hashchange", cancelProjectNavigation);
  // 「查看完整报告」：委托在 document 上，三个执行入口重渲染多少次都不用重新绑。
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-open-result]");
    if (!button) return;
    event.preventDefault();
    openResultPage(button.dataset.openResult);
  });
  // 结果页页签：切分区只换正文，不动地址栏（地址栏管的是「哪份报告」，不是「看哪一段」）。
  elements.resultPageTabs?.addEventListener("click", (event) => {
    const tab = event.target.closest("[data-result-zone]");
    if (!tab) return;
    const zone = tab.dataset.resultZone;
    if (!zone || zone === state.resultZone || !state.resultArticle) return;
    const data = resultReportData(state.resultArticle);
    if (!data) return;
    state.resultZone = zone;
    elements.resultPageTabs.querySelectorAll("[data-result-zone]").forEach((node) => {
      node.setAttribute("aria-selected", node.dataset.resultZone === zone ? "true" : "false");
    });
    renderResultZone(data, zone);
  });
  // 结果页的复制 / 下载：复用历史报告页那两个函数，只是把提示槽换成本页的。
  elements.resultPageActions?.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-result-action]");
    if (!button || !state.resultArticle) return;
    if (button.dataset.resultAction === "copy") await copyArticle(state.resultArticle, elements.resultPageMessage);
    if (button.dataset.resultAction === "download") await downloadArticle(state.resultArticle, elements.resultPageMessage);
    if (["copy", "download"].includes(button.dataset.resultAction)) {
      const notice = elements.resultPageMessage;
      const current = notice?.textContent;
      window.setTimeout(() => { if (notice?.textContent === current) setMessage(notice, ""); }, 3500);
    }
    if (button.dataset.resultAction === "edit") openResultEditor();
    if (button.dataset.resultAction === "manage") {
      const details = document.getElementById("reportManagement");
      if (details) { details.open = true; details.scrollIntoView({ behavior: "smooth", block: "start" }); }
    }
  });
  window.addEventListener("beforeunload", (event) => {
    if (!resultEditorDirty()) return;
    event.preventDefault();
    event.returnValue = "";
  });
  document.addEventListener("click", (event) => {
    if (!resultEditorDirty()) return;
    const link = event.target.closest("a[href], [data-open-result], [data-view-target]");
    if (!link || link.getAttribute("href") === window.location.hash) return;
    if (!window.confirm("有未保存的修改，确定离开编辑吗？")) { event.preventDefault(); event.stopImmediatePropagation(); }
    else closeResultEditor(true);
  }, true);
  document.getElementById("reportManagement")?.addEventListener("toggle", event => {
    if (event.currentTarget.open && state.resultArticle && !elements.assetResult?.textContent.trim()) renderArticleResult(state.resultArticle);
  });
  // Report TOC anchors: scroll only. Never touch location.hash (hash drives view routing).
  document.addEventListener("click", (event) => {
    const anchorButton = event.target.closest("[data-report-anchor]");
    if (!anchorButton) return;
    event.preventDefault();
    const anchorId = anchorButton.dataset.reportAnchor;
    if (!anchorId) return;
    // B 部分：滑轨内的标签点击 → 切到对应面板（横向原生滚动 + 高亮），
    const slider = anchorButton.closest("[data-report-slider]");
    if (slider) {
      const index = Number(anchorButton.dataset.sliderIndex);
      if (Number.isFinite(index)) {
        slideReportTo(slider, index);
        return;
      }
    }
    const scope = anchorButton.closest(".report-doc") || document;
    const target = scope.querySelector(`[id="${anchorId}"]`);
    if (!target) return;
    if (target.tagName === "DETAILS" && !target.open) target.open = true;
    target.scrollIntoView({ behavior: "smooth", block: "start" });
  });
  // B 部分：滑轨左右箭头与页点（原生滚动，事件只做索引换算与高亮）。
  document.addEventListener("click", (event) => {
    const prev = event.target.closest("[data-slider-prev]");
    const next = event.target.closest("[data-slider-next]");
    const dot = event.target.closest("[data-slider-dot]");
    if (!prev && !next && !dot) return;
    const slider = (prev || next || dot).closest("[data-report-slider]");
    if (!slider) return;
    if (prev) {
      slideReportTo(slider, currentSliderIndex(slider) - 1);
    } else if (next) {
      slideReportTo(slider, currentSliderIndex(slider) + 1);
    } else {
      slideReportTo(slider, Number(dot.dataset.sliderDot));
    }
  });
  // B 部分：手动横滑后同步标签/页点高亮（scroll 不冒泡，capture 阶段接住）。
  document.addEventListener("scroll", (event) => {
    const track = event.target;
    if (!track || typeof track.matches !== "function" || !track.matches("[data-slider-track]")) return;
    const slider = track.closest("[data-report-slider]");
    if (slider) syncReportSliderState(slider);
  }, true);
  document.addEventListener("keydown", (event) => {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    const slider = event.target.closest("[data-report-slider]");
    if (!slider) return;
    event.preventDefault();
    const delta = event.key === "ArrowRight" ? 1 : -1;
    const next = slideReportTo(slider, currentSliderIndex(slider) + delta);
    // 焦点跟随到对应标签（读屏能念出「第 N 项，共 M 项」）。
    const tab = slider.querySelector(`[data-slider-tab][data-slider-index="${next}"]`);
    if (tab) tab.focus();
  });
  function syncHeadActionsForEdit() {
    const editing = Boolean(elements.settingsWrap && !elements.settingsWrap.hidden);
    if (elements.openAnalysisButton) elements.openAnalysisButton.hidden = editing;
    if (elements.editProjectButton) {
      elements.editProjectButton.setAttribute("aria-expanded", String(editing));
      elements.editProjectButton.textContent = editing ? "收起编辑" : "编辑";
    }
  }
  elements.editProjectButton?.addEventListener("click", () => {
    if (elements.settingsWrap) elements.settingsWrap.hidden = !elements.settingsWrap.hidden;
    syncHeadActionsForEdit();
  });
  elements.cancelEditProjectButton?.addEventListener("click", () => {
    if (elements.settingsWrap) elements.settingsWrap.hidden = true;
    syncHeadActionsForEdit();
    elements.editProjectButton?.focus();
  });
  elements.runAnalysisPanelButton?.addEventListener("click", runAnalysis);
  document.querySelectorAll("[data-start-project-analysis]").forEach((button) => {
    button.addEventListener("click", startAnalysisFromProject);
  });
  document.querySelectorAll("[data-open-workspace-view]").forEach((button) => {
    button.addEventListener("click", () => {
      const target = button.dataset.openWorkspaceView || defaultWorkspaceView;
      if (target === "analysis") {
        openAnalysisWorkspace();
        return;
      }
      openWorkspaceView(target);
    });
  });

  // Team
  elements.inviteTeamMemberButton?.addEventListener("click", () => {
    if (elements.teamInviteForm) elements.teamInviteForm.hidden = !elements.teamInviteForm.hidden;
  });
  elements.cancelInviteButton?.addEventListener("click", () => {
    if (elements.teamInviteForm) elements.teamInviteForm.hidden = true;
  });
  elements.inviteForm?.addEventListener("submit", inviteTeamMember);
  elements.reloadTeamButton?.addEventListener("click", loadTeam);

  // AI Keys: bind to the existing workspace Key management endpoints.
  elements.aikeyList?.querySelectorAll("[data-model-slot]").forEach((form) => {
    form.addEventListener("submit", createAiKey);
    form.querySelector("[data-test-model]").addEventListener("click", () => submitModelConfiguration(form, true));
    form.addEventListener("input", () => { delete form.dataset.verificationToken; });
    form.elements.provider.addEventListener("change", () => {
      const preset = state.aiPresets[form.elements.provider.value] || {};
      form.elements.base_url.value = preset.base_url || "";
      form.elements.api_key.value = "";
      form.elements.model.value = "";
      form.elements.json_mode.checked = !!preset.json_mode;
      form.querySelector("[data-custom-json]").hidden = form.elements.provider.value !== "custom";
    });
    form.querySelectorAll("[data-aikey-action]").forEach((button) => {
      button.addEventListener("click", () => handleAiKeyAction(button.dataset.aikeyAction, Number(button.dataset.keyId)));
    });
  });
  elements.reloadAiKeysButton?.addEventListener("click", loadAiKeys);

  // Knowledge
  elements.createKnowledgeBaseButton?.addEventListener("click", () => {
    if (elements.kbCreateForm) elements.kbCreateForm.hidden = !elements.kbCreateForm.hidden;
  });
  elements.cancelKbButton?.addEventListener("click", () => {
    if (elements.kbCreateForm) elements.kbCreateForm.hidden = true;
  });
  elements.createKbForm?.addEventListener("submit", createKnowledgeBase);
  elements.reloadKnowledgeBasesButton?.addEventListener("click", loadKnowledgeBases);


  // Sensitive Words (SW)
  elements.swAddFormEl?.addEventListener("submit", addSensitiveWords);
  elements.swReloadButton?.addEventListener("click", loadSensitiveWords);

  // Skill Prompts (SP)
  elements.spReloadButton?.addEventListener("click", loadSkillPrompts);

  elements.atForm?.querySelector('[name="skill_id"]')?.addEventListener("change", atRenderDynamicFields);
  elements.spList?.addEventListener("click", (event) => {
    const btn = event.target.closest("[data-sp-open]");
    if (btn) openSkillPromptDrawer(btn.dataset.spOpen);
  });

  // Analytics
  elements.reloadAnalyticsButton?.addEventListener("click", loadAnalytics);
  elements.analyticsDaysSelect?.addEventListener("change", () => {
    state.analyticsDays = Number(elements.analyticsDaysSelect.value || 30);
    loadAnalytics();
  });
  document.querySelectorAll("[data-analytics-tab]").forEach((btn) => {
    btn.addEventListener("click", () => {
      state.analyticsTab = btn.dataset.analyticsTab || "trends";
      renderAnalytics();
    });
  });
  // 绑一次：摘要接口失败时 renderDashboardActivity 不会跑，链接也得能用。
  document.querySelector("[data-analytics-pending-articles]")?.addEventListener("click", openPendingArticlesFromOverview);
  document.querySelector("[data-analytics-skill-reports]")?.addEventListener("click", () => openAssetsFiltered("skill_execution"));

  elements.skillWorkspaceTabs?.querySelectorAll("[data-skill-workspace-mode]").forEach((tab, index, tabs) => {
    tab.addEventListener("click", () => setSkillWorkspaceMode(tab.dataset.skillWorkspaceMode));
    tab.addEventListener("keydown", (event) => {
      const next = event.key === "Home" ? 0 : event.key === "End" ? tabs.length - 1
        : event.key === "ArrowRight" ? (index + 1) % tabs.length
        : event.key === "ArrowLeft" ? (index - 1 + tabs.length) % tabs.length : null;
      if (next === null) return;
      event.preventDefault();
      setSkillWorkspaceMode(tabs[next].dataset.skillWorkspaceMode);
      tabs[next].focus();
    });
  });

  // Never restore a previously authenticated workspace from the back/forward cache.
  window.addEventListener("pagehide", () => showState("connectingState"));
  window.addEventListener("pageshow", (event) => {
    if (event.persisted) window.location.reload();
  });

  // ─────────────────────────────────────────────────────────────
  //  前端拆分基座（计划 §7）：window.GEO
  //
  //  geo-entry.js 是 1.4 万行的单个 IIFE。按区块搬出去的代码需要一个稳定的接入面，
  //  照仓库已有先例（geo-workbench.js 暴露 window.GW、geo-workbench-views.js 消费）暴露
  //  window.GEO：共享状态 / 元素表 / 各区块要用的辅助函数 + 两个注册表。
  //  ⚠️ 只暴露确实被外部文件用到的成员；它不是新的公共 API，别顺手往里加东西。
  // ─────────────────────────────────────────────────────────────
  const GEO = {
    state,
    elements,
    api,
    escapeHtml,
    formatDate,
    formatNumber,
    setMessage,
    validationMessage,
    promptWorkspaceValue,
    confirmWorkspaceAction,
    toast: clToast,
    clIsArticle,
    clDrawerState,
    // 2026-09-21 §7：第九块（技能工作区）用到的主文件状态 / 常量。
    STAGE_FULL_LABELS,
    executionPollers,
    skillExecutionsState,
    // 2026-09-21 §7：第十块（结果页 + 资产视图）要用到的主文件成员。
    readableText,
    readableList,
    firstReadableList,
    fieldLabel,
    tryParseJsonValue,
    buildReportMarkdown,
    normalizeReport,
    parseStructuredResultFromText,
    downloadText,
    setFormValue,
    runAnalysis,
    startAnalysisFromProject,
    openResultPage,
    renderArticlePublish,
    shouldUseReadableArticleBody,
    reportOneLineSummary,
    glanceFallbackLine,
    SECTION_LABELS,
    REPORT_SKIP_KEYS,
    REPORT_PAIR_KEYS,
    REPORT_POINT_KEYS,
    OPEN_SECTION_COUNT,
    LONG_REPORT_SECTION_THRESHOLD,
    REPORT_READING_ORDER,
    REPORT_DL_KEYS,
    REPORT_TABLE_KEYS,
    REPORT_OL_KEYS,
    REPORT_NOTE_KEYS,
    REPORT_CODE_KEYS,
    SECONDARY_REPORT_KEYS,
    REPORT_ZONE_OF,
    REPORT_ZONE_CARRY,
    REPORT_ZONE_CHROME_KEYS,
    RESULT_ZONES,
    // 2026-09-21 §7：第十一块（项目簇）要用到的主文件成员。
    $,
    viewLoadState,
    config,
    tenantSubdomain,
    statusLabel,
    listText,
    lineList,
    wait,
    formPayload,
    rememberProject, openNewProject,
    rememberedProjectId,
    forgetRememberedProject,
    currentWorkspaceRoute,
    defaultWorkspaceView,
    setActiveWorkspaceView,
    syncHeadActionsForEdit,
    loadAnalyses,
    // 2026-09-21 §7：第九块（技能工作区）还要用到的主文件成员。
    workspaceViewMeta,
    upsertProject,
    pendingIdempotencyRequest,
    clearPendingIdempotencyRequest,
    retainPendingIdempotencyRequest,
    renderProjectDetail,
    selectArticle,
    loadArticles,
    articleSummaryText,
    articleReadableBody,
    resultReportData,
    formatLabel,
    skillExecutionText,
    renderExecutionGlance,
    copySkillExecution,
    downloadSkillExecution,
    downloadSkillFormat,
    // 2026-09-21 §7：第五块（内容库 + 素材库）用到的几个主文件辅助函数 / 入口。
    articleDisplayTitle,
    articleBodyBlock,
    errorNoText,
    downloadBlob,
    openContentTools,
    openExistingArticles,
    // 2026-09-21 §7：第六块（发布与渠道）用到的辅助函数。
    copyText,
    // 2026-09-21 §7：拆出去的块还要用到这几个主文件里的辅助函数（财务面/后续块共用）。
    formatMoneyFen,
    formatKnowledgeDate,
    withBusyButton,
    showWorkspaceNotice,
    openWorkspaceView,
    // 2026-09-21 §7：第三块（企业知识库）用到的几个主文件辅助函数 / 加载器。
    clToast,
    clDebounce,
    fillErrorNo,
    loadKnowledgeBases,
    // 2026-09-21 §7：第四块（知识库文档）用到的几个主文件辅助函数。
    authHeaders,
    recentProjectStorageKey,
    loadKnowledgeStats,
    KB_DOC_STATUS_LABELS,
    KB_DOC_STATUS_VARIANTS,
    viewLoaders: Object.create(null),
    extraLoaders: Object.create(null),
    /** 注册"进这个视图要跑的加载器"（例如 URL 导入、文章分类、API 令牌）。 */
    registerViewLoader(view, loader) {
      (this.viewLoaders[view] || (this.viewLoaders[view] = [])).push(loader);
    },
    /** 注册"额外加载器"：由主文件在合适时机取用（财务面把一块附加视图与余额并行拉取）。 */
    registerViewExtra(view, loader) {
      (this.extraLoaders[view] || (this.extraLoaders[view] = [])).push(loader);
    },
    runViewLoaders(view, tasks) {
      (this.viewLoaders[view] || []).forEach((loader) => tasks.push(loader()));
    },
    viewExtrasFor(view) {
      return (this.extraLoaders[view] || []).slice();
    },
  };
  window.GEO = GEO;

  bootstrap();
})();
