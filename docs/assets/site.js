// copy buttons: every .cmd gets one, copying its <pre> without prompts/comments
document.querySelectorAll('.cmd').forEach((box) => {
  const btn = Object.assign(document.createElement('button'), { className: 'copy', type: 'button', textContent: 'Copy' });
  btn.addEventListener('click', async () => {
    const pre = box.querySelector('pre').cloneNode(true);
    pre.querySelectorAll('.p, .c').forEach((n) => n.remove());
    const text = pre.textContent.split('\n').map((l) => l.trimEnd()).filter(Boolean).join('\n');
    try { await navigator.clipboard.writeText(text); btn.textContent = 'Copied'; }
    catch { btn.textContent = 'Select and copy'; }
    setTimeout(() => (btn.textContent = 'Copy'), 1600);
  });
  box.append(btn);
});

// tabs: [role=tablist] buttons toggle sibling [role=tabpanel]s by aria-controls
document.querySelectorAll('[role="tablist"]').forEach((list) => {
  const tabs = [...list.querySelectorAll('[role="tab"]')];
  const select = (tab) => tabs.forEach((t) => {
    const on = t === tab;
    t.setAttribute('aria-selected', on);
    t.tabIndex = on ? 0 : -1;
    document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
  });
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => select(t));
    t.addEventListener('keydown', (e) => {
      const d = { ArrowRight: 1, ArrowLeft: -1 }[e.key];
      if (d) { const n = tabs[(i + d + tabs.length) % tabs.length]; select(n); n.focus(); }
    });
  });
  // preselect the visitor's OS
  const win = /Win/.test(navigator.platform);
  select(tabs.find((t) => (t.dataset.os === 'windows') === win) || tabs[0]);
});
