<?php
namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Common;
use Typecho\Config;
use Typecho\Date;
use Widget\Options;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action
{
    /**
     * 上传文件处理函数
     *
     * 优化点：
     * 1. 使用 random_bytes 命名，移除 doesObjectExist 网络请求
     * 2. WebP 临时文件使用 tempPath，避免 tempnam 空文件残留
     * 3. 文件大小优先从本地获取，避免 headObject 网络请求
     * 4. 提取 WebP 转换、本地备份等重复逻辑为私有方法
     *
     * @param array $file
     * @return array|false
     */
    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $file['name'] = Image::sanitizeFileName($file['name']);
        $ext = Image::getExtension($file['name']);
        if (!Upload::checkFileType($ext)) {
            return false;
        }
        $uploadfile = self::getUploadFile($file);
        if (!isset($uploadfile)) {
            return false;
        }

        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return self::defaultUploadHandle($file, $ext, $uploadfile);
        }

        // random_bytes 命名，冲突率可忽略，无需 doesObjectExist 网络请求
        $dateSubDir = self::getUploadSubDir($ext);
        $fileDir = self::getUploadDir() . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $fileName = self::randomHex(8) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;
        $cosKey = ltrim($path, '/');

        $cosClient = null;
        try {
            $cosClient = self::CosInit();
            self::uploadToCos($cosClient, $opt, $cosKey, $uploadfile, $file);
        } catch (\Throwable $e) {
            self::debugLog(sprintf(
                'COS upload failed, fallback to local. Exception: %s, Msg: %s, Bucket: %s, Key: %s',
                get_class($e), $e->getMessage(), $opt->bucket ?? '', $cosKey ?? $path ?? ''
            ));
            return self::defaultUploadHandle($file, $ext, $uploadfile);
        }

        // WebP 自动转换并上传；成功则附件记录使用 WebP 路径，确保编辑器返回 WebP 链接
        $webpSuccess = self::handleWebpConversion($cosClient, $opt, $file, $uploadfile, $path, $ext);
        $returnPath = $webpSuccess ? Image::getWebpPath($path) : $path;

        // 文件大小（优先本地，避免 headObject 网络请求）
        $file['size'] = self::getFileSizeSafe($file, $uploadfile, $cosClient, $opt, $cosKey);

        // 本地备份
        self::saveLocalBackup($file, $uploadfile, $opt, $path);

        // MIME 类型（tmp_name 直接检测；bytes/bits 写入临时文件后检测）
        $mime = false;
        if (isset($file['tmp_name']) && is_string($uploadfile) && file_exists($uploadfile)) {
            $mime = @Common::mimeContentType($uploadfile);
        } elseif (is_string($uploadfile) && $uploadfile !== '') {
            $mimeTmp = tempnam(sys_get_temp_dir(), 'cos_mime_');
            if ($mimeTmp !== false && @file_put_contents($mimeTmp, $uploadfile) !== false) {
                $mime = @Common::mimeContentType($mimeTmp);
                @unlink($mimeTmp);
            }
        }
        if (!$mime) {
            $mime = @Common::mimeContentType($path);
        }

        return [
            'name' => $file['name'],
            'path' => $returnPath,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => $mime ?: 'application/octet-stream'
        ];
    }

    /**
     * Typecho 默认本地上传逻辑（回退分支）
     */
    private static function defaultUploadHandle(array $file, string $ext, $uploadfile)
    {
        $rootDir = self::getUploadRootDir();
        $uploadDir = self::getLocalUploadDir();
        $dateSubDir = self::getUploadSubDir($ext);
        $dir = $rootDir . $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $relPath = $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');

        if (!is_dir($dir) && !self::makeUploadDir($dir)) {
            return false;
        }

        $fileName = self::randomHex(8) . '.' . $ext;
        $absPath = $dir . '/' . $fileName;
        $relPath .= '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($uploadfile, $absPath)) return false;
        } elseif (isset($file['bytes'])) {
            if (!@file_put_contents($absPath, $file['bytes'])) return false;
        } elseif (isset($file['bits'])) {
            if (!@file_put_contents($absPath, $file['bits'])) return false;
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = @filesize($absPath);
        }

        return [
            'name' => $file['name'],
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => @Common::mimeContentType($absPath)
        ];
    }

    /**
     * 修改文件处理函数
     */
    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $file['name'] = Image::sanitizeFileName($file['name']);
        $ext = Image::getExtension($file['name']);
        if ($content['attachment']->type != $ext) {
            return false;
        }
        $uploadfile = self::getUploadFile($file);
        if (!isset($uploadfile)) {
            return false;
        }

        $opt = Plugin::getOptions();
        $relPath = $content['attachment']->path;

        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
        }

        // 若附件记录的路径是 WebP（之前上传时转换成功），推导原始路径用于上传新文件
        $originalPath = $relPath;
        if (Image::getExtension($relPath) === 'webp') {
            $origExt = $content['attachment']->type ?? $ext;
            if ($origExt && $origExt !== 'webp') {
                $originalPath = preg_replace('/\.webp$/', '.' . $origExt, $relPath);
            }
        }

        $cosKey = ltrim($originalPath, '/');
        $cosClient = null;
        try {
            $cosClient = self::CosInit();
            self::uploadToCos($cosClient, $opt, $cosKey, $uploadfile, $file);
        } catch (\Throwable $e) {
            self::debugLog(sprintf(
                'COS modify failed, fallback to local. Exception: %s, Msg: %s, Key: %s',
                get_class($e), $e->getMessage(), $cosKey ?: $relPath
            ));
            return self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
        }

        // WebP 自动转换并上传（覆盖旧 WebP）；成功则返回 WebP 路径
        $webpSuccess = self::handleWebpConversion($cosClient, $opt, $file, $uploadfile, $originalPath, $ext);
        $returnPath = $webpSuccess ? Image::getWebpPath($originalPath) : $originalPath;

        // 文件大小（优先本地）
        $file['size'] = self::getFileSizeSafe($file, $uploadfile, $cosClient, $opt, $cosKey);

        // 本地备份（替换旧文件）
        self::saveLocalBackup($file, $uploadfile, $opt, $originalPath, true);

        return [
            'name' => $content['attachment']->name,
            'path' => $returnPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }

    /**
     * 默认本地修改逻辑（回退分支）
     */
    private static function defaultModifyHandle(array $content, array $file, string $ext, $uploadfile, string $relPath)
    {
        if ($content['attachment']->type != $ext) return false;
        $rootDir = self::getUploadRootDir();
        $absPath = $rootDir . $relPath;
        $dir = dirname($absPath);
        if (!is_dir($dir) && !self::makeUploadDir($dir)) return false;

        $tmp = $absPath . '.tmp_' . self::randomHex(4);
        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($uploadfile, $tmp)) { @unlink($tmp); return false; }
        } elseif (isset($file['bytes'])) {
            if (@file_put_contents($tmp, $file['bytes']) === false) { @unlink($tmp); return false; }
        } elseif (isset($file['bits'])) {
            if (@file_put_contents($tmp, $file['bits']) === false) { @unlink($tmp); return false; }
        } else {
            return false;
        }
        @unlink($absPath);
        if (!@rename($tmp, $absPath)) {
            self::debugLog('defaultModifyHandle rename failed: ' . $tmp . ' -> ' . $absPath);
            return false;
        }
        if (!isset($file['size'])) $file['size'] = @filesize($absPath);

        return [
            'name' => $content['attachment']->name,
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }

    /**
     * 删除文件处理
     *
     * 修复：删除 WebP 不依赖 webp_enable 配置，避免关闭功能后残留 .webp 文件。
     * 只要是图片类型且不是 webp/gif/svg/ico，就尝试删除对应 .webp（COS 删除不存在的对象不报错）。
     */
    public static function deleteHandle(array $content): bool
    {
        $relPath = $content['attachment']->path ?? '';
        if ($relPath === '') {
            return true;
        }
        $rootDir = self::getUploadRootDir();
        $absPath = $rootDir . $relPath;
        $opt = Plugin::getOptions();

        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return @unlink($absPath);
        }

        $localSyncEnabled = self::isEnabled($opt, 'local') && self::isEnabled($opt, 'local_sync');
        $localDeleted = true;
        if ($localSyncEnabled) {
            // 本地备份存的是原图路径；若附件记录路径是 WebP，需推导原始路径
            $localPath = $relPath;
            if (Image::getExtension($relPath) === 'webp') {
                $origExt = $content['attachment']->type ?? '';
                if ($origExt && $origExt !== 'webp') {
                    $localPath = preg_replace('/\.webp$/', '.' . $origExt, $relPath);
                }
            }
            $localDeleted = @unlink($rootDir . $localPath);
        }

        $cosDeleted = true;
        if (self::isEnabled($opt, 'remote_sync')) {
            try {
                $cosClient = self::CosInit();
                $deleteKey = ltrim($relPath, '/');
                self::cosCallWithRetry(function () use ($cosClient, $opt, $deleteKey) {
                    $cosClient->deleteObject(['Bucket' => $opt->bucket, 'Key' => $deleteKey]);
                }, 1, 'delete');

                // 同步删除衍生文件：
                // - 路径是原始格式(jpg/png等) → 删除对应的 .webp
                // - 路径是 .webp → 删除对应的原始文件(从 type 字段推导扩展名)
                $ext = Image::getExtension($relPath);
                if ($ext === 'webp') {
                    $origExt = $content['attachment']->type ?? '';
                    if ($origExt && $origExt !== 'webp') {
                        $origPath = preg_replace('/\.webp$/', '.' . $origExt, $relPath);
                        try {
                            $cosClient->deleteObject([
                                'Bucket' => $opt->bucket,
                                'Key' => ltrim($origPath, '/')
                            ]);
                        } catch (\Throwable $e) {
                            self::debugLog('[WebP] delete original failed (non-fatal): ' . $e->getMessage());
                        }
                    }
                } elseif (Image::getMediaType($ext) === 'images' && !in_array($ext, ['gif', 'svg', 'ico'], true)) {
                    try {
                        $cosClient->deleteObject([
                            'Bucket' => $opt->bucket,
                            'Key' => ltrim(Image::getWebpPath($relPath), '/')
                        ]);
                    } catch (\Throwable $e) {
                        self::debugLog('[WebP] delete failed (non-fatal): ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                self::debugLog('COS deleteObject failed (non-fatal): ' . $e->getMessage());
                $cosDeleted = false;
            }
        }

        if (!$cosDeleted) {
            self::debugLog(sprintf('[deleteHandle] COS 对象残留需手动清理：bucket=%s key=%s', $opt->bucket ?? '', ltrim($relPath, '/')));
        }

        if ($localSyncEnabled && !$localDeleted) return false;
        return true;
    }

    // ==================== 提取的公共方法（减少重复代码） ====================

    /**
     * WebP 转换并上传（uploadHandle / modifyHandle 共用）
     *
     * @return bool 转换并上传成功返回 true，否则返回 false
     */
    private static function handleWebpConversion($cosClient, $opt, array $file, $uploadfile, string $path, string $ext): bool
    {
        if (!Image::isConvertibleImage($ext, $opt)) {
            return false;
        }
        $srcPath = Image::getSourceFilePath($file, $uploadfile);
        if ($srcPath === null) {
            self::debugLog('[WebP] skipped: source file path null');
            return false;
        }
        $srcIsTmp = ($srcPath !== ($file['tmp_name'] ?? ''));
        $webpTmpPath = Image::tempPath('cos_webp_', 'webp');
        $quality = max(0, min(100, (int)($opt->webp_quality ?? 80)));

        $success = false;
        if (Image::convertToWebp($srcPath, $webpTmpPath, $quality)) {
            $webpRelPath = Image::getWebpPath($path);
            if (Image::uploadWebpToCos($cosClient, $opt, $webpRelPath, $webpTmpPath)) {
                $success = true;
            } else {
                self::debugLog('[WebP] upload to COS failed. path=' . $path, true);
            }
        } else {
            self::debugLog('[WebP] convertToWebp returned false, ext=' . $ext . ' src=' . $srcPath, true);
        }

        @unlink($webpTmpPath);
        if ($srcIsTmp) {
            @unlink($srcPath);
        }
        return $success;
    }

    /**
     * 安全获取文件大小（优先本地文件，避免 headObject 网络请求）
     */
    private static function getFileSizeSafe(array $file, $uploadfile, $cosClient, $opt, string $cosKey): int
    {
        if (isset($file['size'])) {
            return (int)$file['size'];
        }
        // 优先从本地临时文件获取
        if (is_string($uploadfile) && file_exists($uploadfile)) {
            $size = @filesize($uploadfile);
            if ($size !== false) return $size;
        }
        // 回退到 headObject
        try {
            if ($cosClient) {
                $fileInfo = $cosClient->headObject(['Bucket' => $opt->bucket, 'Key' => $cosKey])->toArray();
                return (int)($fileInfo['ContentLength'] ?? 0);
            }
        } catch (\Throwable $e) {
            self::debugLog('headObject failed: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * 保存本地备份
     *
     * @param array $file 上传文件信息
     * @param mixed $uploadfile 文件路径或二进制内容
     * @param object $opt 插件配置
     * @param string $path 相对存储路径
     * @param bool $deleteOld 是否先删除旧文件（modifyHandle 替换文件时为 true）
     */
    private static function saveLocalBackup(array $file, $uploadfile, $opt, string $path, bool $deleteOld = false): void
    {
        if (!self::isEnabled($opt, 'local')) return;
        $rootDir = self::getUploadRootDir();
        $localDir = $rootDir . dirname($path);
        $localPath = $rootDir . $path;
        if (!self::makeUploadDir($localDir)) return;
        if ($deleteOld) {
            @unlink($localPath);
        }
        if (isset($file['tmp_name'])) {
            @move_uploaded_file($uploadfile, $localPath);
        } elseif (isset($file['bytes']) || isset($file['bits'])) {
            @file_put_contents($localPath, $file['bytes'] ?? $file['bits']);
        }
    }

    // ==================== URL 生成 ====================

    public static function attachmentHandle(Config $attachment): string
    {
        return self::resolveAttachmentUrl($attachment->path ?? '');
    }

    public static function attachmentDataHandle(array $content): string
    {
        return self::resolveAttachmentUrl($content['attachment']->path ?? '');
    }

    /**
     * 根据附件路径生成可访问 URL（COS 优先，失败回退本地）
     */
    private static function resolveAttachmentUrl(string $path): string
    {
        $fallbackUrl = self::makeLocalAttachmentUrl($path);
        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return $fallbackUrl;
        }
        try {
            return self::buildObjectUrl($opt, $path);
        } catch (\Throwable $e) {
            self::debugLog('[resolveAttachmentUrl] failed, fallback: ' . $e->getMessage());
            return $fallbackUrl;
        }
    }

    private static function buildObjectUrl($opt, string $path): string
    {
        $cosKey = ltrim($path, '/');
        if ($cosKey === '') throw new \RuntimeException('empty path');

        // 对路径分段 URL 编码（保留 / 分隔符），兼容中文/空格等特殊字符
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $cosKey)));

        if (!empty($opt->domain)) {
            return 'https://' . self::normalizeDomain($opt->domain) . '/' . $encodedKey;
        }
        $defaultCosDomain = $opt->bucket . '.cos.' . $opt->region . '.myqcloud.com';
        return 'https://' . $defaultCosDomain . '/' . $encodedKey;
    }

    private static function makeLocalAttachmentUrl(string $path): string
    {
        try {
            $options = Options::alloc();
            $baseUrl = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : ($options->siteUrl ?? '');
            $url = Common::url($path, $baseUrl);
        } catch (\Throwable $e) {
            $url = '';
        }
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            if (isset($_SERVER['HTTP_HOST']) && preg_match('/^\[?[a-zA-Z0-9.\-:]+\]?(:[0-9]+)?$/', $_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($path, '/');
            } else {
                $url = 'https://localhost/' . ltrim($path, '/');
            }
        }
        return $url;
    }

    // ==================== COS 客户端 ====================

    public static function CosInit($options = null)
    {
        if (!$options) {
            $options = Plugin::getOptions();
            if (!$options) throw new \RuntimeException('插件未配置');
        }
        if (empty($options->secid) || empty($options->sekey)) throw new \RuntimeException('SecretId/SecretKey 不能为空');
        if (empty($options->region)) throw new \RuntimeException('地域不能为空');

        $cacheKey = md5($options->secid . '|' . ($options->region ?? '') . '|' . ($options->bucket ?? ''));
        if (Plugin::$cachedCosSingletonClient !== null && Plugin::$cachedCosSingletonKey === $cacheKey) {
            return Plugin::$cachedCosSingletonClient;
        }

        self::loadSdk();

        $client = new \Qcloud\Cos\Client([
            'region'      => $options->region,
            'schema'       => 'https',
            'credentials'  => ['secretId' => $options->secid, 'secretKey' => $options->sekey],
            'userAgent'    => Plugin::USER_AGENT,
            'timeout'      => (float)($options->timeout ?? 5.0),
        ]);

        Plugin::$cachedCosSingletonClient = $client;
        Plugin::$cachedCosSingletonKey = $cacheKey;
        return $client;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if (stripos($domain, 'https://') === 0) $domain = substr($domain, 8);
        elseif (stripos($domain, 'http://') === 0) $domain = substr($domain, 7);
        return rtrim($domain, '/');
    }

    private static function loadSdk(): void
    {
        static $loaded = false;
        static $lastErr = null;
        if ($loaded) return;
        if ($lastErr !== null) throw new \RuntimeException($lastErr);

        $pharPath = __DIR__ . '/phar/cos-sdk-v5-7.phar';
        $autoload = 'phar://' . $pharPath . '/vendor/autoload.php';
        if (!file_exists($pharPath)) {
            $lastErr = 'COS SDK phar 文件不存在: ' . $pharPath;
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        if (!extension_loaded('phar')) {
            $lastErr = 'PHP 未启用 phar 扩展';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }

        $includeError = null;
        set_error_handler(static function ($severity, $message) use (&$includeError) { $includeError = $message; });
        try {
            $includeOk = include_once $autoload;
        } finally {
            restore_error_handler();
        }

        if ($includeOk === false || $includeError !== null) {
            $lastErr = 'COS SDK autoload 加载失败: ' . ($includeError ?: 'include_once returned false');
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        if (!class_exists('\\Qcloud\\Cos\\Client')) {
            $lastErr = 'COS SDK 已加载但类不存在，可能 phar 损坏';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        $loaded = true;
    }

    // ==================== 日志（委托给 Logger，解耦 Image 依赖） ====================

    public static function debugLog(string $msg, bool $force = false): void
    {
        Logger::log($msg, $force);
    }

    /**
     * 判断开关类选项是否启用（兼容 Checkbox 数组值和旧版 Select 字符串值）
     *
     * @param object|null $opt 插件配置对象
     * @param string $key 选项名
     * @return bool
     */
    public static function isEnabled($opt, string $key): bool
    {
        if (!$opt) {
            return false;
        }
        $val = $opt->$key ?? null;
        if (is_array($val)) {
            return in_array('open', $val, true);
        }
        return $val === 'open';
    }

    // ==================== COS 上传公共方法（含重试） ====================

    /**
     * COS API 调用带重试（网络瞬时故障自动重试 1 次）
     *
     * @param callable $fn 执行 COS 调用的闭包
     * @param int $retries 重试次数
     * @param string $label 日志标签
     * @throws \Throwable 重试耗尽后抛出原始异常
     */
    private static function cosCallWithRetry(callable $fn, int $retries = 1, string $label = 'cos'): void
    {
        for ($i = 0; $i <= $retries; $i++) {
            try {
                $fn();
                return;
            } catch (\Throwable $e) {
                if ($i >= $retries) {
                    throw $e;
                }
                self::debugLog(sprintf('[COS] %s 失败，第 %d 次重试: %s', $label, $i + 1, $e->getMessage()));
                usleep(200000);
            }
        }
    }

    /** COS 上传通用请求头 */
    private static function cosUploadHeaders(): array
    {
        return [
            'ACL'          => 'public-read',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'Expires'      => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
        ];
    }

    /**
     * 上传文件到 COS（自动处理 tmp_name / bytes / bits，含重试）
     *
     * @param mixed $cosClient COS 客户端
     * @param object $opt 插件配置
     * @param string $cosKey COS 对象键
     * @param mixed $uploadfile 文件路径或二进制内容
     * @param array $file 上传文件信息
     * @throws \Throwable 上传失败时抛出
     */
    private static function uploadToCos($cosClient, $opt, string $cosKey, $uploadfile, array $file): void
    {
        $fileHandle = null;
        $body = null;
        if (isset($file['tmp_name'])) {
            $fileHandle = @fopen($uploadfile, 'rb');
            if ($fileHandle === false) {
                throw new \RuntimeException('打开上传临时文件失败: ' . $uploadfile);
            }
            $body = $fileHandle;
        } else {
            $body = $uploadfile;
        }
        try {
            self::cosCallWithRetry(function () use ($cosClient, $opt, $cosKey, $body) {
                if (is_resource($body)) {
                    @rewind($body);
                }
                $cosClient->upload($opt->bucket, $cosKey, $body, self::cosUploadHeaders());
            }, 1, 'upload');
        } finally {
            if (is_resource($fileHandle)) {
                @fclose($fileHandle);
            }
        }
    }

    // ==================== 存在性检查 ====================

    public static function doesBucketExist($opt): ?bool
    {
        $checkOpt = (object)[
            'secid' => $opt->secid ?? '', 'sekey' => $opt->sekey ?? '',
            'region' => $opt->region ?? '', 'bucket' => $opt->bucket ?? '', 'domain' => '',
        ];
        try {
            $cosClient = self::CosInit($checkOpt);
        } catch (\Throwable $e) {
            self::debugLog('doesBucketExist CosInit failed: ' . $e->getMessage());
            return null;
        }
        try {
            return $cosClient->doesBucketExist($opt->bucket) ? true : false;
        } catch (\Throwable $e) {
            self::debugLog('doesBucketExist SDK failed (inconclusive): ' . $e->getMessage());
            return null;
        }
    }

    // ==================== 路径与目录 ====================

    private static function makeUploadDir(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (is_dir($path)) return true;
        return @mkdir($path, 0755, true);
    }

    private static function getUploadRootDir(): string
    {
        return defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
    }

    private static function getUploadDir(): string
    {
        $opt = Plugin::getOptions();
        $dir = '';
        if ($opt && !empty($opt->path)) {
            $dir = $opt->path;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            $dir = __TYPECHO_UPLOAD_DIR__;
        } else {
            $dir = Plugin::UPLOAD_DIR;
        }
        $dir = '/' . ltrim($dir, '/');
        $dir = rtrim($dir, '/');
        $dir = preg_replace('#(?:^|/)\.\.?(?:/|$)#', '/', $dir);
        $dir = preg_replace('#/+#', '/', $dir);
        return $dir === '' ? '/' : rtrim($dir, '/');
    }

    /**
     * 获取本地存储上传目录
     *
     * 当开启「同步修改本地存储路径」时，使用与 COS 相同的配置路径；
     * 否则使用 Typecho 默认的 __TYPECHO_UPLOAD_DIR__ 或 Plugin::UPLOAD_DIR。
     *
     * @return string 以 / 开头，结尾不带 /
     */
    private static function getLocalUploadDir(): string
    {
        $opt = Plugin::getOptions();
        if (self::isEnabled($opt, 'sync_local_path')) {
            return self::getUploadDir();
        }
        if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return '/' . trim(__TYPECHO_UPLOAD_DIR__, '/');
        }
        return Plugin::UPLOAD_DIR;
    }

    /**
     * 校验本地存储路径是否可写
     *
     * 用于配置保存时提前发现权限问题，仅返回警告信息，不阻断保存。
     *
     * @param string $relPath 相对路径（如 usr/uploads）
     * @return string|null 可写返回 null，不可写返回警告信息
     */
    public static function checkLocalPathWritable(string $relPath): ?string
    {
        $rootDir = self::getUploadRootDir();
        $absPath = rtrim($rootDir, '/') . '/' . trim($relPath, '/');

        if (is_dir($absPath)) {
            if (!is_writable($absPath)) {
                return '本地存储路径不可写：' . $absPath . '。请检查目录权限，否则回退到本地上传时会失败。';
            }
            return null;
        }

        // 目录不存在，尝试创建测试目录验证父目录可写
        $testDir = $absPath . '/.cos_write_test_' . self::randomHex(4);
        if (!@mkdir($testDir, 0755, true)) {
            return '本地存储路径无法创建：' . $absPath . '。请检查父目录权限，否则回退到本地上传时会失败。';
        }
        @rmdir($testDir);
        // 清理可能创建的中间空目录（仅当目录为空时）
        $parent = dirname($testDir);
        while ($parent !== $rootDir && $parent !== '/' && @rmdir($parent)) {
            $parent = dirname($parent);
        }
        return null;
    }

    private static function getUploadSubDir(string $ext = ''): string
    {
        $opt = Plugin::getOptions();
        $template = trim((string)($opt->dir_structure ?? '{year}/{month}'), '/');
        if ($template === '') return '';

        $date = new Date();
        $ext = strtolower($ext);
        $replace = [
            '{year}'  => $date->year,
            '{month}' => $date->month,
            '{day}'   => $date->day,
            '{type}'  => Image::getMediaType($ext),
            '{ext}'   => $ext !== '' ? $ext : 'other',
        ];
        $subDir = str_replace(array_keys($replace), array_values($replace), $template);
        $subDir = preg_replace('#(?:^|/)\.\.?(?:/|$)#', '/', $subDir);
        $subDir = preg_replace('#/+#', '/', $subDir);
        return trim($subDir, '/');
    }

    private static function getUploadFile(array $file)
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? null));
    }

    /**
     * 生成安全随机十六进制字符串
     *
     * 优先使用 random_bytes（CSPRNG），极端环境下回退到 uniqid。
     *
     * @param int $length 随机字节数（输出十六进制长度为 2*length）
     * @return string
     */
    private static function randomHex(int $length = 8): string
    {
        try {
            return bin2hex(random_bytes($length));
        } catch (\Throwable $e) {
            return bin2hex(uniqid((string)mt_rand(), true));
        }
    }
}
