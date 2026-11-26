(() => {
  "use strict";

  const GEO = window.GEO;
  if (!GEO) return;
  const { api, elements, setMessage, state, validationMessage } = GEO;
  const endpoint = "/api/v1/ai-keys/magpie";
  const editors = [...(elements.aikeyList?.querySelectorAll("[data-magpie-slot]") || [])].map((section) => {
    const form = section.querySelector(".model-magpie-form");
    return {
      section,
      form,
      slot: section.dataset.magpieSlot,
      fields: form.elements,
      actions: Object.fromEntries([...form.querySelectorAll("[data-magpie-action]")].map((button) => [button.dataset.magpieAction, button])),
      message: section.querySelector("[data-magpie-message]"),
      direct: section.querySelector("[data-use-direct]"),
      localConnect: section.querySelector("[data-magpie-local-connect]"),
      profile: null,
      binding: null,
      catalog: null,
      catalogFailed: false,
      pending: null,
      currentRequest: null,
      draftTouched: false,
    };
  });
  if (!editors.length) return;

  let magpieSettingsOwner = "";
  let targets = [];
  let loadSequence = 0;
  let localConnectAvailable = false;
  let localConnectRequest = null;

  function currentOwner() {
    return `${state.session?.tenant?.id ?? ""}:${state.session?.user?.id ?? ""}`;
  }

  function selectedTarget(editor) {
    return targets.find((target) => target.alias === editor.fields.target_alias.value);
  }

  function sameEndpoint(profile, target) {
    return profile?.target_alias === target?.alias && profile?.base_url === target?.base_url && profile?.auth_mode === target?.auth_mode;
  }

  function authorizedProfile(profile, target) {
    return Boolean(profile && target && sameEndpoint(profile, target) && profile.target_revision === target.revision);
  }

  function hasDraft(editor) {
    return !authorizedProfile(editor.profile, selectedTarget(editor))
      || editor.fields.api_key.value.trim() !== ""
      || editor.fields.model.value !== (editor.profile?.model || "");
  }

  function showCatalog(editor, model) {
    const records = editor.catalog?.models || [];
    const option = (label, id) => new Option(label, id);
    const modelOption = (record) => {
      const label = record.display_name && record.display_name !== record.id ? `${record.display_name} · ${record.id}` : record.id;
      if (editor.slot !== "vision" || record.image_input === true) return option(label, record.id);
      const unavailable = option(`${label} · ${record.image_input === false ? "不支持图片" : "图片能力未确认"}`, record.id);
      unavailable.disabled = true;
      return unavailable;
    };
    const options = records.map(modelOption);
    if (model && !records.some((record) => record.id === model)) {
      const savedModel = option(model, model);
      savedModel.disabled = Boolean(editor.catalog);
      options.unshift(savedModel);
    }
    editor.fields.model.replaceChildren(option("选择目录中的模型", ""), ...options);
    editor.fields.model.value = model;
    editor.fields.model.disabled = !editor.catalog?.models.length;
    const selected = records.find((record) => record.id === model);
    let detail = model;
    if (editor.catalog) {
      if (!records.length) detail = model ? `${model} · 当前目录为空，已保存的模型不可用。` : "当前目录为空。";
      else if (model && !selected) detail = `${model} · 已不在当前目录，请选择可用模型。`;
      else if (selected && editor.slot === "vision" && selected.image_input !== true) {
        detail = `${model} · ${selected.image_input === false ? "不支持图片" : "图片能力未确认"}。`;
      } else if (!model && editor.slot === "vision" && !records.some((record) => record.image_input === true)) {
        detail = "当前目录没有已确认支持图片输入的模型。";
      }
    } else {
      detail = model ? `${model} · 等待读取目录。` : "";
    }
    editor.section.querySelector("[data-magpie-model-status]").textContent = detail;
  }

  function catalogContains(editor, model) {
    return editor.catalog?.models.some((record) =>
      record.id === model && (editor.slot !== "vision" || record.image_input === true));
  }

  function renderLocalConnectControls() {
    const busy = Boolean(localConnectRequest || editors.some((editor) => editor.pending));
    for (const editor of editors) {
      if (!editor.localConnect) continue;
      editor.localConnect.disabled = !localConnectAvailable || busy || magpieSettingsOwner !== currentOwner();
      editor.localConnect.textContent = localConnectRequest ? "正在连接本机 Magpie…" : "连接本机 Magpie";
    }
  }

  function render(editor) {
    const { section, form, fields, profile, binding, actions, direct, catalog, pending } = editor;
    const target = selectedTarget(editor);
    const savedEndpoint = Boolean(profile && sameEndpoint(profile, target));
    const authorizedEndpoint = authorizedProfile(profile, target);
    const gateway = target?.auth_mode === "gateway_key";
    const activeProfile = binding?.source_type === "profile";
    const usesProfile = activeProfile && profile && profile.id === binding.profile_id;
    const busy = Boolean(pending || localConnectRequest);
    const knownUnavailable = catalog && !catalogContains(editor, profile?.model);
    const sourceLabel = activeProfile
      ? (usesProfile && profile.enabled && profile.model && !knownUnavailable && !editor.catalogFailed && targets.some((item) => authorizedProfile(profile, item))
        ? "当前使用 Magpie" : "当前选择 Magpie · 连接不可用")
      : binding?.source_type === "disabled" ? "当前模型已停用" : "当前选择第三方直连";
    section.querySelector("[data-active-model-source]").textContent = sourceLabel;
    direct.hidden = !activeProfile && binding?.source_type !== "disabled";
    direct.disabled = busy;

    form.hidden = targets.length === 0;
    section.querySelector("[data-magpie-authorization]").textContent = !targets.length
      ? (editor.localConnect ? "尚未连接本机 Magpie。请先启动 Magpie，再点击“连接本机 Magpie”。"
        : "当前工作区未获得 Magpie 部署授权。请联系部署者配置可信目标。")
      : profile && !targets.some((item) => authorizedProfile(profile, item))
        ? (editor.localConnect ? "已保存的连接授权已变更。请重新连接本机 Magpie，再保存连接并读取目录。"
          : "已保存的目标授权已变更。请选择部署者授权的目标并保存。")
        : (editor.localConnect
          ? (catalog
            ? (catalog.models.length ? `已连接${gateway ? " Magpie" : "本机 Magpie"}，请从目录选择并保存模型。`
              : "已连接 Magpie，当前模型目录为空。请在 Magpie 中配置模型后刷新目录。")
            : profile ? "已允许连接 Magpie。请点击“检测服务并读取目录”，确认可选模型。"
              : "已允许连接 Magpie。请点击“连接本机 Magpie”，检测服务并读取目录。")
          : "当前工作区已获得 Magpie 部署授权。");
    section.querySelector("[data-magpie-endpoint]").textContent = target?.base_url || "";
    section.querySelector("[data-magpie-key]").hidden = !gateway;
    fields.api_key.disabled = !gateway;
    fields.api_key.required = gateway && !(authorizedEndpoint && profile.key_preview);
    section.querySelector("[data-magpie-key-status]").textContent = authorizedEndpoint && profile.key_preview
      ? `已保存 ${profile.key_preview}。留空保留当前目标的 Key。`
      : "请填写部署授权目标对应的 Magpie Gateway Key。";

    const connectionChanged = !authorizedEndpoint || fields.api_key.value.trim() !== "";
    const mutating = localConnectRequest || (pending && pending !== "directory");
    form.setAttribute("aria-busy", String(busy));
    fields.target_alias.disabled = Boolean(mutating);
    if (gateway) fields.api_key.disabled = Boolean(mutating);
    fields.model.disabled = Boolean(mutating || connectionChanged || !catalog?.models.length);
    const unsupportedDraft = fields.model.value && fields.model.value !== profile?.model && !catalogContains(editor, fields.model.value);
    actions.save.disabled = Boolean(busy || !target || !hasDraft(editor) || unsupportedDraft);
    actions.directory.disabled = Boolean(busy || connectionChanged || !profile?.enabled);
    actions.directory.textContent = catalog ? "刷新模型目录" : "检测服务并读取目录";
    actions.activate.hidden = usesProfile || !profile?.model || !profile.enabled;
    actions.activate.disabled = Boolean(busy || hasDraft(editor) || knownUnavailable);
    actions.toggle.hidden = !savedEndpoint;
    actions.toggle.textContent = profile?.enabled ? "停用 Magpie 连接" : "启用 Magpie 连接";
    actions.toggle.disabled = Boolean(busy || hasDraft(editor));
    renderLocalConnectControls();
    GEO.syncModelConfigUi?.();
  }

  function applyProfile(editor, profile) {
    editor.profile = profile;
    editor.catalog = null;
    editor.catalogFailed = false;
    editor.draftTouched = false;
    editor.fields.api_key.value = "";
    editor.fields.target_alias.value = targets.some((target) => sameEndpoint(profile, target))
      ? profile.target_alias : targets[0]?.alias || "";
    showCatalog(editor, sameEndpoint(profile, selectedTarget(editor)) ? profile?.model || "" : "");
  }

  function clearEditor(editor) {
    editor.binding = null;
    editor.pending = null;
    editor.currentRequest = null;
    editor.fields.target_alias.replaceChildren();
    applyProfile(editor, null);
    render(editor);
    setMessage(editor.message, "");
  }

  function applySettings(body, preserveDrafts = false) {
    const previousTargets = targets;
    targets = body.targets || [];
    localConnectAvailable = body.local_connect_available === true;
    for (const editor of editors) {
      if (editor.pending && editor.pending !== "directory") continue;
      const profile = (body.profiles || []).find((row) => row.slot === editor.slot) || null;
      editor.binding = body.bindings?.[editor.slot] || null;
      const fields = editor.fields;
      const keepDraft = preserveDrafts && editor.draftTouched && hasDraft(editor);
      const draftModel = fields.model.value;
      const selectedAlias = fields.target_alias.value;
      fields.target_alias.replaceChildren(...targets.map((target) => new Option(target.alias, target.alias)));
      fields.target_alias.value = selectedAlias;
      const previousTarget = previousTargets.find((target) => target.alias === selectedAlias);
      const target = selectedTarget(editor);
      const targetChanged = !target || target.base_url !== previousTarget?.base_url
        || target.auth_mode !== previousTarget?.auth_mode || target.revision !== previousTarget?.revision;
      if (keepDraft) {
        if (targetChanged || editor.profile?.id !== profile?.id || editor.profile?.revision !== profile?.revision) {
          editor.catalog = null;
          editor.catalogFailed = false;
          showCatalog(editor, draftModel);
        }
        render(editor);
        continue;
      }
      if (targetChanged || editor.profile?.id !== profile?.id || editor.profile?.revision !== profile?.revision) {
        editor.pending = null;
        editor.currentRequest = null;
        applyProfile(editor, profile);
        setMessage(editor.message, "");
      }
      render(editor);
    }
    renderLocalConnectControls();
  }

  async function loadMagpieSettings() {
    if (localConnectRequest && magpieSettingsOwner === currentOwner()) return;
    const sequence = ++loadSequence;
    const identity = currentOwner();
    if (identity !== magpieSettingsOwner) {
      magpieSettingsOwner = identity;
      targets = [];
      localConnectAvailable = false;
      localConnectRequest = null;
      editors.forEach((editor) => {
        clearEditor(editor);
        editor.section.querySelector("[data-active-model-source]").textContent = "正在读取当前来源…";
        editor.section.querySelector("[data-magpie-authorization]").textContent = editor.localConnect ? "正在读取本机连接状态…" : "正在读取部署授权…";
      });
    }
    try {
      const body = await api(endpoint);
      if (sequence !== loadSequence || identity !== currentOwner()) return;
      applySettings(body);
    } catch (error) {
      if (sequence !== loadSequence || identity !== currentOwner()) return;
      const forbidden = error.status === 403;
      if (forbidden) { targets = []; localConnectAvailable = false; }
      for (const editor of editors) {
        if (forbidden) {
          clearEditor(editor);
          editor.section.querySelector("[data-active-model-source]").textContent = "";
        }
        editor.section.querySelector("[data-magpie-authorization]").textContent = "无法读取 Magpie 部署授权。";
        setMessage(editor.message, forbidden ? "当前角色不能管理模型配置。" : "无法加载 Magpie 配置，请刷新重试。", "danger");
      }
      renderLocalConnectControls();
    }
  }

  async function connectLocalMagpie(editor) {
    if (!localConnectAvailable || localConnectRequest || editors.some((editor) => editor.pending)
      || !magpieSettingsOwner || magpieSettingsOwner !== currentOwner()) return;
    const identity = magpieSettingsOwner;
    const keepDraft = editor.draftTouched && hasDraft(editor);
    const savedProfile = editor.profile;
    const request = Symbol("local-connect");
    const isCurrent = () => localConnectRequest === request && identity === currentOwner() && identity === magpieSettingsOwner;
    localConnectRequest = request;
    loadSequence++;
    editors.forEach((editor) => {
      render(editor);
      setMessage(editor.message, "正在检测本机 Magpie 并建立连接授权…");
    });
    let directoryRequested = false;
    try {
      const result = await api(`${endpoint}/local-connect`, {
        method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ consent: true }),
      });
      if (!isCurrent()) return;
      if (result.service?.name !== "magpie" || typeof result.service?.version !== "string" || !result.service.version.trim()) {
        throw new Error("未能确认本机 Magpie 的服务身份，请刷新配置后再检查。");
      }
      applySettings(result, true);
      const version = result.service?.version ? ` ${result.service.version}` : "";
      editors.forEach((item) => setMessage(item.message,
        `检测到 Magpie${version}，已允许本机连接。尚未读取模型目录，请保存连接并读取目录确认可用模型。当前来源未切换。`, "success"));
      const local = result.local_target;
      if (!local || local.alias !== result.local_target_alias || !targets.some((item) => item.alias === local.alias && item.revision === local.revision)) {
        throw new Error("本机连接信息未确认，请刷新配置后再检查。");
      }
      if (keepDraft || (savedProfile && !authorizedProfile(savedProfile, local))) {
        setMessage(editor.message, `检测到 Magpie${version}。已保留你现有的连接和未保存内容，请处理后再选择本机连接并读取目录；当前来源未切换。`);
        return;
      }
      let profile = editor.profile;
      if (profile && (!authorizedProfile(profile, local) || !profile.enabled)) {
        setMessage(editor.message, "已允许本机连接。请先核对并启用已保存的连接，再读取目录；当前来源未切换。");
        return;
      }
      if (!profile) {
        setMessage(editor.message, "已检测到本机 Magpie，正在准备当前用途的连接…");
        profile = await api(`${endpoint}/profiles`, {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ slot: editor.slot, target_alias: local.alias, model: null }),
        });
        if (!isCurrent()) return;
        applyProfile(editor, profile);
        render(editor);
      }
      setMessage(editor.message, `已检测到 Magpie${version}，正在读取模型目录…`);
      directoryRequested = true;
      const directory = await api(`${endpoint}/profiles/${encodeURIComponent(profile.id)}/directory`, {
        method: "POST", headers: { "Content-Type": "application/json" }, body: "{}",
      });
      if (!isCurrent()) return;
      if (directory.revision !== profile.revision) throw new Error("连接配置已变更，请刷新后读取目录。");
      editor.catalog = { models: directory.models || [] };
      editor.catalogFailed = false;
      showCatalog(editor, editor.fields.model.value);
      setMessage(editor.message, `检测到 Magpie${version}，目录包含 ${editor.catalog.models.length} 个聊天模型。请选择并保存模型，再点击“设为${editor.slot === "vision" ? "视觉" : "文本"}模型”；当前来源未切换。`, "success");
    } catch (error) {
      if (!isCurrent()) return;
      if (directoryRequested) {
        editor.catalog = null;
        editor.catalogFailed = true;
        showCatalog(editor, editor.fields.model.value);
      }
      editors.forEach((editor) => setMessage(editor.message,
        `${validationMessage(error)} 请确认 Magpie 已启动，刷新配置后再检查连接；当前来源未切换。`, "danger"));
    } finally {
      if (isCurrent()) {
        localConnectRequest = null;
        editors.forEach(render);
      }
    }
  }

  function connectionEdited(editor, targetChanged) {
    editor.draftTouched = true;
    const model = sameEndpoint(editor.profile, selectedTarget(editor)) ? editor.profile?.model || "" : "";
    if (targetChanged) editor.fields.api_key.value = "";
    if (editor.pending === "directory") {
      editor.pending = null;
      editor.currentRequest = null;
    }
    editor.catalog = null;
    editor.catalogFailed = false;
    showCatalog(editor, model);
    setMessage(editor.message, "");
    render(editor);
  }

  async function perform(editor, action) {
    if (editor.pending || localConnectRequest || !magpieSettingsOwner || magpieSettingsOwner !== currentOwner()) return;
    if (action === "save" && (!selectedTarget(editor) || !hasDraft(editor) || !editor.form.reportValidity())) return;
    const { fields, profile } = editor;
    const identity = magpieSettingsOwner;
    const request = Symbol(action);
    const target = selectedTarget(editor);
    const body = {};
    const profilePath = profile ? `${endpoint}/profiles/${encodeURIComponent(profile.id)}` : `${endpoint}/profiles`;
    let path = profilePath;
    let method = profile ? "PATCH" : "POST";
    let progress = "正在保存 Magpie 配置…";
    const secret = action === "save" ? fields.api_key.value.trim() : "";
    if (action === "save") {
      body.target_alias = target.alias;
      if (!profile) body.slot = editor.slot;
      if (profile) body.revision = profile.revision;
      if (secret && target.auth_mode === "gateway_key") body.api_key = secret;
      if (fields.model.value !== (profile?.model || "")) body.model = fields.model.value || null;
    } else if (action === "toggle") {
      progress = "正在更新 Magpie 连接状态…";
      Object.assign(body, { enabled: !profile.enabled, revision: profile.revision });
    } else if (action === "directory") {
      progress = "正在检测 Magpie 并读取目录…";
      path = `${profilePath}/directory`;
      method = "POST";
    } else {
      progress = "正在切换当前来源…";
      path = `${endpoint}/bindings/${editor.slot}`;
      method = "PUT";
      body.source_type = action === "activate" ? "profile" : "legacy";
      if (action === "activate") Object.assign(body, { profile_id: profile.id, revision: profile.revision });
    }

    const isCurrent = () => identity === currentOwner() && identity === magpieSettingsOwner
      && editor.currentRequest === request;
    if (action !== "directory") loadSequence++;
    editor.pending = action;
    editor.currentRequest = request;
    render(editor);
    setMessage(editor.message, progress);
    try {
      const result = await api(path, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
      if (!isCurrent()) return;
      if (action === "directory") {
        if (result.revision !== profile.revision) {
          throw new Error("连接配置已变更，请刷新页面后读取目录。");
        }
        editor.catalog = { models: result.models || [] };
        editor.catalogFailed = false;
        showCatalog(editor, fields.model.value);
        setMessage(editor.message, `Magpie ${result.version} · 目录包含 ${editor.catalog.models.length} 个聊天模型。`, "success");
      } else if (action === "save" || action === "toggle") {
        applyProfile(editor, result);
        setMessage(editor.message, action === "save"
          ? "Magpie 配置已保存。" : (result.enabled ? "Magpie 连接已启用。" : "Magpie 连接已停用。"), "success");
      } else {
        editor.binding = result;
        setMessage(editor.message, action === "activate" ? "当前来源已切换到 Magpie。" : "当前来源已切回第三方直连。", "success");
      }
    } catch (error) {
      if (!isCurrent()) return;
      if (action === "directory") {
        editor.catalog = null;
        editor.catalogFailed = true;
        showCatalog(editor, fields.model.value);
      }
      const message = validationMessage(error);
      const safeMessage = secret ? message.replaceAll(secret, "••••") : message;
      setMessage(editor.message, action === "directory" ? `${safeMessage} 请重新读取目录。` : safeMessage, "danger");
    } finally {
      if (isCurrent()) {
        editor.pending = null;
        editor.currentRequest = null;
        render(editor);
      }
    }
  }

  for (const editor of editors) {
    editor.localConnect?.addEventListener("click", () => connectLocalMagpie(editor));
    editor.form.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!editor.actions.save.disabled) void perform(editor, "save");
    });
    editor.fields.target_alias.addEventListener("change", () => connectionEdited(editor, true));
    editor.fields.api_key.addEventListener("input", () => connectionEdited(editor, false));
    editor.fields.model.addEventListener("change", () => {
      editor.draftTouched = true;
      showCatalog(editor, editor.fields.model.value);
      render(editor);
    });
    for (const action of ["directory", "activate", "toggle"]) {
      editor.actions[action].addEventListener("click", () => perform(editor, action));
    }
    editor.direct.addEventListener("click", () => perform(editor, "legacy"));
  }
  elements.reloadAiKeysButton?.addEventListener("click", loadMagpieSettings);
  GEO.registerViewLoader("aikeys", loadMagpieSettings);
})();
