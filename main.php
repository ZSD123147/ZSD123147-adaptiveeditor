<?php

/**
 * 自适应编辑器 —— 唯一后台入口。所有界面与 AJAX 动作经 ?act= 分发（见 core/router.php）。
 */
$_ad_root = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__, 3))), '/') . '/';
require $_ad_root . 'zb_system/function/c_system_base.php';
require $_ad_root . 'zb_system/function/c_system_admin.php';
require __DIR__ . '/core/bootstrap.php';

$zbp->Load();

ade_bootstrap();
ade_dispatch();

RunTime();
