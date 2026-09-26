<?php

/**
 * 自适应编辑器 —— 安全与响应基元。
 * 对外输出与写操作校验一律收敛在本文件。CSRF/权限/JSON 全部复用内核。
 */
if (!defined('ZBP_PATH')) {
    exit('Access denied');
}

/**
 * HTML 转义（幂等）。先解码再编码，避免与内核 FilterPost() 的转义叠加成 &amp;amp;。
 * ENT_SUBSTITUTE：非法 UTF-8 用 U+FFFD 顶替，避免 htmlspecialchars 直接返回空串把整条内容吞掉。
 *
 * @param mixed $s
 *
 * @return string
 */
function ade_e($s)
{
    if ($s === null || is_array($s) || is_object($s)) {
        return '';
    }

    return htmlspecialchars(
        html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * 还原为纯文本，供 JSON 下发后由 JS 直接赋值给 input.value / textContent。
 *
 * @param mixed $s
 *
 * @return string
 */
function ade_plain($s)
{
    if ($s === null || is_array($s) || is_object($s)) {
        return '';
    }

    return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * JSON 序列化。不用内核 JsonEncode()（会把正文 HTML 转义）；HEX_* 保证 </script> 不会提前闭合。
 * JSON_INVALID_UTF8_SUBSTITUTE：非法 UTF-8 用替换字符顶替，避免 json_encode 返回 false 让数据岛变空。
 *
 * @param mixed $data
 *
 * @return string
 */
function ade_json($data)
{
    return (string) json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/** 输出 JSON 响应头。内核 JsonError()/JsonReturn() 只负责编码，不设头。 */
function ade_json_headers()
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
}

/** 成功响应。复用内核 JsonReturn()，其内部 code=0 不会 exit，故自行结束。 */
function ade_ok($data = null)
{
    ade_json_headers();
    JsonReturn($data);
    exit;
}

/** 失败响应。复用内核 JsonError()，其内部会 exit。 */
function ade_fail($msg, $code = 1)
{
    ade_json_headers();
    JsonError($code === 0 ? 1 : (int) $code, (string) $msg, null);
}

/**
 * 校验 CSRF。复用内核 CheckIsRefererValid()：先校验 csrfToken，开启「附加安全」时再校验 Referer。
 * 失败时内核会抛 ZbpErrorException，这里转成 JSON。
 */
function ade_need_csrf()
{
    try {
        CheckIsRefererValid();
    } catch (Throwable $e) {
        ade_fail('请求校验失败，请刷新页面后重试', 5);
    }
}

/**
 * 限流。复用内核 zbp_throttle()，键里带上用户 ID + 来源 IP，避免同站多人互相顶掉配额。
 * 内核在缓存不可用时返回 null，这里与内核 ApiThrottle 同姿态：只把 false 当超限。
 */
function ade_need_throttle($name, $maxReqs = 20, $period = 60)
{
    global $zbp;

    $key = ADE_ID . ':' . $name . ':' . md5($zbp->user->ID . '|' . GetGuestIP());

    if (zbp_throttle($key, (int) $maxReqs, (int) $period) === false) {
        ade_fail('操作过于频繁，请稍后再试', 429);
    }
}

/**
 * 执行动作，把内核异常统一转成 JSON（ShowError 是抛异常而非输出后退出）。
 * 捕获 Throwable：PHP 的 Error（TypeError 等）不是 Exception，漏掉会逃逸成非 JSON 的致命页。
 *
 * @param callable $handler
 */
function ade_run($handler)
{
    try {
        call_user_func($handler);
    } catch (ZbpErrorException $e) {
        ade_fail($e->getMessage(), $e->getCode());
    } catch (Throwable $e) {
        ade_fail('操作未完成，请稍后重试', 1);
    }
}

/**
 * 生成插件内资源的绝对 URL。
 *
 * @param string $rel 相对插件根目录的路径
 *
 * @return string
 */
function ade_url($rel)
{
    global $zbp;

    return $zbp->host . 'zb_users/plugin/' . ADE_ID . '/' . ltrim($rel, '/');
}

/**
 * 生成带版本号的资源 URL：拼接 ADE_VERSION + 文件 mtime，文件一改 URL 即变。
 *
 * @param string $rel
 *
 * @return string
 */
function ade_asset($rel)
{
    $v = ADE_VERSION;
    $file = ADE_PATH . ltrim($rel, '/');
    if (is_file($file)) {
        $v .= '-' . substr((string) filemtime($file), -8);
    }

    return ade_url($rel) . '?v=' . $v;
}
