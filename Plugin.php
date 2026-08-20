<?php
namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Plugin\PluginInterface;
use Typecho\Config;
use Typecho\Widget\Helper\Form;
use Widget\Options;
use Widget\Upload;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 实现网站静态资源存储到云对象存储，有效降低本地存储负载，提升用户体验。
 * @package 腾讯云对象存储插件
 * @author 木子小鱼
 * @version 1.1.0
 * @link https://github.com/muzinext/TypechoCosPlugin
 * @dependence 1.3.0-*
 * @date 2026-08-19
 */
class Plugin implements PluginInterface
{
    /** 插件名称（用于配置存储、静态资源路径） */
    public const NAME = 'TypechoCosPlugin';

    /** 上传文件目录（与Typecho核心保持一致，前缀带/） */
    public const UPLOAD_DIR = '/usr/uploads';

    /** 插件版本号 */
    public const PLUGIN_VERSION = '1.1.0';

    /** User-Agent 标识 */
    public const USER_AGENT = 'typecho/1.3.0;tencentcloud-typecho-plugin-cos/' . self::PLUGIN_VERSION . ';cos-php-sdk-v5/2.6.17';

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
     * 1. 桶校验：使用 region 模式调用 HeadBucket，仅记录警告不阻断保存
     * 2. 持久化：调用 Helper::configPlugin 写入数据库
     *
     * 重要：本方法绝不抛出异常。必填项校验由 Typecho 表单 addRule('required') 负责，
     * 此处仅做连通性等附加校验，失败时记录日志并继续保存，避免页面跳转异常。
     *
     * @param array $config 配置信息
     * @param bool $is_init 是否初始化
     */
    public static function configHandle(array $config, bool $is_init): void
    {
        try {
            if (!$is_init) {
                $opt = (object)$config;

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
                    self::setWarning($bucketWarn);
                }

                // 本地路径可写性校验（仅当开启「同步修改本地存储路径」时）
                if (Action::isEnabled($opt, 'sync_local_path')) {
                    $localWarn = Action::checkLocalPathWritable((string)($opt->path ?? 'usr/uploads'));
                    if ($localWarn !== null) {
                        Action::debugLog('[configHandle] 本地路径警告(不阻断保存): ' . $localWarn, true);
                        self::setWarning($localWarn);
                    }
                }
            }

            Helper::configPlugin(self::NAME, $config);
            // 配置保存后失效同请求内的 options 静态缓存与 CosClient 单例
            self::$cachedOptionsInstance = null;
            self::$cachedCosSingletonClient = null;
            self::$cachedCosSingletonKey = '';
        } catch (\Throwable $e) {
            Action::debugLog(sprintf(
                '[configHandle] FATAL: cls=%s, code=%s, msg=%s, file=%s:%d, trace=%s',
                get_class($e),
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ), true);
            // 绝不抛出异常，避免页面跳转异常。配置保存失败时记录日志即可。
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
            $opt = Options::alloc()->plugin(self::NAME);
            self::$cachedOptionsInstance = $opt;
            return $opt;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 向后端页面推送一条警告提示（保存配置后跳转显示）
     *
     * @param string $msg 警告内容
     */
    private static function setWarning(string $msg): void
    {
        try {
            \Widget\Notice::alloc()->set($msg, 'warning');
        } catch (\Throwable $e) {
            Action::debugLog('[setWarning] failed: ' . $e->getMessage());
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
