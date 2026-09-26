/* ==========================================================================
   自适应编辑器 —— 前端基座（原生 JS，零依赖；请求经 main.php?act= 单入口，自动附带 csrfToken）
   ========================================================================== */
(function (window, document) {
  'use strict';

  const configNode = document.getElementById('adeConfig');
  let config = {};
  try {
    config = configNode ? JSON.parse(configNode.textContent || configNode.innerHTML) : {};
  } catch (e) {
    config = {};
  }

  /* ---------- DOM ---------- */
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function on(target, ev, fn) { if (target) { target.addEventListener(ev, fn, false); } }

  function clear(node) {
    while (node && node.firstChild) { node.removeChild(node.firstChild); }
    return node;
  }

  function el(tag, attrs, children) {
    const n = document.createElement(tag);
    let k;
    if (attrs) {
      for (k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) { continue; }
        if (k === 'class') { n.className = attrs[k]; }
        else if (k === 'text') { n.textContent = attrs[k]; }
        else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), attrs[k], false); }
        else { n.setAttribute(k, attrs[k]); }
      }
    }
    (children || []).forEach(function (c) {
      if (c === null || c === undefined) { return; }
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }

  /* ---------- CSRF ---------- */
  function csrf() {
    const m = document.querySelector('meta[name="csrfToken"]');
    return m ? m.getAttribute('content') : '';
  }

  /* ---------- 序列化 ---------- */
  function encodePair(out, key, value) {
    if (value === null || value === undefined) { return; }
    if (Object.prototype.toString.call(value) === '[object Array]') {
      value.forEach(function (v) { encodePair(out, key + '[]', v); });
      return;
    }
    out.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
  }

  function serialize(data) {
    const out = [];
    Object.keys(data || {}).forEach(function (k) { encodePair(out, k, data[k]); });
    return out.join('&');
  }

  /* ---------- 请求 ---------- */
  /**
   * @param {string} act   路由动作名
   * @param {object} opts  { method, data, form, onProgress }
   * @param {function} cb  cb(errMsg|null, data, errCode)
   */
  function request(act, opts, cb) {
    opts = opts || {};
    const method = (opts.method || 'GET').toUpperCase();
    let url = config.entry + '?act=' + encodeURIComponent(act);
    let body = null;
    const xhr = new XMLHttpRequest();

    if (method === 'GET') {
      const q = serialize(opts.data);
      if (q) { url += '&' + q; }
    } else if (opts.form) {
      body = opts.form;
      body.append('csrfToken', csrf());
    } else {
      const data = {};
      Object.keys(opts.data || {}).forEach(function (k) { data[k] = opts.data[k]; });
      data.csrfToken = csrf();
      body = serialize(data);
    }

    xhr.open(method, url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (method === 'POST' && !opts.form) {
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    if (opts.onProgress && xhr.upload) {
      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) { opts.onProgress(Math.round(e.loaded / e.total * 100)); }
      };
    }

    xhr.onload = function () {
      let res = null;
      try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
      if (!res || !res.err) {
        cb('服务器返回了无法识别的内容（HTTP ' + xhr.status + '）', null, -1);
        return;
      }
      if (res.err.code !== 0) {
        // 第三个参数把内核错误码原样交出去：调用方要据此区分「体积超限(27)」这类需要给指引的错
        cb(res.err.msg || '操作失败（' + res.err.code + '）', null, res.err.code);
        return;
      }
      cb(null, res.data, 0);
    };
    xhr.onerror = function () { cb('网络异常，请检查连接后重试', null, -1); };
    xhr.ontimeout = function () { cb('请求超时，请重试', null, -1); };
    xhr.timeout = 120000;
    xhr.send(body);
    return xhr;
  }

  /* ---------- 提示 ---------- */
  let toastEl = null;
  let toastTimer = null;
  function toast(msg, type, ms) {
    if (!toastEl) { toastEl = document.getElementById('adeToast'); }
    if (!toastEl) { return; }
    toastEl.textContent = msg;
    toastEl.className = 'ade-toast is-show' + (type ? ' is-' + type : '');
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () { toastEl.className = 'ade-toast'; }, ms || 2600);
  }

  /* ---------- 对话框 ---------- */
  let dialog = null;

  function setLayer(id, open) {
    const node = document.getElementById(id);
    if (!node) { return null; }
    node.hidden = !open;
    node.classList.toggle('is-open', !!open);
    return node;
  }

  function closeDialog() {
    setLayer('adeMask', false);
    setLayer('adeDialog', false);
    dialog = null;
  }

  /**
   * @param {object} opts { title, body, okText, cancelText, danger, onOk }
   *        body 为字符串或 DOM；onOk(close) 返回 false 可阻止关闭
   */
  function openDialog(opts) {
    opts = opts || {};
    const mask = document.getElementById('adeMask');
    const box = document.getElementById('adeDialog');
    if (!mask || !box) { return; }

    const titleEl = document.getElementById('adeDialogTitle');
    const bodyEl = document.getElementById('adeDialogBody');
    const footEl = document.getElementById('adeDialogFoot');
    if (!titleEl || !bodyEl || !footEl) { return; }

    titleEl.textContent = opts.title || '';
    clear(bodyEl);
    if (typeof opts.body === 'string') { bodyEl.textContent = opts.body; }
    else if (opts.body) { bodyEl.appendChild(opts.body); }

    clear(footEl);
    if (opts.cancelText !== null) {
      footEl.appendChild(el('button', {
        type: 'button',
        class: 'ade-btn',
        text: opts.cancelText || '取消',
        onclick: closeDialog
      }));
    }
    footEl.appendChild(el('button', {
      type: 'button',
      class: 'ade-btn ' + (opts.danger ? 'ade-btn-danger' : 'ade-btn-primary'),
      text: opts.okText || '确定',
      onclick: function () {
        /* onOk 抛异常也要关弹窗，否则会一直压在主界面上 */
        let keep = false;
        if (opts.onOk) {
          try {
            keep = opts.onOk(closeDialog) === false;
          } catch (err) {
            toast('操作出错：' + (err && err.message ? err.message : err), 'error');
          }
        }
        if (!keep) { closeDialog(); }
      }
    }));

    setLayer('adeMask', true);
    setLayer('adeDialog', true);
    dialog = box;

    const first = box.querySelector('input,select,textarea,button');
    if (first) { first.focus(); }
  }

  function confirmBox(title, message, onYes) {
    openDialog({
      title: title,
      body: message,
      okText: '确定',
      cancelText: '取消',
      danger: true,
      onOk: function () { onYes(); }
    });
  }

  on(document, 'keydown', function (e) {
    if (e.key === 'Escape' && dialog) { closeDialog(); }
  });
  on(document.getElementById('adeMask'), 'click', closeDialog);

  /* ---------- 按钮忙碌态 ---------- */
  function busy(btn, isBusy, label) {
    if (!btn) { return; }
    if (isBusy) {
      if (!btn.hasAttribute('data-label')) { btn.setAttribute('data-label', btn.textContent); }
      btn.classList.add('is-busy');
      btn.disabled = true;
      btn.textContent = '';
      btn.appendChild(el('i', { class: 'ade-spin' }));
      btn.appendChild(document.createTextNode(label || '处理中'));
    } else {
      btn.classList.remove('is-busy');
      btn.disabled = false;
      btn.textContent = btn.getAttribute('data-label') || btn.textContent;
    }
  }

  /* ---------- 工具 ---------- */
  function formatBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) { return n + ' B'; }
    if (n < 1048576) { return (n / 1024).toFixed(1) + ' KB'; }
    return (n / 1048576).toFixed(2) + ' MB';
  }

  function init() {
    if (typeof window.ADEViewInit === 'function') { window.ADEViewInit(config); }
  }

  window.ADE = {
    config: config,
    qs: qs,
    qsa: qsa,
    on: on,
    el: el,
    clear: clear,
    csrf: csrf,
    serialize: serialize,
    request: request,
    toast: toast,
    dialog: openDialog,
    confirm: confirmBox,
    busy: busy,
    formatBytes: formatBytes
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, false);
  } else {
    init();
  }
}(window, document));
