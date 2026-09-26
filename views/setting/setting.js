/* ==========================================================================
   自适应编辑器 —— 设置界面。表单由服务端渲染，JS 只补范围校验、免刷新保存、回写夹紧值。
   ========================================================================== */
(function (window, document) {
  'use strict';

  const Q = window.ADE;
  let started = false;

  let form = null;
  let saveBtn = null;

  function numbers() { return Q.qsa('input[type="number"]', form); }
  function switches() { return Q.qsa('input[type="checkbox"]', form); }
  function radios() { return Q.qsa('input[type="radio"]', form); }

  function hasClass(node, cls) {
    return !!node && node.nodeType === 1 && !!node.classList && node.classList.contains(cls);
  }

  // 找输入项对应的中文标签，报错时指名道姓
  function labelOf(input) {
    let node = input.parentNode;
    while (node && node !== form && !hasClass(node, 'ade-set-cell')) {
      node = node.parentNode;
    }
    if (!node || node === form) { return '该项'; }

    const label = Q.qs('.ade-set-label', node);
    return label ? label.textContent : '该项';
  }

  /** 按 input 自带的 min/max 校验（范围由服务端 schema 生成）。@return {string|null} */
  function validate() {
    let error = null;
    numbers().forEach(function (input) {
      if (error) { return; }
      const min = parseInt(input.getAttribute('min'), 10);
      const max = parseInt(input.getAttribute('max'), 10);
      const n = parseInt(input.value, 10);
      if (!(n >= min && n <= max)) {
        error = labelOf(input) + '需为 ' + min + '–' + max + ' 之间的整数';
      }
    });
    return error;
  }

  function collect() {
    const data = {};
    numbers().forEach(function (input) { data[input.name] = parseInt(input.value, 10); });
    switches().forEach(function (input) { data[input.name] = input.checked ? 1 : 0; });
    /* 双向切换是单选项：同名的一组只回传被选中的那个值 */
    radios().forEach(function (input) {
      if (input.checked) { data[input.name] = parseInt(input.value, 10); }
    });
    return data;
  }

  // 用服务端返回的（已夹紧）配置回写表单，保证界面显示库里的值
  function applyValues(config) {
    if (!config) { return; }
    Q.qsa('input', form).forEach(function (input) {
      if (!Object.prototype.hasOwnProperty.call(config, input.name)) { return; }
      const value = config[input.name];
      if (input.type === 'checkbox') {
        input.checked = !!value;
      } else if (input.type === 'radio') {
        /* 单选项按「值相等」决定选中，只写 value 是选不上的 */
        input.checked = (String(input.value) === String(parseInt(value, 10)));
      } else {
        input.value = String(value);
      }
    });
  }

  function save() {
    const error = validate();
    if (error) { Q.toast(error, 'error'); return; }

    Q.busy(saveBtn, true, '保存中');
    Q.request('savesetting', { method: 'POST', data: collect() }, function (err, res) {
      Q.busy(saveBtn, false);
      if (err) { Q.toast(err, 'error'); return; }
      applyValues(res);
      Q.toast('设置已保存', 'ok');
    });
  }

  function init() {
    form = Q.qs('#adeSettingForm');
    saveBtn = Q.qs('#adeSave');
    if (!form || !saveBtn) { return; }

    Q.on(form, 'submit', function (e) {
      e.preventDefault();
      save();
    });
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
