<?php

/**
 * 自适应编辑器 —— 路由层。
 * 请求经唯一入口 main.php?act=xxx 分发。路由表是视图/动作的唯一事实来源。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/**
 * 路由表。
 *
 * kind    : view=渲染页面 / action=输出 JSON
 * file    : 相对插件根目录的功能文件（功能两层目录）
 * handler : 该文件中必须定义的函数名
 * rights  : 需全部满足的权限动作名
 * rights_any : 满足其一即可的权限动作名
 * method  : 允许的请求方法
 * csrf    : 是否校验 CSRF
 *
 * @return array
 */
function ade_routes()
{
    return array(
        'editor' => array(
            'kind' => 'view', 'file' => 'views/editor/editor.php', 'handler' => 'ade_view_editor',
            'title' => '写文章', 'nav' => 'editor', 'method' => 'GET', 'csrf' => false,
            'rights_any' => array('ArticleEdt', 'ArticleNew'),
            'assets' => array('views/editor/editor.css', 'views/editor/editor.js'),
        ),
        'manage' => array(
            'kind' => 'view', 'file' => 'views/manage/manage.php', 'handler' => 'ade_view_manage',
            'title' => '文章管理', 'nav' => 'manage', 'method' => 'GET', 'csrf' => false,
            'rights' => array('ArticleMng'),
            'assets' => array('views/manage/manage.css', 'views/manage/manage.js'),
        ),
        'setting' => array(
            'kind' => 'view', 'file' => 'views/setting/setting.php', 'handler' => 'ade_view_setting',
            'title' => '设置', 'nav' => 'setting', 'method' => 'GET', 'csrf' => false,
            'rights' => array('root'),
            'assets' => array('views/setting/setting.css', 'views/setting/setting.js'),
        ),

        'save' => array(
            'kind' => 'action', 'file' => 'views/editor/editor.php', 'handler' => 'ade_action_save',
            'method' => 'POST', 'csrf' => true, 'rights_any' => array('ArticleEdt', 'ArticleNew'),
        ),
        'upload' => array(
            'kind' => 'action', 'file' => 'views/editor/editor.php', 'handler' => 'ade_action_upload',
            'method' => 'POST', 'csrf' => true, 'rights' => array('UploadPst'),
        ),
        'uploadurl' => array(
            'kind' => 'action', 'file' => 'views/editor/editor.php', 'handler' => 'ade_action_uploadurl',
            'method' => 'POST', 'csrf' => true, 'rights' => array('UploadPst'),
        ),
        'del' => array(
            'kind' => 'action', 'file' => 'views/manage/manage.php', 'handler' => 'ade_action_del',
            'method' => 'POST', 'csrf' => true, 'rights' => array('ArticleDel'),
        ),
        'batdel' => array(
            'kind' => 'action', 'file' => 'views/manage/manage.php', 'handler' => 'ade_action_batdel',
            'method' => 'POST', 'csrf' => true, 'rights' => array('PostBat'),
        ),

        'savesetting' => array(
            'kind' => 'action', 'file' => 'views/setting/setting.php', 'handler' => 'ade_action_savesetting',
            'method' => 'POST', 'csrf' => true, 'rights' => array('root'),
        ),
    );
}

/** 取当前动作名，非法值一律回落到默认视图。 */
function ade_act()
{
    $act = strtolower((string) GetVars('act', 'GET', 'editor'));
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $act)) {
        return 'editor';
    }

    return array_key_exists($act, ade_routes()) ? $act : 'editor';
}

/** 分发。 */
function ade_dispatch()
{
    $act = ade_act();
    $routes = ade_routes();
    $route = $routes[$act];

    ade_guard($route);

    require_once ADE_PATH . $route['file'];

    if ($route['kind'] === 'view') {
        ade_render($act, $route);
    } else {
        ade_run($route['handler']);
    }
}

/**
 * 路由级闸门：方法、权限、CSRF。
 *
 * @param array $route
 */
function ade_guard($route)
{
    $method = strtoupper((string) GetVars('REQUEST_METHOD', 'SERVER', 'GET'));
    if ($route['method'] !== $method) {
        $route['kind'] === 'view'
            ? ade_halt('请求方式不被允许', 405)
            : ade_fail('请求方式不被允许', 405);
    }

    if (!ade_rights_ok($route)) {
        $route['kind'] === 'view'
            ? ade_halt('没有访问该界面的权限', 403)
            : ade_fail('没有操作权限', 6);
    }

    if (!empty($route['csrf'])) {
        ade_need_csrf();
    }
}

/** 权限判定。rights 全满足、rights_any 任一满足。 */
function ade_rights_ok($route)
{
    global $zbp;

    foreach ((array) GetValueInArray($route, 'rights', array()) as $action) {
        if (!$zbp->CheckRights($action)) {
            return false;
        }
    }

    $any = (array) GetValueInArray($route, 'rights_any', array());
    if (count($any) > 0) {
        foreach ($any as $action) {
            if ($zbp->CheckRights($action)) {
                return true;
            }
        }

        return false;
    }

    return true;
}
