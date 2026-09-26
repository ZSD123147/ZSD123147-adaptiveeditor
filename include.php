<?php

/**
 * 自适应编辑器 —— 注册入口。每请求执行，只挂钩子与声明生命周期。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/security.php';

RegisterPlugin(ADE_ID, 'ActivePlugin_AdaptiveEditor');

/**
 * 每次请求都会执行，只用于挂载钩子。
 */
function ActivePlugin_AdaptiveEditor()
{
    Add_Filter_Plugin('Filter_Plugin_Admin_LeftMenu', 'ade_admin_leftmenu');
    Add_Filter_Plugin('Filter_Plugin_Admin_ArticleMng_SubMenu', 'ade_admin_submenu');
    Add_Filter_Plugin('Filter_Plugin_Admin_PluginMng_SubMenu', 'ade_admin_submenu');
}

/**
 * 安装时执行一次（zb_system/cmd.php:258 触发）。
 * 手动上传后直接启用的场景不会走到这里，故 ade_config() 另有默认值回落。
 */
function InstallPlugin_AdaptiveEditor()
{
    ade_install();
}

/**
 * 有意不定义 UninstallPlugin_AdaptiveEditor()：本内核里 UninstallPlugin() 只由 DisablePlugin() 调用
 * （zb_system/function/c_system_event.php:2130），在此清配置会让「停用再启用」静默丢光设置。
 * 官方建议保留配置以备重新启用；卸载后残留的 Configs 行不影响任何功能。
 */

/**
 * 后台左侧菜单入口。
 *
 * @param array $m 由 ResponseAdmin_LeftMenu() 以引用方式传入
 */
function ade_admin_leftmenu(&$m)
{
    global $zbp;

    if (!ade_config('leftmenu')) {
        return;
    }

    $m['nav_AdaptiveEditor'] = MakeLeftMenu(
        'ArticleEdt',
        '自适应编辑器',
        ade_url('main.php'),
        'nav_AdaptiveEditor',
        'aAdaptiveEditor',
        '',
        'icon-pencil-square'
    );
}

/**
 * 在「文章管理」「插件管理」页的二级菜单里挂一个入口，方便从原生后台直接跳过来。
 */
function ade_admin_submenu()
{
    global $zbp;

    if (!$zbp->CheckRights('ArticleEdt')) {
        return;
    }

    echo '<a href="' . ade_e(ade_url('main.php')) . '"><span class="m-left">自适应编辑器</span></a>';
}
