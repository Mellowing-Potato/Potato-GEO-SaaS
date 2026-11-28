/* Only copied into the temporary local review directory by preview-workspace-ui.php. */
(() => {
  const project = {
    id: 901, name: '示例文旅 AI 传播演示', brand_name: '示例文旅', brand_url: '',
    status: 'active', current_stage: 0, progress: 0,
    settings: { target_market: '四川及成渝地区文化旅游游客', product_description: '李白文化、亲子游与研学线路。', target_keywords: ['李白故里示例', '示例两日游'], competitors: [], goals: '帮助游客找到可信的出游信息。' },
    workflow_recommendations: [],
    // EXEC-10B: actual six-stage response shape for drawer/queue verification.
    stages: ['research', 'strategy', 'production', 'distribution', 'monitoring', 'iteration'].map((stage_key, stage_index) => ({
      id: 9010 + stage_index, project_id: 901, stage_index, stage_key,
      status: stage_index === 0 ? 'active' : 'pending', progress: stage_index === 0 ? 40 : 0,
      started_at: stage_index === 0 ? '2026-09-09T01:00:00Z' : null, completed_at: null,
    })),
    current_stage_requirements: { met: false, requirements: ['需要完成该阶段的一项能力（意图挖掘）'], skills: ['yao-geo-intent-miner'] },
  };
  const skills = [
    { id: 'yao-geo-intent-miner', name: '意图挖掘', category: 'geo-research', stage_index: 0, scene_description: '梳理游客真实问题，确定内容优先级。', brief_fields: [{ key: 'brand_name', label: '品牌名称', required: true }, { key: 'seed_keywords', label: '种子关键词', type: 'textarea', required: true }, { key: 'count', label: '问题数量', type: 'number', min: 5, max: 30, default: 10 }] },
    { id: 'page-audit', name: '页面检查', category: 'geo-research', stage_index: 0, scene_description: '检查已发布页面的内容和结构。', brief_fields: [{ key: 'url', label: '页面地址', type: 'url', required: true }, { key: 'mode', label: '检查范围', type: 'select', options: ['完整页面', '正文'] }] },
    ...[['yao-geo-explainer-builder', '解释型文章'], ['comparison', '对比型文章'], ['ranking', '排名型文章']].map(([id, name]) => ({ id, name, category: 'geo-content-production', stage_index: 2, scene_description: '围绕真实搜索问题，生成可编辑的文章。', effect_description: '产出 2000–4000 字的 SEO/GEO 优化编辑稿，附研究参考与待核验项。', brief_fields: [{ key: 'topic', label: '文章要解释的问题或主题', required: true }, { key: 'target_keyword', label: '希望覆盖的搜索词', required: true }] })),
    ...[['yao-geo-panorama-audit', '综合诊断', 1], ['yao-geo-channel-publishing', '渠道发布', 3], ['yao-geo-effect-monitor', '效果监测', 4], ['yao-geo-content-refiner', '内容优化', 5]].map(([id, name, stage_index]) => ({ id, name, stage_index, category: 'geo-strategy', scene_description: '仅供本地六阶段抽屉排版和导航验证。', brief_fields: [] })),
  ];
  const report = {
    title: '示例文旅AI搜索意图挖掘与GEO传播优化报告', brand_name: '示例文旅', analysis_date: '2026-09-09',
    summary_cards: [
      { label: '意图问题库规模', value: '30+条', note: '覆盖两日游、研学、亲子、美食与住宿。仅为本地排版样例。' },
      { label: '监测平台覆盖', value: '5', note: 'DeepSeek、豆包、千问、Kimi、元宝，未执行真实采样。' },
      { label: '真实数据模式', value: 'M0 未接入', note: '当前以种子词和业务假设构建问题，后续需接入真实问答。' },
      { label: '核心机会方向', value: '李白文化研学与两日游攻略', note: '整理深度内容与官方引用，优先回答行程安排问题。' },
      { label: '竞品对标', value: '阆中古城、剑门关、绵阳文旅、北川文旅', note: '观察区域文旅内容差异，保留每条比较的来源与时间。' },
      { label: '首要风险边界', value: '价格、活动与交通等需官方校准', note: '不输出未经核实的票价、活动时间及服务承诺。' },
    ],
    sections: [
      { title: '执行摘要与关键发现', paragraphs: ['本地报告样例围绕示例旅游、李白故里、研学、亲子与美食等问题，展示长报告在不同屏幕上的阅读效果。这里没有接入真实平台数据，也不代表已经核实的诊断结论。', '游客的提问通常从目的地选择逐步走向行程安排。内容可以围绕交通、同行者的年龄与兴趣、活动时长组织，让读者在一处找到关联信息，并查看每项信息的依据。'] },
      { title: '研究依据与方法', paragraphs: ['将问题按信息查询、目的地比较与行程决策整理，再分别记录可引用的资料。每个问题保留独立表达，避免只有关键词而没有明确意图。', '对于缺乏证据的信息，标记待核实项与负责补充的资料类型。新资料进入后再更新内容，保留可追溯的来源。'], bullets: ['记录提问场景和适用人群。', '区分已核实的事实与业务假设。', '为内容建议补充来源、更新日期和适用边界。'] },
    ],
    summary: '优先回答游客在规划行程时最常见的问题，将旅游信息整理成可查证、便于阅读的内容。此处为样式检查用的示例报告。',
    key_findings: ['出游线路、亲子项目和研学选择需要清晰的内容入口。', '涉及价格、开放时间和机构资质的信息需要证据支撑。'],
    recommendations: ['按用户问题组织文章与 FAQ。', '先补齐来源，再发布内容。'],
    content_assets: [
      { 问题方向: '两日游线路', 内容资产: '攻略文章 / 页面模块', 证据需求: '官方路线和联票', 优先级: 'P0' },
      { 问题方向: '研学机构选择', 内容资产: 'FAQ / 知识库', 证据需求: '资质认证和案例', 优先级: 'P0' },
      { 问题方向: '亲子游玩项目', 内容资产: '专题文章 / FAQ', 证据需求: '适龄项目清单', 优先级: 'P1' },
    ],
    monitoring_plan: '在接入真实平台回答前，先制定采样计划，分别设计核心问题，每周记录品牌提及和引用来源。记录字段包括平台、提问时间、答案摘要、品牌是否提及、引用来源和下一轮追问。当前状态为计划，尚未执行采样。',
    evidence_gaps: '需要补充官方景点资料、活动日历、研学机构资质名单和亲子项目适龄说明。获得真实数据后再校准内容优先级，保留可追溯的来源与更新时间。',
    compliance_boundaries: '涉及研学安全、儿童项目和食品过敏的内容，应提示游客参考官方最新公告与现场告知。价格、开放时间和服务承诺均需确认来源。',
    roadmap: [{ 阶段: '30 天', 行动: '完成官方资料收集，建立事实校准表，输出核心问题清单。' }, { 阶段: '60 天', 行动: '上线两日游、李白故里与亲子游专题，完成第一轮采样。' }, { 阶段: '90 天', 行动: '更新 FAQ 知识库，复盘品牌提及和引用来源。' }],
    补充说明: '额外字段也必须能查看，不能在改版中丢失。',
    资料更新流程: '本地样例：记录资料来源、核实日期及修改原因。',
    协作安排: '本地样例：由资料负责人确认来源，内容编辑整理正文。',
    后续复盘: '本地样例：检查内容是否仍适用于当前行程与服务范围。',
  };
  const article = { id: 101, project_id: 901, title: report.title, source_type: 'geo_analysis', content_type: 'report', status: 'draft', created_at: '2026-09-09T12:00:00Z', structured: report, body_markdown: '# 本地示例报告', available_formats: ['markdown', 'json'] };
  const historyReports = [article,
    { ...article, id: 102, title: '示例文旅搜索意图与内容机会', source_type: 'skill_execution', status: 'editing', created_at: '2026-09-09T11:00:00Z' },
    { ...article, id: 103, title: '示例文旅阶段监测报告', source_type: 'skill_execution', status: 'approved', created_at: '2026-09-10T01:00:00Z' },
    { ...article, id: 104, title: '示例文旅项目初始诊断', status: 'approved', created_at: '2026-09-07T02:00:00Z' },
  ].map(item => ({ ...item, structured: { ...report, title: item.title } }));
  const contentArticles = ['draft', 'editing', 'pending', 'approved', 'published', 'recycled'].map((status, index) => ({
    id: 201 + index, project_id: 901, source_type: 'skill_execution', content_type: 'explainer', status,
    title: ['示例两日游：如何安排李白文化与亲子线路', '研学机构选择：出发前需要确认的资料与服务范围', '示例美食指南：按区域整理游客常见问题', '李白故里示例：文化景点与行程建议', '亲子出游准备清单', '旧版线路草稿'][index],
    summary: '本地样式预览文章，检查长标题、状态、编辑表单与操作入口。',
    body_markdown: '# 出发前先确认行程\n\n根据同行者的年龄和兴趣安排景点，提前确认开放时间、预约要求与交通信息。\n\n## 整理可信的信息\n\n价格、机构资质与活动时间均需核实来源。此处是本地示例内容，不代表已核实的旅游建议。',
    word_count: 2180 + index * 100, version: 2, created_at: '2026-09-09T12:00:00Z', updated_at: '2026-09-10T01:00:00Z',
    reviewed_at: status === 'editing' ? '2026-09-09T13:00:00Z' : null,
    review_notes: status === 'editing' ? '请补充机构资质与服务范围的来源，保留已核实信息。' : null,
    flagged_sensitive: status === 'pending', available_formats: ['docx', 'wechat'],
  }));
  const materialSamples = {
    titles: [{ id: 301, title: '示例两日游：如何兼顾文化体验与亲子活动', note: '适合首次到访的家庭游客；活动时间发布前核实。', category: '出游指南', usage_count: 3 }],
    keywords: [{ id: 302, keyword: '李白故里示例', note: '文章核心搜索词', category: '文旅', search_volume: 0, usage_count: 2 }],
    images: [],
    authors: [{ id: 303, name: '文旅编辑', bio: '整理出游信息与来源；这是本地示例作者。', avatar_url: '', external_link: '' }],
  };
  const execution = { id: 701, project_id: 901, skill_id: 'yao-geo-intent-miner', skill_name: '意图挖掘', status: 'failed', created_at: '2026-09-09T12:00:00Z', result_text: '示例编辑稿：请在正式发布前补充来源。', available_formats: ['markdown'], quality_report: { passed: false, reasons: ['示例：仍需补充资料'] } };
  const runningExecution = { id: 702, project_id: 901, skill_id: 'yao-geo-intent-miner', skill_name: '意图挖掘（本地运行中样例）', status: 'running', created_at: '2026-09-29T03:45:00Z', result_text: null, available_formats: [] };
  const previewRunning = new URL(location.href).searchParams.has('preview-running');
  window.__uiPreviewRequests = [];
  const leadFormSamples = [
    { id: 971, name: '文旅活动咨询（本地样例）', slug: 'preview-travel', status: 'active', description: '收集活动兴趣与联系意愿，内容均为虚构示例。', submissions_count: 2, created_at: '2026-09-09T01:00:00Z' },
    { id: 972, name: '历史活动报名（本地样例）', slug: 'preview-old', status: 'inactive', description: '', submissions_count: 0, created_at: '2026-09-08T01:00:00Z' },
  ];
  const leadSamples = ['new', 'contacted'].map((status, i) => ({ id: 981 + i, form: leadFormSamples[0], status, source_url: 'https://example.invalid/travel/family', notify_status: i ? 'failed' : 'sent', notify_error: i ? '样例：通知服务暂不可用' : '', handler_name: i ? '示例编辑' : '', created_at: '2026-09-09T01:00:00Z', note: i ? '示例备注，可在此记录跟进情况。' : '', payload: { 姓名: '示例访客', 出游意向: '亲子活动与李白文化体验', 同意联系: true }, ip_address: '192.0.2.1' }));
  const visibilitySamples = ['completed', 'failed', 'running'].map((status, i) => ({
    id: 991 + i, project_id: 901, status, is_terminal: status !== 'running',
    question: ['示例两日游如何安排文化体验与亲子活动？（本地样例）', '选择研学活动前应确认哪些资料？（失败样例）', '示例有哪些适合家庭的文化体验？（运行中样例）'][i],
    points_charged_fen: i ? null : 1.2, duration_seconds: i ? 0 : 62, search_call_count: i ? 0 : 3, search_count_estimated: i === 0,
    created_at: '2026-09-10T01:00:00Z', error_message: status === 'failed' ? '本地示例错误：参考资料读取失败。' : '',
    answer_text: status === 'completed' ? '这是本地样式示例回答，未执行真实监测。\n\n可以围绕同行者的年龄、兴趣和行程时间整理出游问题，再逐项确认景点开放时间、交通及预约信息。\n\n示例中的来源与金额仅用于检查页面格式。' : '',
    answer_citations_present: false, search_queries: i ? [] : ['示例 两日游 文化体验', '示例 亲子 活动'],
    sources: i ? [] : [{ citation_key: '1', title: '出游资料参考（本地样例）', url: 'https://example.invalid/travel', domain: 'example.invalid', site_name: '样例站点', published_at: null }],
    usage: { search_call_count: i ? 0 : 3, prompt_tokens: i ? 0 : 1200, completion_tokens: i ? 0 : 600, cached_tokens: 0 },
  }));
  const response = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  const manualPersonas = [{ id: 911, name: '文旅编辑（样例）', tone: '清晰、平实', domain: '文旅', disclosure_text: '本地样式示例', is_active: true }, { id: 912, name: '历史人设（样例）', is_active: false }];
  const manualAccounts = [{ id: 921, persona_id: 911, persona: manualPersonas[0], account_name: '文旅账号（样例）', platform: 'zhihu', is_active: true, profile_url: 'https://example.invalid/profile' }, { id: 922, persona_id: 911, persona: manualPersonas[0], account_name: '自定义渠道账号（样例）', platform: 'custom', custom_platform: '示例论坛', is_active: true }];
  const manualPublications = ['ready', 'in_progress', 'completed', 'failed'].map((status, i) => ({
    id: 931 + i, type: i % 2 ? 'comment' : 'post', status, platform: 'zhihu', revision: 2,
    persona: manualPersonas[0], account: manualAccounts[0], persona_id: 911, account_id: 921, article_id: 204,
    assigned_user_id: 901, assignee: { id: 901, name: '本地预览' },
    content: '本地示例内容：围绕文化体验与亲子活动整理出游信息。\n\n开放时间和预约要求需确认后填写，此处未执行真实发布。',
    target_url: i % 2 ? 'https://example.invalid/topic' : null, target_context: i % 2 ? '示例讨论：如何安排行程' : null,
    completion_url: status === 'completed' ? 'https://example.invalid/published' : null,
    scheduled_at: '2026-09-12T01:00:00Z', created_at: '2026-09-10T01:00:00Z',
    risk_status: i === 1 ? 'warning' : 'clean', duplicate_warning_count: i === 1 ? 1 : 0,
    risk_result: { matches: i === 1 ? [{ origin: 'tenant', word: '示例提示词', field: 'content', count: 1, severity: 'warning', suggestion: '样例提示：请核实内容。' }] : [] },
    transitions: [{ from_status: 'draft', to_status: status, actor: { name: '示例编辑' }, result_note: '仅展示流转记录的版式。', created_at: '2026-09-10T02:00:00Z' }],
  }));
  let previewHasPassword = new URL(location.href).searchParams.has('preview-password');
  let previewPassword = 'Preview-password-1';
  let previewCodeSent = false;
  let previewPreferences = [{ id: 821, project_id: 901, skill_id: 'yao-geo-explainer-builder', preferences: { tone: 'plain', structure: 'conclusion_first', length: 'default', requirements: '解释专业词语，减少宣传口号。' } }];
  const previewKnowledgeBases = [
    { id: 701, project_id: 901, name: '示例文旅资料库', description: '景点介绍、服务说明与常见问答', documents_count: 0, created_at: '2026-09-09T01:00:00Z' },
    { id: 702, project_id: 902, name: '城市文旅资料库', description: '仅供城市文旅内容研究项目参考', documents_count: 0, created_at: '2026-09-09T01:00:00Z' },
    ...(new URL(location.href).searchParams.has('preview-legacy') ? [{ id: 703, project_id: null, name: '待确认归属的历史资料', documents_count: 0 }] : []),
  ];
  const previewSession = { token: 'local-preview-only', user: { id: 901, name: '本地预览', role: 'admin' }, tenant: { id: 901, name: '示例工作区', subdomain: 'local-preview' } };
  window.fetch = async (input, options = {}) => {
    const url = new URL(typeof input === 'string' ? input : input.url, location.href);
    const method = options.method || 'GET';
    window.__uiPreviewRequests.push({ path: url.pathname, method, body: options.body || null });
    if (url.origin !== location.origin) return response({ message: '本地预览不连接外部服务。' }, 503);
    const path = url.pathname;
    // 想看"没有权限"的渲染形态时，在预览地址上加 ?preview-forbidden=url-imports,categories…
    // （只让这些端点回 403；用来验证 R29/R30：读不到要如实说，不能伪装成"暂无数据"。）
    // ⚠️ 读页面地址上的开关（不是请求地址上的）：fetch 打的是 /api/v1/xxx，不带页面的查询串。
    const forbidden = String(new URL(location.href).searchParams.get('preview-forbidden') || '').split(',').filter(Boolean);
    if (forbidden.some((key) => path.includes(key))) return response({ message: '本地预览：按开关返回 403。' }, 403);
    const knowledgeProjectId = Number(new Headers(options.headers || {}).get('X-Project-Id'));
    if (path === '/api/v1/generation-preferences') return response({ data: previewPreferences.filter(row => row.project_id === knowledgeProjectId) });
    if (path.startsWith('/api/v1/generation-preferences/')) {
      const skillId = decodeURIComponent(path.split('/').pop());
      if (!knowledgeProjectId) return response({ message: '请先选择项目。' }, 422);
      if (method === 'PUT' || method === 'DELETE') {
        previewPreferences = previewPreferences.filter(row => row.project_id !== knowledgeProjectId || row.skill_id !== skillId);
        if (method === 'PUT') previewPreferences.push({ id: 822, project_id: knowledgeProjectId, skill_id: skillId, preferences: JSON.parse(options.body) });
      }
      return response({ data: previewPreferences.find(row => row.project_id === knowledgeProjectId && row.skill_id === skillId) || null });
    }
    // 运营台预览默认给的是租户 admin —— 于是运营台永远停在"无权限"守卫态，看不到任何数据。
    // 需要核对运营台版式/接线时，在预览地址上加 ?preview-platform-admin 显式打开样例身份；
    // 只影响这份本地 fixture，不改任何真实会话或权限。
    if (path === '/api/v1/auth/session-login') {
      return response(location.search.includes('preview-platform-admin')
        ? { ...previewSession, user: { ...previewSession.user, name: '本地预览（平台运营样例）', role: 'platform_admin' } }
        : previewSession);
    }
    if (path === '/api/v1/knowledge-legacy') return response({ bases: previewKnowledgeBases.filter(kb => kb.project_id === null), archives: [] });
    if (path === '/api/v1/knowledge-legacy/assign' && method === 'POST') {
      const kb = previewKnowledgeBases.find(kb => kb.id === JSON.parse(options.body).id && kb.project_id === null);
      if (!kb || !knowledgeProjectId) return response({ message: '请选择项目并刷新重试。' }, 422);
      kb.project_id = knowledgeProjectId; return response({ message: '本地样例已分配。' });
    }
    if (path === '/api/v1/knowledge-bases' && method === 'POST') {
      const input = JSON.parse(options.body || '{}');
      if (!knowledgeProjectId || !input.name?.trim()) return response({ message: '请选择项目并填写名称。' }, 422);
      const kb = { ...input, id: Math.max(...previewKnowledgeBases.map(kb => kb.id)) + 1, project_id: knowledgeProjectId, documents_count: 0 };
      previewKnowledgeBases.push(kb); return response({ data: kb }, 201);
    }
    if (path === '/api/v1/projects/901' && method === 'PATCH') {
      const data = JSON.parse(options.body || '{}');
      if (Object.keys(data).length !== 1 || typeof data.name !== 'string' || !data.name.trim() || data.name.length > 255) return response({ message: '本地预览仅支持修改项目名称，请填写 1–255 个字符。' }, 422);
      project.name = data.name.trim();
      return response(project);
    }
    if (path === '/api/v1/account/password/code' && method === 'POST') {
      previewCodeSent = true;
      return response({ message: '本地模拟发送；示例验证码 123456。' });
    }
    if (path === '/api/v1/account/password' && method === 'PATCH') {
      const data = JSON.parse(options.body || '{}');
      if (!data.password || data.password.length < 8 || data.password !== data.password_confirmation) return response({ message: '请填写至少 8 位的新密码，并保持两次输入一致。' }, 422);
      if (data.mode === 'reset' ? (!previewCodeSent || data.code !== '123456') : (previewHasPassword && data.current_password !== previewPassword)) return response({ message: data.mode === 'reset' ? '示例验证码错误，请先发送再填写 123456。' : '当前密码不正确。' }, 422);
      previewHasPassword = true;
      previewPassword = data.password;
      previewCodeSent = false;
      return response(previewSession);
    }
    if (method !== 'GET') return response({ message: '这是本地样式预览，未提交或执行任何真实操作。' }, 422);
    if (path === '/api/v1/account/profile') return response({ user: { id: 901, name: '本地预览', phone: '13800000005', email: '' }, has_password: previewHasPassword });
    // 账户注销（计划 §9.5）：本地预览只演示"未申请"这一态，提交动作不写任何状态
    //（预览沙盘里没有服务端，POST 只回一句"样例数据"的话，绝不假装真的注销成功）。
    if (path === '/api/v1/account/deletion' && method === 'GET') return response({ data: { pending: false, requested_at: null, purge_at: null, days_left: 0, grace_days: 15, purged_at: null } });
    if (path === '/api/v1/account/deletion' && method === 'POST') return response({ message: '本地预览样例：真实环境下这一步会提交注销申请并退出全部登录。', data: { requested_at: '2026-09-21T03:00:00Z', purge_at: '2026-10-06T03:00:00Z', grace_days: 15 } });
    if (path === '/api/v1/account/sessions') return response({ data: [
      { current: true, created_at: '2026-09-14T04:58:15Z', expires_at: '2026-09-28T04:58:15Z' },
      ...(new URL(location.href).searchParams.has('preview-sessions') ? [{ current: false, created_at: '2026-09-13T02:30:00Z', expires_at: '2026-09-27T02:30:00Z' }] : []),
    ] });
    if (path === '/api/v1/me') return response({ user: { id: 901, name: '本地样例', role: 'admin' } });
    if (path === '/api/v1/platform/overview') return response({ platform: { tenants_total: 3, tenants_active: 2, users_total: 8, projects_total: 5 }, content: { articles_period: 20, articles_published_total: 12 }, execution: { executions_period: 25, tokens_period: 120000 }, commercial: { orders_period: 3, revenue_fen: 1280000, active_subscriptions: 2 } });
    if (path === '/api/v1/platform/commercial') return response({ orders: { by_status: [{ status: 'paid', count: 1, revenue_fen: 1280000 }, { status: 'wait', count: 1, revenue_fen: 0 }, { status: 'failed', count: 1, revenue_fen: 0 }] }, subscriptions: { by_status: { active: 2, paused: 1 } }, recent_events: [{ id: 901, event_type: 'order.paid', status: 'failed', tenant_id: 901, tenant_name: '样例工作区', tenant_subdomain: 'local-preview', error_message: '本地失败样例：回调处理暂不可用', created_at: '2026-09-10T01:00:00Z' }] });
    if (path === '/api/v1/platform/commercial/orders') return response({ data: ['paid', 'pending', 'failed'].map((status, i) => ({ id: 910 + i, tenant_name: '样例工作区', tenant_id: 901, plan_key: 'pro', status, amount_fen: 1280000, created_at: '2026-09-10T01:00:00Z' })) });
    if (path === '/api/v1/platform/tenants') return response({ data: ['active', 'trialing', 'paused'].map((status, i) => ({ id: 901 + i, subdomain: 'preview-' + i, name: '本地样例工作区 ' + (i + 1), users_count: 3, projects_count: 2, articles_total: 10, last_30d: { executions: 8, tokens: 4000 }, subscription: { status, plan_key: 'pro', current_period_end: '2026-09-20T01:00:00Z' } })) });
    if (path === '/api/v1/platform/invoices') return response({ pending_issued_count: 1, data: [{ id: 920, tenant_name: '样例工作区', status: 'submitted', amount_fen: 1280000, submitted_at: '2026-09-10T01:00:00Z' }] });
    if (path === '/api/v1/platform/health') return response({ model_calls: { total: 25, success_rate: 96, failed: 1 }, points_debit: { integration_rate: 100, deducted: 25, failed: 0 }, executions: { total: 25, failed: 1, failure_rate: 4 }, publications: { total: 10, failed: 0, failure_rate: 0 } });
    if (path === '/api/v1/platform/operations') return response({ metrics: { tenants_total: 3, tenants_with_risks: 1, publishing_channels_active: 2, publishing_channels_total: 3, publications_failed: 1, commercial_payment_exceptions: 1 }, recent_publications: [], recent_commercial_events: [] });
    if (path === '/api/v1/platform/queue-health') return response({ workers: [], queues: [], alerts: [] });
    // 对账待办（计划 §9.6）：两条样例 —— 一条金额不一致（未处理，可复核），一条仅本端有。
    if (path === '/api/v1/platform/reconciliations') {
      return response({
        data: [
          { finding_key: 'points_mismatch:ext-1001', classification: 'points_mismatch', status: 'open', tenant_id: 901, model_call_log_id: 5001, points_recorded: 100, points_gateway: 88, points_diff: 12, created_at: '2026-09-20T02:00:00Z', reviewed_at: null, reviewed_by: null, review_decision: null, review_note: null },
          { finding_key: 'php_only:ext-1002', classification: 'php_only', status: 'open', tenant_id: 902, model_call_log_id: 5002, points_recorded: 30, points_gateway: 0, points_diff: 30, created_at: '2026-09-19T02:00:00Z', reviewed_at: '2026-09-20T03:00:00Z', reviewed_by: '本地预览', review_decision: 'reviewed', review_note: '样例：已核对网关账本条目，属对账口径差异。' },
        ],
        meta: { current_page: 1, last_page: 1, total: 2 },
        summary: { open_total: 2, open_php_only: 1, open_gateway_only: 0, open_points_mismatch: 1, open_php_blocked: 0, open_direct_settlement: 0 },
        decisions: ['reviewed', 'waived', 'escalated'],
      });
    }
    if (path === '/api/v1/platform/sensitive-words') {
      const rows = [{ id: 930, word: '示例提示词', severity: 'warning', category: 'custom', is_enabled: true, note: '本地样例，仅用于查看版式', updated_at: '2026-09-10T01:00:00Z' }];
      return response({ version: 1, enabled_count: 1, data: rows.filter(row => !url.searchParams.get('q') || row.word.includes(url.searchParams.get('q'))) });
    }
    if (path === '/api/v1/platform/sensitive-word-hits/summary') return response({ data: [{ word: '示例提示词', origin: 'platform', article_count: 2, tenant_count: 1, hit_count: 3, last_hit_at: '2026-09-10T01:00:00Z' }] });
    if (path === '/api/v1/platform/sensitive-word-hits') return response({ retention_days: 30, data: [] });
    // 第十六批新增的两个运营台分区（审计日志 / 安全设置）：没有这两条样例，
    // 预览里审计表永远是空的、"正在加载"，看不到行渲染与分页文案的真实形态。
    if (path === '/api/v1/platform/audit') {
      const rows = [
        { id: 960, action: 'security-settings.update', actor_name: '运营（样例）', actor_type: 'user', target_type: 'platform', target_id: null, created_at: '2026-09-10T01:00:00Z', details: { changed: ['password_min_length'], from: 6, to: 8 } },
        { id: 959, action: 'tenant.pause', actor_name: '运营（样例）', actor_type: 'user', target_type: 'tenant', target_id: 901, created_at: '2026-09-09T01:00:00Z', details: { reason: '本地样例：核对暂停流程' } },
        // §9.7 起租户侧的敏感动作也写这张表（行里带 tenant_id）：样例必须覆盖，
        // 否则"租户"列在预览里永远只有"平台"一种取值，看不出它是干什么的。
        { id: 957, tenant_id: 902, action: 'channel_secret.created', actor_name: '客户管理员（样例）', actor_type: 'user', target_type: 'PublishingChannel', target_id: 951, created_at: '2026-09-08T02:00:00Z', details: { channel: '客户 WordPress', key_id: 'key_sample01' } },
        { id: 956, action: 'order.sync', actor_name: '系统', actor_type: 'system', target_type: 'order', target_id: 910, created_at: '2026-09-08T01:00:00Z', details: { result: 'ok' } },
      ];
      return response({ data: rows, meta: { current_page: 1, last_page: 2, per_page: 4, total: 7 } });
    }
    if (path === '/api/v1/platform/security-settings') return response({ settings: { password_min_length: 8, require_special_char: true, api_rate_limit_per_min: 120 } });
    // 第十六批在工作台新接线的四处入口（URL 导入 / 文章分类 / API 令牌 / 账户中心概览）。
    // 没有这些样例，预览里它们只会显示"加载失败"或空态 —— 看不到真实渲染形态，
    // 也就没法用它做"从侧栏进去能看到这个功能"的验收。
    const previewUrlImports = [
      { id: 990, url: 'https://example.com/%E6%B1%9F%E6%B2%B9%E6%96%87%E6%97%85%E6%94%BB%E7%95%A5?utm_source=geo_demo&utm_campaign=spring_travel_guide_2026', status: 'completed', title: '示例文旅攻略（本地样例）', article_id: 601, created_at: '2026-09-10T01:00:00Z', updated_at: '2026-09-10T01:02:00Z', metadata: { word_count: 1680, extraction_quality: { state: 'ok', preview: '本地样例提取正文摘要：涵盖两日游路线、交通接驳与亲子行程建议。' } } },
      { id: 989, url: 'https://example.com/%E6%9D%8E%E7%99%BD%E6%95%85%E9%87%8C%E7%A0%94%E5%AD%A6', status: 'processing', title: '', article_id: null, created_at: '2026-09-10T00:30:00Z' },
      { id: 988, url: 'https://example.com/private-page', status: 'failed', title: '', error_message: '本地样例：目标页面需要登录，抓不到正文。', article_id: null, created_at: '2026-09-09T23:00:00Z' },
    ];
    if (path === '/api/v1/url-imports') {
      if (method === 'POST') return response({ data: { id: 990, status: 'pending' } }, 201);
      return response({ data: previewUrlImports, meta: { current_page: 1, last_page: 1, per_page: 20, total: previewUrlImports.length } });
    }
    if (path.startsWith('/api/v1/url-imports/')) {
      const id = Number(path.split('/').pop());
      const found = previewUrlImports.find(row => row.id === id);
      return found ? response({ data: found }) : response({ message: '本地样例未找到该导入记录。' }, 404);
    }
    if (path === '/api/v1/categories') {
      if (method === 'POST') return response({ data: { id: 980, name: '新分类（本地样例）', slug: 'preview-new', sort_order: 30, articles_count: 0 } }, 201);
      return response({ data: [
        { id: 977, name: '目的地攻略', slug: 'destination-guide', sort_order: 10, articles_count: 6 },
        { id: 978, name: '研学与亲子', slug: 'study-tour', sort_order: 20, articles_count: 3 },
        { id: 979, name: '待归类', slug: 'uncategorized', sort_order: 99, articles_count: null },
      ] });
    }
    if (path === '/api/v1/manual-publications/settings/personas') return response({ data: manualPersonas });
    if (path === '/api/v1/manual-publications/settings/accounts') return response({ data: manualAccounts });
    if (path === '/api/v1/team/') return response({ data: [{ id: 901, name: '本地预览' }, { id: 902, name: '示例协作人' }] });
    if (path === '/api/v1/articles') return response({ data: contentArticles.filter(item => item.status === url.searchParams.get('status')) });
    if (path === '/api/v1/manual-publications') return response({ data: { data: manualPublications }, stats: { total: 4, ready: 1, in_progress: 1, completed: 1 } });
    const manualId = path.match(/^\/api\/v1\/manual-publications\/(93[1-4])$/);
    if (manualId) {
      const publication = manualPublications.find(item => item.id === Number(manualId[1]));
      const next = { ready: ['in_progress', 'cancelled'], in_progress: ['completed', 'failed', 'skipped'], completed: [], failed: ['ready'] }[publication.status];
      return response({ data: publication, can_update: publication.status === 'ready', can_transition: next.length > 0, allowed_next: next });
    }
    if (path === '/api/v1/visibility/runs') return response({ data: visibilitySamples, meta: { current_page: 1, last_page: 1, total: 3 } });
    const visibilityId = path.match(/^\/api\/v1\/visibility\/runs\/(99[1-3])$/);
    if (visibilityId) return response({ data: visibilitySamples.find(run => run.id === Number(visibilityId[1])) });
    if (path === '/api/v1/visibility/question-suggestions') return response({ data: [{ question: '示例两日游如何安排行程？', category: '出游规划' }, { question: '带孩子体验李白文化需要提前准备什么？', category: '亲子活动' }] });
    if (path === '/api/v1/lead-forms') return response({ data: leadFormSamples });
    const leadFormId = path.match(/^\/api\/v1\/lead-forms\/(97[12])$/);
    if (leadFormId) return response({ data: { ...leadFormSamples.find(f => f.id === Number(leadFormId[1])), notify_webhook_configured: Number(leadFormId[1]) === 971, notify_webhook_masked: 'https://example.invalid/••••', notify_webhook_format: 'robot', fields: [
      { name: 'name', label: '姓名', type: 'text', required: true },
      { name: 'phone', label: '联系电话', type: 'phone', required: false },
      { name: 'email', label: '邮箱', type: 'email', required: false },
      { name: 'interest', label: '活动兴趣', type: 'select', required: true, options: ['文化体验', '亲子活动'] },
      { name: 'contact', label: '联系意愿', type: 'checkbox', required: false, options: ['可邮件联系', '可电话联系'] },
      { name: 'message', label: '补充说明', type: 'textarea', required: false },
    ] } });
    if (path === '/api/v1/leads') {
      const items = leadSamples.filter(row => (!url.searchParams.get('status') || row.status === url.searchParams.get('status')) && (!url.searchParams.get('form_id') || String(row.form.id) === url.searchParams.get('form_id')) && (!url.searchParams.get('search') || `${row.note} ${row.source_url}`.includes(url.searchParams.get('search'))) && (!url.searchParams.get('date_from') || row.created_at.slice(0, 10) >= url.searchParams.get('date_from')) && (!url.searchParams.get('date_to') || row.created_at.slice(0, 10) <= url.searchParams.get('date_to')));
      return response({ data: items, meta: { current_page: 1, last_page: 1, total: items.length }, stats: { total: 2, new: 1, pending: 1, converted: 0 } });
    }
    const leadId = path.match(/^\/api\/v1\/leads\/(98[12])$/);
    if (leadId) return response({ data: leadSamples.find(row => row.id === Number(leadId[1])) });
    if (path === '/api/v1/lead-analytics') return response({ summary: { kpis: { submissions: 2, new: 1, pending: 1, converted: 0, conversion_rate: 0 }, funnel: { views_tracked: true, views: 20, submissions: 2, converted: 0, view_to_submission_rate: 10, submission_to_converted_rate: 0 }, trend: [{ date: '2026-09-08', views: 12, submissions: 1, converted: 0 }, { date: '2026-09-09', views: 8, submissions: 1, converted: 0 }], sources: [{ source: 'https://example.invalid/travel/family', submissions: 2, converted: 0 }], recent: leadSamples } });
    const projectSamples = [project, ...['城市文旅内容研究', '研学活动资料整理', '亲子出游信息服务', '文化体验推广', '景区官方资料项目', '出游问题与答案库', '用于检查长名称显示与搜索的项目样例'].map((name, i) => ({ ...project, id: 902 + i, name, brand_name: '示例品牌 ' + (i + 1) }))];
    if (path === '/api/v1/projects') return response({ data: projectSamples, current_page: 1, last_page: 1, total: projectSamples.length });
    // 全局回收站（计划 §9.5；2026-10-01 起前端读 /api/v1/trash）：按 ProjectController::trash 的真实返回形状给固定样例，
    // 项目与文章混排、按 purge_at 排序，用来检查"还有几天可恢复 / 恢复截止"的排版与恢复、重试撤稿按钮。
    if (path === '/api/v1/trash') return response({
      retention_days: 7,
      data: [
        { id: 931, key: 'project:931', name: '已删除的两日游内容项目（本地样例）', brand_name: '示例文旅', deleted_at: '2026-09-19T02:00:00Z', purge_at: '2026-09-26T02:00:00Z', days_left: 5, type: 'project', type_label: '项目', expired: false, restorable: true, articles_count: 12, skill_executions_count: 3, takedown: { online: 0, failed: 0, last_error: '', remote_urls: [] }, restore_url: '/api/v1/projects/931/restore' },
        // 第二条故意带"撤稿没撤下来"的待办：检查回收站把这个事实亮出来，并给出重试入口
        { id: 932, key: 'project:932', name: '不再使用时删掉的旧项目', brand_name: '示例品牌', deleted_at: '2026-09-21T01:00:00Z', purge_at: '2026-09-28T01:00:00Z', days_left: 7, type: 'project', type_label: '项目', expired: false, restorable: true, articles_count: 0, skill_executions_count: 1, takedown: { online: 2, failed: 2, last_error: 'WP delete returned HTTP 500（本地样例）', remote_urls: ['https://example.invalid/blog/post-1'] }, restore_url: '/api/v1/projects/932/restore' },
        { id: 241, key: 'article:241', type: 'article', type_label: '文章', name: '示例两日游行程安排（已删除的本地样例文章）', project_name: '示例文旅 AI 传播演示', original_status: 'draft', status_label: '草稿', deleted_at: '2026-09-22T03:00:00Z', purge_at: '2026-09-29T03:00:00Z', days_left: 7, expired: false, restorable: true, restore_url: '/api/v1/articles/241/undelete', takedown: { online: 0, failed: 0, last_error: '', remote_urls: [] } },
      ],
    });
    const sampleProject = path.match(/^\/api\/v1\/projects\/(90[2-8])$/);
    if (sampleProject) return response(projectSamples.find(item => item.id === Number(sampleProject[1])));
    if (/^\/api\/v1\/projects\/90[1-8]\/timeline$/.test(path)) return response(project.stages.map(stage => ({
      stage_index: stage.stage_index, stage_key: stage.stage_key, status: stage.status, progress: stage.progress,
      started_at: stage.started_at, completed_at: stage.completed_at,
      skills: skills.filter(skill => Number(skill.stage_index) === stage.stage_index).map(skill => skill.name),
    })));
    if (/^\/api\/v1\/projects\/90[2-8]\/(analyses|articles|executions|timeline|channels)$/.test(path)) return response({ data: [] });
    if (path === '/api/v1/projects/901') return response(project);
    if (path === '/api/v1/skills') return response({ data: skills });
    if (path === '/api/v1/publishing/channels') return response({ data: [
      { id: 951, name: '文旅内容托管页（本地样例）', type: 'hosted_page', is_active: true, created_at: '2026-09-09T01:00:00Z' },
      { id: 952, name: '文旅网站 WordPress（本地样例）', type: 'wordpress_rest', is_active: false, endpoint_url: 'https://example.invalid/wp-json/wp/v2', created_at: '2026-09-09T01:00:00Z' },
      { id: 953, name: '内容分发 Agent（本地样例）', type: 'geoflow_agent', is_active: true, endpoint_url: 'https://example.invalid/agent', created_at: '2026-09-09T01:00:00Z' },
    ] });
    if (path === '/api/v1/publishing/project-channels') return response({ mode: 'bound', bound_channel_ids: [951] });
    if (/\/publishing\/channels\/95[1-3]\/logs$/.test(path)) return response({ data: [{ level: 'warning', event: 'preview', message: '仅用于检查日志版式，未执行真实分发。', created_at: '2026-09-09T01:00:00Z' }] });
    // Standalone access statistics: fixed samples, including local filtering/pagination.
    if (path === '/api/v1/analytics/crawler-breakdown') return response({
      kpis: { pv: 12345, unique_ip: 876, ai_bot_pv: 3200, search_bot_pv: 1800, human_pv: 6800 },
      period: { from: '2026-09-04', to: '2026-09-10（固定样例）' },
      traffic_breakdown: [{ key: 'human', count: 6800 }, { key: 'ai_bot', count: 3200 }, { key: 'search_bot', count: 1800 }, { key: 'other_bot', count: 400 }, { key: 'unknown', count: 145 }],
      top_ai_bots: [{ bot_name: '示例 AI 爬虫 A', hits: 2400, articles_reached: 32 }, { bot_name: '用于检查窄屏换行的较长爬虫名称（本地样例）', hits: 800, articles_reached: 12 }],
    });
    if (path === '/api/v1/analytics/traffic-trend') return response({ trend: [1200, 1800, 1400, 2500, 1700, 2100, 1645].map((pv, i) => ({ date: '2026-09-' + String(i + 4).padStart(2, '0'), pv, ai_bot_pv: [300, 480, 360, 650, 400, 610, 400][i] })) });
    if (path === '/api/v1/analytics/top-articles') return response({ data: [{ article_id: 201, title: '示例文化体验与亲子出游资料整理（本地样例）', views: 1200, unique_ip: 360 }, { article_id: 202, title: '较长标题：出游前需要确认的开放时间、交通安排与预约信息（本地样例）', views: 860, unique_ip: 240 }] });
    if (path === '/api/v1/analytics/channel-beacon-health') return response({ beacon_threshold_days: 7, data: [{ channel_id: 951, channel_name: '文旅内容站（样例）', last_beacon_at: '2026-09-10T01:00:00Z', beaconing: true, hits_7d: 120 }, { channel_id: 952, channel_name: '未回传渠道（样例）', last_beacon_at: null, beaconing: false, hits_7d: 0 }] });
    if (path === '/api/v1/analytics/visit-logs') {
      const samples = Array.from({ length: 32 }, (_, i) => ({ created_at: '2026-09-10T01:' + String(i).padStart(2, '0') + ':00Z', traffic_type: ['human', 'ai_bot', 'search_bot', 'other_bot', 'unknown'][i % 5], bot_name: i % 5 === 1 ? '示例 AI 爬虫 A' : null, path: '/articles/' + (201 + i % 2) + (i === 0 ? '/long-path-for-local-responsive-layout-preview' : ''), ip_address: '192.0.2.' + (i + 1), status_code: i === 5 ? 404 : 200 }));
      const filtered = samples.filter(item => (!url.searchParams.get('traffic_type') || item.traffic_type === url.searchParams.get('traffic_type')) && (!url.searchParams.get('path') || item.path.includes(url.searchParams.get('path'))));
      const page = Math.max(1, Number(url.searchParams.get('page')) || 1);
      return response({ data: filtered.slice((page - 1) * 25, page * 25), meta: { current_page: page, last_page: Math.max(1, Math.ceil(filtered.length / 25)), total: filtered.length } });
    }
    if (path === '/api/v1/analytics/summary') return response({ metrics: { projects_active: 1, projects_total: 1, articles_total: 6, articles_published: 1, executions_total: 12, executions_success: 10, tokens_used: 128000, publications_total: 2, publications_published: 1 } });
    if (path === '/api/v1/analytics/trends') return response({ trends: { articles: [{ date: '2026-09-08', count: 4 }, { date: '2026-09-09', count: 2 }], executions: [{ date: '2026-09-08', count: 7, success_count: 6 }, { date: '2026-09-09', count: 5, success_count: 4 }] } });
    if (path === '/api/v1/analytics/model-usage') return response({ summary: { total_calls: 12, total_tokens: 128000 }, by_model: [{ model: '本地样例模型', total_calls: 12, completed_calls: 10, total_tokens: 128000 }] });
    if (path === '/api/v1/analytics/by-project') return response({ projects: [{ project_name: project.name, brand_name: project.brand_name, articles: { total: 6 }, executions: { total: 12, success_rate: 83.3 }, publications: { period: 2 } }] });
    if (path === '/api/v1/analytics/distribution') return response({ metrics: { channels_total: 3, channels_active: 2, publications_total: 2, success_rate: 0.5 }, recent_publications: [{ article_title: contentArticles[4].title, channel_name: '托管页（本地样例）', status: 'published' }, { article_title: contentArticles[3].title, channel_name: 'WordPress（本地样例）', status: 'failed' }] });
    if (path === '/api/v1/dashboard/activity') return response({ data: [{ project_id: project.id, type: 'execution', skill_name: '解释型文章（本地样例）', status: 'completed', project_name: project.name, created_at: '2026-09-09T01:00:00Z' }] });
    if (path === '/api/v1/dashboard/usage') return response({ quotas: { projects: { current: 1, limit: 10, percent: 10 }, articles: { current: 6, limit: null }, skill_executions: { current: 12 }, tokens: { current: 128000, limit: null } } });
    if (path === '/api/v1/tasks') return response({ data: [
      { id: 501, name: '每周文旅问答', skill_id: 'yao-geo-explainer-builder', status: 'active', cadence: 'weekly', auto_publish: false, auto_distribute: true, query: '示例出游常见问题', next_run_at: '2026-09-14T01:00:00Z' },
      { id: 502, name: '亲子线路内容整理', skill_id: 'yao-geo-explainer-builder', status: 'paused', cadence: 'daily', auto_publish: false, auto_distribute: false },
    ] });
    if (path === '/api/v1/tasks/runs') return response({ data: [{ id: 601, task_id: 501, status: 'completed', article_title: contentArticles[0].title, metadata: { token_usage: 1200 }, started_at: '2026-09-09T01:00:00Z', finished_at: '2026-09-09T01:02:00Z' }] });
    if (path === '/api/v1/ai-keys') return response({
      data: [{ id: 801, slot: 'text', provider: 'openai', model: 'example-model', base_url: 'https://api.example.com/v1', label: '示例模型配置', key_preview: '•••• 本地示例', is_active: true }],
      presets: { openai: { name: 'OpenAI', base_url: 'https://api.example.com/v1', json_mode: true } },
    });
    if (path === '/api/v1/ai-keys/magpie') return response({
      targets: [],
      profiles: [],
      bindings: { text: { source_type: 'legacy', profile_id: null }, vision: { source_type: 'legacy', profile_id: null } },
      local_connect_available: false,
    });
    if (path === '/api/v1/knowledge-bases') return response({ data: previewKnowledgeBases.filter(kb => kb.project_id === knowledgeProjectId) });
    if (path === '/api/v1/knowledge-bases/stats') return response({ knowledge_bases_count: previewKnowledgeBases.filter(kb => kb.project_id === knowledgeProjectId).length, total_documents: 0, ready_documents: 0, processing_documents: 0 });
    // 知识库详情的文档列表要有样例：空列表走的是空态分支，渲染不到状态徽标 / 时间 / 失败原因
    // 这几条真实路径（2026-09-21：拆分后这三条路径曾因漏解构 formatDate 而炸）。
    if (/^\/api\/v1\/knowledge-bases\/\d+\/documents$/.test(path)) return response({ data: [
      { id: 881, name: '示例文旅景点资料（本地样例）.pdf', status: 'ready', chunk_count: 42, created_at: '2026-09-10T01:00:00Z', error_message: null },
      { id: 882, name: '研学机构名单（本地样例）.docx', status: 'processing', chunk_count: 0, created_at: '2026-09-10T02:00:00Z', error_message: null },
      { id: 883, name: '旧版宣传稿（本地样例）.pdf', status: 'failed', chunk_count: 0, created_at: '2026-09-09T01:00:00Z', error_message: '本地样例：PDF 加密，无法提取正文。' },
    ] });
    if (path === '/api/v1/enterprise-knowledge') return response({ data: knowledgeProjectId === 901 ? [{ id: 711, project_id: 901, name: '文旅服务知识档案', status: 'draft', sources_count: 1, revisions_count: 1, created_at: '2026-09-09T01:00:00Z' }] : [] });
    if (path === '/api/v1/enterprise-knowledge/711') return response({ data: { id: 711, name: '文旅服务知识档案', status: 'draft', draft_content: '本地示例草稿：整理游客常见问题，标明待核实的价格和开放时间。', sources: [{ original_name: '出游说明', content: '示例来源内容', file_type: 'text' }] }, validation_items: [{ label: '内容已填写', ok: true }, { label: '补充资料来源', ok: false, detail: '本地样例：关键事实仍需核实。' }] });
    if (path === '/api/v1/sensitive-words') return response({ data: [{ id: 811, word: '示例警告词', severity: 'warning' }, { id: 812, word: '示例拦截词', severity: 'blocked' }] });
    if (path === '/api/v1/skill-prompts') return response({ data: [{ id: 821, skill_id: 'yao-geo-explainer-builder', is_active: true, version: 2 }] });
    if (path === '/api/v1/skill-prompts/yao-geo-explainer-builder') return response({ data: { id: 821, prompt_text: '以清晰的结构解释问题，列出需要核实的事实与来源。此处是本地样式预览文本。', is_active: true, version: 2 } });
    if (path.startsWith('/api/v1/skill-prompts/')) return response({ message: '尚未覆盖，使用系统默认。' }, 404);
    if (path === '/api/v1/projects/901/analyses') return response({ data: [] });
    if (path === '/api/v1/executions') return response({ data: !url.searchParams.get('project_id') || url.searchParams.get('project_id') === '901' ? [...(previewRunning ? [runningExecution] : []), execution] : [] });
    if (path === '/api/v1/executions/701') return response(execution);
    if (previewRunning && path === '/api/v1/executions/702') return response(runningExecution);
    if (previewRunning && path === '/api/v1/executions/702/status') return response({ execution: runningExecution, is_terminal: false });
    if (path === '/api/v1/articles/101') return response(article);
    const historyId = path.match(/^\/api\/v1\/articles\/(10[2-4])$/);
    if (historyId) return response(historyReports.find(item => item.id === Number(historyId[1])));
    const contentId = path.match(/^\/api\/v1\/articles\/(20[1-6])$/);
    if (contentId) return response(contentArticles.find(item => item.id === Number(contentId[1])));
    if (/\/articles\/20[1-6]\/reviews$/.test(path)) return response({ data: [{ review_status: 'changes_requested', reviewer_name: '示例审核人', review_note: '核实来源后重新提交。', created_at: '2026-09-09T13:00:00Z' }] });
    if (/\/articles\/20[1-6]\/versions$/.test(path)) return response({ data: [{ id: 401, version: 1, creator_name: '示例编辑', created_at: '2026-09-09T12:00:00Z' }, { id: 402, version: 2, creator_name: '示例编辑', created_at: '2026-09-10T01:00:00Z' }] });
    if (path === '/api/v1/articles/205/publications') return response({ data: [{ id: 951, status: 'published', channel: { name: '站内演示发布（本地样例）', type: 'hosted_page' }, remote_url: '/reading/' }] });
    if (/\/articles\/20[1-6]\/(images|publications)$/.test(path)) return response({ data: [] });
    if (path === '/api/v1/projects/901/articles') {
      const status = url.searchParams.get('status');
      const search = url.searchParams.get('search') || '';
      const source = url.searchParams.get('source_type');
      const type = url.searchParams.get('content_type');
      const items = [...historyReports, ...contentArticles].sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at)).filter(item =>
        (status ? item.status === status : item.status !== 'recycled') &&
        (!source || item.source_type === source) &&
        (!type || item.content_type === type) &&
        (!search || `${item.title} ${item.summary || ''} ${item.body_markdown}`.includes(search)));
      return response({ data: items, current_page: 1, last_page: 1, total: items.length });
    }
    const materialType = path.match(/^\/api\/v1\/materials\/(titles|keywords|images|authors)$/);
    if (materialType) return response({ data: materialSamples[materialType[1]] });
    if (/\/(channels|publishing-channels|articles|materials)$/.test(path)) return response({ data: [], current_page: 1, last_page: 1, total: 0 });
    return response({ message: '该功能未提供样式预览数据，未访问真实服务。' }, 503);
  };
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (link && new URL(link.href).origin !== location.origin) event.preventDefault();
  }, true);
})();
