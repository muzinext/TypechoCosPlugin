<?php
namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Config;
use Typecho\Widget\Helper\Form;
use Widget\Options;
use Widget\Upload;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

//插件名
if (!defined('pluginName')) {
    define('pluginName', 'TypechoCosPlugin');
}

/**
 * 实现网站静态资源存储到腾讯云COS，有效降低本地存储负载，提升用户体验。
 * @package 腾讯云对象存储（COS）插件
 * @author 木子小鱼
 * @version 1.0.5
 * @link https://github.com/muzinext/TypechoCosPlugin
 * @dependence 1.3.0-*
 * @date 2026-08-19
 */
class Plugin implements PluginInterface
{
    /** 上传文件目录（与Typecho核心保持一致，前缀带/） */
    public const UPLOAD_DIR = '/usr/uploads';

    /** 插件版本号 */
    public const PLUGIN_VERSION = '1.0.5';

    /** User-Agent 标识 */
    public const USER_AGENT = 'typecho/1.3.0;tencentcloud-typecho-plugin-cos/1.0.5;cos-php-sdk-v5/2.6.17';

    /** getOptions() 单请求内静态缓存；configHandle 保存后需失效 */
    public static $cachedOptionsInstance = null;

    /** CosInit() region 模式 Client 单例（按 secid:region:bucket 指纹缓存） */
    public static $cachedCosSingletonClient = null;
    public static $cachedCosSingletonKey = '';

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @return string
     */
    public static function activate()
    {
        try {
            \Typecho\Plugin::factory(Upload::class)->uploadHandle = [__CLASS__, 'uploadHandle'];
            \Typecho\Plugin::factory(Upload::class)->modifyHandle = [__CLASS__, 'modifyHandle'];
            \Typecho\Plugin::factory(Upload::class)->deleteHandle = [__CLASS__, 'deleteHandle'];
            \Typecho\Plugin::factory(Upload::class)->attachmentHandle = [__CLASS__, 'attachmentHandle'];
            \Typecho\Plugin::factory(Upload::class)->attachmentDataHandle = [__CLASS__, 'attachmentDataHandle'];
        } catch (\Throwable $e) {
            return _t('COS插件激活失败：%s', $e->getMessage());
        }
        return _t('COS插件已激活，正确设置后才可正常使用哦~');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @return string
     */
    public static function deactivate()
    {
        return _t('插件已禁用，可能会影响文章图片/附件哦~');
    }

    /**
     * 获取插件配置面板
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        ConfigPanel::render($form);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 自定义配置修改
     *
     * 保存流程：
     * 1. 桶校验：使用 region 模式（不依赖自定义 domain）调用 HeadBucket，凭证错误才会失败
     * 2. 持久化：调用 Helper::configPlugin 写入数据库
     *
     * 注意：Typecho 的 Edit::config 不捕获 PluginException，抛出会导致 500 server error。
     * 因此此处仅对桶校验失败抛出（凭证错误属严重问题）。
     *
     * @param array $config 配置信息
     * @param bool $is_init 是否初始化
     * @throws PluginException
     */
    public static function configHandle(array $config, bool $is_init): void
    {
        // ========== 整个保存流程 Throwable 兜底 ==========
        try {
            if (!$is_init) {
                $opt = (object)$config;

                // 凭证/地域/桶必填校验
                $filled = [
                    'SecretId'       => !empty($opt->secid),
                    'SecretKey'      => !empty($opt->sekey),
                    '所属地域'        => !empty($opt->region),
                    '存储桶名称'      => !empty($opt->bucket),
                ];
                $missing = array_keys(array_filter($filled, function ($v) { return !$v; }));
                if (!empty($missing)) {
                    throw new PluginException('以下必填项不能为空：' . implode('、', $missing));
                }

                // 存储桶连通性校验：仅提示，不阻断保存
                $bucketWarn = null;
                try {
                    $exist = Action::doesBucketExist($opt);
                    if ($exist === false) {
                        $bucketWarn = '存储桶不存在：请核对 BucketName-AppId 与地域（region）是否正确。配置已保存，但上传会失败并回退到本地上传。';
                    } elseif ($exist === null) {
                        $bucketWarn = '无法确认存储桶存在（可能凭证错误、网络不通、地域/桶名有误、或子账号缺少 headBucket 权限）。配置已保存，后续上传时会再次尝试连接，如失败会自动回退到本地上传。';
                    }
                } catch (\Throwable $e) {
                    $bucketWarn = '存储桶连通性校验异常：' . $e->getMessage() . '。配置已保存，上传时会再次尝试连接。';
                    Action::debugLog(sprintf(
                        '[configHandle] doesBucketExist raised unexpected exception: cls=%s, code=%s, msg=%s, file=%s:%d',
                        get_class($e),
                        $e->getCode(),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), true);
                }
                if ($bucketWarn !== null) {
                    Action::debugLog('[configHandle] 桶校验警告(不阻断保存): ' . $bucketWarn, true);
                }
            }

            Helper::configPlugin(pluginName, $config);
            // 配置保存后失效同请求内的 options 静态缓存与 CosClient 单例
            self::$cachedOptionsInstance = null;
            self::$cachedCosSingletonClient = null;
            self::$cachedCosSingletonKey = '';
        } catch (PluginException $e) {
            Action::debugLog(sprintf(
                '[configHandle] PluginException: msg=%s, trace=%s',
                $e->getMessage(),
                $e->getTraceAsString()
            ), true);
            throw $e;
        } catch (\Throwable $e) {
            Action::debugLog(sprintf(
                '[configHandle] FATAL Throwable: cls=%s, code=%s, msg=%s, file=%s:%d, trace=%s',
                get_class($e),
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ), true);
            throw new PluginException(
                '保存插件配置时发生未预期错误：' . $e->getMessage() . '（详情请查看服务器 php-fpm.log/error_log，关键词 [TypechoCosPlugin] FATAL）',
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 获取插件配置选项（单请求内静态缓存）
     *
     * @return Config|null
     */
    public static function getOptions()
    {
        if (self::$cachedOptionsInstance !== null) {
            return self::$cachedOptionsInstance;
        }
        try {
            $opt = Options::alloc()->plugin(pluginName);
            self::$cachedOptionsInstance = $opt;
            return $opt;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ========== 薄委托方法：Typecho 钩子入口，实际逻辑在 Action 类 ==========

    public static function uploadHandle(array $file)
    {
        return Action::uploadHandle($file);
    }

    public static function modifyHandle(array $content, array $file)
    {
        return Action::modifyHandle($content, $file);
    }

    public static function deleteHandle(array $content): bool
    {
        return Action::deleteHandle($content);
    }

    public static function attachmentHandle(Config $attachment): string
    {
        return Action::attachmentHandle($attachment);
    }

    public static function attachmentDataHandle(array $content): string
    {
        return Action::attachmentDataHandle($content);
    }
}
