/* ==========================================================================
   自适应编辑器 —— 写文章界面。零依赖 contenteditable。
   安全约定：进编辑区的 HTML 一律过 sanitizeNode() 白名单；不用 innerHTML 注入、不用内联事件、不 eval、不加载外部资源。
   ========================================================================== */
(function (window, document) {
  'use strict';

  const Q = window.ADE;

  /* 内核 PostArticle_CheckTagAndConvertIDtoString() 只取前 20 个标签
     （zb_system/function/c_system_event.php:692） */
  const MAX_TAGS = 20;

  /* 整棵子树一起丢弃的标签：可执行、可加载外部资源、表单控件 */
  const DROP_TAGS = {
    SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, BASE: 1, NOSCRIPT: 1, TEMPLATE: 1,
    IFRAME: 1, FRAME: 1, FRAMESET: 1, OBJECT: 1, EMBED: 1, APPLET: 1, PARAM: 1,
    SVG: 1, MATH: 1, CANVAS: 1, VIDEO: 1, AUDIO: 1, SOURCE: 1, TRACK: 1,
    FORM: 1, INPUT: 1, BUTTON: 1, TEXTAREA: 1, SELECT: 1, OPTION: 1,
    MAP: 1, AREA: 1
  };

  /* 允许保留的标签；不在表内的标签会被「脱壳」——保留其子节点，去掉标签本身。
     这份名单是内核 XssHtml::$m_AllowTag 的子集（zb_system/function/lib/xsshtml.php:43），
     必须保持：无 ArticleAll 权限的作者，PostArticle()->FilterPost() 会用 XssHtml 再过一道
     （c_system_event.php:512 → c_system_function.php:737），前端留下而内核没有的标签会在
     保存后被静默剥掉——引用、上下标、definition list 都栽过。脱壳行为也与 strip_tags 一致。 */
  const KEEP_TAGS = {
    P: 1, DIV: 1, SPAN: 1, BR: 1, HR: 1,
    H1: 1, H2: 1, H3: 1, H4: 1, H5: 1, H6: 1,
    UL: 1, OL: 1, LI: 1,
    STRONG: 1, B: 1, EM: 1, U: 1,
    CODE: 1, PRE: 1,
    A: 1, IMG: 1,
    TABLE: 1, TR: 1, TD: 1, TH: 1
  };

  /* 每个标签允许保留的属性，'*' 为通用；同样受 XssHtml::$m_AllowAttr 约束（同上文件:41）。
     colspan/rowspan 不在内核名单内，合并单元格会被剥——这是内核行为，不在这里越权补。 */
  const KEEP_ATTRS = {
    '*': ['style', 'class', 'id', 'title'],
    A: ['href', 'target'],
    IMG: ['src', 'alt', 'width', 'height']
  };

  /* 只放 http(s)：内核 __true_url() 会给任何非 https?:// 开头的 href 前缀 "http://"
     （xsshtml.php:98-105），mailto: 与站内相对路径都会变成 href="http://mailto:..." 这类坏链 */
  const URL_SCHEMES_OK = { 'http:': 1, 'https:': 1 };


  let config = {};
  let settings = {};
  let rights = {};

  let editor = null;
  let toolbar = null;
  let titleInput = null;
  let statEl = null;
  let fileInput = null;
  let cateSel = null;
  let statusSel = null;
  let topSel = null;
  let timeInput = null;
  /* 右栏控件与各自原位（*Home）：手机端把同一份 DOM 搬进底部抽屉，回桌面端按原位放回 */
  let trio = null;         /* 「分类 / 状态 / 置顶」整块 */
  let trioHome = null;
  let timeField = null;    /* 时间字段（含 label + input） */
  let timeHome = null;
  let actions = null;      /* .ade-actions（发布按钮） */
  let actionsHome = null;
  let drawer = null;       /* 发布抽屉 #adeDrawer */
  let drawerBody = null;   /* 抽屉里的内容容器 #adeDrawerBody */
  let drawerHandle = null; /* 抽屉拉手 #adeDrawerHandle */
  let publishBtn = null;
  let lightbox = null;
  let lightboxImg = null;
  let lightboxDel = null;
  let lightboxClose = null;
  let lightboxIn = null;
  let lightboxOut = null;
  let lightboxScale = null;
  let lightboxTarget = null;  /* 灯箱里正在预览的那个正文图片节点 */

  /* 灯箱缩放：100% = 适配视口的基准尺寸（与 CSS 的初始呈现一致） */
  const LIGHTBOX_STEP = 1.25;
  const LIGHTBOX_MIN = 0.25;
  const LIGHTBOX_MAX = 4;
  let lightboxZoom = 1;
  let lightboxFit = 0;        /* 适配视口时的基准宽度（px），按原图尺寸现算 */

  let articleId = 0;
  let intro = '';
  let tags = [];          /* 本文标签（字符串数组）；面板无标签字段，全部经工具栏弹窗管理 */
  let commonTags = [];    /* 站点常用标签（按使用数倒序），来自 #adeTagCommon 数据岛 */
  let savedRange = null;
  let pendingRange = null;
  let uploading = 0;
  let pendingSeq = 0;         /* 待转存图片的编号 */
  const pendingMap = {};        /* id → {kind:'file'|'data'|'url', file|data|url, name} */
  let dirty = false;
  let started = false;

  /* ---------------------------------------------------------------- 净化 */

  function cleanUrl(v) {
    // 抹掉控制字符与首尾空白，否则 "java\u0000script:" 之类可绕过前缀判断
    return String(v === null || v === undefined ? '' : v).replace(/[\u0000-\u0020\u007f]/g, '');
  }

  function schemeOf(u) {
    const m = /^([a-z0-9+.-]+):/i.exec(u);
    return m ? m[1].toLowerCase() + ':' : '';
  }

  function safeHref(v) {
    const u = cleanUrl(v);
    if (u === '') { return ''; }
    const s = schemeOf(u);
    if (s !== '' && !URL_SCHEMES_OK[s]) { return ''; }
    return u;
  }

  /* 弹窗手打的地址必须是完整 http(s) 网址。相对路径与 mailto: 交给内核只会被 __true_url()
     前缀成 href="http:///path" 这类坏链；粘贴带进来的 <a> 一律由 stripPastedLinks() 剥掉，
     所以这里不需要像 safeHref 那样放行无协议地址。 */
  function linkHref(v) {
    const u = safeHref(v);
    if (u === '' || !URL_SCHEMES_OK[schemeOf(u)]) { return ''; }
    return u;
  }

  function safeSrc(v) {
    const u = cleanUrl(v);
    if (u === '') { return ''; }
    /* data:image 允许进编辑区，随后会被上传换成站内地址 */
    if (/^data:image\/(png|jpe?g|gif|webp|bmp);base64,/i.test(u)) { return u; }
    const s = schemeOf(u);
    if (s !== '' && !URL_SCHEMES_OK[s]) { return ''; }
    return u;
  }

  function safeStyle(v) {
    const s = String(v === null || v === undefined ? '' : v);
    if (/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|@import/i.test(s)) { return ''; }
    return s;
  }

  function cleanAttrs(node) {
    const tag = node.tagName.toUpperCase();
    const allowed = (KEEP_ATTRS[tag] || []).concat(KEEP_ATTRS['*']);
    const attrs = Array.prototype.slice.call(node.attributes);
    let i;

    for (i = 0; i < attrs.length; i++) {
      if (allowed.indexOf(attrs[i].name.toLowerCase()) < 0) {
        node.removeAttribute(attrs[i].name);
      }
    }

    if (node.hasAttribute('href')) {
      const href = safeHref(node.getAttribute('href'));
      if (href === '') { node.removeAttribute('href'); } else { node.setAttribute('href', href); }
      /* 不补 rel="noopener"：rel 不在内核 m_AllowAttr 里，非管理员保存时会被剥光，
         而 target 反倒由内核 __node_a() 主动加上——在这里维持这个组合只会造成假象 */
    }
    if (node.hasAttribute('src')) {
      const src = safeSrc(node.getAttribute('src'));
      if (src === '') { node.removeAttribute('src'); } else { node.setAttribute('src', src); }
    }
    if (node.hasAttribute('style')) {
      const st = safeStyle(node.getAttribute('style'));
      if (st === '') { node.removeAttribute('style'); } else { node.setAttribute('style', st); }
    }
    /* 内核用 <hr class="more"> 承载摘要分隔线，这是唯一需要保留的 class */
    if (tag === 'HR' && node.getAttribute('class') !== 'more') { node.removeAttribute('class'); }
  }

  function sanitizeNode(parent) {
    const kids = Array.prototype.slice.call(parent.childNodes);
    let i;
    for (i = 0; i < kids.length; i++) {
      const node = kids[i];

      if (node.nodeType === 3) { continue; }
      if (node.nodeType !== 1) { parent.removeChild(node); continue; }

      const tag = node.tagName.toUpperCase();
      if (DROP_TAGS[tag]) { parent.removeChild(node); continue; }

      /* 先净化子树，再决定自身去留，脱壳时搬上去的子节点才是干净的 */
      sanitizeNode(node);

      if (!KEEP_TAGS[tag]) {
        while (node.firstChild) { parent.insertBefore(node.firstChild, node); }
        parent.removeChild(node);
        continue;
      }

      cleanAttrs(node);
    }
    return parent;
  }

  // DOMParser 产出惰性文档，脚本不执行、图片不加载，解析本身安全
  function parseFragment(html) {
    const doc = new DOMParser().parseFromString('<!doctype html><body>' + String(html || ''), 'text/html');
    sanitizeNode(doc.body);

    const frag = document.createDocumentFragment();
    const kids = Array.prototype.slice.call(doc.body.childNodes);
    for (let i = 0; i < kids.length; i++) {
      frag.appendChild(document.importNode(kids[i], true));
    }
    return frag;
  }

  /* ---------------------------------------------------------------- 选区 */

  function containsEditor(node) {
    return !!(node && editor && editor.contains(node));
  }

  function saveRange() {
    const sel = window.getSelection ? window.getSelection() : null;
    if (sel && sel.rangeCount > 0) {
      const r = sel.getRangeAt(0);
      if (containsEditor(r.commonAncestorContainer)) { savedRange = r.cloneRange(); }
    }
  }

  function currentRange() {
    const sel = window.getSelection ? window.getSelection() : null;
    if (sel && sel.rangeCount > 0) {
      const r = sel.getRangeAt(0);
      if (containsEditor(r.commonAncestorContainer)) { return r.cloneRange(); }
    }
    if (containsEditor(savedRange ? savedRange.commonAncestorContainer : null)) {
      return savedRange.cloneRange();
    }
    const rr = document.createRange();
    rr.selectNodeContents(editor);
    rr.collapse(false);
    return rr;
  }

  function applyRange(r) {
    savedRange = r.cloneRange();
    const sel = window.getSelection ? window.getSelection() : null;
    if (!sel) { return; }
    sel.removeAllRanges();
    sel.addRange(r);
  }

  function insertFragment(frag, range) {
    const r = range || currentRange();
    r.deleteContents();

    let last = null;
    let node;
    while ((node = frag.firstChild) !== null) {
      r.insertNode(node);
      r.setStartAfter(node);
      last = node;
    }
    if (last) { r.setEndAfter(last); }
    r.collapse(false);
    applyRange(r);

    markDirty();
    return last;
  }

  function setHtml(node, html) {
    Q.clear(node);
    const frag = parseFragment(html);
    while (frag.firstChild) { node.appendChild(frag.firstChild); }
  }

  function rangeFromPoint(x, y) {
    let r = null;
    try {
      if (document.caretRangeFromPoint) {
        r = document.caretRangeFromPoint(x, y);
      } else if (document.caretPositionFromPoint) {
        const pos = document.caretPositionFromPoint(x, y);
        if (pos) {
          r = document.createRange();
          r.setStart(pos.offsetNode, pos.offset);
          r.collapse(true);
        }
      }
    } catch (e) {
      r = null;
    }
    return (r && containsEditor(r.startContainer)) ? r : currentRange();
  }

  function closestTag(node, tag) {
    while (node && node !== editor) {
      if (node.nodeType === 1 && node.tagName.toUpperCase() === tag) { return node; }
      node = node.parentNode;
    }
    return null;
  }

  /* ---------------------------------------------------------------- 工具栏
     只有「链接」「图片」「标签」三项，没有需要回显激活态的排版命令 */

  function onToolbarClick(e) {
    let btn = e.target;
    while (btn && btn !== toolbar && !(btn.classList && btn.classList.contains('ade-tb'))) { btn = btn.parentNode; }
    if (!btn || btn === toolbar) { return; }

    const cmd = btn.getAttribute('data-cmd');
    if (cmd === 'link') { openLinkDialog(); }
    else if (cmd === 'image') { pickFile(); }
    else if (cmd === 'tag') { openTagDialog(); }
  }

  /* ---------------------------------------------------------------- 超链接 */

  function openLinkDialog() {
    const range = currentRange();
    const anchor = closestTag(range.startContainer, 'A');
    let text = String(range.toString() || '');
    let href = '';

    if (anchor) {
      href = anchor.getAttribute('href') || '';
      if (text === '') { text = anchor.textContent || ''; }
    }

    const urlInput = Q.el('input', { type: 'text', class: 'ade-input', placeholder: 'https://example.com', value: href });
    const txtInput = Q.el('input', { type: 'text', class: 'ade-input', placeholder: '链接显示的文字', value: text });
    const body = Q.el('div', null, [
      Q.el('div', { class: 'ade-field' }, [
        Q.el('span', { class: 'ade-label', text: '链接地址' }),
        Q.el('div', { class: 'ade-control' }, [urlInput])
      ]),
      Q.el('div', { class: 'ade-field' }, [
        Q.el('span', { class: 'ade-label', text: '显示文字' }),
        Q.el('div', { class: 'ade-control' }, [txtInput])
      ])
    ]);

    Q.dialog({
      title: anchor ? '修改超链接' : '插入超链接',
      body: body,
      okText: '确定',
      onOk: function () {
        const url = linkHref(String(urlInput.value || '').trim());
        if (url === '') {
          Q.toast('链接地址无效：请填写以 http:// 或 https:// 开头的完整网址', 'error');
          return false;
        }
        const label = String(txtInput.value || '').trim() || url;

        // 先还焦到编辑区，否则光标不会停在新链接上
        editor.focus();

        if (anchor) {
          anchor.setAttribute('href', url);
          if (anchor.textContent !== label) { anchor.textContent = label; }
          markDirty();
          return;
        }

        const a = document.createElement('a');
        a.setAttribute('href', url);
        a.textContent = label;

        const frag = document.createDocumentFragment();
        frag.appendChild(a);
        insertFragment(frag, range);
      }
    });
  }

  /* ---------------------------------------------------------------- 上传 */

  function canUpload() {
    if (rights.upload) { return true; }
    Q.toast('你没有上传图片的权限', 'error');
    return false;
  }

  function extOf(file) {
    const name = file.name || '';
    const i = name.lastIndexOf('.');
    const ext = i > 0 ? name.slice(i + 1).toLowerCase() : '';
    if (config.uploadExts.indexOf(ext) >= 0) { return ext; }

    const m = /^image\/(jpeg|jpg|png|gif|webp|bmp)$/i.exec(file.type || '');
    if (m) { return m[1].toLowerCase() === 'jpeg' ? 'jpg' : m[1].toLowerCase(); }
    return '';
  }

  /**
   * 图片超站点上传体积上限的持久提示弹窗（不用 toast：两秒就消，用户看不出原因）。
   * 设置项路径取自内核 c_system_admin.php:1685（tab5「后台设置」内 allow_upload_size）；
   * 后台 tab 脚本加载时强制 default-tab，不读 location.hash，#tab5 深链无效，只能用文字指引。
   *
   * @param {number} maxMb 站点允许的单文件上限（MB）
   * @param {Array}  names 被拒图片名
   * @param {string} done  结果措辞
   */
  function showSizeLimit(maxMb, names, done) {
    const body = document.createElement('div');

    body.appendChild(Q.el('p', {
      text: '以下 ' + names.length + ' 张图片超过站点允许的上传大小（' + maxMb + ' MB），' + done + '：'
    }));

    const ul = Q.el('ul', { class: 'ade-size-list' });
    names.forEach(function (n) { ul.appendChild(Q.el('li', { text: n })); });
    body.appendChild(ul);

    if (rights.setting && config.adminSetting) {
      body.appendChild(Q.el('p', { class: 'ade-hint' }, [
        '修改位置：',
        Q.el('a', { href: config.adminSetting, target: '_blank', rel: 'noopener', text: '网站设置' }),
        ' → 切到「后台设置」标签 → 「允许上传文件的大小(单位MB)」，保存后回到本页重试。'
      ]));
    } else {
      body.appendChild(Q.el('p', {
        class: 'ade-hint',
        text: '修改位置：原生后台 → 网站设置 → 「后台设置」标签 → 「允许上传文件的大小(单位MB)」。你的账号没有网站设置权限，需要请管理员调整。'
      }));
    }

    body.appendChild(Q.el('p', {
      class: 'ade-hint',
      text: '注意：该项还受服务器 PHP 的 upload_max_filesize 与 post_max_size 约束，只改网站设置可能不生效。'
    }));

    Q.dialog({ title: '图片超出大小限制', body: body, okText: '知道了', cancelText: null });
  }

  function normalizeFiles(list) {
    const out = [];
    const maxMb = config.uploadMaxMb || 2;
    const max = maxMb * 1048576;
    const oversize = [];

    Array.prototype.slice.call(list || []).forEach(function (file) {
      if (!file) { return; }
      const named = !!(file.name && file.name.indexOf('.') > 0);
      const label = named ? file.name : '未命名图片';
      const ext = extOf(file);
      if (ext === '') {
        Q.toast('已跳过不支持的文件：' + label + '（仅 ' + config.uploadExts.join(' / ') + '）', 'error');
        return;
      }
      if (file.size > max) {
        oversize.push(label + '（' + Q.formatBytes(file.size) + '）');
        return;
      }
      out.push({
        file: file,
        ext: ext,
        name: named ? file.name : ('paste-' + Date.now() + '-' + out.length + '.' + ext)
      });
    });

    // 多张一起超限只弹一次：逐个弹会互相覆盖
    if (oversize.length > 0) { showSizeLimit(maxMb, oversize, '已跳过'); }

    return out;
  }

  function sendFile(item, cb) {
    const fd = new FormData();
    fd.append('file', item.file, item.name);
    Q.request('upload', { method: 'POST', form: fd }, cb);
  }

  function sendBase64(dataUrl, name, cb) {
    const i = dataUrl.indexOf(',');
    if (i < 0) { cb('图片数据无效', null); return; }
    Q.request('upload', { method: 'POST', data: { base64: dataUrl.slice(i + 1), name: name } }, cb);
  }

  function nameOfDataUrl(dataUrl) {
    const m = /^data:image\/(png|jpe?g|gif|webp|bmp);/i.exec(dataUrl);
    let ext = m ? m[1].toLowerCase() : 'png';
    if (ext === 'jpeg') { ext = 'jpg'; }
    if (config.uploadExts.indexOf(ext) < 0) { ext = 'png'; }
    return 'paste-' + Date.now() + '.' + ext;
  }

  function dataUrlToSiteUrl(dataUrl, cb) {
    uploading++;
    updateStat();
    sendBase64(dataUrl, nameOfDataUrl(dataUrl), function (err, res, code) {
      uploading--;
      updateStat();
      cb(err, res, code);
    });
  }

  /* 图片本地化开启时：图片先留编辑器里预览，保存时才转存本站，避免留下孤儿附件 */
  function localizeOn() {
    return !!settings.localize_image;
  }

  function registerPending(img, payload) {
    const id = 'p' + (++pendingSeq);
    payload.id = id;
    pendingMap[id] = payload;
    img.setAttribute('data-ade-pending', id);
    return id;
  }

  function sendPending(p, cb) {
    if (p.kind === 'file') {
      sendFile({ file: p.file, name: p.name }, cb);
      return;
    }
    if (p.kind === 'data') {
      sendBase64(p.data, p.name, cb);
      return;
    }
    Q.request('uploadurl', { method: 'POST', data: { url: p.url, name: p.name || '' } }, cb);
  }

  /* 把待转存图片全部落库并换成站内地址；任一失败即放弃保存，不让 blob:/站外地址进正文。
     cb(failed, sizeNames)：sizeNames 单独回传，让调用方对「超限」给指引而不是笼统报错 */
  function flushPendingImages(cb) {
    const list = Q.qsa('img[data-ade-pending]', editor);
    const total = list.length;
    let i = 0;
    let failed = '';
    const sizeNames = [];

    if (total === 0) { cb('', sizeNames); return; }

    function step() {
      if (i >= total) { cb(failed, sizeNames); return; }

      const img = list[i++];
      const p = pendingMap[img.getAttribute('data-ade-pending')];
      if (!p) { step(); return; }

      Q.toast('正在转存图片 ' + i + '/' + total + '…');
      sendPending(p, function (err, res, code) {
        if (err || !res || !res.url) {
          if (failed === '') { failed = err || '图片转存失败'; }
          if (code === 27) { sizeNames.push(p.name || '图片'); }
          step();
          return;
        }

        const old = img.getAttribute('src') || '';
        if (old.indexOf('blob:') === 0) { URL.revokeObjectURL(old); }
        img.removeAttribute('data-ade-pending');
        img.removeAttribute('class');
        img.setAttribute('src', res.url);
        delete pendingMap[p.id];
        step();
      });
    }

    step();
  }

  /* 先插占位 <img> 再回填 src：多图保持顺序，也让上传状态可见 */
  function uploadItems(items, range) {
    if (items.length === 0) { return; }
    if (!canUpload()) { return; }

    /* 延迟转存：本地文件先给一个 blob 预览地址，保存时再真正落库 */
    if (localizeOn()) {
      const pendFrag = document.createDocumentFragment();
      items.forEach(function (item) {
        const pimg = document.createElement('img');
        pimg.className = 'is-pending';
        pimg.alt = item.file.name || '';
        pimg.setAttribute('src', URL.createObjectURL(item.file));
        registerPending(pimg, { kind: 'file', file: item.file, name: item.name });
        pendFrag.appendChild(pimg);
      });
      insertFragment(pendFrag, range);
      updateStat();
      return;
    }

    const frag = document.createDocumentFragment();
    items.forEach(function (item) {
      const img = document.createElement('img');
      img.className = 'is-uploading';
      img.alt = item.file.name || '';
      item.img = img;
      frag.appendChild(img);
    });
    insertFragment(frag, range);

    items.forEach(function (item) {
      uploading++;
      sendFile(item, function (err, res) {
        uploading--;
        if (err || !res || !res.url) {
          item.img.className = 'is-failed';
          item.img.alt = '上传失败';
          Q.toast(err || '图片上传失败', 'error', 4000);
        } else {
          item.img.removeAttribute('class');
          item.img.setAttribute('src', res.url);
          item.img.setAttribute('alt', item.file.name || '');
          markDirty();
        }
        updateStat();
      });
    });
    updateStat();
  }

  /* 粘贴的 HTML 里内嵌的 data:image —— 换成站内地址，避免超大 base64 被存进正文。
     这条路径没有 normalizeFiles 那样的前端预检，服务端 27 是唯一的超限信号 */
  function uploadDataImg(img, dataUrl) {
    dataUrlToSiteUrl(dataUrl, function (err, res, code) {
      if (err || !res || !res.url) {
        if (img.parentNode) { img.parentNode.removeChild(img); }
        if (code === 27) { showSizeLimit(config.uploadMaxMb || 2, ['粘贴的图片'], '未能上传'); }
        else { Q.toast(err || '粘贴的图片上传失败', 'error', 4000); }
        return;
      }
      img.removeAttribute('class');
      img.setAttribute('src', res.url);
      markDirty();
    });
  }

  function pickFile() {
    if (!canUpload()) { return; }
    pendingRange = currentRange();
    fileInput.value = '';
    fileInput.click();
  }

  /* ---------------------------------------------------------------- 粘贴 */

  function clipboardFiles(cd) {
    const out = [];
    let i;
    if (cd.files && cd.files.length) {
      for (i = 0; i < cd.files.length; i++) { out.push(cd.files[i]); }
      return out;
    }
    if (cd.items && cd.items.length) {
      for (i = 0; i < cd.items.length; i++) {
        if (cd.items[i].kind === 'file') {
          const f = cd.items[i].getAsFile();
          if (f) { out.push(f); }
        }
      }
    }
    return out;
  }

  function getData(cd, type) {
    try { return cd.getData(type) || ''; } catch (e) { return ''; }
  }

  /* 粘贴图常带内联尺寸，优先级高于样式表，会压过编辑器的统一尺寸；这里插入前摘掉尺寸/定位声明。
     只在粘贴入口做：加载已有正文不洗，免得改掉库里存的内联样式。 */
  const SIZE_STYLE_RE = /(^|;)\s*(?:width|height|max-width|max-height|min-width|min-height|zoom|transform|float)\s*:[^;]*/gi;

  function normalizeImgSize(img) {
    const style = img.getAttribute('style');
    if (style === null) { return; }

    const kept = style.replace(SIZE_STYLE_RE, '$1')
      .replace(/;{2,}/g, ';')
      .replace(/^\s*;+|;+\s*$/g, '')
      .trim();
    if (kept === '') { img.removeAttribute('style'); } else { img.setAttribute('style', kept); }
  }

  // 常见跟踪参数：命中即从查询串摘掉，其余参数与锚点保留
  const TRACK_PARAM_RE = /^(?:utm_[a-z0-9_]+|gclid|fbclid|msclkid|dclid|yclid|igshid|spm|scm|share_token|share_source|share_medium|share_session_id|wxfrom|wx_lazy|wx_co|from_source|from_spmid|s_cid|_hsenc|mc_cid|mc_eid)$/i;

  function stripTrackParams(url) {
    const u = String(url || '');
    const q = u.indexOf('?');
    if (q < 0) { return u; }

    const hashAt = u.indexOf('#', q);
    const hash = hashAt >= 0 ? u.slice(hashAt) : '';
    const query = u.slice(q + 1, hashAt >= 0 ? hashAt : u.length);
    const kept = [];

    query.split('&').forEach(function (pair) {
      if (pair === '') { return; }
      let name = pair.split('=')[0];
      try { name = decodeURIComponent(name); } catch (e) { /* 解不开就按原文比对 */ }
      if (TRACK_PARAM_RE.test(name)) { return; }
      kept.push(pair);
    });

    return u.slice(0, q) + (kept.length ? '?' + kept.join('&') : '') + hash;
  }

  /* 取 URL 末段当文件名，仅用于服务端推断图片类型（真实类型以字节头为准） */
  function nameFromUrl(url) {
    const path = String(url || '').split('#')[0].split('?')[0];
    const base = path.slice(path.lastIndexOf('/') + 1);
    return base === '' ? 'remote.png' : base;
  }

  /* 粘贴清洗：去掉全部 <a>（文字图片保留）+ 剥掉图片地址跟踪参数。只在粘贴入口执行 */
  function stripPastedLinks(frag) {
    if (!frag.querySelectorAll) { return; }

    Array.prototype.forEach.call(frag.querySelectorAll('a'), function (a) {
      if (!a.parentNode) { return; }
      while (a.firstChild) { a.parentNode.insertBefore(a.firstChild, a); }
      a.parentNode.removeChild(a);
    });

    Array.prototype.forEach.call(frag.querySelectorAll('img'), function (img) {
      const src = img.getAttribute('src') || '';
      if (/^https?:\/\//i.test(src)) { img.setAttribute('src', stripTrackParams(src)); }
    });
  }

  function pasteHtml(html, range) {
    const frag = parseFragment(html);
    stripPastedLinks(frag);
    const pending = [];

    const imgs = frag.querySelectorAll ? frag.querySelectorAll('img') : [];
    Array.prototype.forEach.call(imgs, function (img) {
      normalizeImgSize(img);

      const src = img.getAttribute('src') || '';
      if (/^data:image\//i.test(src)) {
        /* 延迟转存：先留着 data: 预览，保存时再上传 */
        if (localizeOn()) {
          img.className = 'is-pending';
          registerPending(img, { kind: 'data', data: src, name: nameOfDataUrl(src) });
          return;
        }
        img.removeAttribute('src');
        img.className = 'is-uploading';
        pending.push({ img: img, data: src });
        return;
      }

      if (src === '') {
        if (img.parentNode) { img.parentNode.removeChild(img); }
        return;
      }

      /* 站外图片：延迟转存时一并下载回本站；没有上传权限就保持原地址 */
      if (localizeOn() && rights.upload && /^https?:\/\//i.test(src)) {
        img.className = 'is-pending';
        registerPending(img, { kind: 'url', url: src, name: nameFromUrl(src) });
      }
    });

    insertFragment(frag, range);

    if (pending.length === 0) { return; }
    if (!canUpload()) {
      pending.forEach(function (p) {
        if (p.img.parentNode) { p.img.parentNode.removeChild(p.img); }
      });
      return;
    }
    Q.toast('正在上传粘贴的 ' + pending.length + ' 张图片…');
    pending.forEach(function (p) { uploadDataImg(p.img, p.data); });
  }

  function pasteText(text, range) {
    text = String(text || '').replace(/\r\n?/g, '\n');
    if (text === '') { return; }

    const lines = text.split('\n');
    const frag = document.createDocumentFragment();

    if (lines.length === 1) {
      frag.appendChild(document.createTextNode(lines[0]));
    } else {
      lines.forEach(function (line) {
        const p = document.createElement('p');
        p.appendChild(document.createTextNode(line === '' ? '\u00a0' : line));
        frag.appendChild(p);
      });
    }
    insertFragment(frag, range);
  }

  function onPaste(e) {
    const cd = e.clipboardData;
    if (!cd) { return; }          /* 合成 paste 事件可能不带剪贴板：放行默认粘贴 */
    e.preventDefault();

    const range = currentRange();

    const files = normalizeFiles(clipboardFiles(cd));
    if (files.length > 0) { uploadItems(files, range); return; }

    const html = getData(cd, 'text/html');
    if (html !== '' && settings.paste_html) { pasteHtml(html, range); return; }

    pasteText(getData(cd, 'text/plain'), range);
  }

  /* ---------------------------------------------------------------- 拖拽 */

  function hasFiles(dt) {
    if (!dt) { return false; }
    if (dt.files && dt.files.length) { return true; }
    if (dt.types) {
      for (let i = 0; i < dt.types.length; i++) { if (dt.types[i] === 'Files') { return true; } }
    }
    return false;
  }

  function onDragOver(e) {
    if (!hasFiles(e.dataTransfer)) { return; }
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    editor.classList.add('is-dragover');
  }

  function onDrop(e) {
    editor.classList.remove('is-dragover');
    if (!hasFiles(e.dataTransfer)) { return; }
    e.preventDefault();
    uploadItems(normalizeFiles(e.dataTransfer.files), rangeFromPoint(e.clientX, e.clientY));
  }

  /* ---------------------------------------------------------------- 灯箱
     点正文图片放大看原图，可就地删除（只摘正文里的图，不动附件库原文件）。 */

  /* 100% 时宽度交给 CSS 适配；缩放需按原图尺寸算基准再乘倍数（max-width 只能限制不能放大） */
  function lightboxFitWidth() {
    const nw = lightboxImg.naturalWidth || 0;
    const nh = lightboxImg.naturalHeight || 0;
    const maxW = Math.min(window.innerWidth * 0.92, 1200);
    const maxH = window.innerHeight * (window.innerWidth <= 640 ? 0.66 : 0.74);
    if (nw <= 0 || nh <= 0) { return maxW; }
    return Math.min(maxW, nw, maxH * nw / nh);
  }

  function applyLightboxZoom() {
    if (!lightboxImg) { return; }
    if (lightboxFit <= 0) { lightboxFit = lightboxFitWidth(); }

    lightboxImg.style.width = Math.round(lightboxFit * lightboxZoom) + 'px';
    lightboxImg.style.height = 'auto';
    lightboxImg.style.maxWidth = 'none';
    lightboxImg.style.maxHeight = 'none';

    if (lightboxScale) { lightboxScale.textContent = Math.round(lightboxZoom * 100) + '%'; }
    if (lightboxOut) { lightboxOut.disabled = lightboxZoom <= LIGHTBOX_MIN + 0.001; }
    if (lightboxIn) { lightboxIn.disabled = lightboxZoom >= LIGHTBOX_MAX - 0.001; }
  }

  function zoomLightbox(factor) {
    const next = Math.min(LIGHTBOX_MAX, Math.max(LIGHTBOX_MIN, lightboxZoom * factor));
    if (Math.abs(next - lightboxZoom) < 0.001) { return; }
    lightboxZoom = next;
    applyLightboxZoom();
  }

  function resetLightboxZoom() {
    lightboxZoom = 1;
    lightboxFit = 0;
    if (lightboxImg) { lightboxImg.removeAttribute('style'); }
    if (lightboxScale) { lightboxScale.textContent = '100%'; }
    if (lightboxOut) { lightboxOut.disabled = false; }
    if (lightboxIn) { lightboxIn.disabled = false; }
  }

  function openLightbox(img) {
    if (!lightbox || !lightboxImg) { return; }
    lightboxTarget = img;
    resetLightboxZoom();
    lightboxImg.setAttribute('src', img.getAttribute('src') || '');
    lightboxImg.setAttribute('alt', img.getAttribute('alt') || '');
    lightbox.hidden = false;
    lightbox.classList.add('is-open');
  }

  function closeLightbox() {
    if (!lightbox) { return; }
    lightbox.hidden = true;
    lightbox.classList.remove('is-open');
    if (lightboxImg) {
      lightboxImg.removeAttribute('src');
      lightboxImg.removeAttribute('style');
    }
    lightboxTarget = null;
  }

  function deleteLightboxImage() {
    const img = lightboxTarget;
    closeLightbox();
    if (!img || !img.parentNode) { return; }

    // 未转存的本地预览图：释放 blob 地址
    const src = img.getAttribute('src') || '';
    if (src.indexOf('blob:') === 0) { URL.revokeObjectURL(src); }

    img.parentNode.removeChild(img);
    markDirty();
    Q.toast('图片已从正文移除', 'ok');
  }

  // 只在点到真实图片时开灯箱：占位图与失败图没有可看的原图
  function onEditorClick(e) {
    const img = e.target;
    if (!img || img.tagName !== 'IMG') { return; }
    const cls = img.getAttribute('class') || '';
    if (cls === 'is-uploading' || cls === 'is-failed') { return; }
    openLightbox(img);
  }

  /* ---------------------------------------------------------------- 标签
     标签只存 tags 数组，由工具栏「标签」弹窗统一增删，点「确定」才写回。 */

  function tagString() {
    return tags.join(',');
  }

  // 与内核对齐：逗号及 ;，、 都当分隔符，粘贴 "a,b、c" 也能一次加进来
  function splitTagNames(raw) {
    const out = [];
    String(raw || '').split(/[,，;；、]/).forEach(function (part) {
      let name = part.replace(/\s+/g, ' ').trim();
      if (name === '') { return; }
      if (name.length > 50) { name = name.slice(0, 50); }
      if (out.indexOf(name) < 0) { out.push(name); }
    });
    return out;
  }

  // 常用标签来自 #adeTagCommon 数据岛；坏数据退化成空列表
  function readCommonTags() {
    const node = document.getElementById('adeTagCommon');
    let arr = [];
    try { arr = node ? JSON.parse(node.textContent || '[]') : []; } catch (e) { arr = []; }
    if (Object.prototype.toString.call(arr) !== '[object Array]') { return []; }

    return arr.map(function (n) { return String(n || '').trim(); })
      .filter(function (n) { return n !== ''; });
  }

  /* 标签编辑器：一套 DOM 两种挂载。桌面端内嵌右栏即时写回；手机端挂弹窗，确定才写回。
     getList/setList 让两处共用同一份交互逻辑。 */
  const tagEditors = [];    /* 已挂载的实例：任一处改动后统一刷新，避免两处显示不一致 */

  function refreshTagEditors() {
    tagEditors.forEach(function (ui) { ui.render(); });
  }

  function createTagEditor(getList, setList, live) {
    /* 输入框在上、已选芯片紧随其下（与「添加的标签放在输入框下面」一致） */
    const input = Q.el('input', {
      type: 'text', class: 'ade-input',
      placeholder: '输入标签名，回车添加（可用逗号一次加多个）',
      maxlength: '200', autocomplete: 'off'
    });
    const chipBox = Q.el('div', { class: 'ade-chips' });
    /* 常用标签：站点里用得最多的标签，点一下即绑定/取消 */
    const commonBox = Q.el('div', { class: 'ade-tag-common' });
    const commonWrap = Q.el('div', { class: 'ade-tag-common-wrap' }, [
      Q.el('span', { class: 'ade-tag-common-title', text: '常用标签' }),
      commonBox
    ]);
    const commonPicks = [];   /* 常用标签按钮，只负责同步高亮态 */

    function commit(list) {
      setList(list);
      if (live) { markDirty(); }
      refreshTagEditors();
    }

    function render() {
      const list = getList();
      Q.clear(chipBox);
      list.forEach(function (name) {
        chipBox.appendChild(Q.el('span', { class: 'ade-chip', 'data-name': name }, [
          Q.el('b', { text: name }),
          Q.el('button', { type: 'button', 'aria-label': '移除标签 ' + name, text: '×' })
        ]));
      });
      chipBox.classList.toggle('is-empty', list.length === 0);

      /* 常用标签的节点只建一次，之后只改高亮：整批重建会连滚动位置一起丢掉 */
      commonPicks.forEach(function (btn) {
        const on = list.indexOf(btn.getAttribute('data-name')) >= 0;
        btn.classList.toggle('is-picked', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function addRaw(raw) {
      const list = getList().slice();
      let added = 0;
      splitTagNames(raw).forEach(function (name) {
        if (list.indexOf(name) >= 0) { return; }
        if (list.length >= MAX_TAGS) {
          Q.toast('最多只能有 ' + MAX_TAGS + ' 个标签', 'error');
          return;
        }
        list.push(name);
        added++;
      });
      if (added > 0) { commit(list); }
      return added;
    }

    /* 常用标签与芯片 × 走同一个动作：已绑定就取消、未绑定就绑定 */
    function toggle(name) {
      const list = getList().slice();
      const at = list.indexOf(name);
      if (at >= 0) {
        list.splice(at, 1);
      } else {
        if (list.length >= MAX_TAGS) {
          Q.toast('最多只能有 ' + MAX_TAGS + ' 个标签', 'error');
          return;
        }
        list.push(name);
      }
      commit(list);
    }

    /* 芯片上的 × 用事件委托：render() 会整批重建节点，逐个绑定会留失效引用 */
    Q.on(chipBox, 'click', function (e) {
      const chip = e.target && e.target.tagName === 'BUTTON' ? e.target.parentNode : null;
      const name = chip ? chip.getAttribute('data-name') : '';
      if (name !== '') { toggle(name); }
    });
    Q.on(input, 'keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',' || e.key === '，') {
        e.preventDefault();
        if (addRaw(input.value) > 0) { input.value = ''; }
      }
    });
    Q.on(commonBox, 'click', function (e) {
      let btn = e.target;
      while (btn && btn !== commonBox && !(btn.classList && btn.classList.contains('ade-tag-pick'))) {
        btn = btn.parentNode;
      }
      if (!btn || btn === commonBox) { return; }
      const name = btn.getAttribute('data-name') || '';
      if (name !== '') { toggle(name); }
    });

    commonTags.forEach(function (name) {
      const btn = Q.el('button', { type: 'button', class: 'ade-tag-pick', text: name, 'data-name': name });
      btn.setAttribute('aria-pressed', 'false');
      commonBox.appendChild(btn);
      commonPicks.push(btn);
    });

    /* 站点有常用标签时才补那一块；只有账号没有建标签权限时才补一行说明 */
    const el = Q.el('div', { class: 'ade-tag-editor' }, [input, chipBox]);
    if (commonTags.length > 0) { el.appendChild(commonWrap); }
    if (!rights.tag) {
      el.appendChild(Q.el('p', { class: 'ade-hint', text: '你的账号没有创建标签的权限，只能绑定站点已有的标签名。' }));
    }

    const ui = { el: el, render: render };
    tagEditors.push(ui);
    render();
    return ui;
  }

  /* 手机端（≤900px）：工具栏按钮打开弹窗，改动先落在副本上，点「确定」写回。
     实例复用不重建：弹窗有取消/确定/ESC/遮罩四个关闭入口而无销毁回调，每次新建都会
     往 tagEditors 里留下摘不掉的死实例，令每次改动连带重渲染全部历史实例。 */
  let tagDialogUi = null;
  let tagDialogList = [];

  function openTagDialog() {
    tagDialogList = tags.slice();
    if (!tagDialogUi) {
      tagDialogUi = createTagEditor(
        function () { return tagDialogList; },
        function (v) { tagDialogList = v; },
        false
      );
    }
    tagDialogUi.render();

    Q.dialog({
      title: '管理标签',
      body: tagDialogUi.el,
      okText: '确定',
      onOk: function () {
        if (tagDialogList.join(',') !== tags.join(',')) {
          tags = tagDialogList;
          markDirty();
          refreshTagEditors();
        }
      }
    });
  }

  /* 窄屏判定：断点须与 editor.css 的媒体查询一致（900px 分界） */
  function narrow() {
    return window.matchMedia('(max-width: 900px)').matches;
  }

  function tagsUseDialog() { return narrow(); }

  /* 桌面端（>900px）：把同一套编辑器嵌进右栏的标签字段。
     可重复调用：窄屏下 .ade-field-tags 只是被 CSS 隐藏，节点留在原地，回宽屏无需重建。 */
  let panelTagUi = null;

  function mountPanelTagEditor() {
    const host = document.getElementById('adeTagHost');
    if (!host || panelTagUi || tagsUseDialog()) { return; }
    panelTagUi = createTagEditor(
      function () { return tags; },
      function (v) { tags = v; },
      true
    );
    host.appendChild(panelTagUi.el);
  }

  /* ---------------------------------------------------------------- 发布抽屉（手机端）
     窄屏把右栏整块收成底部抽屉，默认只露拉手；搬同一份 DOM（复制会撞 id），
     JS 没跑时右栏控件仍在原位（是「没搬」而非「藏起来」）。 */

  function mountDrawer() {
    if (!drawerBody || !trio || !timeField || !actions) { return; }
    if (narrow()) {
      if (trio.parentNode !== drawerBody) { drawerBody.appendChild(trio); }
      if (timeField.parentNode !== drawerBody) { drawerBody.appendChild(timeField); }
      if (actions.parentNode !== drawerBody) { drawerBody.appendChild(actions); }
    } else {
      if (trioHome && trio.parentNode !== trioHome) { trioHome.appendChild(trio); }
      if (timeHome && timeField.parentNode !== timeHome) { timeHome.appendChild(timeField); }
      if (actionsHome && actions.parentNode !== actionsHome) { actionsHome.appendChild(actions); }
    }
  }

  // 抽屉开关：true 开 / false 关 / 不传翻转；aria-expanded 与 .is-open 必须一起切
  function setDrawer(open) {
    if (!drawer) { return; }
    const next = open === undefined ? !drawer.classList.contains('is-open') : !!open;
    drawer.classList.toggle('is-open', next);
    if (drawerHandle) {
      drawerHandle.setAttribute('aria-expanded', next ? 'true' : 'false');
      const label = drawerHandle.querySelector('.ade-drawer-handle-label');
      if (label) { label.textContent = next ? '收起' : '发布文章'; }
    }
  }

  // 点抽屉外关闭（判断 target 是否在抽屉树内）
  function onDocClickForDrawer(e) {
    if (!drawer || !drawer.classList.contains('is-open')) { return; }
    let n = e.target;
    while (n && n !== document.body) {
      if (n === drawer) { return; }
      n = n.parentNode;
    }
    setDrawer(false);
  }

  function onEscForDrawer(e) {
    if (e.key === 'Escape' && drawer && drawer.classList.contains('is-open')) {
      setDrawer(false);
    }
  }

  /* ---------------------------------------------------------------- 状态栏 */

  function isEmpty() {
    const text = (editor.textContent || '').replace(/[\u00a0\s]/g, '');
    return text === '' && !editor.querySelector('img[src]');
  }

  function markDirty() {
    dirty = true;
    editor.classList.toggle('is-empty', isEmpty());
    updateStat();
  }

  function updateStat() {
    if (!statEl) { return; }
    editor.classList.toggle('is-empty', isEmpty());

    const text = (editor.textContent || '').replace(/[\u00a0\s]/g, '');
    let s = '约 ' + text.length + ' 字';

    if (uploading > 0) {
      s = '正在上传 ' + uploading + ' 张图片…';
    } else if (!narrow()) {
      // 手机端只留字数，图片张数与「未保存修改」不占位
      const imgs = editor.querySelectorAll('img[src]').length;
      if (imgs > 0) { s += ' · ' + imgs + ' 张图'; }
      if (dirty) { s += ' · 有未保存的修改'; }
    }

    statEl.textContent = s;
  }

  /* ---------------------------------------------------------------- 保存 */

  function getContent() {
    const clone = editor.cloneNode(true);
    // 兜底：占位图与 data:/blob:/ 未落库图一律不入库
    Array.prototype.forEach.call(clone.querySelectorAll('img'), function (img) {
      const src = img.getAttribute('src') || '';
      const cls = img.getAttribute('class') || '';
      if (src === '' || /^data:/i.test(src) || /^blob:/i.test(src) || img.hasAttribute('data-ade-pending')
          || cls === 'is-uploading' || cls === 'is-failed' || cls === 'is-pending') {
        if (img.parentNode) { img.parentNode.removeChild(img); }
      }
    });
    return clone.innerHTML;
  }

  function collect() {
    return {
      id: articleId,
      title: titleInput.value.trim(),
      content: getContent(),
      intro: intro,
      cateid: cateSel ? cateSel.value : 0,
      status: statusSel ? statusSel.value : 1,
      /* 置顶下拉直接把内核登记的取值（0/1/2/4）交回服务端，不再压平成开/关 */
      istop: topSel ? (parseInt(topSel.value, 10) || 0) : 0,
      posttime: timeInput ? timeInput.value : '',
      tag: tagString()
    };
  }

  function save(btn) {
    if (uploading > 0) {
      Q.toast('还有 ' + uploading + ' 张图片在上传，请稍候再保存', 'error');
      return;
    }

    const data = collect();
    if (data.title === '' && isEmpty()) {
      Q.toast('标题和正文不能都为空', 'error');
      return;
    }
    if (data.posttime !== '' && isNaN(Date.parse(data.posttime))) {
      Q.toast('时间格式不正确', 'error');
      return;
    }

    Q.busy(btn, true, '保存中');

    // 先落库待转存图片，全部成功再提交正文，避免 blob:/站外地址进库
    flushPendingImages(function (failed, sizeNames) {
      if (sizeNames.length > 0) {
        Q.busy(btn, false);
        showSizeLimit(config.uploadMaxMb || 2, sizeNames, '未能转存');
        return;
      }
      if (failed !== '') {
        Q.busy(btn, false);
        Q.toast('图片转存失败：' + failed, 'error', 4000);
        return;
      }
      // 成功路径不复位 busy：submit() 还要发一次请求，提前解禁会让按钮可连点
      submit(btn);
    });
  }

  /* 正文提交：图片此时都已换成站内地址，这里才真正落库 */
  function submit(btn) {
    const data = collect();
    Q.request('save', { method: 'POST', data: data }, function (err, res) {
      Q.busy(btn, false);
      if (err) { Q.toast(err, 'error', 4000); return; }
      if (!res) { Q.toast('保存失败，请重试', 'error'); return; }

      articleId = res.id || articleId;
      // 跳转前清脏标记，否则 beforeunload 会拦下导航
      dirty = false;

      // 公开 / 草稿 / 审核统一走设置里的「发布后去向」
      if (!settings.publish_jump) {
        window.location.href = config.entry + '?act=editor&saved=' + articleId;
        return;
      }
      window.location.href = config.entry + '?act=manage'
        + (articleId > 0 ? '&saved=' + articleId : '');
    });
  }

  /* ---------------------------------------------------------------- 初始化 */

  function init(cfg) {
    config = cfg || Q.config || {};
    settings = config.settings || {};
    rights = config.rights || {};

    editor = document.getElementById('adeContent');
    if (!editor) { return; }

    toolbar = document.getElementById('adeToolbar');
    titleInput = document.getElementById('adeTitle');
    statEl = document.getElementById('adeStat');
    fileInput = document.getElementById('adeFile');
    cateSel = document.getElementById('adeCate');
    statusSel = document.getElementById('adeStatus');
    topSel = document.getElementById('adeTop');
    timeInput = document.getElementById('adeTime');
    trio = document.querySelector('.ade-trio');
    actions = document.querySelector('.ade-actions');
    /* 时间字段是含 label + input 的整行，搬进抽屉时整块带走，文字标签与「时间」二字都不会丢 */
    timeField = timeInput ? timeInput.closest('.ade-field') : null;
    publishBtn = document.getElementById('adePublish');
    lightbox = document.getElementById('adeLightbox');
    lightboxImg = document.getElementById('adeLightboxImg');
    lightboxDel = document.getElementById('adeLightboxDel');
    lightboxClose = document.getElementById('adeLightboxClose');
    lightboxIn = document.getElementById('adeLightboxIn');
    lightboxOut = document.getElementById('adeLightboxOut');
    lightboxScale = document.getElementById('adeLightboxScale');

    const island = document.getElementById('adeArticle');
    let payload = {};
    try { payload = island ? JSON.parse(island.textContent || '') : {}; } catch (e) { payload = {}; }
    articleId = payload.id || 0;
    intro = payload.intro || '';
    tags = Object.prototype.toString.call(payload.tags) === '[object Array]'
      ? payload.tags.map(function (t) { return String(t || '').trim(); })
          .filter(function (t) { return t !== ''; })
          .slice(0, MAX_TAGS)
      : [];
    commonTags = readCommonTags();

    editor.style.minHeight = (settings.editor_height || 450) + 'px';

    try {
      document.execCommand('styleWithCSS', false, false);
      document.execCommand('defaultParagraphSeparator', false, 'p');
    } catch (e) { /* 部分浏览器不支持，忽略 */ }

    setHtml(editor, payload.content || '');

    /* 没有对应权限的控件直接不显示，而不是留一个点了报错的按钮 */
    if (!rights.upload && toolbar) {
      const imgBtn = toolbar.querySelector('[data-cmd="image"]');
      if (imgBtn) { imgBtn.hidden = true; }
    }
    if (articleId === 0 && !rights.new) {
      publishBtn.disabled = true;
      statEl.textContent = '你的账号没有新建文章的权限';
    }
    /* --- 事件绑定 --- */
    Q.on(toolbar, 'click', onToolbarClick);
    /* 工具栏按钮抢焦点会丢掉编辑区选区 */
    Q.on(toolbar, 'mousedown', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('ade-tb')) { e.preventDefault(); }
    });

    Q.on(editor, 'paste', onPaste);
    Q.on(editor, 'input', markDirty);
    Q.on(editor, 'keyup', saveRange);
    Q.on(editor, 'mouseup', saveRange);
    Q.on(editor, 'focus', saveRange);
    Q.on(editor, 'blur', saveRange);
    Q.on(editor, 'dragover', onDragOver);
    Q.on(editor, 'dragleave', function () { editor.classList.remove('is-dragover'); });
    Q.on(editor, 'drop', onDrop);
    /* 点正文里的图片开灯箱（事件委托：图片是随时增删的） */
    Q.on(editor, 'click', onEditorClick);

    /* 拖到编辑区以外时，阻止浏览器直接打开文件 */
    Q.on(document, 'dragover', function (e) { if (hasFiles(e.dataTransfer)) { e.preventDefault(); } });
    Q.on(document, 'drop', function (e) { if (hasFiles(e.dataTransfer)) { e.preventDefault(); } });

    Q.on(fileInput, 'change', function () {
      uploadItems(normalizeFiles(fileInput.files), pendingRange);
      pendingRange = null;
      fileInput.value = '';
    });

    Q.on(titleInput, 'input', markDirty);
    Q.on(cateSel, 'change', markDirty);
    Q.on(statusSel, 'change', markDirty);
    Q.on(topSel, 'change', markDirty);
    Q.on(timeInput, 'change', markDirty);

    /* --- 灯箱 --- */
    if (lightboxClose) { Q.on(lightboxClose, 'click', closeLightbox); }
    if (lightboxDel) { Q.on(lightboxDel, 'click', deleteLightboxImage); }
    if (lightboxIn) { Q.on(lightboxIn, 'click', function () { zoomLightbox(LIGHTBOX_STEP); }); }
    if (lightboxOut) { Q.on(lightboxOut, 'click', function () { zoomLightbox(1 / LIGHTBOX_STEP); }); }
    if (lightboxImg) {
      /* 原图尺寸要等 load 之后才知道；若那时已经在缩放态，就用真实尺寸重算基准 */
      Q.on(lightboxImg, 'load', function () {
        if (!lightboxTarget || lightboxZoom === 1) { return; }
        lightboxFit = 0;
        applyLightboxZoom();
      });
    }
    if (lightbox) {
      /* 点暗色背景关闭（点图片与按钮本身不关） */
      Q.on(lightbox, 'click', function (e) { if (e.target === lightbox) { closeLightbox(); } });
    }
    Q.on(document, 'keydown', function (e) {
      if (!lightboxTarget) { return; }
      if (e.key === 'Escape') { closeLightbox(); }
      else if (e.key === '+' || e.key === '=') { zoomLightbox(LIGHTBOX_STEP); }
      else if (e.key === '-' || e.key === '_') { zoomLightbox(1 / LIGHTBOX_STEP); }
    });

    Q.on(publishBtn, 'click', function () {
      // 目标状态由「状态」下拉决定（服务端会再夹紧一次）
      save(publishBtn);
    });
    Q.on(document, 'keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
        e.preventDefault();
        if (!publishBtn.disabled) { save(publishBtn); }
      }
    });
    Q.on(window, 'beforeunload', function (e) {
      if (!dirty) { return undefined; }
      e.preventDefault();
      e.returnValue = '';
      return '';
    });

    /* 标签：桌面端把编辑器嵌进右栏字段，手机端保持工具栏弹窗（同一套编辑器） */
    mountPanelTagEditor();

    // 抽屉三个「原位」必须先于 mountDrawer() 记下，否则 appendChild 后 parentNode 已变成抽屉
    drawer = document.getElementById('adeDrawer');
    drawerBody = document.getElementById('adeDrawerBody');
    drawerHandle = document.getElementById('adeDrawerHandle');
    trioHome = trio ? trio.parentNode : null;
    timeHome = timeField ? timeField.parentNode : null;
    actionsHome = actions ? actions.parentNode : null;
    if (drawerHandle) { Q.on(drawerHandle, 'click', function () { setDrawer(); }); }
    Q.on(document, 'click', onDocClickForDrawer);
    Q.on(document, 'keydown', onEscForDrawer);
    mountDrawer();
    // 跨断点时控件归位；回宽屏顺手关抽屉（宽屏里抽屉本就 display:none）
    window.matchMedia('(max-width: 900px)').addEventListener('change', function () {
      if (!narrow()) { setDrawer(false); }
      mountDrawer();
      mountPanelTagEditor();
      updateStat();
    });

    updateStat();
    editor.classList.toggle('is-empty', isEmpty());
  }

  function start(cfg) {
    if (started) { return; }
    started = true;
    init(cfg);
  }

  window.ADEViewInit = start;

  /* 兜底：DOM 已就绪时 core.js 会立刻调用 ADEViewInit，这里自行补一次启动 */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { start(Q.config); }, false);
  } else {
    start(Q.config);
  }
}(window, document));
