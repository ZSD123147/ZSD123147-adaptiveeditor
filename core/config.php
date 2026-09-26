<?php

/**
 * 自适应编辑器 —— 配置层。
 * 职责：配置项定义、默认值、取值约束、安装时持久化。本文件不依赖 core 内其它文件。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

if (!defined('ADE_ID')) {
    define('ADE_ID', 'adaptiveeditor');
}
if (!defined('ADE_VERSION')) {
    define('ADE_VERSION', '1.0.1');
}

/**
 * 配置项默认值与约束。新增设置项只需在此登记一行。
 *
 * @return array
 */
function ade_config_schema()
{
    return array(
        'editor_height'  => array('type' => 'int',  'default' => 450, 'min' => 200, 'max' => 2000),
        'paste_html'     => array('type' => 'bool', 'default' => 0),
        'manage_perpage' => array('type' => 'int',  'default' => 20,  'min' => 5,   'max' => 100),
        'localize_image' => array('type' => 'bool', 'default' => 0),
        'publish_jump'   => array('type' => 'bool', 'default' => 0),
        'leftmenu'       => array('type' => 'bool', 'default' => 0),
    );
}

/**
 * 读取配置（缺失的键自动回落默认值，取值自动夹紧到合法区间）。
 *
 * @param string|null $key 为 null 时返回全部
 *
 * @return mixed
 */
function ade_config($key = null)
{
    global $zbp;

    $config = $zbp->Config(ADE_ID);
    $result = array();

    foreach (ade_config_schema() as $name => $rule) {
        $value = $config->HasKey($name) ? $config->$name : $rule['default'];
        $result[$name] = ade_config_cast($value, $rule);
    }

    return $key === null ? $result : (isset($result[$key]) ? $result[$key] : null);
}

/**
 * 按规则归一化单个配置值。
 *
 * @param mixed $value
 * @param array $rule
 *
 * @return int
 */
function ade_config_cast($value, $rule)
{
    if ($rule['type'] === 'bool') {
        return $value ? 1 : 0;
    }

    $value = (int) $value;
    if (isset($rule['min']) && $value < $rule['min']) {
        $value = (int) $rule['min'];
    }
    if (isset($rule['max']) && $value > $rule['max']) {
        $value = (int) $rule['max'];
    }

    return $value;
}

/**
 * 保存配置。只接受 schema 中登记过的键，其余一律丢弃。
 *
 * @param array $input
 *
 * @return array 归一化后的完整配置
 */
function ade_config_save($input)
{
    global $zbp;

    $config = $zbp->Config(ADE_ID);
    $schema = ade_config_schema();

    foreach ($schema as $name => $rule) {
        if (!array_key_exists($name, $input)) {
            continue;
        }
        $config->$name = ade_config_cast($input[$name], $rule);
    }

    $zbp->SaveConfig(ADE_ID);

    return ade_config();
}

/**
 * 首次安装：为 schema 中所有尚未落库的键补齐默认值。
 */
function ade_install()
{
    global $zbp;

    $config = $zbp->Config(ADE_ID);
    foreach (ade_config_schema() as $name => $rule) {
        if (!$config->HasKey($name)) {
            $config->$name = $rule['default'];
        }
    }
    $zbp->SaveConfig(ADE_ID);
}
