<?php

/**
 * 自适应编辑器 —— 数据访问层.
 *
 * 本层是插件与内核之间唯一的数据通道：所有文章、分类、标签、附件的读写都在这里完成，
 * 且一律调用内核既有函数/对象，不自行拼接 SQL、不自行落盘。
 *
 * 已核实的内核依赖（文件:行号）：
 *   PostArticle()                              zb_system/function/c_system_event.php:375
 *   Include_BatchPost_Article()                zb_system/function/c_system_function.php:496
 *   PostUpload()                               zb_system/function/c_system_event.php:1986
 *   GetHttpContent()                           zb_system/function/c_system_common.php:694
 *   $zbp->GetPostByID()                        zb_system/function/lib/zblogphp.php:3176
 *   $zbp->GetPostList()                        zb_system/function/lib/zblogphp.php:2794
 *   $zbp->GetTagList()                         zb_system/function/lib/zblogphp.php:2897
 *   $zbp->LoadTagsByIDString()                 zb_system/function/lib/zblogphp.php:3637
 *   OutputOptionItemsOfCategories()            zb_system/function/c_system_admin_function.php:386
 *   OutputOptionItemsOfPostStatus()            zb_system/function/c_system_admin_function.php:654
 *   OutputOptionItemsOfIsTop()                 zb_system/function/c_system_admin_function.php:620
 *   PageBar / dbsql 自动计数并 Make()          zb_system/function/lib/pagebar.php:140, lib/dbsql.php:147-162
 *   Base__Upload（Name/Dir/FullFile/Url）      zb_system/function/lib/base/upload.php:23,197,214,220
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/** 编辑器允许的上传类型。比站点 ZC_UPLOAD_FILETYPE 更窄，因为这里只处理正文配图。 */
function ade_image_exts()
{
    return array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp');
}

/** 按 ID 取文章，并校验类型与归属。 */
function ade_article_get($id)
{
    global $zbp;

    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }

    $article = $zbp->GetPostByID($id);
    if ((int) $article->ID === 0) {
        return null;
    }
    if ((int) $article->Type !== ZC_POST_TYPE_ARTICLE) {
        return null;
    }
    if (!$zbp->CheckRights('ArticleAll') && (int) $article->AuthorID !== (int) $zbp->user->ID) {
        return null;
    }

    return $article;
}

/** 与当前用户相关的文章权限标记（置顶 / 发布），载入与新建共用一个来源。 */
function ade_article_rights()
{
    global $zbp;

    return array(
        'canistop'   => $zbp->CheckRights('ArticleAll') ? 1 : 0,
        'canpublish' => $zbp->CheckRights('ArticlePub') ? 1 : 0,
    );
}

/**
 * 编辑器载入用的文章数据。
 *
 * @param int $id
 *
 * @return array|null
 */
function ade_article_payload($id)
{
    global $zbp;

    $article = ade_article_get($id);
    if ($article === null) {
        return null;
    }

    // 对齐 zb_system/admin/edit.php:82-89：<!--more--> 换成可见的 <hr class="more">，自动摘要一并清空
    $content = (string) $article->Content;
    $intro = (string) $article->Intro;
    if ($intro !== '') {
        if (str_contains($content, '<!--more-->')) {
            $intro = '';
            $content = str_replace('<!--more-->', '<hr class="more" />', $content);
        } elseif (str_contains($intro, '<!--autointro-->')) {
            $intro = '';
        }
    }

    $tags = array();
    foreach ($zbp->LoadTagsByIDString($article->Tag) as $tag) {
        $tags[] = ade_plain($tag->Name);
    }

    return array(
        'id'          => (int) $article->ID,
        'title'       => ade_plain($article->Title),
        'content'     => $content,
        'intro'       => $intro,
        'cateid'      => (int) $article->CateID,
        'authorid'    => (int) $article->AuthorID,
        'status'      => (int) $article->Status,
        'istop'       => (int) $article->IsTop,
        'posttime'    => $article->Time('Y-m-d\TH:i'),
        'tags'        => $tags,
        'url'         => (string) $article->Url,
    ) + ade_article_rights();
}

/** 新建文章时右侧面板的初始值。 */
function ade_article_blank()
{
    global $zbp;

    // 与原生后台一致：默认落在第一个真实分类（原生下拉无 selected，浏览器默认选第一项）
    $cats = isset($zbp->categoriesbyorder_type[ZC_POST_TYPE_ARTICLE])
        ? $zbp->categoriesbyorder_type[ZC_POST_TYPE_ARTICLE]
        : array();
    $firstCate = count($cats) ? (int) key($cats) : 0;

    return array(
        'id'         => 0,
        'title'      => '',
        'content'    => '',
        'intro'      => '',
        'cateid'     => $firstCate,
        'authorid'   => (int) $zbp->user->ID,
        'status'     => $zbp->CheckRights('ArticlePub') ? ZC_POST_STATUS_PUBLIC : ZC_POST_STATUS_AUDITING,
        'istop'      => 0,
        'posttime'   => date('Y-m-d\TH:i'),
        'tags'       => array(),
        'url'        => '',
    ) + ade_article_rights();
}

/**
 * 归一化搜索词：去空白 + 截断。注入由内核 SEARCH 操作符的 EscapeString 挡住。
 *
 * @param mixed $raw
 *
 * @return string
 */
function ade_search_word($raw)
{
    $s = trim(ade_plain($raw));
    if ($s === '') {
        return '';
    }

    // mbstring 为硬依赖：回退到 substr() 会从多字节中间截断，产出非法 UTF-8
    return mb_substr($s, 0, 50, 'UTF-8');
}

/**
 * 文章列表（服务端渲染）。分页总数与页码由内核 dbsql 自动计算。
 *
 * @param string $cateid ''=不限分类；其余为分类 ID。用字符串区分「不限」与「ID 0」
 * @param string $status ''=不限状态；其余为 log_Status。公开=0 同样是「有值但为假」
 *
 * @return Post[]
 */
function ade_article_list($search, $cateid, $status, $page, $perpage, &$pagebar)
{
    global $zbp;

    $pagebar = new PageBar('{%host%}zb_users/plugin/' . ADE_ID . '/main.php?act=manage{&search=%search%}{&cate=%cate%}{&status=%status%}{&page=%page%}', false);
    $pagebar->PageCount = (int) $perpage;
    $pagebar->PageNow = max(1, (int) $page);
    $pagebar->PageBarCount = (int) $zbp->pagebarcount;
    $pagebar->UrlRule->Rules['{%search%}'] = rawurlencode((string) $search);
    // 筛选项不写进 URL 的话，一翻页就丢了
    $pagebar->UrlRule->Rules['{%cate%}'] = ($cateid === '') ? '' : (string) (int) $cateid;
    $pagebar->UrlRule->Rules['{%status%}'] = ade_status_filter_value($status);

    // 列名一律硬编码：内核只对「值」做 EscapeString，列名原样拼接
    $w = array();
    $w[] = array('=', 'log_Type', ZC_POST_TYPE_ARTICLE);
    if (!$zbp->CheckRights('ArticleAll')) {
        $w[] = array('=', 'log_AuthorID', $zbp->user->ID);
    }
    if ($search !== '') {
        $w[] = array('search', 'log_Title', 'log_Intro', 'log_Content', $search);
    }
    // ''=不限分类、'0'=未归类，两者须区分；只认分类表里存在的 id
    if ($cateid !== '') {
        $cid = (int) $cateid;
        if ($cid === 0 || isset($zbp->categories[$cid])) {
            $w[] = array('=', 'log_CateID', $cid);
        }
    }
    // 状态筛选：公开=0 是「有值但为假」，必须按归一化结果判断，不能写 if($status)
    $statusValue = ade_status_filter_value($status);
    if ($statusValue !== '') {
        $w[] = array('=', 'log_Status', (int) $statusValue);
    }

    $l = array(($pagebar->PageNow - 1) * $pagebar->PageCount, $pagebar->PageCount);

    return $zbp->GetPostList('', $w, array('log_ID' => 'DESC'), $l, array('pagebar' => $pagebar));
}

/**
 * 保存文章。
 *
 * 做法：把校验后的数据重建成内核 PostArticle() 认识的 $_POST 结构，再交给内核完成落库、
 * 摘要生成、标签计数、模块重建等全部既有逻辑。字段名与 zb_system/admin/edit.php 完全一致。
 *
 * @param array $input
 *
 * @return array 保存结果（含新的文章 ID 与地址）
 */
function ade_article_save($input)
{
    global $zbp;

    $id = (int) GetValueInArray($input, 'id', 0);
    $authorId = (int) $zbp->user->ID;
    $postTime = date('Y-m-d H:i:s');

    if ($id > 0) {
        $article = ade_article_get($id);
        if ($article === null) {
            ade_fail('文章不存在，或你没有权限编辑它', 9);
        }
        // 回传原作者：PostArticle() 缺省 AuthorID 时会写成当前用户
        $authorId = (int) $article->AuthorID;
        $postTime = $article->Time('Y-m-d H:i:s');
    } elseif (!$zbp->CheckRights('ArticleNew')) {
        ade_fail('没有新建文章的权限', 6);
    }

    $title = trim(ade_plain(GetValueInArray($input, 'title', '')));
    if ($title === '') {
        $title = ade_plain($zbp->lang['msg']['unnamed']);
    }
    $titleMax = (int) $zbp->option['ZC_ARTICLE_TITLE_MAX'];
    $title = mb_substr($title, 0, $titleMax, 'UTF-8');

    $content = (string) GetValueInArray($input, 'content', '');
    // 浏览器序列化会丢掉 <hr> 的斜杠，先归一成内核严格正则认得的 <!--more-->（c_system_event.php:395）
    $content = (string) preg_replace('/<hr\b[^>]*\bclass\s*=\s*["\']?more["\']?[^>]*>/i', '<!--more-->', $content);

    $cateId = (int) GetValueInArray($input, 'cateid', 0);
    if ($cateId > 0 && !isset($zbp->categoriesbyorder[$cateId])) {
        $cateId = 0;
    }

    $status = (int) GetValueInArray($input, 'status', ZC_POST_STATUS_DRAFT);
    // 只放内核下拉真给的三值：私人(4)/加锁(8) 无对应输入项，写进去等于把文章锁出前台且这里再也解不开
    $allowedStatus = array(ZC_POST_STATUS_PUBLIC, ZC_POST_STATUS_DRAFT, ZC_POST_STATUS_AUDITING);
    if (!in_array($status, $allowedStatus, true)) {
        $status = ZC_POST_STATUS_DRAFT;
    }
    // 内核只在「新建」与「原文为待审」时强制降级，这里补齐草稿转公开的场景
    if ($status === ZC_POST_STATUS_PUBLIC && !$zbp->CheckRights('ArticlePub')) {
        $status = ZC_POST_STATUS_AUDITING;
    }

    // 置顶取内核登记的四值 0/1/2/4（与 OutputOptionItemsOfIsTop 同源），其余回落 0
    $isTop = (int) GetValueInArray($input, 'istop', 0);
    if (!in_array($isTop, array(0, 1, 2, 4), true)) {
        $isTop = 0;
    }

    $inputTime = trim((string) GetValueInArray($input, 'posttime', ''));
    if ($inputTime !== '') {
        $ts = strtotime($inputTime);
        if ($ts !== false && $ts > 0) {
            $postTime = date('Y-m-d H:i:s', $ts);
        }
    }

    // 不在此截断：log_Tag 的 250 限的是内核转换后的 {ID} 串，按名字串截会把标签切成半截建进库
    $tag = (string) GetValueInArray($input, 'tag', '');

    // 重建 $_POST：只保留内核认识的键，杜绝 IsLock/Template/Meta 等越权字段被写入
    $_POST = array(
        'ID'       => $id,
        'Type'     => ZC_POST_TYPE_ARTICLE,
        'Title'    => $title,
        'Content'  => $content,
        'CateID'   => $cateId,
        'AuthorID' => $authorId,
        'Status'   => $status,
        'IsTop'    => $isTop,
        'Tag'      => $tag,
        'PostTime' => $postTime,
    );

    // 摘要仅在调用方明确交回时写入：不带该键内核就不动原摘要（c_system_event.php:397）
    if (array_key_exists('intro', $input)) {
        $_POST['Intro'] = (string) $input['intro'];
    }

    $saved = PostArticle();
    if (!$saved) {
        ade_fail('保存失败，请重试');
    }

    // 与 zb_system/cmd.php:101-102 一致
    $zbp->BuildModule();
    $zbp->SaveCache();

    return array(
        'id'     => (int) $saved->ID,
        'url'    => (string) $saved->Url,
        'status' => (int) $saved->Status,
        'istop'  => (int) $saved->IsTop,
        'edit'   => ade_url('main.php?act=editor&id=' . (int) $saved->ID),
    );
}

/**
 * 过滤出当前用户确实有权删除的文章 ID。
 *
 * @param array $ids
 *
 * @return array
 */
function ade_article_deletable($ids)
{
    $ok = array();
    foreach ((array) $ids as $id) {
        $id = (int) $id;
        if ($id > 0 && ade_article_get($id) !== null) {
            $ok[$id] = $id;
        }
    }

    return array_values(array_unique($ok));
}

/**
 * 删除文章（单篇与批量共用同一条内核路径）。
 * 复用 Include_BatchPost_Article()：它会连带删除评论、回退标签/作者/分类计数、
 * 触发 Filter_Plugin_DelArticle_Succeed，比手工循环 DelArticle() 更完整。
 *
 * @return int 实际删除数量
 */
function ade_article_delete($ids)
{
    global $zbp;

    $ids = ade_article_deletable($ids);
    if (count($ids) === 0) {
        ade_fail('没有可删除的文章');
    }

    $_POST['id'] = $ids;
    Include_BatchPost_Article(ZC_POST_TYPE_ARTICLE);

    // 与 zb_system/cmd.php:89-90 一致
    $zbp->BuildModule();
    $zbp->SaveCache();

    return count($ids);
}

/**
 * 分类下拉选项。直接复用内核生成器，自带层级缩进与钩子。
 *
 * @param int $default
 *
 * @return string
 */
function ade_category_options($default)
{
    global $zbp;

    $default = (int) $default;
    $s = (string) OutputOptionItemsOfCategories($default, ZC_POST_TYPE_ARTICLE);

    // 内核只输出真实分类；不补选项时下拉会默认选中第一项，把分类悄悄改掉
    if (!isset($zbp->categoriesbyorder[$default])) {
        $label = $default <= 0 ? '未归类' : '分类已失效（ID ' . $default . '）';
        $s = '<option value="' . max(0, $default) . '" selected="selected">' . ade_e($label) . '</option>' . $s;
    }

    return $s;
}

/** 分类「筛选」下拉：只列「全部分类」+ 真实分类，不提供「未归类」（0 不是分类）。 */
function ade_category_filter_options($selected)
{
    global $zbp;

    $all = '<option value=""' . ($selected === '' ? ' selected="selected"' : '') . '>全部分类</option>';

    if (count($zbp->categories) === 0) {
        return $all;
    }

    // 传一个不存在的 id 作默认值，避免内核替我们 selected 上某一项
    return $all . OutputOptionItemsOfCategories(-1, ZC_POST_TYPE_ARTICLE);
}

/**
 * 状态下拉选项。直接复用内核生成器，其内部已按 ArticlePub / ArticleAll 权限裁剪可选项。
 *
 * @param int $default
 *
 * @return string
 */
function ade_status_options($default)
{
    global $zbp;

    $default = (int) $default;
    $s = (string) OutputOptionItemsOfPostStatus($default);

    // 内核只输出 公开/草稿/审核；不补选项会把 私人(4)/加锁(8) 悄悄改成公开
    if (!str_contains($s, 'value="' . $default . '"')) {
        $s = '<option value="' . $default . '" selected="selected">' . ade_e(ade_status_name($default)) . '</option>' . $s;
    }

    return $s;
}

/** 状态名。取自语言包 post_status_name（zb_users/language/zh-cn.php:366-372）。 */
function ade_status_name($status)
{
    global $zbp;

    $names = GetValueInArray($zbp->lang, 'post_status_name', array());

    return (string) GetValueInArray($names, (string) (int) $status, '状态 ' . (int) $status);
}

/**
 * 状态对应的徽标样式类。类名一律取自 core.css 已有的定义。
 *
 * @param int $status
 *
 * @return string
 */
function ade_status_class($status)
{
    $map = array(
        ZC_POST_STATUS_PUBLIC   => 'is-public',
        ZC_POST_STATUS_DRAFT    => 'is-draft',
        ZC_POST_STATUS_AUDITING => 'is-audit',
    );

    return GetValueInArray($map, (string) (int) $status, '');
}

/**
 * 把任意输入归一成状态筛选值：'' 表示不限，其余为合法状态值字符串。
 * 非法输入按「没筛」处理，不能 (int) 成 0（那等于悄悄变成「只看公开」）。
 */
function ade_status_filter_value($raw)
{
    global $zbp;

    if ($raw === null) {
        return '';
    }
    $s = trim((string) $raw);
    // 只收纯整数写法：is_numeric 会放过 '2.5' / '1e2'，(int) 之后悄悄变成别的筛选值
    if ($s === '' || !preg_match('/^-?\d+$/', $s)) {
        return '';
    }
    $names = GetValueInArray($zbp->lang, 'post_status_name', array());
    $v = (int) $s;

    return array_key_exists($v, (array) $names) ? (string) $v : '';
}

/**
 * 状态「筛选」下拉选项：首项空值=全部状态，其余同源于 post_status_name。
 *
 * @param string $selected
 *
 * @return string
 */
function ade_status_filter_options($selected)
{
    global $zbp;

    $selected = ade_status_filter_value($selected);
    $s = '<option value=""' . ($selected === '' ? ' selected="selected"' : '') . '>全部状态</option>';

    $names = (array) GetValueInArray($zbp->lang, 'post_status_name', array());
    ksort($names);
    foreach ($names as $value => $label) {
        // 私人(4)/加锁(8) 不进筛选下拉（非常规流转状态）；URL 直链筛选仍有效
        $v = (int) $value;
        if ($v === 4 || $v === 8) {
            continue;
        }
        $s .= '<option value="' . $v . '"'
            . ($selected === (string) $v ? ' selected="selected"' : '')
            . '>' . ade_e($label) . '</option>';
    }

    return $s;
}

/** 常用标签：按使用数倒序取前 N 个的名字（对齐内核 edit.php「显示常用标签」）。 */
function ade_tag_list($limit = 100)
{
    global $zbp;

    $limit = max(1, min(200, (int) $limit));
    $names = array();
    $array = $zbp->GetTagList(
        null,
        array('=', 'tag_Type', ZC_POST_TYPE_ARTICLE),
        array('tag_Count' => 'DESC', 'tag_ID' => 'ASC'),
        array($limit),
        null
    );
    foreach ($array as $tag) {
        $name = ade_plain($tag->Name);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * 生成不与客户端输入相关的唯一文件名。
 *
 * @param string $ext
 *
 * @return string
 */
function ade_unique_name($ext)
{
    // 命名规则对齐 zb_system/function/c_system_event.php:2001
    return date('YmdHis') . time() . rand(10000, 99999) . '.' . $ext;
}

/** 上传结果的下发结构。 */
function ade_upload_payload($upload)
{
    return array(
        'id'   => (int) $upload->ID,
        'name' => (string) $upload->Name,
        'url'  => (string) $upload->Url,
        'size' => (int) $upload->Size,
    );
}

/**
 * 表单文件上传。完整复用内核 PostUpload()（含扩展名、大小、同月重名校验与计数）。
 *
 * @return array
 */
function ade_upload_file()
{
    global $zbp;

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        ade_fail('没有接收到文件');
    }
    if ((int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        ade_fail('文件上传失败，错误码 ' . (int) $_FILES['file']['error']);
    }

    // 按魔数定真实类型：扩展名拦不住「.jpg 里塞 PHP」的伪装
    $head = ade_read_head($_FILES['file']['tmp_name'], 16);
    $_FILES['file']['name'] = 'image.' . ade_image_verify($head);

    // 只处理 file 字段，避免其它字段被内核一并写盘
    $_FILES = array('file' => $_FILES['file']);
    // 强制重命名：避免落盘路径被客户端文件名控制
    $_POST['auto_rename'] = 'on';

    $upload = PostUpload();
    if (!$upload) {
        ade_fail('图片保存失败，请重试');
    }

    return ade_upload_payload($upload);
}

/**
 * Base64 图片上传（剪贴板截图）。按 PostUpload() 同套步骤实现，落盘走 SaveBase64File()。
 *
 * @param string $name 兼容保留，不再用于推断扩展名
 */
function ade_upload_base64($base64, $name)
{
    global $zbp;

    $base64 = (string) $base64;
    // 解码前先卡长度（base64 膨胀约 4/3，按 1.4 倍留余量），避免超大串先吃内存
    $maxBytes = ade_max_bytes();
    if (strlen($base64) > (int) ceil($maxBytes * 1.4)) {
        ade_fail('图片大小超出站点限制（' . round($maxBytes / 1048576, 1) . ' MB）', 27);
    }

    $raw = base64_decode($base64, true);
    if ($raw === false || $raw === '') {
        ade_fail('图片数据无效');
    }

    // 以解码后真实内容定类型：粘贴文件名不可信
    $ext = ade_image_verify($raw);

    $upload = new Upload();
    $upload->Name = ade_unique_name($ext);
    $upload->SourceName = $upload->Name;
    $upload->MimeType = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
    $upload->Size = strlen($raw);
    $upload->AuthorID = $zbp->user->ID;

    if (!$upload->CheckExtName()) {
        ade_fail('站点不允许上传该类型的文件', 26);
    }
    if (!$upload->CheckSize()) {
        ade_fail('图片大小超出站点限制（' . (int) $zbp->option['ZC_UPLOAD_FILESIZE'] . ' MB）', 27);
    }
    if (!$upload->SaveBase64File($base64)) {
        ade_fail('图片写入失败');
    }

    $upload->Save();
    $zbp->AddCache($upload);
    CountMemberArray(array($upload->AuthorID), array(0, 0, 0, +1));

    HookFilterPlugin('Filter_Plugin_PostUpload_Succeed', $upload);

    return ade_upload_payload($upload);
}

/**
 * 按文件头魔数判断图片真实类型（站外图片 URL 常无扩展名，不可信）。
 *
 * @param string $bytes
 *
 * @return string 小写扩展名；识别不出返回空串
 */
function ade_image_ext_from_bytes($bytes)
{
    $head = substr((string) $bytes, 0, 16);

    // match(true) 首个成立即返回，顺序与原 strncmp 链一致
    return match (true) {
        str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'png',
        str_starts_with($head, "\xff\xd8\xff") => 'jpg',
        str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
        str_starts_with($head, 'BM') => 'bmp',
        str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
        default => '',
    };
}

/** 读文件头部若干字节（用于按魔数判定真实类型）。 */
function ade_read_head($path, $len)
{
    $fp = @fopen($path, 'rb');
    if ($fp === false) {
        return '';
    }
    $head = (string) fread($fp, $len);
    fclose($fp);

    return $head;
}

/**
 * 按魔数校验图片并给出真实扩展名；识别不出直接拒绝，不退回客户端文件名。
 *
 * @param string $bytes
 *
 * @return string 小写扩展名
 */
function ade_image_verify($bytes)
{
    $ext = ade_image_ext_from_bytes($bytes);
    if ($ext === '') {
        ade_fail('文件内容不是有效的图片', 26);
    }

    return $ext;
}

/** 判断规范 IP 是否落内网/保留段。IPv4 映射的 IPv6（::ffff:x.x.x.x）先拆出内嵌 IPv4 再判。 */
function ade_ip_is_internal($ip)
{
    $ip = (string) $ip;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16
            && substr($bin, 0, 10) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            && substr($bin, 10, 2) === "\xff\xff"
        ) {
            $v4 = unpack('N', substr($bin, 12, 4));
            $ip = (string) long2ip((int) $v4[1]);
        }
    }

    // PHP 的 NO_PRIV_RANGE|NO_RES_RANGE 不覆盖 RFC6598 的 CGNAT 100.64.0.0/10，而这段在云厂商
    // 与容器网络里是真实可路由的内网地址。放在归并 IPv4 之后，::ffff:100.64.x.x 也一并挡掉。
    // 内核的 is_intranet_ip() 补了这段，但它的 IPv6 分支拿 explode 出的字符串与整数比大小，不可信。
    $long = ip2long($ip);
    if ($long !== false && ($long & 0xFFC00000) === 0x64400000) {
        return true;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * 把非规范 IPv4 字面量（十进制/十六进制/八进制）归一成点分十进制，供内网判定。
 *
 * @param string $host
 *
 * @return string 点分十进制；识别不出返回空串
 */
function ade_ip_literal($host)
{
    if (preg_match('/^0x[0-9a-f]+$/i', $host)) {
        $n = hexdec(substr($host, 2));
    } elseif (preg_match('/^0[0-7]+$/', $host)) {
        $n = octdec($host);
    } elseif (preg_match('/^\d+$/', $host)) {
        $n = (float) $host;
        if ($n > 0xffffffff) {
            return '';
        }
        $n = (int) $n;
    } else {
        return '';
    }

    if ($n < 0 || $n > 0xffffffff) {
        return '';
    }

    return (string) long2ip((int) $n);
}

/**
 * 主机名是否指向内网（SSRF 防线）。三层：本机名/.local、规范与非规范 IP 字面量、域名解析后逐 IP 复判。
 * 与真实请求之间仍有 DNS 重绑定这一理论窗口，但常见手法已覆盖。
 */
function ade_host_is_internal($host)
{
    $host = strtolower(trim((string) $host, " \t\n\r\0\x0B[]"));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
        return true;
    }

    // 规范 IP（IPv4 / IPv6）
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return ade_ip_is_internal($host);
    }

    // 非规范 IPv4 字面量
    $literal = ade_ip_literal($host);
    if ($literal !== '') {
        return ade_ip_is_internal($literal);
    }

    // 域名：A 记录逐个复判（gethostbynamel 只回 IPv4）
    $ips = @gethostbynamel($host);
    if (is_array($ips)) {
        foreach ($ips as $ip) {
            if (ade_ip_is_internal((string) $ip)) {
                return true;
            }
        }
    }
    // AAAA 记录（best-effort）：纯 IPv6 的内网域名只能靠这里
    $recs = @dns_get_record($host, DNS_AAAA);
    if (is_array($recs)) {
        foreach ($recs as $r) {
            if (isset($r['ipv6']) && ade_ip_is_internal((string) $r['ipv6'])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * 校验一个目标 URL 的协议与主机。下载的每一跳（含重定向）都要过这里。
 *
 * @param string $url
 */
function ade_remote_guard($url)
{
    if (!preg_match('#^https?://#i', $url)) {
        ade_fail('只支持 http(s) 协议的站外图片', 26);
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (ade_host_is_internal($host)) {
        ade_fail('该地址不允许转存', 26);
    }
}

/** 把重定向目标 Location 拼回绝对 URL（用于逐跳复核，不直接交给内核跟随）。 */
function ade_url_join($base, $loc)
{
    $loc = trim((string) $loc);
    if ($loc === '') {
        return '';
    }
    // 协议相对 //host/path
    if (str_starts_with($loc, '//')) {
        $p = parse_url($base);

        return (isset($p['scheme']) ? $p['scheme'] : 'http') . ':' . $loc;
    }
    // 绝对 URL
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $loc)) {
        return $loc;
    }

    $p = parse_url($base);
    if ($p === false || !isset($p['host'])) {
        return '';
    }
    $scheme = isset($p['scheme']) ? $p['scheme'] : 'http';
    $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $path = isset($p['path']) ? $p['path'] : '/';
    if ($loc[0] === '/') {
        return $scheme . '://' . $host . $loc;
    }
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    if ($dir === '') {
        $dir = '/';
    }

    return $scheme . '://' . $host . $dir . $loc;
}

/**
 * 站点允许的单文件字节上限。
 *
 * 下载、base64 解码、落盘三处必须共用同一个数：各算各的话，最先失败的那道防线决定了实际上限，
 * 报错文案与真实行为就会对不上。
 *
 * @return int
 */
function ade_max_bytes()
{
    global $zbp;

    return 1024 * 1024 * (int) $zbp->option['ZC_UPLOAD_FILESIZE'];
}

/**
 * 给下载装硬字节上限。
 *
 * Network 没有流式接口：responseText 是整体可读的，事后再 strlen 只能证明"已经吃进内存"。
 * curl 驱动借官方文档给出的 $http->ch 逃生门（dev-network 示例 6）挂中止回调，超限即掐断传输。
 * fsockopen / filegetcontents 两个驱动没有 ch 属性，也就没有可挂的写入回调，
 * 退回调用方的事后检查——所以这条防线是"能挡则挡"，不是"必挡"。
 *
 * @param object $ajax     Network::Create() 的返回值
 * @param int    $maxBytes
 * @param bool   $aborted  出参：本次传输是否因超限被中止。curl 中止后 responseText 与状态码都会归零，
 *                         不记这一笔就分不清「图太大」和「网络断了」，只能统一报下载失败
 */
function ade_limit_bytes($ajax, $maxBytes, &$aborted)
{
    $aborted = false;

    if ($maxBytes <= 0 || !property_exists($ajax, 'ch')) {
        return;
    }

    $ch = $ajax->ch;
    // open() 尚未执行或 curl_init 失败
    if (!$ch) {
        return;
    }

    // 进度回调返回非 0 即让 curl 以 CURLE_ABORTED_BY_CALLBACK 中止本次传输
    $abort = function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($maxBytes, &$aborted) {
        if ($downloaded > $maxBytes) {
            $aborted = true;

            return 1;
        }

        return 0;
    };

    @curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    @curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, $abort);
}

/**
 * 带 SSRF 复核与字节上限的站外取图：逐跳过 ade_remote_guard()，最多 3 跳。
 *
 * @param int $maxBytes 超出即中止下载，见 ade_limit_bytes()
 *
 * @return string|null
 */
function ade_fetch_remote($url, $maxBytes)
{
    $current = trim((string) $url);

    for ($hop = 0; $hop < 3; $hop++) {
        ade_remote_guard($current);

        $ajax = Network::Create();
        if (!$ajax) {
            return null;
        }
        $ajax->open('GET', $current);
        $ajax->enableGzip();
        // 这是用户点一次粘贴就触发一次的同步请求，不能用 dev-network 示例里的 120s
        $ajax->setTimeOuts(10, 5, 0, 0);
        $ajax->setMaxRedirs(0);   // 关掉内核自动跟随，重定向由这里逐跳复核
        ade_limit_bytes($ajax, $maxBytes, $aborted);
        $ajax->send();

        if ($aborted) {
            ade_fail('图片大小超出站点限制（' . round($maxBytes / 1048576, 1) . ' MB）', 27);
        }

        $code = (int) $ajax->getStatusCode();
        if ($code >= 300 && $code < 400) {
            $loc = trim((string) $ajax->getResponseHeader('Location'));
            if ($loc === '') {
                return null;
            }
            $current = ade_url_join($current, $loc);
            if ($current === '') {
                return null;
            }
            continue;
        }
        if ($code !== 200) {
            return null;
        }

        return (string) $ajax->responseText;
    }

    return null;
}

/**
 * 下载站外图片并转存到本站附件库（图片本地化服务端）。
 * 逐跳复核重定向防 SSRF；落盘走 SaveBase64File()（SaveFile() 只认 HTTP 上传的临时文件）。
 *
 * @param string $url
 * @param string $name 兼容保留；类型以字节头为准
 *
 * @return array
 */
function ade_upload_remote($url, $name)
{
    $maxBytes = ade_max_bytes();

    $bytes = ade_fetch_remote($url, $maxBytes);
    if ($bytes === null || $bytes === '') {
        ade_fail('站外图片下载失败');
    }

    if (strlen($bytes) > $maxBytes) {
        ade_fail('图片大小超出站点限制（' . round($maxBytes / 1048576, 1) . ' MB）', 27);
    }

    // 同样以真实内容定类型，不退回 URL 扩展名
    $ext = ade_image_verify($bytes);

    return ade_upload_base64(base64_encode($bytes), 'remote.' . $ext);
}
