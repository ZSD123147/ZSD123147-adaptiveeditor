<?php

/**
 * 自适应编辑器 —— 视图层。
 * 输出完全自持的 HTML：不 require 内核 admin_* 资源、不引用 admin2.css / jQuery / icon.css。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/** 渲染视图：先捕获功能层输出的正文，再套上布局。 */
function ade_render($act, $route)
{
    ob_start();
    call_user_func($route['handler']);
    $body = ob_get_clean();

    ade_layout($act, $route, (string) $body);
}

/** 输出整页。 */
function ade_layout($act, $route, $body)
{
    global $zbp;

    $config = ade_config();
    $title = $route['title'];
    $assets = GetValueInArray($route, 'assets', array());

    // 前端运行参数走 application/json 数据岛，规避后台严格 CSP
    $jsConfig = array(
        'act'          => $act,
        'entry'        => ade_url('main.php'),
        'settings'     => $config,
        'rights'       => array(
            'istop'   => $zbp->CheckRights('ArticleAll') ? 1 : 0,
            'publish' => $zbp->CheckRights('ArticlePub') ? 1 : 0,
            'new'     => $zbp->CheckRights('ArticleNew') ? 1 : 0,
            'edt'     => $zbp->CheckRights('ArticleEdt') ? 1 : 0,
            'tag'     => ($zbp->CheckRights('TagNew') && $zbp->CheckRights('TagPst')) ? 1 : 0,
            'upload'  => $zbp->CheckRights('UploadPst') ? 1 : 0,
            'del'     => $zbp->CheckRights('ArticleDel') ? 1 : 0,
            'batdel'  => ($zbp->CheckRights('PostBat') && $zbp->option['ZC_POST_BATCH_DELETE']) ? 1 : 0,
            'setting' => $zbp->CheckRights('root') ? 1 : 0,
        ),
        'uploadExts'   => ade_image_exts(),
        'uploadMaxMb'  => (int) $zbp->option['ZC_UPLOAD_FILESIZE'],
        // 图片超限时给用户提供直达链接（$zbp->adminurl = host . 'zb_system/admin/'）
        'adminSetting' => $zbp->adminurl . 'index.php?act=SettingMng',
        'version'      => ADE_VERSION,
    );
    $jsConfigJson = ade_json($jsConfig);

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="none">
<meta name="csrfToken" content="<?php echo ade_e($zbp->GetCSRFToken()); ?>">
<title><?php echo ade_e($title); ?> · 自适应编辑器</title>
<link rel="stylesheet" href="<?php echo ade_e(ade_asset('core/core.css')); ?>">
<?php foreach ($assets as $asset) {
        if (substr($asset, -4) === '.css') {
            echo '<link rel="stylesheet" href="' . ade_e(ade_asset($asset)) . '">' . "\n";
        }
    } ?>
</head>
<body class="ade-body ade-act-<?php echo ade_e($act); ?>">
<header class="ade-top">
  <?php $icon = ade_site_icon(); ?>
  <a class="ade-brand" href="<?php echo ade_e($zbp->host); ?>" title="<?php echo ade_e($zbp->option['ZC_BLOG_NAME']); ?>" aria-label="<?php echo ade_e($zbp->option['ZC_BLOG_NAME']); ?>">
    <?php if ($icon !== '') { ?>
    <img class="ade-brand-img" src="<?php echo ade_e($icon); ?>" alt="<?php echo ade_e($zbp->option['ZC_BLOG_NAME']); ?>">
    <?php } else { ?>
    <span class="ade-brand-text"><?php echo ade_e($zbp->option['ZC_BLOG_NAME']); ?></span>
    <?php } ?>
  </a>
  <nav class="ade-nav" id="adeNav" aria-label="主导航">
    <?php foreach (ade_routes() as $key => $item) {
        if ($item['kind'] !== 'view') {
            continue;
        }
        if (!ade_rights_ok($item)) {
            continue;
        }
        $active = ($item['nav'] === $route['nav']) ? ' class="is-active" aria-current="page"' : '';
        echo '<a href="' . ade_e(ade_url('main.php?act=' . $key)) . '"' . $active . '>' . ade_e($item['title']) . '</a>';
    } ?>
  </nav>
  <div class="ade-user">
    <a class="ade-back" href="<?php echo ade_e(ade_admin_url()); ?>">返回原生后台</a>
  </div>
</header>
<main class="ade-main" id="adeMain">
<?php echo $body; ?>
</main>
<div class="ade-toast" id="adeToast" role="status" aria-live="polite"></div>
<div class="ade-mask" id="adeMask" hidden></div>
<div class="ade-dialog" id="adeDialog" role="dialog" aria-modal="true" aria-labelledby="adeDialogTitle" hidden>
  <div class="ade-dialog-box">
    <h2 id="adeDialogTitle"></h2>
    <div class="ade-dialog-body" id="adeDialogBody"></div>
    <div class="ade-dialog-foot" id="adeDialogFoot"></div>
  </div>
</div>
<script type="application/json" id="adeConfig"><?php echo $jsConfigJson; ?></script>
<script src="<?php echo ade_e(ade_asset('core/core.js')); ?>"></script>
<?php foreach ($assets as $asset) {
        if (substr($asset, -3) === '.js') {
            echo '<script src="' . ade_e(ade_asset($asset)) . '"></script>' . "\n";
        }
    } ?>
</body>
</html>
<?php
}

/**
 * 探测站点图标 URL（内核无 logo 配置项）：按 favicon.ico → 主题目录 → 插件 logo.png 顺序，都不存在返回空串。
 *
 * @return string
 */
function ade_site_icon()
{
    global $zbp;

    $root = $zbp->host;
    $dir = $zbp->usersdir . 'theme/' . $zbp->theme . '/';

    $candidates = array(
        array($root . 'favicon.ico', $zbp->path . 'favicon.ico'),
    );
    foreach (array('favicon.ico', 'logo.png', 'logo.svg', 'logo.jpg', 'logo.jpeg', 'logo.gif') as $name) {
        $candidates[] = array($root . 'zb_users/theme/' . rawurlencode($zbp->theme) . '/' . $name, $dir . $name);
    }
    // 部分主题把图片放在 style/images/ 下
    foreach (array('favicon.ico', 'logo.png', 'logo.svg') as $name) {
        $candidates[] = array($root . 'zb_users/theme/' . rawurlencode($zbp->theme) . '/style/images/' . $name, $dir . 'style/images/' . $name);
    }

    foreach ($candidates as $c) {
        if (is_file($c[1])) {
            return $c[0];
        }
    }

    if (is_file(ADE_PATH . 'logo.png')) {
        return ade_asset('logo.png');
    }

    return '';
}

/**
 * 生成一段带 label 的表单行。仅用于减少功能层重复标记。
 *
 * @param string $class 附加到 .ade-field 上的类名（传空则输出与以往完全一致的标记）
 *
 * @return string
 */
function ade_field($label, $fieldHtml, $hint = '', $class = '')
{
    $s = '<div class="ade-field' . ($class !== '' ? ' ' . ade_e($class) : '') . '"><span class="ade-label">' . ade_e($label) . '</span>';
    $s .= '<div class="ade-control">' . $fieldHtml;
    if ($hint !== '') {
        $s .= '<p class="ade-hint">' . ade_e($hint) . '</p>';
    }
    $s .= '</div></div>';

    return $s;
}
