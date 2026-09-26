<?php

/**
 * 自适应编辑器 —— 写文章界面。视图与动作同文件，数据读写走 core/model.php。
 * 正文经 JSON 数据岛下发，前端净化后再插入，不把库里的原始 HTML 直接交给解析器。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/**
 * 工具栏按钮：只保留「链接 / 图片 / 标签」三项，用文字而非图标字体（不引内核 icon.css）。
 *
 * @return array
 */
function ade_editor_buttons()
{
    return array(
        array('link', '链接', '插入或修改超链接'),
        array('image', '图片', '上传并插入图片'),
        array('tag', '标签', '管理本文标签'),
    );
}

/** 视图：渲染写文章界面。 */
function ade_view_editor()
{
    global $zbp;

    $id = (int) GetVars('id', 'GET');
    $data = $id > 0 ? ade_article_payload($id) : ade_article_blank();

    if ($data === null) {
        echo '<div class="ade-panel"><p class="ade-empty">文章不存在，或你没有权限编辑它。</p></div>';

        return;
    }

    $titleMax = (int) $zbp->option['ZC_ARTICLE_TITLE_MAX'];
    $canPublish = (bool) $data['canpublish'];
    // 保存后跳回不带 id 的编辑页；saved 只认 ade_article_get() 查回的真值
    $savedId = (int) GetVars('saved', 'GET', 0);
    $saved = $savedId > 0 ? ade_article_get($savedId) : null;

    $accept = array();
    foreach (ade_image_exts() as $ext) {
        $accept[] = '.' . $ext;
    }
    ?>
<div class="ade-edit">
  <section class="ade-panel ade-edit-main">
    <div class="ade-panel-body">
      <label class="ade-sr" for="adeTitle">文章标题</label>
      <input type="text" id="adeTitle" class="ade-input ade-title" maxlength="<?php echo $titleMax; ?>"
             placeholder="请输入文章标题" autocomplete="off" value="<?php echo ade_e($data['title']); ?>">

      <div class="ade-toolbar" id="adeToolbar" role="toolbar" aria-label="正文工具栏" aria-controls="adeContent">
        <span class="ade-tb-group">
          <?php foreach (ade_editor_buttons() as $btn) { ?>
          <button type="button" class="ade-tb" data-cmd="<?php echo ade_e($btn[0]); ?>"
                  title="<?php echo ade_e($btn[2]); ?>" aria-label="<?php echo ade_e($btn[2]); ?>"><?php echo ade_e($btn[1]); ?></button>
          <?php } ?>
        </span>
        <span class="ade-stat" id="adeStat">正文准备中…</span>
      </div>

      <div class="ade-editor" id="adeContent" contenteditable="true" role="textbox" aria-multiline="true"
           aria-label="文章正文" data-placeholder="在此撰写正文，可直接粘贴图文混排内容或把图片拖进来"></div>
    </div>
  </section>

  <aside class="ade-panel ade-edit-side">
    <div class="ade-panel-body">
      <?php if ($saved !== null) { ?>
      <p class="ade-saved">已保存《<?php echo ade_e($saved->Title); ?>》（<?php echo ade_e(ade_status_name($saved->Status)); ?>），<a href="<?php echo ade_e(ade_url('main.php?act=editor&id=' . (int) $saved->ID)); ?>">继续编辑</a>或<a href="<?php echo ade_e($saved->Url); ?>" target="_blank" rel="noopener">在前台打开</a>。下方已是新文章。</p>
      <?php } ?>
      <div class="ade-actions" id="adeActions">
        <button type="button" class="ade-btn ade-btn-primary ade-btn-block" id="adePublish"><?php echo $canPublish ? '发布' : '提交审核'; ?></button>
      </div>

      <div class="ade-trio">
        <div class="ade-trio-cell">
          <span class="ade-trio-label">分类</span>
          <select id="adeCate" class="ade-select"><?php echo ade_category_options($data['cateid']); ?></select>
        </div>
        <div class="ade-trio-cell">
          <span class="ade-trio-label">状态</span>
          <select id="adeStatus" class="ade-select"><?php echo ade_status_options($data['status']); ?></select>
        </div>
        <?php if ($data['canistop']) { ?>
        <div class="ade-trio-cell">
          <span class="ade-trio-label">置顶</span>
          <?php /* 与内核同形：下拉而非开关，选项由内核生成器给出（0/2/1/4） */ ?>
          <select id="adeTop" class="ade-select"><?php echo OutputOptionItemsOfIsTop($data['istop']); ?></select>
        </div>
        <?php } ?>
      </div>

      <?php /* 标签：桌面端由 JS 把标签编辑器嵌进这个容器（手机端整块隐藏，改用工具栏弹窗） */ ?>
      <div class="ade-field ade-field-tags" id="adeTagField">
        <span class="ade-label">标签</span>
        <div class="ade-control">
          <div class="ade-tag-host" id="adeTagHost"></div>
        </div>
      </div>

      <?php echo ade_field('时间', '<input type="datetime-local" id="adeTime" class="ade-input" value="' . ade_e($data['posttime']) . '">'); ?>
    </div>
  </aside>
</div>

<?php /* 手机端：发布区收成底部抽屉（搬同一份 DOM，不复制控件） */ ?>
<div class="ade-drawer" id="adeDrawer" aria-label="发布设置">
  <button type="button" class="ade-drawer-handle" id="adeDrawerHandle"
          aria-controls="adeDrawerBody" aria-expanded="false">
    <span class="ade-drawer-handle-label">发布文章</span>
    <i class="ade-drawer-handle-icon" aria-hidden="true"></i>
  </button>
  <div class="ade-drawer-body" id="adeDrawerBody"></div>
</div>

<div class="ade-lightbox" id="adeLightbox" role="dialog" aria-modal="true" aria-label="图片预览" hidden>
  <div class="ade-lightbox-box">
    <img class="ade-lightbox-img" id="adeLightboxImg" alt="">
    <div class="ade-lightbox-bar">
      <button type="button" class="ade-btn ade-btn-sm" id="adeLightboxOut" title="缩小" aria-label="缩小">−</button>
      <span class="ade-lightbox-zoom" id="adeLightboxScale" aria-live="polite">100%</span>
      <button type="button" class="ade-btn ade-btn-sm" id="adeLightboxIn" title="放大" aria-label="放大">＋</button>
      <button type="button" class="ade-btn ade-btn-sm" id="adeLightboxClose">关闭</button>
      <button type="button" class="ade-btn ade-btn-sm ade-btn-danger" id="adeLightboxDel">删除图片</button>
    </div>
  </div>
</div>

<input type="file" id="adeFile" class="ade-sr" tabindex="-1" aria-hidden="true" accept="<?php echo ade_e(implode(',', $accept)); ?>">
<script type="application/json" id="adeArticle"><?php
    echo ade_json(array(
        'id'      => (int) $data['id'],
        'content' => (string) $data['content'],
        'intro'   => (string) $data['intro'],
        'tags'    => array_map('strval', (array) $data['tags']),
    ));
?></script>
<?php /* 常用标签（前 100），供标签弹窗一键绑定 */ ?>
<script type="application/json" id="adeTagCommon"><?php
    echo ade_json(ade_tag_list(100));
?></script>
<?php
}

/**
 * 动作：保存文章。目标状态由前端「状态」下拉决定，服务端再夹紧。
 */
function ade_action_save()
{
    $input = array(
        'id'       => (int) GetVars('id', 'POST'),
        'title'    => (string) GetVars('title', 'POST'),
        'content'  => (string) GetVars('content', 'POST'),
        'cateid'   => (int) GetVars('cateid', 'POST'),
        'status'   => (int) GetVars('status', 'POST'),
        'istop'    => (int) GetVars('istop', 'POST'),
        'posttime' => (string) GetVars('posttime', 'POST'),
        'tag'      => (string) GetVars('tag', 'POST'),
    );

    // 摘要是载入时由 ade_article_payload() 按内核规则处理过再下发的，这里原样交回
    if (GetVars('intro', 'POST') !== null) {
        $input['intro'] = (string) GetVars('intro', 'POST');
    }

    ade_ok(ade_article_save($input));
}

/** 动作：上传图片。支持 multipart 的 file 字段，或 base64 + name（粘贴的 data:image）。 */
function ade_action_upload()
{
    $base64 = (string) GetVars('base64', 'POST');
    if ($base64 !== '') {
        $name = (string) GetVars('name', 'POST');
        ade_ok(ade_upload_base64($base64, $name === '' ? 'paste.png' : $name));
    }

    ade_ok(ade_upload_file());
}

/**
 * 动作：把站外图片下载转存到本站（图片本地化）。入参是 URL，单独通道便于做 SSRF 校验。
 * 这是全插件唯一会代用户向任意主机发起请求的入口，所以在字节上限之外再加一道限流。
 */
function ade_action_uploadurl()
{
    ade_need_throttle('uploadurl', 20, 60);

    $url = (string) GetVars('url', 'POST');
    $name = (string) GetVars('name', 'POST');

    ade_ok(ade_upload_remote($url, $name === '' ? 'remote.png' : $name));
}
