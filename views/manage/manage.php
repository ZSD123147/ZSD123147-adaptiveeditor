<?php

/**
 * 自适应编辑器 —— 文章管理界面（功能层）。视图与动作同文件。
 * 列表整页服务端渲染（关掉 JS 也能搜索翻页），JS 只补全选联动、删除确认、免刷新提交。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/** 视图：文章列表。 */
function ade_view_manage()
{
    global $zbp;

    $search = ade_search_word(GetVars('search', 'GET'));
    $page = (int) GetVars('page', 'GET', 1);

    // 分类筛选：''=不限，其余为分类 ID。非数字一律按没筛处理（cate=abc 转 (int) 会变成 0）
    $cate = GetVars('cate', 'GET', null);
    $cate = ($cate === null || !is_numeric($cate)) ? '' : (string) (int) $cate;

    // 状态筛选：公开=0 是「有值但为假」，判定交给归一化函数，不能写 if($status)
    $status = ade_status_filter_value(GetVars('status', 'GET', null));

    $pagebar = null;
    $array = ade_article_list($search, $cate, $status, $page, (int) ade_config('manage_perpage'), $pagebar);

    $canBat = $zbp->CheckRights('PostBat') && (bool) $zbp->option['ZC_POST_BATCH_DELETE'];
    $canDel = (bool) $zbp->CheckRights('ArticleDel');
    $canEdt = (bool) $zbp->CheckRights('ArticleEdt');
    $canNew = (bool) $zbp->CheckRights('ArticleNew');
    $total = (int) $pagebar->Count;

    // 发布后整页跳转，成功提示由列表页服务端渲染；saved 只认 ade_article_get() 的真值
    $savedId = (int) GetVars('saved', 'GET', 0);
    $saved = $savedId > 0 ? ade_article_get($savedId) : null;
    ?>
  <?php
    /* 清空搜索词时要保留的筛选项。集中拼一次，以后加分筛项不会漏 */
    $keep = '';
    if ($cate !== '') {
        $keep .= '&cate=' . rawurlencode($cate);
    }
    if ($status !== '') {
        $keep .= '&status=' . rawurlencode($status);
    }
  ?>
<section class="ade-panel ade-manage<?php echo $canBat ? ' has-bat' : ''; ?>">
  <form class="ade-bar" method="get" action="<?php echo ade_e(ade_url('main.php')); ?>">
    <input type="hidden" name="act" value="manage">
    <label class="ade-sr" for="adeCateFilter">按分类筛选</label>
    <select id="adeCateFilter" name="cate" class="ade-select ade-bar-cate">
      <?php echo ade_category_filter_options($cate); ?>
    </select>
    <label class="ade-sr" for="adeStatusFilter">按状态筛选</label>
    <select id="adeStatusFilter" name="status" class="ade-select ade-bar-status">
      <?php echo ade_status_filter_options($status); ?>
    </select>
    <label class="ade-sr" for="adeSearch">搜索文章</label>
    <span class="ade-search<?php echo $search !== '' ? ' has-word' : ''; ?>">
      <input type="search" id="adeSearch" name="search" class="ade-input" maxlength="50"
             placeholder="按标题、摘要或正文搜索" autocomplete="off" value="<?php echo ade_e($search); ?>">
      <?php if ($search !== '') { ?>
      <a class="ade-search-clear" href="<?php echo ade_e(ade_url('main.php?act=manage' . $keep)); ?>" title="清空">×</a>
      <?php } ?>
      <button type="submit" class="ade-search-go" title="搜索">搜索</button>
    </span>
  </form>

  <?php if ($saved !== null) { ?>
  <p class="ade-saved">已保存文章《<?php echo ade_e($saved->Title); ?>》（<?php echo ade_e(ade_status_name($saved->Status)); ?>），<a href="<?php echo ade_e($saved->Url); ?>" target="_blank" rel="noopener">在前台打开</a>或<a href="<?php echo ade_e(ade_url('main.php?act=editor&id=' . (int) $saved->ID)); ?>">继续编辑</a>。</p>
  <?php } ?>

  <?php if (!$canBat && $zbp->CheckRights('PostBat')) { ?>
  <p class="ade-note">站点未开启「启用文章批量删除」，所以这里只能逐篇删除。开启路径：后台 → 网站设置 → 后台设置 → 启用文章批量删除。</p>
  <?php } ?>

  <?php if ($canBat) { ?>
  <div class="ade-batbar" id="adeBatBar" hidden>
    <span id="adeSelInfo">已选 0 篇</span>
    <button type="button" class="ade-btn ade-btn-danger ade-btn-sm" id="adeBatDel">批量删除</button>
    <button type="button" class="ade-btn ade-btn-ghost ade-btn-sm" id="adeSelNone">取消选择</button>
  </div>
  <?php } ?>

  <div class="ade-tablewrap" id="adeListWrap">
    <?php if (count($array) === 0) {
        // 区分「真没文章」与「没筛中」：筛选无结果时不能写「还没有文章」
        $hasFilter = ($search !== '' || $cate !== '' || $status !== '');
    ?>
    <p class="ade-empty"><?php echo $hasFilter ? '没有匹配的文章。' : '还没有文章。';
    ?><?php if ($hasFilter) { ?>
      <?php if ($canEdt) { ?><a href="<?php echo ade_e(ade_url('main.php?act=manage')); ?>">清空筛选</a><?php } ?>
    <?php } elseif ($canNew) { ?>
      <a href="<?php echo ade_e(ade_url('main.php?act=editor')); ?>">去写第一篇</a>
    <?php } ?></p>
    <?php } else { ?>
    <table class="ade-table" id="adeList">
      <thead>
        <tr>
          <?php if ($canBat) { ?>
          <th class="c-check"><button type="button" class="ade-list-all" id="adeAll" title="全选 / 取消全选">全选</button></th>
          <?php } ?>
          <th class="c-id">ID</th>
          <th class="c-cate">分类</th>
          <th class="c-author">作者</th>
          <th class="c-title"><span class="ade-th-name">标题</span></th>
          <th class="c-time">时间</th>
          <th class="c-act"><span class="ade-th-name">操作</span><span class="ade-list-count" id="adeTotal" data-count="<?php echo $total; ?>">共 <?php echo $total; ?> 篇</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($array as $article) {
            $id = (int) $article->ID;
            $cateId = (int) $article->CateID;
            // CateID=0 或分类已删时内核给出「未命名」，这里按分类表是否存在给出准确文案
            // （用 $cateName 而非 $cate：循环外 $cate 还存着分类筛选值，覆写是埋雷）
            if ($cateId > 0 && isset($zbp->categoriesbyorder[$cateId])) {
                $cateName = (string) $article->Category->Name;
            } else {
                $cateName = $cateId > 0 ? '分类已失效' : '未归类';
            }
            $author = (string) $article->Author->Name;
        ?>
        <tr class="ade-row" data-id="<?php echo $id; ?>">
          <?php if ($canBat) { ?>
          <td class="c-check"><input type="checkbox" class="ade-pick" name="id[]" value="<?php echo $id; ?>" aria-label="选择文章 <?php echo $id; ?>"></td>
          <?php } ?>
          <td class="c-id"><?php echo $id; ?></td>
          <td class="c-cate"><?php echo ade_e($cateName); ?></td>
          <td class="c-author"><?php echo ade_e($author !== '' ? $author : '—'); ?></td>
          <td class="c-title">
            <div class="ade-title-cell">
              <a class="ade-title-link" href="<?php echo ade_e($article->Url); ?>" target="_blank" rel="noopener" title="在前台打开"><?php echo ade_e($article->Title); ?></a>
              <?php if ((int) $article->IsTop !== 0) { ?><span class="ade-badge is-top">顶</span><?php } ?>
              <span class="ade-badge <?php echo ade_e(ade_status_class($article->Status)); ?>"><?php echo ade_e(ade_status_name($article->Status)); ?></span>
            </div>
          </td>
          <td class="c-time"><?php echo ade_e($article->Time('Y-m-d H:i')); ?></td>
          <td class="c-act">
            <?php if ($canEdt) { ?>
            <a class="ade-btn ade-btn-sm" href="<?php echo ade_e(ade_url('main.php?act=editor&id=' . $id)); ?>">编辑</a>
            <?php } ?>
            <?php if ($canDel) { ?>
            <button type="button" class="ade-btn ade-btn-sm ade-del" data-id="<?php echo $id; ?>"
                    data-title="<?php echo ade_e(ade_plain($article->Title)); ?>">删除</button>
            <?php } ?>
          </td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
    <?php } ?>
  </div>

  <?php if ($total > 0) {
      // 分页 URL 复用内核 PageBar 生成好的；按钮键不存在即表示到头/到尾，不再自己算一遍
      $btns = $pagebar->Buttons;
      $lPrev = (string) $zbp->langs->msg->prev_button;
      $lNext = (string) $zbp->langs->msg->next_button;
      $skip = array(
          (string) $zbp->langs->msg->first_button => true,
          $lPrev => true,
          $lNext => true,
          (string) $zbp->langs->msg->last_button => true,
      );

      // 手机端页码窗口：内核按站点设置生成全部数字按钮（默认 10 个），窄屏会折行。
      // 这里不改内核，只给窗口外的页码加标记类交给 CSS 隐藏；窗口随当前页滑动、恒含当前页
      $winSize = 4;
      $pageAll = (int) $pagebar->PageAll;
      $pageNow = (int) $pagebar->PageNow;
      $winStart = max(1, min($pageNow - 1, $pageAll - $winSize + 1));
      $winEnd = min($pageAll, $winStart + $winSize - 1);
  ?>
  <div class="ade-pager">
    <?php if (isset($btns[$lPrev])) { ?>
    <a class="ade-pg-nav" href="<?php echo ade_e($btns[$lPrev]); ?>">‹ 上一页</a>
    <?php } else { ?>
    <span class="ade-pg-nav is-off" aria-disabled="true">‹ 上一页</span>
    <?php } ?>

    <?php
      if ((int) $pagebar->PageAll > 1) {
          foreach ($btns as $key => $value) {
              if (isset($skip[(string) $key])) {
                  continue;
              }
              // 只有纯数字标签参与窗口裁剪；本地化后的标签一律保留，免得切掉看不见的按钮
              $out = ((string) (int) $key === (string) $key)
                  && ((int) $key < $winStart || (int) $key > $winEnd);
              if ((string) $pagebar->PageNow === (string) $key) {
                  echo '<span class="is-now' . ($out ? ' ade-pg-out' : '') . '">' . ade_e($key) . '</span>';
              } else {
                  echo '<a' . ($out ? ' class="ade-pg-out"' : '') . ' href="' . ade_e($value) . '">' . ade_e($key) . '</a>';
              }
          }
      }
    ?>

    <?php if (isset($btns[$lNext])) { ?>
    <a class="ade-pg-nav" href="<?php echo ade_e($btns[$lNext]); ?>">下一页 ›</a>
    <?php } else { ?>
    <span class="ade-pg-nav is-off" aria-disabled="true">下一页 ›</span>
    <?php } ?>

    <span class="ade-total">第 <?php echo (int) $pagebar->PageNow; ?> / <?php echo (int) $pagebar->PageAll; ?> 页</span>
  </div>
  <?php } ?>
</section>
<?php
}

/**
 * 动作：删除单篇。只走 ArticleDel 权限，不受 ZC_POST_BATCH_DELETE 开关限制（那个管批量）。
 */
function ade_action_del()
{
    $id = (int) GetVars('id', 'POST');
    if ($id <= 0) {
        ade_fail('缺少文章 ID');
    }

    ade_ok(array('count' => ade_article_delete(array($id))));
}

/** 动作：批量删除。除路由表 PostBat 权限外，再查一次站点开关，避免绕过站点设置。 */
function ade_action_batdel()
{
    global $zbp;

    if (!$zbp->option['ZC_POST_BATCH_DELETE']) {
        ade_fail('站点未开启文章批量删除，请在「后台 → 网站设置 → 后台设置」中打开', 7);
    }

    $ids = GetVars('id', 'POST');
    if (!is_array($ids) || count($ids) === 0) {
        ade_fail('请先选择要删除的文章');
    }
    // 一次删太多会连带触发大量计数回退与模块重建，这里给个明确的上限
    if (count($ids) > 200) {
        ade_fail('一次最多删除 200 篇，请分批操作');
    }

    ade_ok(array('count' => ade_article_delete($ids)));
}
