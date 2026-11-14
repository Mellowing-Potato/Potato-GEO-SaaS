(() => {
  'use strict';

  const isEditorPage = location.hostname === 'zhuanlan.zhihu.com'
    && (location.pathname === '/write' || /^\/p\/\d+\/edit$/.test(location.pathname));
  if (! isEditorPage) return;

  const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const draftUrl = () => /^\/p\/\d+\/edit$/.test(location.pathname)
    ? location.origin + location.pathname : null;
  const canonicalProfile = (value) => {
    try {
      const url = new URL(value);
      if (url.protocol !== 'https:' || url.username || url.password
        || ! ['zhihu.com', 'www.zhihu.com'].includes(url.hostname)
        || ! /^\/people\/[^/]+\/?$/.test(url.pathname)) return '';
      return url.pathname.replace(/\/$/, '').toLowerCase();
    } catch {
      return '';
    }
  };

  async function waitFor(selector, timeoutMs = 8000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const element = document.querySelector(selector);
      if (element) return element;
      await pause(150);
    }
    return null;
  }

  async function observedProfile(expectedProfile) {
    const button = await waitFor('button.AppHeader-profileEntry');
    if (! button) return { profile: '', stable: false };
    // The header and its menu can render before the signed-in user's data hydrates.
    // Do not compare the first link that happens to exist, or read a hidden/stale menu.
    const visibleLinks = () => [...document.querySelectorAll('a.AppHeaderProfileMenu-item[href*="/people/"]')]
      .filter((link) => link.getClientRects().length > 0);
    const visibleProfiles = () => visibleLinks().map((link) => canonicalProfile(link.href)).filter(Boolean);
    const openedMenu = visibleLinks().length === 0;
    if (openedMenu) button.click();
    let profile = '';
    let stableSince = Date.now();
    let lastOpenAttempt = stableSince;
    let menuSeen = !openedMenu;
    const deadline = stableSince + 8000;
    try {
      while (Date.now() < deadline) {
        const now = Date.now();
        const links = visibleLinks();
        menuSeen ||= links.length > 0;
        // A synthetic click can precede the header's event handlers. Retry only
        // while no menu is visible; never toggle a menu that has already opened.
        if (openedMenu && links.length === 0 && now - lastOpenAttempt >= 500) {
          const currentButton = document.querySelector('button.AppHeader-profileEntry');
          if (currentButton?.getAttribute('aria-expanded') !== 'true') currentButton?.click();
          lastOpenAttempt = now;
        }
        const profiles = [...new Set(visibleProfiles())];
        const candidate = profiles.length === 1 ? profiles[0] : '';
        if (candidate !== profile) { profile = candidate; stableSince = now; }
        // Matching identities must also remain stable. A different identity gets the
        // whole hydration window before returning a mismatch, and never permits a write.
        if (profile && profile === expectedProfile && now - stableSince >= 750) {
          return { profile, stable: true, menuSeen };
        }
        await pause(150);
      }
      return { profile, stable: !!profile && Date.now() - stableSince >= 750, menuSeen };
    } finally {
      if (openedMenu && visibleProfiles().length > 0) {
        document.querySelector('button.AppHeader-profileEntry')?.click();
      }
    }
  }

  async function insertBody(editor, body) {
    const beforeText = editor.textContent;
    editor.focus();
    const selection = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(editor);
    selection.removeAllRanges();
    selection.addRange(range);
    // Let Draft.js receive the selection change, then paste all paragraphs as one
    // fragment. Multi-line execCommand input can leave only the last paragraph.
    // This DataTransfer is in-memory; no system clipboard permission/read is needed.
    await pause(50);
    if (editor.textContent !== beforeText) return false;
    const data = new DataTransfer();
    data.setData('text/plain', body);
    const event = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: data });
    editor.dispatchEvent(event);
    return event.defaultPrevented;
  }

  function contentWithLinkTargets(node) {
    if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || '';
    if (node.nodeType === Node.ELEMENT_NODE && node.tagName === 'A') {
      // Zhihu resolves pasted URLs into page-title links asynchronously. Verify
      // their actual destinations, not titles that can change after saving.
      const href = node.getAttribute('href');
      if (!href) return null;
      try {
        if (!['http:', 'https:'].includes(new URL(href, location.origin).protocol)) return null;
      } catch { return null; }
      return href;
    }
    const parts = Array.from(node.childNodes, contentWithLinkTargets);
    return parts.includes(null) ? null : parts.join('');
  }

  async function fill(payload) {
    if (document.querySelector('iframe[src*="captcha"], [class*="Captcha"], [class*="captcha"]')) {
      return { ok: false, code: 'human_verification_required' };
    }
    const expectedProfile = canonicalProfile(payload.profileUrl);
    if (! expectedProfile) return { ok: false, code: 'invalid_payload' };
    const identity = await observedProfile(expectedProfile);
    const currentProfile = identity.profile;
    if (! identity.stable) return { ok: false, code: 'account_not_ready',
      identityStage: identity.menuSeen ? 'profile_changing' : 'profile_menu_unavailable' };
    if (currentProfile !== expectedProfile) {
      return { ok: false, code: 'account_mismatch', expectedProfile, currentProfile };
    }
    const title = await waitFor('textarea[placeholder*="标题"]');
    const editor = await waitFor('.public-DraftEditor-content[contenteditable="true"]');
    if (! title || ! editor) return { ok: false, code: 'editor_not_found' };
    if ((title.value.trim() || editor.textContent.trim()) && ! payload.replaceExisting) {
      return { ok: false, code: 'editor_not_empty' };
    }
    if (payload.draftUrl && draftUrl() !== payload.draftUrl) {
      return { ok: false, code: 'wrong_draft' };
    }

    const expectedText = payload.body.replace(/[\s\u200b]+/g, '');
    const contentMatches = () => title.value === payload.title
      && contentWithLinkTargets(editor)?.replace(/[\s\u200b]+/g, '') === expectedText;
    const savedTip = () => document.querySelector('.DraftStatusTip');
    const showsSaved = () => !!savedTip()?.querySelector('.ZDI--CloudCheck24');
    if (payload.draftUrl) {
      // Existing drafts hydrate their content and status separately. Wait for the
      // saved baseline before deciding whether any write is necessary.
      const readyDeadline = Date.now() + 8000;
      let stableSince = null;
      while (Date.now() < readyDeadline) {
        if (showsSaved()) {
          stableSince ??= Date.now();
          if (Date.now() - stableSince >= 750) break;
        } else stableSince = null;
        await pause(150);
      }
      if (draftUrl() !== payload.draftUrl) return { ok: false, code: 'wrong_draft' };
      if (contentMatches() && showsSaved() && stableSince !== null && Date.now() - stableSince >= 750) {
        return { ok: true, code: 'draft_unchanged', saved: true, draftUrl: draftUrl() };
      }
      if (!showsSaved()) {
        return { ok: false, code: 'save_not_ready' };
      }
    }
    const startedOnWrite = location.pathname === '/write';
    let sawSaving = false;
    let bodyEditStarted = false;
    const observer = new MutationObserver(() => {
      const tip = savedTip();
      // Existing drafts have a stable saved baseline above. The status component
      // can disappear during saving instead of keeping a node with saving text.
      if (bodyEditStarted
        && (!tip || ! tip.querySelector('.ZDI--CloudCheck24') || /保存中|正在保存/.test(tip.textContent))) {
        sawSaving = true;
      }
    });
    // The platform can replace the status component while rendering; do not keep
    // reading a detached node or miss the replacement component's save transition.
    observer.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true });

    try {
      const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
      if (! setter) return { ok: false, code: 'insert_failed' };
      setter.call(title, payload.title);
      title.dispatchEvent(new Event('input', { bubbles: true }));
      bodyEditStarted = true;
      if (! await insertBody(editor, payload.body)) return { ok: false, code: 'insert_failed' };

      const insertedAt = Date.now();
      const deadline = insertedAt + 60000;
      while (Date.now() < deadline) {
        await pause(350);
        const tip = savedTip();
        if (!tip || !showsSaved() || /保存中|正在保存/.test(tip.textContent)) sawSaving = true;
        const saved = Date.now() - insertedAt >= 1000 && !!draftUrl()
          && showsSaved()
          && (sawSaving || (startedOnWrite && !!draftUrl()));
        if (contentMatches() && saved) {
          return { ok: true, code: 'draft_saved', saved: true, draftUrl: draftUrl() };
        }
      }
      if (!contentMatches()) {
        return { ok: false, code: 'content_mismatch' };
      }
      return { ok: true, code: 'filled_unconfirmed', saved: false, draftUrl: draftUrl(),
        saveStage: !showsSaved() ? 'platform_not_saved' : 'transition_not_observed' };
    } finally {
      observer.disconnect();
    }
  }

  let busy = false;
  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type !== 'YANJI_FILL_DRAFT') return false;
    if (busy) { sendResponse({ ok: false, code: 'editor_busy' }); return false; }
    busy = true;
    fill(message.payload)
      .then(sendResponse)
      .catch(() => sendResponse({ ok: false, code: 'insert_failed' }))
      .finally(() => { busy = false; });
    return true;
  });
  chrome.runtime.sendMessage({ type: 'YANJI_ZHIHU_READY' }).catch(() => {});
})();
