<?php

/**
 * 自适应编辑器 —— 引导层。
 * 职责：加载 core 各模块、执行访问闸门（启用 / 登录态 / 时区 / CSRF 时效）。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

if (!defined('ADE_PATH')) {
    define('ADE_PATH', dirname(__DIR__) . '/');
}

require_once ADE_PATH . 'core/config.php';
require_once ADE_PATH . 'core/security.php';
require_once ADE_PATH . 'core/model.php';
require_once ADE_PATH . 'core/view.php';
require_once ADE_PATH . 'core/router.php';

/**
 * 访问闸门。任何请求进入路由前都必须先经过这里。
 */
function ade_bootstrap()
{
    global $zbp;

    // 与 zb_system/cmd.php:98 一致：长文编辑时放宽 CSRF Token 时效（小时）
    $zbp->csrfExpiration = 48;

    // 对齐 zb_system/admin/edit.php:29-35：按后台时区偏移，保证显示与落库时间对称
    if (isset($_COOKIE['timezone'])) {
        $tz = GetVars('timezone', 'COOKIE');
        if (is_numeric($tz)) {
            date_default_timezone_set(GetTimeZoneByGMT($tz));
        }
        unset($tz);
    }

    if (!$zbp->CheckPlugin(ADE_ID)) {
        // dev-plugin 文档指定的写法：走内核错误页而非自渲染。
        // ShowError 可能被其它插件注册的 Filter_Plugin_Zbp_ShowError 接管后 return 而非抛出
        // （zblogphp.php:4095-4098），此时若不 die 就会在未启用的状态下继续执行，故不可省。
        $zbp->ShowError(48);
        die();
    }

    if (!$zbp->islogin) {
        Redirect302($zbp->cmdurl . '?act=login');
        die();
    }
}

/** 以最小 HTML 终止请求。用于闸门与视图级权限失败，避免 JSON 或异常页污染阅读。 */
function ade_halt($msg, $httpCode = 403)
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: text/html; charset=utf-8');
    }
    // 样式走 core.css 的 .ade-halt（令牌复用，与主界面同一份样式表的 body.ade-body 基线）
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>自适应编辑器</title>'
        . '<link rel="stylesheet" href="' . ade_e(ade_asset('core/core.css')) . '">'
        . '</head><body class="ade-body ade-halt">'
        . '<p>' . ade_e($msg) . '</p>'
        . '<p><a href="' . ade_e(ade_admin_url()) . '">返回后台</a></p>'
        . '</body></html>';
    exit;
}

/** 后台首页 URL。 */
function ade_admin_url()
{
    global $zbp;

    return $zbp->cmdurl . '?act=admin';
}
