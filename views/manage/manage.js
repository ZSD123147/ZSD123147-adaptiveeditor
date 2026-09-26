/* ==========================================================================
   自适应编辑器 —— 文章管理界面。列表由服务端整页渲染（关 JS 也能搜索翻页），
   JS 只补全选联动、删除确认、免刷新提交。
   ========================================================================== */
(function (window, document) {
  'use strict';

  const Q = window.ADE;
  let started = false;

  let allBox = null;
  let batBar = null;
  let selInfo = null;
  let batDel = null;
  let selNone = null;
  let totalEl = null;
  let wrap = null;
  let picks = [];
  let total = 0;

  /* ------------------------------------------------------------------ 工具 */

  function closestClass(node, cls) {
    while (node && node !== document) {
      if (node.nodeType === 1 && node.classList && node.classList.contains(cls)) { return node; }
      node = node.parentNode;
    }
    return null;
  }

  // 行统一带 .ade-row 类，JS 只认类不认元素类型，改版式不必改选择器
  function rowOfBox(box) {
    return closestClass(box, 'ade-row');
  }

  function rowOf(id) {
    const n = parseInt(id, 10);
    return n > 0 ? Q.qs('#adeList .ade-row[data-id="' + n + '"]') : null;
  }

  function picked() {
    return picks.filter(function (c) { return c.checked; });
  }

  function pickedIds() {
    return picked().map(function (c) { return parseInt(c.value, 10); }).filter(function (n) { return n > 0; });
  }

  /* ------------------------------------------------------------------ 状态 */

  function refresh() {
    const list = picked();

    if (allBox && allBox.tagName === 'INPUT') {
      allBox.checked = picks.length > 0 && list.length === picks.length;
      allBox.indeterminate = list.length > 0 && list.length < picks.length;
    }
    if (batBar) { batBar.hidden = list.length === 0; }
    if (selInfo) { selInfo.textContent = '已选 ' + list.length + ' 篇'; }

    picks.forEach(function (c) {
      const row = rowOfBox(c);
      if (!row) { return; }
      if (c.checked) { row.classList.add('is-picked'); } else { row.classList.remove('is-picked'); }
    });
  }

  function setTotal(n) {
    total = Math.max(0, parseInt(n, 10) || 0);
    if (totalEl) {
      totalEl.setAttribute('data-count', String(total));
      totalEl.textContent = '共 ' + total + ' 篇';
    }
  }

  /** 把已删条目摘掉并同步总数；当前页删空给提示，不删分页条（其它页可能还有） */
  function afterDelete(ids) {
    ids.forEach(function (id) {
      const row = rowOf(id);
      if (row && row.parentNode) { row.parentNode.removeChild(row); }
    });

    setTotal(total - ids.length);
    picks = Q.qsa('.ade-pick');

    const body = Q.qs('#adeList tbody');
    if (wrap && (!body || body.children.length === 0)) {
      Q.clear(wrap);
      wrap.appendChild(Q.el('p', {
        class: 'ade-empty',
        text: total > 0 ? '本页已经没有文章了，请用下方的上一页 / 下一页翻到其它页。' : '还没有文章。'
      }));
    }

    refresh();
  }

  /* ------------------------------------------------------------------ 删除 */

  function delOne(btn) {
    const id = parseInt(btn.getAttribute('data-id'), 10);
    if (!(id > 0)) { return; }

    const title = btn.getAttribute('data-title') || '';
    Q.confirm('删除文章', '确定要删除《' + title + '》（ID ' + id + '）吗？文章连同它的评论一起删除，且无法恢复。', function () {
      Q.busy(btn, true, '删除中');
      Q.request('del', { method: 'POST', data: { id: id } }, function (err, res) {
        Q.busy(btn, false);
        if (err) { Q.toast(err, 'error'); return; }
        Q.toast('已删除', 'ok');
        afterDelete([id]);
      });
    });
  }

  function delMany() {
    const ids = pickedIds();
    if (ids.length === 0) { Q.toast('请先勾选要删除的文章'); return; }

    Q.confirm('批量删除', '确定要删除所选的 ' + ids.length + ' 篇文章吗？文章连同它们的评论一起删除，且无法恢复。', function () {
      Q.busy(batDel, true, '删除中');
      Q.request('batdel', { method: 'POST', data: { id: ids } }, function (err, res) {
        Q.busy(batDel, false);
        if (err) { Q.toast(err, 'error'); return; }

        const n = res ? parseInt(res.count, 10) : 0;
        // 实际删除数 != 勾选数时交服务端重渲染，避免界面与库里对不上
        if (n !== ids.length) { window.location.reload(); return; }

        Q.toast('已删除 ' + n + ' 篇', 'ok');
        afterDelete(ids);
      });
    });
  }

  /* ------------------------------------------------------------------ 装配 */

  function init() {
    allBox = Q.qs('#adeAll');
    batBar = Q.qs('#adeBatBar');
    selInfo = Q.qs('#adeSelInfo');
    batDel = Q.qs('#adeBatDel');
    selNone = Q.qs('#adeSelNone');
    totalEl = Q.qs('#adeTotal');
    wrap = Q.qs('#adeListWrap');
    picks = Q.qsa('.ade-pick');
    total = totalEl ? (parseInt(totalEl.getAttribute('data-count'), 10) || 0) : 0;

    // 全选是纯文字按钮：点击即在本页 全选 / 全不选 之间切换
    if (allBox) {
      Q.on(allBox, 'click', function () {
        const on = picks.length > 0 && picked().length === picks.length;
        picks.forEach(function (c) { c.checked = !on; });
        refresh();
      });
    }

    // 下拉切换即提交（走原生 submit，与搜索同路径，筛选项不丢）
    const bar = Q.qs('form.ade-bar');
    if (bar) {
      Q.on(bar, 'change', function (e) {
        const t = e.target;
        if (t && t.tagName === 'SELECT') { bar.submit(); }
      });
    }
    picks.forEach(function (c) { Q.on(c, 'change', refresh); });

    if (batDel) { Q.on(batDel, 'click', delMany); }
    if (selNone) {
      Q.on(selNone, 'click', function () {
        picks.forEach(function (c) { c.checked = false; });
        refresh();
      });
    }

    // 删除按钮走事件委托：行会被脚本摘掉，逐个绑定会留下失效引用
    Q.on(document, 'click', function (e) {
      const btn = closestClass(e.target, 'ade-del');
      if (btn) { delOne(btn); }
    });

    refresh();
  }

  function start() {
    if (started) { return; }
    started = true;
    init();
  }

  window.ADEViewInit = start;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, false);
  } else {
    start();
  }
}(window, document));
