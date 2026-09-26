<?php

/**
 * 自适应编辑器 —— 设置界面（功能层）。设置项全部登记在 core/config.php 的 schema，这里不重复写死。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/**
 * 设置项展示信息（顺序即页面顺序）。name 与 schema 键一一对应。
 * kind: number=数字 / switch=开关 / choice=双向切换。数字项在前、开关项在后，利于手机端两列分组。
 */
function ade_setting_fields()
{
    return array(
        array(
            'name'  => 'editor_height',
            'kind'  => 'number',
            'label' => '正文区高度',
            'unit'  => 'px',
            'hint'  => '写文章界面中间可编辑区域的最小高度，内容超出后会自行撑开。',
        ),
        array(
            'name'  => 'manage_perpage',
            'kind'  => 'number',
            'label' => '列表每页条数',
            'unit'  => '篇',
            'hint'  => '文章管理界面每页显示的文章数量。',
        ),
        array(
            'name'    => 'paste_html',
            'kind'    => 'choice',
            'label'   => '粘贴模式',
            'options' => array('图文模式' => 1, '纯文本模式' => 0),
            'hint'    => '「图文模式」保留段落、加粗、斜体、下划线、标题、有序 / 无序列表、表格、代码与图片；引用、删除线、上下标、脚本、内联事件，以及来源带进来的超链接与跟踪参数会被清洗掉。清洗规则与内核保存时那道白名单一致，编辑里看到的就是前台最终的样子。「纯文本模式」一律按纯文本粘贴，每行成为一个段落。两种模式下都可以用 Ctrl/⌘+Shift+V 临时按纯文本粘贴。',
        ),
        array(
            'name'    => 'publish_jump',
            'kind'    => 'choice',
            'label'   => '发布后去向',
            'options' => array('跳到文章管理' => 1, '留在写文章页' => 0),
            'hint'    => '发布成功后：选「跳到文章管理」直接回列表页并给出结果提示；选「留在写文章页」则回到一张空的编辑页，继续写下一篇。',
        ),
        array(
            'name'  => 'localize_image',
            'kind'  => 'switch',
            'label' => '图片本地化',
            'on'    => '保存时转存',
            'hint'  => '开启后，粘贴与上传的图片先留在编辑器里，只有在文章保存成功时才写入本站附件库并替换为站内地址；站外图片也会一并下载到本站。关闭则回到「插入时立即上传、站外图片保留原地址」。',
        ),
        array(
            'name'  => 'leftmenu',
            'kind'  => 'switch',
            'label' => '原生后台入口',
            'on'    => '显示入口',
            'hint'  => '在 Z-Blog 后台左侧菜单，以及「文章管理」「插件管理」的二级菜单里显示「自适应编辑器」入口。关闭后本插件界面仍可通过网址直接访问。',
        ),
    );
}

/**
 * 视图：设置界面。
 */
function ade_view_setting()
{
    $config = ade_config();
    $schema = ade_config_schema();
    ?>
<section class="ade-panel ade-setting">
  <div class="ade-panel-head">
    <h2>设置</h2>
    <span class="ade-head-note">版本 <?php echo ade_e(ADE_VERSION); ?></span>
  </div>
  <div class="ade-panel-body">
    <form id="adeSettingForm" novalidate>
      <div class="ade-set-grid">
        <?php
          foreach (ade_setting_fields() as $field) {
              $name = $field['name'];
              if (!isset($schema[$name])) {
                  continue;
              }
              echo ade_setting_row($field, $schema[$name], GetValueInArray($config, $name, $schema[$name]['default']));
          }
        ?>
      </div>

      <div class="ade-setting-foot">
        <button type="submit" class="ade-btn ade-btn-primary" id="adeSave">保存设置</button>
        <span class="ade-hint">设置只保存在本站的插件配置中；停用或卸载插件都不会影响已保存的设置。</span>
      </div>
    </form>
  </div>
</section>
<?php
}

/**
 * 渲染一行设置。数字项的取值范围由 schema 生成，不手写。
 *
 * @param array $field
 * @param array $rule
 * @param mixed $value
 *
 * @return string
 */
function ade_setting_row($field, $rule, $value)
{
    $name = ade_e($field['name']);
    $hint = (string) $field['hint'];

    if ($field['kind'] === 'choice') {
        // 双向切换：两个互斥单选项做成胶囊；值仍是原布尔键
        $items = '';
        foreach ((array) $field['options'] as $optLabel => $optValue) {
            $items .= '<label class="ade-seg-item"><input type="radio" name="' . $name . '" value="' . (int) $optValue . '"'
                . ((int) $value === (int) $optValue ? ' checked' : '') . '><span>' . ade_e($optLabel) . '</span></label>';
        }
        $control = '<div class="ade-seg" role="radiogroup" aria-label="' . ade_e($field['label']) . '">' . $items . '</div>';
    } elseif ($field['kind'] === 'switch') {
        $control = '<label class="ade-switch"><input type="checkbox" name="' . $name . '"'
            . ($value ? ' checked' : '') . '><i></i><span>' . ade_e($field['on']) . '</span></label>';
    } else {
        $min = (int) $rule['min'];
        $max = (int) $rule['max'];
        $control = '<div class="ade-numrow">'
            . '<input type="number" class="ade-input ade-num" name="' . $name . '" value="' . (int) $value . '"'
            . ' min="' . $min . '" max="' . $max . '" step="1" inputmode="numeric">'
            . '<span class="ade-unit">' . ade_e($field['unit']) . '</span>'
            . '</div>';
        $hint .= '允许 ' . $min . '–' . $max . '，默认 ' . (int) $rule['default'] . '。';
    }

    // 横向布局下放不下一段长说明，改成悬浮提示：信息不丢，但不占版面
    return '<div class="ade-set-cell" title="' . ade_e($hint) . '">'
        . '<span class="ade-set-label">' . ade_e($field['label']) . '</span>'
        . $control
        . '</div>';
}

/** 动作：保存设置。只接受已登记键，取值由 ade_config_save() 按 schema 夹紧。 */
function ade_action_savesetting()
{
    $input = array();
    foreach (ade_setting_fields() as $field) {
        $name = $field['name'];

        if ($field['kind'] === 'switch') {
            $input[$name] = GetVars($name, 'POST') ? 1 : 0;
            continue;
        }

        if ($field['kind'] === 'choice') {
            // 只接受登记过的取值，其余一律落到第一项，不给越界值留缝
            $allowed = array_map('intval', array_values((array) $field['options']));
            $v = (int) GetVars($name, 'POST');
            $input[$name] = in_array($v, $allowed, true) ? $v : (int) reset($allowed);
            continue;
        }

        $input[$name] = (int) GetVars($name, 'POST');
    }

    ade_ok(ade_config_save($input));
}
