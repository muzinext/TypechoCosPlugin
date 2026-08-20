<?php
namespace TypechoPlugin\TypechoCosPlugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 统一日志输出（脱敏 + 级别控制）
 *
 * 独立成类，避免 Image 等工具类反向依赖 Action。
 * 默认仅在 TYPECHO_COS_DEBUG=1 时输出到 error_log。
 *
 * @package TypechoCosPlugin
 */
class Logger
{
    /** @var bool|null 调试开关缓存 */
    private static ?bool $debugEnabled = null;

    /**
     * 输出日志
     *
     * @param string $msg 日志内容
     * @param bool $force 强制输出（不受 TYPECHO_COS_DEBUG 开关控制）
     */
    public static function log(string $msg, bool $force = false): void
    {
        if (self::$debugEnabled === null) {
            self::$debugEnabled = (defined('TYPECHO_COS_DEBUG') && TYPECHO_COS_DEBUG);
        }
        if (!$force && !self::$debugEnabled) {
            return;
        }
        if (!function_exists('error_log')) {
            return;
        }
        error_log('[TypechoCosPlugin] ' . self::maskSensitive($msg));
    }

    /**
     * 敏感信息脱敏
     *
     * 覆盖 COS 签名参数（q-ak、q-signature 等）及通用密钥字段。
     * 兼容 URL 编码（%3D/%26）与未编码（=/&）两种形式。
     *
     * @param string $msg
     * @return string
     */
    private static function maskSensitive(string $msg): string
    {
        // 仅匹配 COS 签名参数和明确的密钥字段，避免误脱敏正常 URL 参数（如 keyword=、apikey=）
        $result = preg_replace_callback(
            '/(q-ak|q-signature|q-key-time|q-sign-token|q-sign-algorithm|q-header-list|q-url-param-list|secretid|secretkey|accesskey|secret|token|signature)([^&"\']{0,6})(=|%3D)([^&"\' ]+)/i',
            function ($m) {
                $v = $m[4];
                $mask = (strlen($v) <= 4) ? '***' : (substr($v, 0, 2) . '***' . substr($v, -1));
                return $m[1] . $m[2] . $m[3] . $mask;
            },
            $msg
        );
        return $result ?? $msg;
    }
}
