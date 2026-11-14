/* Reading-only enhancement. The source renderer remains responsible for content and escaping. */
(() => {
  let dispose = () => {};
  const get = (id) => document.getElementById(id);
  const headingSelector = 'h2, h3, h4, h5, h6';
  const chineseNumber = (value) => {
    if (value < 10) return '零一二三四五六七八九'[value];
    for (const [unit, name] of [[1000, '千'], [100, '百'], [10, '十']]) {
      if (value >= unit) {
        const rest = value % unit;
        return (value < 20 ? '' : chineseNumber(Math.floor(value / unit))) + name
          + (rest ? (rest < unit / 10 ? '零' : '') + chineseNumber(rest) : '');
      }
    }
  };

  function numberHeading(heading, number) {
    heading.querySelector(':scope > .reader-heading-number')?.remove();
    let label = heading.querySelector(':scope > .reader-heading-label');
    if (!label) {
      label = document.createElement('span');
      label.className = 'reader-heading-label';
      label.append(...heading.childNodes);
      heading.append(label);
      // Normalize only explicit section prefixes; keep dates and “30/60/90 天” intact.
      const prefix = label.textContent.match(/^\s*(?:第[一二三四五六七八九十百千零〇\d]+[章节][、.．：:\s]*|[一二三四五六七八九十百千零〇]+[、．.]\s*|[（(][一二三四五六七八九十百千零〇\d]+[）)]\s*|\d+(?:\.\d+)*[、．]\s*|\d+(?:\.\d+)*[.)]\s+)/)?.[0];
      if (prefix) {
        const walker = document.createTreeWalker(label, NodeFilter.SHOW_TEXT);
        let remaining = prefix.length;
        while (remaining) {
          const node = walker.nextNode();
          if (!node) break;
          const length = Math.min(remaining, node.length);
          node.deleteData(0, length);
          remaining -= length;
        }
      }
    }
    const marker = document.createElement('span');
    marker.className = 'reader-heading-number';
    marker.textContent = number;
    heading.prepend(marker);
    return label.textContent.trim();
  }

  function reset() {
    dispose();
    dispose = () => {};
    get('readerToc')?.replaceChildren();
    if (get('readerOutline')) get('readerOutline').hidden = true;
    get('readerLayout')?.classList.remove('has-outline');
    if (get('readerOutlineToggle')) get('readerOutlineToggle').hidden = true;
    if (get('readerMore')) get('readerMore').open = false;
  }

  function mount({ outline = true } = {}) {
    reset();
    const body = get('resultPageBody');
    const toc = get('readerToc');
    if (!body || !toc) return;

    body.querySelectorAll('.report-metrics').forEach((metrics) => {
      if (metrics.previousElementSibling?.classList.contains('reader-summary-title')) return;
      const heading = document.createElement('h3');
      heading.className = 'reader-summary-title';
      heading.textContent = '摘要速览';
      metrics.before(heading);
    });
    body.querySelectorAll('.report-table-wrap').forEach((wrap) => {
      wrap.tabIndex = 0;
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('aria-label', `${wrap.closest('.report-section')?.querySelector('h4')?.textContent || '报告'}表格，可横向滚动`);
    });
    body.querySelectorAll('.report-focused-list').forEach((list) => {
      const update = () => {
        const query = list.querySelector('[data-report-search]')?.value.trim().toLocaleLowerCase() || '';
        const intent = list.querySelector('[data-report-filter="intent_type"]')?.value || '';
        const priority = list.querySelector('[data-report-filter="priority"]')?.value || '';
        let count = 0;
        list.querySelectorAll('[data-report-row]').forEach((row) => {
          row.hidden = Boolean((query && !row.textContent.toLocaleLowerCase().includes(query))
            || (intent && row.dataset.intentType !== intent)
            || (priority && row.dataset.priority !== priority));
          if (!row.hidden) count++;
        });
        const label = list.querySelector('[data-report-count]');
        if (label) label.textContent = `显示 ${count} / 完整清单 ${Number(label.dataset.reportTotal) || 0} 条`;
      };
      list.querySelectorAll('input, select').forEach((control) => {
        control.addEventListener('input', update);
        control.addEventListener('change', update);
      });
    });

    const headings = [...body.querySelectorAll(headingSelector)]
      .filter((heading) => heading.textContent.trim() && !heading.closest('table') && !heading.classList.contains('report-title'));
    if (!headings.length || !outline) return;
    let chapter = 0;
    let subsection = 0;
    const links = headings.map((heading, index) => {
      const summary = heading.classList.contains('reader-summary-title');
      const section = heading.closest('.report-section');
      const nested = !summary && chapter > 0 && (section
        ? section.querySelector(headingSelector) !== heading
        : ['H5', 'H6'].includes(heading.tagName));
      let prefix = '';
      if (!summary) {
        if (nested) prefix = `（${chineseNumber(++subsection)}）`;
        else { prefix = `${chineseNumber(++chapter)}、`; subsection = 0; }
      }
      heading.classList.toggle('reader-subheading', nested);
      const title = summary ? heading.textContent.trim() : numberHeading(heading, prefix);
      heading.id = `reader-heading-${index + 1}`;
      heading.tabIndex = -1;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'reader-toc-link';
      button.classList.toggle('reader-toc-subsection', nested);
      button.setAttribute('aria-controls', heading.id);
      const number = document.createElement('span');
      number.className = 'reader-toc-number';
      number.textContent = prefix;
      const label = document.createElement('span');
      label.textContent = title;
      if (prefix) button.append(number);
      button.append(label);
      button.addEventListener('click', () => {
        // Keep #result/<id> intact; a chapter must never replace the application's route.
        for (let parent = heading.parentElement; parent && parent !== body; parent = parent.parentElement) {
          if (parent.tagName === 'DETAILS') parent.open = true;
        }
        if (window.matchMedia('(max-width: 1200px)').matches) get('readerOutlineDisclosure').open = false;
        heading.focus({ preventScroll: true });
        heading.scrollIntoView({ block: 'start', behavior: 'instant' });
        activate(index);
      });
      toc.append(button);
      return button;
    });
    let active = -1;
    function activate(index) {
      if (index === active) return;
      active = index;
      links.forEach((link, i) => {
        if (i === index) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
      const link = links[index];
      if (link && !window.matchMedia('(max-width: 1200px)').matches) {
        if (link.offsetTop < toc.scrollTop) toc.scrollTop = link.offsetTop;
        else if (link.offsetTop + link.offsetHeight > toc.scrollTop + toc.clientHeight) toc.scrollTop = link.offsetTop + link.offsetHeight - toc.clientHeight;
      }
    }
    get('readerOutline').hidden = false;
    get('readerLayout').classList.toggle('has-outline', !window.matchMedia('(max-width: 1200px)').matches);
    get('readerSectionCount').textContent = `${headings.filter(heading => !heading.classList.contains('reader-summary-title')).length} 节`;
    get('readerOutlineDisclosure').open = !window.matchMedia('(max-width: 1200px)').matches;
    get('readerOutlineToggle').hidden = false;
    get('readerOutlineToggle').setAttribute('aria-expanded', String(get('readerOutlineDisclosure').open));
    get('readerOutlineToggle').textContent = get('readerOutlineDisclosure').open ? '收起目录' : '展开目录';
    let frame = 0;
    function update() {
      frame = 0;
      if (get('resultPanel').hidden) return;
      let index = 0;
      headings.forEach((heading, i) => { if (heading.getBoundingClientRect().top <= 160) index = i; });
      activate(index);
    }
    const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
    document.addEventListener('scroll', schedule, { capture: true, passive: true });
    window.addEventListener('resize', schedule);
    dispose = () => {
      document.removeEventListener('scroll', schedule, true);
      window.removeEventListener('resize', schedule);
      if (frame) cancelAnimationFrame(frame);
    };
    activate(0);
    schedule();
  }
  document.addEventListener('click', (event) => {
    const menu = get('readerMore');
    if (menu && (!menu.contains(event.target) || event.target.closest('button'))) menu.open = false;
  });
  document.addEventListener('keydown', (event) => {
    const menu = get('readerMore');
    if (event.key === 'Escape' && menu?.open) { menu.open = false; menu.querySelector('summary').focus(); }
  });
  get('readerOutlineToggle')?.addEventListener('click', () => {
    const disclosure = get('readerOutlineDisclosure');
    if (!disclosure) return;
    disclosure.open = !disclosure.open;
    get('readerOutlineToggle').setAttribute('aria-expanded', String(disclosure.open));
    get('readerOutlineToggle').textContent = disclosure.open ? '收起目录' : '展开目录';
    get('readerLayout')?.classList.toggle('has-outline', disclosure.open);
  });
  get('readerOutlineDisclosure')?.addEventListener('toggle', () => {
    const open = get('readerOutlineDisclosure').open;
    get('readerLayout')?.classList.toggle('has-outline', open);
    get('readerOutlineToggle')?.setAttribute('aria-expanded', String(open));
    if (get('readerOutlineToggle')) get('readerOutlineToggle').textContent = open ? '收起目录' : '展开目录';
  });
  get('readerFocus')?.addEventListener('click', () => {
    const shell = get('resultPanel')?.closest('.workspace-shell');
    const active = shell?.classList.toggle('reader-focus');
    get('readerFocus').setAttribute('aria-pressed', String(Boolean(active)));
    get('readerFocus').textContent = active ? '退出专注阅读' : '专注阅读';
    if (active && get('readerOutlineDisclosure')) get('readerOutlineDisclosure').open = false;
  });
  window.addEventListener('hashchange', () => { if (get('readerMore')) get('readerMore').open = false; });
  window.GeoReportReader = { mount, reset };
})();
