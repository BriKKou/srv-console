(() => {
  'use strict';

  const boot = JSON.parse(document.getElementById('boot').textContent);
  const strings = boot.strings;
  const locale = boot.lang === 'fr' ? 'fr-FR' : 'en-GB';
  const SVGNS = 'http://www.w3.org/2000/svg';
  const SIZE_UNITS = boot.lang === 'fr' ? ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'] : ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  const HISTORY_EVERY = 60;
  const $ = (id) => document.getElementById(id);

  let data = null;
  let history = [];
  const netPrev = {};

  const t = (key, vars = {}) => String(strings[key] ?? key).replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''));

  // DOM building without innerHTML
  function h(tag, props, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(props || {})) {
      if (value == null || value === false) continue;
      if (key === 'class') el.className = value;
      else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
      else el.setAttribute(key, value);
    }
    for (const child of children.flat()) {
      if (child == null || child === false) continue;
      el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
  }

  function svg(tag, attrs) {
    const el = document.createElementNS(SVGNS, tag);
    for (const [key, value] of Object.entries(attrs || {})) el.setAttribute(key, value);
    return el;
  }

  function icon(name) {
    const el = svg('svg', { class: 'ic', 'aria-hidden': 'true' });
    el.append(svg('use', { href: `#i-${name}` }));
    return el;
  }

  const badge = (lvl, text, dot = true) => h('span', { class: `sc-badge ${lvl}` }, dot && h('i', { class: 'dot' }), text);
  const level = (value, warn, err) => (value >= err ? 'err' : value >= warn ? 'warn' : 'ok');
  const show = (el, visible) => { el.hidden = !visible; };
  const now = () => Date.now() / 1000;

  // Formatting
  function fmtNumber(value, digits) {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(value);
  }

  function fmtBytes(bytes) {
    if (bytes == null) return '—';
    let value = bytes;
    let i = 0;
    while (value >= 1024 && i < SIZE_UNITS.length - 1) {
      value /= 1024;
      i++;
    }
    return `${fmtNumber(value, i === 0 || value >= 100 ? 0 : 1)} ${SIZE_UNITS[i]}`;
  }

  function fmtDuration(seconds) {
    const sec = Math.max(0, Math.round(seconds));
    const d = Math.floor(sec / 86400);
    const hh = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    if (d) return `${d} ${t('unit.d')} ${hh} ${t('unit.h')}`;
    if (hh) return `${hh} ${t('unit.h')} ${m} ${t('unit.min')}`;
    if (m) return `${m} ${t('unit.min')}`;
    return `${sec} ${t('unit.s')}`;
  }

  const ago = (ts) => t('time.ago', { d: fmtDuration(now() - ts) });
  const clock = (ts) => new Date(ts * 1000).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const dateTime = (ts) => new Date(ts * 1000).toLocaleString(locale, { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

  // Actions
  function toast(message) {
    const el = $('toast');
    el.textContent = message;
    el.classList.add('show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => el.classList.remove('show'), 3500);
  }

  async function action(verb, target) {
    try {
      const res = await fetch('api/action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Console-Action': '1' },
        body: JSON.stringify({ verb, target }),
      });
      const body = await res.json();
      if (!body.ok) throw new Error(body.error || res.statusText);
      toast(verb === 'reboot' ? t('toast.reboot') : t('toast.restarted', { name: target }));
    } catch (err) {
      toast(t('toast.failed', { error: err.message }));
    }
  }

  function restartButton(target, label, withText) {
    return h('button', {
      type: 'button',
      class: `sc-btn sc-btn--sm${withText ? '' : ' sc-btn--icon sc-btn--ghost'}`,
      title: t('svc.restart', { name: label }),
      'aria-label': t('svc.restart', { name: label }),
      onclick: async (e) => {
        if (!confirm(t('confirm.restart', { name: label }))) return;
        const btn = e.currentTarget;
        btn.disabled = true;
        btn.classList.add('spin');
        await action('restart', target);
        setTimeout(refresh, 4000);
      },
    }, icon('rotate'), withText && t('containers.restart'));
  }

  // Charts
  function linePath(points, height, max) {
    return points.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${(height - (Math.min(p[1], max) / max) * height).toFixed(1)}`).join(' ');
  }

  function spark(el, values, max) {
    const box = el.viewBox.baseVal;
    const clean = values.filter((v) => v != null);
    el.replaceChildren();
    if (clean.length < 2) return;
    const top = max || Math.max(...clean) * 1.15 || 1;
    const points = clean.map((v, i) => [(i / (clean.length - 1)) * box.width, v]);
    const line = linePath(points, box.height, top);
    el.append(svg('path', { class: 'area', d: `${line} L${box.width},${box.height} L0,${box.height} Z` }), svg('path', { class: 'line', d: line }));
  }

  function rxRates(points) {
    const out = [];
    for (let i = 1; i < points.length; i++) {
      const a = points[i - 1];
      const b = points[i];
      const dt = b.t - a.t;
      out.push(dt > 0 && a.rx != null && b.rx != null && b.rx >= a.rx ? (b.rx - a.rx) / dt : null);
    }
    return out;
  }

  function renderChart() {
    const el = $('chart');
    const W = 560;
    const H = 180;
    const from = now() - 86400;
    el.replaceChildren();
    for (let i = 0; i <= 4; i++) el.append(svg('line', { class: 'grid', x1: 0, x2: W, y1: (H / 4) * i, y2: (H / 4) * i }));
    const points = history.filter((p) => p.t >= from);
    const x = (ts) => ((ts - from) / 86400) * W;
    const series = (key) => points.filter((p) => p[key] != null).map((p) => [x(p.t), p[key]]);

    const cpu = series('cpu');
    if (cpu.length > 1) {
      const line = linePath(cpu, H, 100);
      el.append(svg('path', { class: 'cpu-area', d: `${line} L${cpu[cpu.length - 1][0]},${H} L${cpu[0][0]},${H} Z` }), svg('path', { class: 'cpu', d: line }));
    }
    const ram = series('ram');
    if (ram.length > 1) el.append(svg('path', { class: 'ram', d: linePath(ram, H, 100) }));
    const temp = series('temp');
    if (temp.length > 1) el.append(svg('path', { class: 'temp', d: linePath(temp, H, 100) }));

    const labels = [];
    for (let i = 0; i <= 8; i++) {
      const hour = new Date((from + 10800 * i) * 1000).getHours();
      labels.push(h('span', {}, i === 8 ? t('chart.now') : `${String(hour).padStart(2, '0')}h`));
    }
    $('chart-x').replaceChildren(...labels);
  }

  // Panels
  function renderHeader() {
    const sys = data.system;
    $('host').textContent = sys.hostname;
    $('updated').textContent = t('header.updated', { time: clock(now()) });
    $('side-uptime').textContent = t('side.uptime', { d: fmtDuration(sys.uptime) });
    const root = data.disks.find((d) => d.mount === '/');
    if (root) {
      $('side-disk').textContent = `${root.percent}%`;
      const bar = $('side-disk-bar');
      bar.style.width = `${root.percent}%`;
      bar.className = root.percent >= 95 ? 'err' : root.percent >= 85 ? 'warn' : '';
    }
    show($('reboot-btn'), data.capabilities.reboot);
  }

  function setKpi(id, value, lvl, badgeText, sub) {
    const card = $(id);
    show(card, true);
    card.querySelector('.sc-kpi__value').textContent = value;
    const b = card.querySelector('.sc-badge');
    b.className = `sc-badge ${lvl || ''}`;
    b.textContent = badgeText || '';
    const subEl = card.querySelector('.sc-kpi__sub');
    if (subEl) subEl.textContent = sub || '';
  }

  function renderKpis() {
    const recent = history.slice(-120);
    const load = data.load.percent;
    setKpi('kpi-cpu', `${load[0]}%`, level(load[0], 70, 90), t('kpi.cpu_badge', { p: load[2] }));
    spark($('kpi-cpu').querySelector('.sc-spark'), recent.map((p) => p.cpu), 100);

    const mem = data.memory;
    setKpi('kpi-ram', `${mem.percent}%`, level(mem.percent, 80, 92), t('kpi.ram_badge', { used: fmtBytes(mem.used) }));
    spark($('kpi-ram').querySelector('.sc-spark'), recent.map((p) => p.ram), 100);

    const temp = data.cpu.temp;
    if (temp == null) {
      show($('kpi-temp'), false);
    } else {
      const lvl = level(temp, 80, 95);
      setKpi('kpi-temp', `${temp} °C`, lvl, t(`kpi.temp_${lvl}`));
      spark($('kpi-temp').querySelector('.sc-spark'), recent.map((p) => p.temp), 100);
    }

    const svcs = data.services;
    if (!svcs.length) {
      show($('kpi-services'), false);
    } else {
      const down = svcs.filter((s) => !s.up);
      setKpi('kpi-services', `${svcs.length - down.length}/${svcs.length}`, down.length ? 'err' : 'ok',
        down.length ? t('kpi.services_down', { n: down.length }) : t('kpi.services_all'),
        down.length ? down.map((s) => s.name).join(', ') : t('kpi.services_sub'));
    }

    const b = data.backup;
    if (!b) {
      show($('kpi-backup'), false);
    } else if (!b.present) {
      setKpi('kpi-backup', '—', 'warn', t('kpi.backup_missing'), '');
    } else {
      const age = b.last_success ? now() - b.last_success : null;
      const tooOld = data.alerts.some((a) => a.key === 'backup_old');
      const lvl = !b.ok ? 'err' : tooOld ? 'warn' : 'ok';
      const text = !b.ok ? t('kpi.backup_failed') : tooOld ? t('kpi.backup_old') : t('kpi.backup_ok');
      setKpi('kpi-backup', age == null ? t('kpi.backup_never') : fmtDuration(age).replace(/ \d+ \S+$/, ''), lvl, text, b.detail || '');
    }

    const u = data.updates;
    if (!u) {
      show($('kpi-updates'), false);
    } else {
      const lvl = u.security ? 'warn' : u.reboot_required ? 'info' : 'ok';
      const text = u.security ? t('kpi.updates_security', { n: u.security }) : u.reboot_required ? t('kpi.updates_reboot') : t('kpi.updates_ok');
      setKpi('kpi-updates', u.total == null ? '—' : String(u.total), lvl, text, u.reboot_required ? t('kpi.updates_reboot_sub') : t('kpi.updates_sub'));
    }
  }

  function renderServices() {
    const list = data.services;
    show($('services'), list.length > 0);
    $('split').classList.toggle('sc-split--single', list.length === 0);
    if (!list.length) return;
    $('services-count').textContent = t('services.count', { up: list.filter((s) => s.up).length, total: list.length });
    $('services-list').replaceChildren(...list.map((s) => h('li', {},
      badge(s.up ? 'ok' : 'err', s.up ? t('svc.online') : t('svc.offline')),
      h('code', { title: s.name }, s.name),
      h('span', { class: 'port' }, `:${s.port}`),
      h('span', { class: 'acts' },
        s.url && h('a', { class: 'sc-btn sc-btn--sm sc-btn--icon sc-btn--ghost', href: s.url, target: '_blank', rel: 'noopener noreferrer', title: t('svc.open', { name: s.name }), 'aria-label': t('svc.open', { name: s.name }) }, icon('external')),
        s.target && restartButton(s.target, s.name, false)),
    )));
  }

  function containerState(c) {
    if (c.state === 'running') {
      if (c.health === 'unhealthy') return { lvl: 'err', text: t('state.unhealthy') };
      if (c.health === 'starting') return { lvl: 'warn', text: t('state.starting') };
      return { lvl: 'ok', text: c.health === 'healthy' ? t('state.healthy') : t('state.running') };
    }
    const key = `state.${c.state}`;
    return { lvl: ['restarting', 'dead'].includes(c.state) ? 'err' : 'warn', text: key in strings ? t(key) : c.state };
  }

  function renderContainers() {
    const docker = data.docker;
    show($('containers'), Boolean(docker && docker.available));
    if (!docker || !docker.available) return;
    const list = docker.containers;
    $('containers-count').textContent = t('containers.count', { up: list.filter((c) => c.state === 'running').length, total: list.length });
    $('containers-body').replaceChildren(...list.map((c) => {
      const st = containerState(c);
      return h('tr', {},
        h('td', {}, h('strong', { title: c.image }, c.name), h('small', {}, c.image)),
        h('td', { class: 'mono sc-col-wide' }, c.version || '—'),
        h('td', {}, badge(st.lvl, st.text)),
        h('td', { class: 'mono' }, c.state === 'running' && c.started_at ? fmtDuration(now() - c.started_at) : '—'),
        h('td', { class: 'num mono' }, c.cpu == null ? '—' : `${fmtNumber(c.cpu, 2)}%`),
        h('td', { class: 'num mono' }, fmtBytes(c.mem)),
        h('td', { class: 'mono sc-col-scroll' }, c.version || '—'),
        h('td', { class: 'num' }, c.restartable && restartButton(c.name, c.name, true)),
      );
    }));
    fitTables();
  }

  // Flags tables that overflow; measured without the flag, since it hides the image and narrows the table
  function fitTables() {
    document.querySelectorAll('.sc-table-wrap').forEach((wrap) => {
      wrap.classList.remove('is-scroll');
      wrap.classList.toggle('is-scroll', wrap.scrollWidth > wrap.clientWidth);
    });
  }

  function renderStorage() {
    const disks = data.disks;
    const smart = data.smart || [];
    show($('storage'), disks.length > 0 || smart.length > 0);
    $('disks').replaceChildren(...disks.map((d) => {
      // Width set through CSSOM: a style attribute would be blocked by the CSP
      const bar = h('i', { class: d.percent >= 95 ? 'err' : d.percent >= 85 ? 'warn' : '' });
      bar.style.width = `${d.percent}%`;
      return h('div', { class: 'sc-disk' },
        h('div', { class: 'sc-disk__head' },
          h('span', {}, d.label || d.mount),
          h('span', {}, `${d.percent}% · ${fmtBytes(d.used)} / ${fmtBytes(d.used + d.avail)}`)),
        h('div', { class: 'sc-meter' }, bar));
    }));
    $('smart').replaceChildren(...smart.map((s) => {
      const parts = [s.label, s.passed === false ? t('smart.failed') : s.standby ? t('smart.standby') : t('smart.ok')];
      if (s.temp != null) parts.push(`${s.temp} °C`);
      if (s.wear != null) parts.push(t('smart.wear', { n: s.wear }));
      return badge(s.passed === false ? 'err' : s.standby ? '' : 'ok', parts.join(' · '));
    }));
  }

  function renderNetwork() {
    const stamp = now();
    $('net-list').replaceChildren(...data.network.map((n) => {
      // First render has no previous sample: borrow the last two history points
      const last = history.slice(-2);
      const prev = netPrev[n.name] || (last.length === 2 && last[0].rx != null ? { rx: last[0].rx, tx: last[0].tx, t: last[0].t, end: last[1] } : null);
      let down = '—';
      let up = '—';
      if (prev) {
        const end = prev.end ? { rx: prev.end.rx, tx: prev.end.tx, t: prev.end.t } : { rx: n.rx, tx: n.tx, t: stamp };
        const dt = end.t - prev.t;
        if (dt > 0) {
          down = `${fmtBytes(Math.max(0, (end.rx - prev.rx) / dt))}/s`;
          up = `${fmtBytes(Math.max(0, (end.tx - prev.tx) / dt))}/s`;
        }
      }
      netPrev[n.name] = { rx: n.rx, tx: n.tx, t: stamp };
      return h('div', { class: 'sc-net' },
        h('div', {}, h('strong', {}, n.name), h('small', {}, [n.ip, n.speed > 0 ? `${n.speed} Mb/s` : null].filter(Boolean).join(' · '))),
        h('div', { class: 'rate' }, `↓ ${down}`, h('small', {}, t('network.total', { n: fmtBytes(n.rx) }))),
        h('div', { class: 'rate' }, `↑ ${up}`, h('small', {}, t('network.total', { n: fmtBytes(n.tx) }))),
      );
    }));
    const rates = rxRates(history);
    spark($('net-spark'), rates);
    const valid = rates.filter((r) => r != null);
    $('net-caption').textContent = valid.length ? t('network.peak', { rate: `${fmtBytes(Math.max(...valid))}/s` }) : '';
  }

  const ALERT_TITLES = {
    backup_missing: 'backup', backup_failed: 'backup', backup_old: 'backup', updates_security: 'updates',
    reboot_required: 'reboot', collector_missing: 'collector', collector_stale: 'collector', temp_high: 'temp', api_down: 'api',
  };

  function alertText(a) {
    const p = { ...a.params };
    if ('free' in p) p.free = fmtBytes(p.free);
    if ('seconds' in p) p.seconds = fmtDuration(p.seconds);
    if ('state' in p) p.state = (`state.${p.state}` in strings ? t(`state.${p.state}`) : p.state).toLowerCase();
    if (a.key === 'backup_old' && p.since == null) return t('alert.backup_never');
    if ('since' in p) p.since = ago(p.since);
    return t(`alert.${a.key}`, p);
  }

  const alertTitle = (a) => a.subject || t(`alert.title.${ALERT_TITLES[a.key] || 'api'}`);

  function renderAlerts(alerts) {
    $('alerts').replaceChildren(...alerts.filter((a) => a.level !== 'info').map((a) => h('div', { class: `sc-alert ${a.level}` },
      h('strong', {}, alertTitle(a)), h('span', {}, alertText(a)))));
    const worst = alerts.some((a) => a.level === 'err') ? 'err' : alerts.some((a) => a.level === 'warn') ? 'warn' : 'ok';
    const g = $('global-badge');
    g.className = `sc-badge ${worst}`;
    g.lastElementChild.textContent = t(`status.${worst}`);
    const base = document.title.replace(/^(🔴|🟠) /, '');
    document.title = `${worst === 'err' ? '🔴 ' : worst === 'warn' ? '🟠 ' : ''}${base}`;
  }

  function renderJournal() {
    const lines = [];
    const push = (ts, lvl, text) => lines.push({ ts, lvl, text });
    const LVL = { err: 'ERROR', warn: 'WARN', info: 'INFO' };
    data.alerts.forEach((a) => push(data.time, LVL[a.level], `${alertTitle(a)} — ${alertText(a)}`));
    const b = data.backup;
    if (b && b.present && b.last_run) push(b.last_run, b.ok ? 'INFO' : 'ERROR', [b.ok ? t('log.backup_ok') : t('log.backup_failed'), b.detail].filter(Boolean).join(' · '));
    (data.docker?.containers || []).forEach((c) => {
      if (c.state === 'running' && c.started_at && now() - c.started_at < 86400) push(c.started_at, 'INFO', t('log.container', { name: c.name }));
    });
    push(data.system.boot_time, 'INFO', t('log.boot', { kernel: data.system.kernel }));
    (data.logins || []).forEach((l) => {
      if (l.at) push(l.at, 'INFO', l.from ? t('log.login', { user: l.user, from: l.from }) : t('log.login_local', { user: l.user }));
    });
    lines.sort((a, b) => b.ts - a.ts);
    const cls = { ERROR: 'err', WARN: 'warn', INFO: 'info' };
    $('log').replaceChildren(...lines.slice(0, 40).map((l) => h('div', { class: `sc-log__line ${cls[l.lvl]}` },
      h('time', {}, dateTime(l.ts)), h('b', {}, l.lvl), h('span', { title: l.text }, l.text))));
  }

  function renderSystem() {
    const sys = data.system;
    const cpu = data.cpu;
    const rows = [
      [t('sys.os'), sys.os],
      [t('sys.kernel'), sys.kernel],
      [t('sys.cpu'), `${cpu.model} · ${t('sys.cores', { n: cpu.cores })}`],
      [t('sys.freq'), cpu.mhz ? `${cpu.mhz} MHz` : null],
      [t('sys.boot'), dateTime(sys.boot_time)],
      [t('sys.uptime'), t(sys.uptime >= 172800 ? 'sys.uptime_days' : 'sys.uptime_day', { n: Math.floor(sys.uptime / 86400) })],
      [t('sys.sessions'), sys.sessions],
      [t('sys.time'), clock(data.time)],
    ];
    $('sys-kv').replaceChildren(...rows.filter(([, v]) => v != null).flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)]));

    const certs = data.certs || [];
    show($('certs-card'), certs.length > 0);
    $('certs').replaceChildren(...certs.map((c) => h('li', {},
      h('span', { class: 'mono' }, c.name),
      badge(c.days < 7 ? 'err' : c.days < 20 ? 'warn' : 'ok', t('certs.days', { n: c.days }), false))));

    const logins = data.logins || [];
    show($('logins-card'), logins.length > 0);
    $('logins').replaceChildren(...logins.map((l) => h('li', {},
      h('span', { class: 'mono' }, l.from ? `${l.user} · ${l.from}` : l.user),
      h('span', { class: 'sc-caption' }, l.at ? dateTime(l.at) : ''))));
  }

  function renderFooter() {
    const at = data.collector.at;
    const parts = [];
    if (data.demo) parts.push(t('footer.demo'));
    parts.push(at ? t('footer.collected', { ago: ago(at), s: boot.refresh }) : t('footer.missing'));
    $('footer').textContent = parts.join(' · ');
  }

  function render() {
    const steps = [renderHeader, renderKpis, renderServices, renderContainers, renderStorage, renderNetwork, renderSystem, renderChart, renderFooter, renderJournal];
    for (const step of steps) {
      try {
        step();
      } catch (err) {
        console.error(step.name, err);
      }
    }
    renderAlerts(data.alerts);
    updateNav();
  }

  async function refresh() {
    const btn = $('refresh-btn');
    btn.classList.add('spin');
    try {
      const res = await fetch('api/state.php', { cache: 'no-store', credentials: 'same-origin' });
      if (!res.ok) throw new Error(res.statusText);
      data = await res.json();
      render();
    } catch (err) {
      renderAlerts([{ level: 'err', key: 'api_down', subject: null, params: {} }]);
      $('global-badge').lastElementChild.textContent = t('status.offline');
    } finally {
      btn.classList.remove('spin');
    }
  }

  async function loadHistory() {
    try {
      const res = await fetch('api/history.php', { cache: 'no-store', credentials: 'same-origin' });
      if (res.ok) history = await res.json();
      if (data) render();
    } catch (err) { /* keep the previous history */ }
  }

  // Theme (dark by default) and language
  function initSwitches() {
    let theme = 'dark';
    try {
      theme = localStorage.getItem('srv-console-theme') || 'dark';
    } catch (e) { /* storage blocked */ }
    document.querySelectorAll('input[name="theme"]').forEach((input) => {
      input.checked = input.value === theme;
      input.addEventListener('change', () => {
        if (input.value === 'dark') delete document.documentElement.dataset.theme;
        else document.documentElement.dataset.theme = input.value;
        try {
          localStorage.setItem('srv-console-theme', input.value);
        } catch (e) { /* storage blocked */ }
      });
    });

    show($('lang-switch'), boot.switch);
    document.querySelectorAll('input[name="lang"]').forEach((input) => {
      input.checked = input.value === boot.lang;
      input.addEventListener('change', () => {
        document.cookie = `srv_console_lang=${input.value}; path=/; max-age=31536000; SameSite=Lax`;
        location.reload();
      });
    });
  }

  // Sidebar follows the scroll; links to hidden panels are hidden too
  function updateNav() {
    const links = [...document.querySelectorAll('.sc-nav a')];
    let active = links[0];
    links.slice(1).forEach((a) => {
      const section = $(a.dataset.section);
      show(a, Boolean(section) && !section.hidden);
      if (section && !section.hidden && section.getBoundingClientRect().top < window.innerHeight / 3) active = a;
    });
    if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 4) {
      active = links.filter((a) => !a.hidden).pop();
    }
    links.forEach((a) => (a === active ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current')));
  }

  $('refresh-btn').addEventListener('click', refresh);
  $('reboot-btn').addEventListener('click', async () => {
    if (!confirm(t('confirm.reboot'))) return;
    if (prompt(t('confirm.reboot_type')) !== 'REBOOT') return;
    await action('reboot', 'now');
  });
  window.addEventListener('scroll', updateNav, { passive: true });
  window.addEventListener('resize', fitTables, { passive: true });

  initSwitches();
  loadHistory().then(refresh);
  setInterval(refresh, boot.refresh * 1000);
  setInterval(loadHistory, HISTORY_EVERY * 1000);
})();
