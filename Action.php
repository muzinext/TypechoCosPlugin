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
     * 关键修复：
     * 1. 插件未配置/COS上传失败时，回退到Typecho默认的本地上传逻辑
     *    （因为只要钩子被注册，Typecho核心的signal就会变为true，无法再走默认分支）
     * 2. 本地保存时使用 __TYPECHO_ROOT_DIR__ 前缀确保路径正确
     * 3. MIME检测基于已存在的临时文件，而非可能不存在的目标路径
     *
     * @param array $file
     * @return array|false
     */
    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        // 清洗文件名（同时为后续入库 name 字段保持安全值），并取扩展名做类型校验
        $file['name'] = self::sanitizeFileName($file['name']);
        $ext = self::getExtension($file['name']);
        // 判定是否是允许的文件类型
        if (!Upload::checkFileType($ext)) {
            return false;
        }
        // 获得上传文件
        $uploadfile = self::getUploadFile($file);
        // 如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }

        // 获取设置参数
        $opt = Plugin::getOptions();

        // ========== 回退分支：插件未配置 / 凭证不完整 -> 使用默认本地上传 ==========
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return self::defaultUploadHandle($file, $ext, $uploadfile);
        }

        // 获取文件名
        $dateSubDir = self::getUploadSubDir($ext);
        $fileDir = self::getUploadDir() . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;

        /* 上传到COS */
        $cosClient = null;
        try {
            $cosClient = self::CosInit();
            // CRC32+uniqid 的随机命名冲突率极低，仅检查一次，避免 COS 网络请求慢时循环拖慢
            if (self::doesObjectExist($path)) {
                $fileName = sprintf('%u', crc32(uniqid('alt'))) . '.' . $ext;
                $path = $fileDir . '/' . $fileName;
            }

            // COS Key 约定不带前置 /
            $cosKey = ltrim($path, '/');

            // 根据上传来源构造上传 body：
            //   - tmp_name（HTTP 上传）：fopen 文件句柄，性能好且支持大文件分片
            //   - bytes / bits（XML-RPC / MetaWeblog API）：直接作为字符串内容传给 SDK
            //     （旧代码统一 fopen，会把二进制内容当作文件路径，导致 XML-RPC 上传必然失败）
            $fileHandle = null;
            $uploadBody = null;
            if (isset($file['tmp_name'])) {
                $fileHandle = @fopen($uploadfile, 'rb');
                if ($fileHandle === false) {
                    throw new \RuntimeException('打开上传临时文件失败: ' . $uploadfile);
                }
                $uploadBody = $fileHandle;
            } else {
                // bytes / bits 是二进制内容字符串，SDK 的 upload() 直接支持
                $uploadBody = $uploadfile;
            }

            try {
                $cosClient->upload(
                    $opt->bucket,
                    $cosKey,
                    $uploadBody,
                    array(
                        'ACL'          => 'public-read',
                        'CacheControl' => 'public, max-age=31536000, immutable',
                        'Expires'      => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
                    )
                );
            } finally {
                // 始终释放文件句柄，避免异常路径下资源泄漏
                if (is_resource($fileHandle)) {
                    @fclose($fileHandle);
                }
            }
        } catch (\Throwable $e) {
            // 详细记录失败原因，便于排查 COS 上传问题
            self::debugLog(sprintf(
                'COS upload failed, fallback to local. ' .
                'Exception: %s, Code: %s, Msg: %s, ' .
                'Bucket: %s, Region: %s, Key: %s, UploadFile: %s',
                get_class($e),
                method_exists($e, 'getExceptionCode') ? $e->getExceptionCode() : $e->getCode(),
                $e->getMessage(),
                $opt->bucket ?? '',
                $opt->region ?? '',
                $cosKey ?? $path ?? '',
                $uploadfile ?? ''
            ));
            // COS上传失败，回退到默认本地上传
            return self::defaultUploadHandle($file, $ext, $uploadfile);
        }

        if (!isset($file['size'])) {
            try {
                if ($cosClient) {
                    $fileInfo = $cosClient->headObject(array('Bucket' => $opt->bucket, 'Key' => $cosKey))->toArray();
                    $file['size'] = $fileInfo['ContentLength'] ?? 0;
                }
            } catch (\Throwable $e) {
                self::debugLog('headObject failed: ' . $e->getMessage());
            }
            // 兜底：如果还没拿到size，通过临时文件获取
            if (!isset($file['size']) && file_exists($uploadfile)) {
                $file['size'] = filesize($uploadfile);
            }
            // 最终兜底：如果还是拿不到，置为0避免未定义错误
            if (!isset($file['size'])) {
                $file['size'] = 0;
            }
        }

        // 本地保存一份（使用绝对路径）
        if (($opt->local ?? 'close') === 'open') {
            $rootDir = self::getUploadRootDir();
            $localDir = $rootDir . $fileDir;
            $localPath = $rootDir . $path;
            if (self::makeUploadDir($localDir)) {
                if (isset($file['tmp_name'])) {
                    @move_uploaded_file($uploadfile, $localPath);
                } elseif (isset($file['bytes']) || isset($file['bits'])) {
                    $contents = $file['bytes'] ?? $file['bits'];
                    @file_put_contents($localPath, $contents);
                }
            }
        }

        // MIME类型优先基于临时文件检测（一定存在）
        $mime = false;
        if (isset($file['tmp_name']) && file_exists($uploadfile)) {
            $mime = @Common::mimeContentType($uploadfile);
        }
        if (!$mime) {
            // 回退到通过目标路径扩展名推断
            $mime = @Common::mimeContentType($path);
        }

        // 返回相对存储路径
        return array(
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'],
            'type' => $ext,
            'mime' => $mime ?: 'application/octet-stream'
        );
    }

    /**
     * Typecho默认的本地上传逻辑（作为插件回退分支）
     * 当COS未配置或上传失败时调用，保证文件管理功能始终可用
     *
     * @param array $file
     * @param string $ext
     * @param mixed $uploadfile
     * @return array|false
     */
    private static function defaultUploadHandle(array $file, string $ext, $uploadfile)
    {
        $rootDir = self::getUploadRootDir();
        $uploadDir = defined('__TYPECHO_UPLOAD_DIR__') ? __TYPECHO_UPLOAD_DIR__ : Upload::UPLOAD_DIR;
        $dateSubDir = self::getUploadSubDir($ext);

        $dir = $rootDir . $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $relPath = $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');

        if (!is_dir($dir)) {
            if (!self::makeUploadDir($dir)) {
                return false;
            }
        }

        $fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $absPath = $dir . '/' . $fileName;
        $relPath .= '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($uploadfile, $absPath)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            // @ 抑制 warning，避免破坏 OB 链；失败靠返回值判断
            if (!@file_put_contents($absPath, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (!@file_put_contents($absPath, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($absPath);
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
     *
     * 关键修复：
     * 1. 插件未配置/COS上传失败时，回退到Typecho默认的本地修改逻辑
     * 2. 本地保存时使用 __TYPECHO_ROOT_DIR__ 前缀确保路径正确
     * 3. MIME检测基于临时文件，size检测有兜底
     *
     * @param array $content 旧文件
     * @param array $file 新文件
     * @return array|false
     */
    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        // 清洗文件名 + 取扩展名做类型校验
        $file['name'] = self::sanitizeFileName($file['name']);
        $ext = self::getExtension($file['name']);
        // 判定是否是允许的文件类型
        if ($content['attachment']->type != $ext) {
            return false;
        }
        // 获得上传文件
        $uploadfile = self::getUploadFile($file);
        // 如果没有临时文件，则退出
        if (!isset($uploadfile)) {
            return false;
        }
        // 获取设置参数
        $opt = Plugin::getOptions();
        // 获取文件路径（相对路径，如 /usr/uploads/2026/08/xxx.jpg）
        $relPath = $content['attachment']->path;

        // ========== 回退分支：插件未配置 / 凭证不完整 -> 使用默认本地修改 ==========
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
        }

        /* 上传到COS */
        $cosClient = null;
        $cosKey = '';
        try {
            $cosClient = self::CosInit();
            // COS Key 约定不带前置 /
            $cosKey = ltrim($relPath, '/');

            // 根据上传来源构造上传 body：
            //   - tmp_name（HTTP 上传）：fopen 文件句柄
            //   - bytes / bits（XML-RPC / MetaWeblog API）：直接传字符串内容给 SDK
            //     （旧代码统一 fopen 会把二进制当路径打开，导致 XML-RPC 修改必然失败）
            $fileHandle = null;
            $uploadBody = null;
            if (isset($file['tmp_name'])) {
                $fileHandle = @fopen($uploadfile, 'rb');
                if ($fileHandle === false) {
                    throw new \RuntimeException('打开上传临时文件失败: ' . $uploadfile);
                }
                $uploadBody = $fileHandle;
            } else {
                $uploadBody = $uploadfile;
            }

            try {
                $cosClient->upload(
                    $opt->bucket,
                    $cosKey,
                    $uploadBody,
                    array(
                        'ACL'          => 'public-read',
                        'CacheControl' => 'public, max-age=31536000, immutable',
                        'Expires'      => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
                    )
                );
            } finally {
                if (is_resource($fileHandle)) {
                    @fclose($fileHandle);
                }
            }
        } catch (\Throwable $e) {
            // 详细记录失败原因，便于排查
            self::debugLog(sprintf(
                'COS modify failed, fallback to local. ' .
                'Exception: %s, Code: %s, Msg: %s, ' .
                'Bucket: %s, Region: %s, Key: %s, UploadFile: %s',
                get_class($e),
                method_exists($e, 'getExceptionCode') ? $e->getExceptionCode() : $e->getCode(),
                $e->getMessage(),
                $opt->bucket ?? '',
                $opt->region ?? '',
                $cosKey ?: $relPath,
                $uploadfile ?? ''
            ));
            // COS上传失败，回退到默认本地修改
            return self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
        }

        if (!isset($file['size'])) {
            try {
                if ($cosClient) {
                    $fileInfo = $cosClient->headObject(array('Bucket' => $opt->bucket, 'Key' => $cosKey))->toArray();
                    $file['size'] = $fileInfo['ContentLength'] ?? 0;
                }
            } catch (\Throwable $e) {
                self::debugLog('headObject failed: ' . $e->getMessage());
            }
            // 兜底：通过临时文件获取size
            if (!isset($file['size']) && file_exists($uploadfile)) {
                $file['size'] = filesize($uploadfile);
            }
            // 最终兜底：如果还是拿不到，置为0避免未定义错误
            if (!isset($file['size'])) {
                $file['size'] = 0;
            }
        }

        // 本地保存一份（使用绝对路径）
        if (($opt->local ?? 'close') === 'open') {
            $rootDir = self::getUploadRootDir();
            $absPath = $rootDir . $relPath;
            $dir = dirname($absPath);
            if (!is_dir($dir)) {
                self::makeUploadDir($dir);
            }
            @unlink($absPath);
            if (isset($file['tmp_name'])) {
                @move_uploaded_file($uploadfile, $absPath);
            } elseif (isset($file['bytes']) || isset($file['bits'])) {
                $contents = $file['bytes'] ?? $file['bits'];
                @file_put_contents($absPath, $contents);
            }
        }

        // 返回相对存储路径
        return array(
            'name' => $content['attachment']->name,
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        );
    }

    /**
     * Typecho默认的本地修改逻辑（作为插件回退分支）
     * 当COS未配置或上传失败时调用，保证文件修改功能始终可用
     *
     * @param array $content
     * @param array $file
     * @param string $ext
     * @param mixed $uploadfile
     * @param string $relPath
     * @return array|false
     */
    private static function defaultModifyHandle(array $content, array $file, string $ext, $uploadfile, string $relPath)
    {
        if ($content['attachment']->type != $ext) {
            return false;
        }

        $rootDir = self::getUploadRootDir();
        $absPath = $rootDir . $relPath;
        $dir = dirname($absPath);

        if (!is_dir($dir)) {
            if (!self::makeUploadDir($dir)) {
                return false;
            }
        }

        if (isset($file['tmp_name'])) {
            // 安全：先写临时文件再 rename，避免 unlink 后 move_uploaded_file 失败造成旧文件丢失
            $tmp = $absPath . '.tmp_' . bin2hex(random_bytes(4));
            if (!@move_uploaded_file($uploadfile, $tmp)) {
                @unlink($tmp);
                return false;
            }
            @unlink($absPath);
            if (!@rename($tmp, $absPath)) {
                // rename 失败：保留临时文件，避免数据彻底丢失，但本次操作视为失败
                self::debugLog('defaultModifyHandle rename failed: ' . $tmp . ' -> ' . $absPath);
                return false;
            }
        } elseif (isset($file['bytes'])) {
            // 同样先写临时文件，保证旧文件在写入成功前不被破坏
            $tmp = $absPath . '.tmp_' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $file['bytes']) === false) {
                @unlink($tmp);
                return false;
            }
            @unlink($absPath);
            if (!@rename($tmp, $absPath)) {
                self::debugLog('defaultModifyHandle rename failed: ' . $tmp . ' -> ' . $absPath);
                return false;
            }
        } elseif (isset($file['bits'])) {
            $tmp = $absPath . '.tmp_' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $file['bits']) === false) {
                @unlink($tmp);
                return false;
            }
            @unlink($absPath);
            if (!@rename($tmp, $absPath)) {
                self::debugLog('defaultModifyHandle rename failed: ' . $tmp . ' -> ' . $absPath);
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            $file['size'] = filesize($absPath);
        }

        return [
            'name' => $content['attachment']->name,
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }

    /**
     * 删除文件
     *
     * 关键修复：
     * 1. 本地同步删除路径加上 __TYPECHO_ROOT_DIR__ 前缀
     * 2. COS删除失败时不返回false（数据库记录已删除，避免让核心认为删除失败）
     *    改为记录日志并返回true，本地删除成功即算整体成功
     * 3. 回退分支路径前缀统一（attachment->path现在已经带/开头，避免//重复）
     *
     * @param array $content
     * @return bool
     */
    public static function deleteHandle(array $content): bool
    {
        $relPath = $content['attachment']->path;
        $rootDir = self::getUploadRootDir();
        $absPath = $rootDir . $relPath;

        // 获取设置参数
        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            // 插件未配置，回退到本地删除
            return @unlink($absPath);
        }

        $localDeleted = true;
        $localSyncEnabled = ($opt->local ?? 'close') === 'open' && ($opt->local_sync ?? 'close') === 'open';
        // 删除本地备份文件（使用绝对路径）
        if ($localSyncEnabled) {
            $localDeleted = @unlink($absPath);
        }

        $cosDeleted = true;
        // 开启同步删除COS文件则执行
        if (($opt->remote_sync ?? 'close') === 'open') {
            try {
                $cosClient = self::CosInit();
                $cosClient->deleteObject(array(
                    'Bucket' => $opt->bucket,
                    'Key' => ltrim($relPath, '/')
                ));
            } catch (\Throwable $e) {
                self::debugLog('COS deleteObject failed (non-fatal): ' . $e->getMessage());
                // 注意：此处不返回false，因为数据库记录已经被核心删除。
                // COS文件残留可以让用户手动清理，而返回false会误导核心认为整个删除流程失败。
                $cosDeleted = false;
            }
        }

        // Bug 11：COS 删除失败时显式记录"需手动清理"提示，便于管理员排查残留对象
        if (!$cosDeleted) {
            self::debugLog(sprintf(
                '[deleteHandle] COS 对象残留需手动清理：bucket=%s key=%s（数据库记录已删除，不影响站点显示）',
                $opt->bucket ?? '',
                ltrim($relPath, '/')
            ));
        }

        // 语义简化：
        //   - 本地同步删除开启：本地删除成功即视为整体成功（COS 残留由日志提示）
        //   - 本地同步删除未开启：无需本地操作，直接成功
        if ($localSyncEnabled && !$localDeleted) {
            return false;
        }
        return true;
    }

    /**
     * 获取对象访问Url
     * 注意：Typecho 1.3.0 起 attachmentHandle 直接传入 Config 对象，而非 array
     *
     * @param Config $attachment
     * @return string
     */
    public static function attachmentHandle(Config $attachment): string
    {
        $fallbackUrl = self::makeLocalAttachmentUrl($attachment->path ?? '');
        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return $fallbackUrl;
        }
        try {
            return self::buildObjectUrl($opt, $attachment->path ?? '', 'attachmentHandle');
        } catch (\Throwable $e) {
            self::debugLog('[attachmentHandle] failed, fallback: ' . $e->getMessage());
            return $fallbackUrl;
        }
    }

    /**
     * 获取对象访问Url（兼容旧调用形式，仍以 array 形式传入）
     *
     * @param array $content
     * @return string
     */
    public static function attachmentDataHandle(array $content): string
    {
        $path = $content['attachment']->path ?? '';
        $fallbackUrl = self::makeLocalAttachmentUrl($path);
        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return $fallbackUrl;
        }
        try {
            return self::buildObjectUrl($opt, $path, 'attachmentDataHandle');
        } catch (\Throwable $e) {
            self::debugLog('[attachmentDataHandle] failed, fallback: ' . $e->getMessage());
            return $fallbackUrl;
        }
    }

    /**
     * 构造对象访问 URL（attachmentHandle / attachmentDataHandle 共用逻辑）
     *
     * 始终生成无签名的永久访问 URL：
     *   - 配置了自定义域名 -> https://{domain}/{key}
     *   - 未配置自定义域名 -> SDK 生成默认 COS 域名无签名 URL
     *
     * @param object $opt   插件配置（必非空）
     * @param string $path  对象路径（可能带 / 前缀）
     * @param string $ctx   调用上下文，仅用于日志前缀
     * @return string       保证返回长度 >=12 的 HTTP URL（失败时抛出，调用方 fallback）
     */
    private static function buildObjectUrl($opt, string $path, string $ctx = ''): string
    {
        $cosKey = ltrim($path, '/');
        if ($cosKey === '') {
            throw new \RuntimeException('empty path');
        }

        $defaultCosDomain = $opt->bucket . '.cos.' . $opt->region . '.myqcloud.com';

        // 后台管理面板：直接拼接域名 URL（避免 SDK 初始化产生输出损坏响应）
        $adminDir = defined('__TYPECHO_ADMIN_DIR__') ? __TYPECHO_ADMIN_DIR__ : '/admin/';
        $isAdmin = isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], $adminDir) !== false;
        if ($isAdmin) {
            if (!empty($opt->domain)) {
                return 'https://' . self::normalizeDomain($opt->domain) . '/' . $cosKey;
            }
            return 'https://' . $defaultCosDomain . '/' . $cosKey;
        }

        // 无签名模式：有自定义域名用自定义域名，否则用默认 COS 域名
        if (!empty($opt->domain)) {
            $url = 'https://' . self::normalizeDomain($opt->domain) . '/' . $cosKey;
            self::debugLog('[' . $ctx . '] custom domain URL: ' . $url);
            return $url;
        }
        $cosClient = self::CosInit();
        return $cosClient->getObjectUrlWithoutSign($opt->bucket, $cosKey);
    }

    /**
     * 构造本地附件 URL（作为插件未配置或生成失败时的 fallback）
     *
     * @param string $path  附件相对路径，如 /usr/uploads/2026/08/xxx.jpg
     * @return string       本地 HTTP URL，保底至少 12 字符（含协议前缀 https:// 或 http://）
     */
    private static function makeLocalAttachmentUrl(string $path): string
    {
        try {
            $options = Options::alloc();
            $baseUrl = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : ($options->siteUrl ?? '');
            $url = Common::url($path, $baseUrl);
        } catch (\Throwable $e) {
            $url = '';
        }
        // 保底：保证返回合法 HTTP URL，避免调用方拿到空字符串或非 URL
        if (!is_string($url) || strlen($url) < 12 || stripos($url, 'http') !== 0) {
            // S4：HTTP_HOST 是客户端可控的 Host 头，必须做主机名字符白名单过滤，
            // 防止通过构造畸形 Host 头注入路径分隔符或特殊字符到最终 URL
            if (function_exists('getallheaders') && isset($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                // 只允许 域名/IPv4/IPv6 + 可选端口，阻断注入
                if (preg_match('/^\[?[a-zA-Z0-9.\-:]+\]?(:[0-9]+)?$/', $_SERVER['HTTP_HOST'])) {
                    $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($path, '/');
                } else {
                    $url = 'https://localhost/' . ltrim($path, '/');
                }
            } else {
                $url = 'https://localhost/' . ltrim($path, '/');
            }
        }
        return $url;
    }

    /**
     * COS初始化
     *
     * 始终使用 region 模式构造 Client，即使配置了自定义 domain。
     *
     * 为什么不能用 domain 模式：
     *   domain 模式会让所有 API 请求（upload/delete/headBucket 等）都通过
     *   自定义域名发送。但如果该域名挂的是 CDN，CDN 不支持 API 管理操作
     *   (PUT/DELETE/HEAD)，会直接返回 403 Forbidden（响应头无 x-cos-request-id，
     *   server: SLT）。
     *
     * 正确做法：
     *   - API 操作（upload/modify/delete/headBucket 等）：用 region 模式
     *   - 生成访问 URL：在 attachmentHandle 中手动拼接自定义域名
     *
     * @param Config|object|string|null $options 设置信息
     * @return \Qcloud\Cos\Client
     */
    public static function CosInit($options = null)
    {
        if (!$options) {
            $options = Plugin::getOptions();
            if (!$options) {
                throw new \RuntimeException('插件未配置，请先在后台填写配置信息');
            }
        }

        // 凭证校验：在创建客户端之前检查 SecretId 和 SecretKey 是否为空
        // 避免传入空凭证导致 COS SDK 在构造函数中抛出 "Secret is empty" 异常
        if (empty($options->secid) || empty($options->sekey)) {
            throw new \RuntimeException('SecretId 和 SecretKey 不能为空，请检查插件配置');
        }
        if (empty($options->region)) {
            throw new \RuntimeException('地域 region 不能为空，请在插件配置中正确填写');
        }

        // 单例缓存：secid + region + bucket 组合作为 key，避免反复构造 Client
        $cacheKey = md5($options->secid . '|' . ($options->region ?? '') . '|' . ($options->bucket ?? ''));
        if (Plugin::$cachedCosSingletonClient !== null && Plugin::$cachedCosSingletonKey === $cacheKey) {
            return Plugin::$cachedCosSingletonClient;
        }

        // 加载 cos-php-sdk-v5 phar（幂等）
        self::loadSdk();

        $credentials = array(
            'secretId' => $options->secid,
            'secretKey' => $options->sekey
        );

        // 始终使用 region 模式（API 操作必须走 COS 服务域名，否则会被 CDN 拦截）
        // 附带请求超时：防止保存配置时网络不通 -> PHP-FPM 卡住 -> 浏览器显示 500
        // 注意：cos-php-sdk-v5 的 Client 构造函数只透传 timeout 给底层 Guzzle，
        // 并不透传 connect_timeout（已核实 tencentyun/cos-php-sdk-v5 源码），
        // 因此移除 connect_timeout 死代码，仅保留有效的 timeout。
        $client = new \Qcloud\Cos\Client(array(
            'region'      => $options->region,
            'schema'       => 'https',
            'credentials'  => $credentials,
            'userAgent'    => Plugin::USER_AGENT,
            'timeout'      => (float)($options->timeout ?? 5.0),   // 请求总超时（秒）
        ));

        Plugin::$cachedCosSingletonClient = $client;
        Plugin::$cachedCosSingletonKey = $cacheKey;
        return $client;
    }

    /**
     * 规范化自定义域名字符串：去除 http(s):// 前缀、末尾斜杠、首尾空白
     * 用于拼接访问 URL
     *
     * @param string $domain
     * @return string
     */
    private static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if (stripos($domain, 'https://') === 0) {
            $domain = substr($domain, 8);
        } elseif (stripos($domain, 'http://') === 0) {
            $domain = substr($domain, 7);
        }
        $domain = rtrim($domain, '/');
        return $domain;
    }

    /**
     * 加载 COS SDK phar（幂等）
     * 使用 include_once + file_exists 先校验，避免 phar 丢失/权限不足时
     * require_once 抛 Fatal Error(E_COMPILE_ERROR) 直接 500、无法被 Throwable 捕获
     *
     * @throws \RuntimeException 当 phar 文件缺失或 autoload 加载失败时抛出（可被上层捕获）
     */
    private static function loadSdk(): void
    {
        static $loaded = false;
        static $lastErr = null;
        if ($loaded) {
            return;
        }
        if ($lastErr !== null) {
            // 同请求内二次调用直接复用上一次的错误，避免重复 include_once
            throw new \RuntimeException($lastErr);
        }

        $pharPath = __DIR__ . '/phar/cos-sdk-v5-7.phar';
        $autoload = 'phar://' . $pharPath . '/vendor/autoload.php';
        if (!file_exists($pharPath)) {
            $lastErr = 'COS SDK phar 文件不存在: ' . $pharPath;
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        // 检查 phar 扩展
        if (!extension_loaded('phar')) {
            $lastErr = 'PHP 未启用 phar 扩展，无法加载 COS SDK';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        // include_once 失败只产生 Warning（而非 Fatal），靠返回值 + class_exists 双重确认
        $includeError = null;
        set_error_handler(static function ($severity, $message, $file, $line) use (&$includeError) {
            $includeError = $message;
        });
        $includeOk = include_once $autoload;
        restore_error_handler();
        if ($includeOk === false || $includeError !== null) {
            $lastErr = 'COS SDK autoload 加载失败: ' . ($includeError ?: 'include_once returned false');
            self::debugLog('[loadSdk] ' . $lastErr . '; path=' . $autoload, true);
            throw new \RuntimeException($lastErr);
        }
        if (!class_exists('\\Qcloud\\Cos\\Client')) {
            $lastErr = 'COS SDK 已加载但类 Qcloud\\Cos\\Client 不存在，可能是 phar 文件损坏';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        $loaded = true;
    }

    /**
     * 统一日志输出（脱敏 + 级别控制）
     * 默认仅在 TYPECHO_COS_DEBUG=1 时输出到 error_log；始终通过该入口集中管理
     *
     * @param string $msg
     * @param bool   $force 强制输出（用于 configHandle 保存失败等关键链路，不受 TYPECHO_COS_DEBUG 开关控制）
     * @return void
     */
    public static function debugLog(string $msg, bool $force = false): void
    {
        static $forceInit = null;
        if ($forceInit === null) {
            $forceInit = (defined('TYPECHO_COS_DEBUG') && TYPECHO_COS_DEBUG);
        }
        if (!$force && !$forceInit) {
            return;
        }
        if (!function_exists('error_log')) {
            return;
        }
        // 关键字段脱敏：覆盖 COS 原生签名的 q-ak（SecretId）、q-signature、q-key-time、q-sign-token
        // 以及通用敏感参数 key/sign/secret/token/signature/ak/sk 等。
        // 同时兼容 URL 编码（%3D / %26）与未编码（= / &）两种形式，避免 q-ak 明文入日志。
        $safe = preg_replace_callback(
            '/(q-ak|q-signature|q-key-time|q-sign-token|q-sign-algorithm|q-header-list|q-url-param-list|secretid|secretkey|accesskey|secret|token|signature|secretid|secretkey|ak|sk|key|sign)([^&"\']{0,6})(=|%3D)([^&"\' ]+)/i',
            function ($m) {
                $v = $m[4];
                $mask = (strlen($v) <= 4) ? '***' : (substr($v, 0, 2) . '***' . substr($v, -1));
                return $m[1] . $m[2] . $m[3] . $mask;
            },
            $msg
        );
        error_log('[TypechoCosPlugin] ' . $safe);
    }

    /**
     * 判断存储桶是否存在（三态语义）
     *
     * 注意：HeadBucket 是 service-level API，必须使用 region 模式访问。
     * 当用户配置了自定义 domain 时，该域名可能尚未绑定到存储桶，
     * 直接使用 domain 模式调用 HeadBucket 会失败导致误判。
     * 因此这里强制以 region 模式进行桶存在性校验。
     *
     * 返回值三态（Bug 14 修复）：
     *   - true  ：桶确认存在
     *   - false ：SDK 明确返回不存在
     *   - null  ：无法判定（凭证错误 / 网络超时 / SDK 异常 / CosInit 失败）
     * 调用方 configHandle 据此给出更精准的提示，避免把"网络不通"
     * 误判为"桶不存在"。
     *
     * @param object $opt
     * @return bool|null
     */
    public static function doesBucketExist($opt): ?bool
    {
        // 构造一份仅用于校验的配置对象，强制使用 region 模式（忽略 domain）
        // 避免自定义域名未绑定时 HeadBucket 失败导致保存配置被阻塞
        $checkOpt = (object) array(
            'secid' => $opt->secid ?? '',
            'sekey' => $opt->sekey ?? '',
            'region' => $opt->region ?? '',
            'bucket' => $opt->bucket ?? '',
            'domain' => '',
        );

        try {
            $cosClient = self::CosInit($checkOpt);
        } catch (\Throwable $e) {
            // 客户端初始化失败（如 phar 损坏）→ 无法判定
            self::debugLog('doesBucketExist CosInit failed: ' . $e->getMessage());
            return null;
        }
        try {
            $result = $cosClient->doesBucketExist($opt->bucket);
            if (!$result) {
                return false;
            }
        } catch (\Throwable $e) {
            // 任何 SDK 异常（凭证错/网络超时/权限不足）都视为"无法判定"
            // 不再把它们和"桶不存在"混为一谈
            self::debugLog('doesBucketExist SDK call failed (inconclusive): cls=' . get_class($e) . ' msg=' . $e->getMessage());
            return null;
        }
        return true;
    }

    /**
     * 判断对象是否已存在
     *
     * @param string $key 可能带前置/的路径（如 /usr/uploads/2026/08/xxx.jpg）
     * @return bool
     */
    public static function doesObjectExist(string $key): bool
    {
        // 获取设置参数
        $opt = Plugin::getOptions();
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return false;
        }
        // COS Key 约定不带前置 /
        $cosKey = ltrim($key, '/');
        if ($cosKey === '') {
            return false;
        }
        try {
            $cosClient = self::CosInit();
            $result = $cosClient->doesObjectExist($opt->bucket, $cosKey);
            if ($result) {
                return true;
            }
        } catch (\Throwable $e) {
            // COS 网络异常时直接返回 false，让上传继续（即使真实已存在，覆盖仍是安全的）
            self::debugLog('doesObjectExist exception (treat as not-exist): ' . $e->getMessage());
            return false;
        }
        return false;
    }

    /**
     * 创建上传路径（递归创建多级目录，mkdir + recursive=true 一行解决）
     *
     * @param string $path
     * @return bool
     */
    private static function makeUploadDir(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (is_dir($path)) {
            return true;
        }
        return @mkdir($path, 0755, true);
    }

    /**
     * 获取本地文件存储根目录（绝对路径，不带末尾 /）
     *
     * 统一封装 uploadHandle / modifyHandle / deleteHandle 的本地路径前缀取值，
     * 避免 Bug 7 那样的不一致：uploadHandle 之前用 __TYPECHO_ROOT_DIR__，
     * 而 modifyHandle / deleteHandle 用 __TYPECHO_UPLOAD_ROOT_DIR__ ?? __TYPECHO_ROOT_DIR__，
     * 当用户在 config.inc.php 定义了 __TYPECHO_UPLOAD_ROOT_DIR__ 时，会导致上传到本地
     * 备份的文件在修改/删除时找不到，反过来亦然。
     *
     * @return string
     */
    private static function getUploadRootDir(): string
    {
        return defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
    }

    /**
     * 获取文件上传目录
     *
     * 统一返回格式：以 / 开头，结尾不带 /（与 Typecho 核心 Upload::UPLOAD_DIR 保持一致）
     * 例如：/usr/uploads
     *
     * 注意：用户配置面板中默认值填写的是 "usr/uploads"（无前置/），此处会自动规范化。
     * COS 对象 Key 不需要前置 /，调用处按需使用 ltrim($path, '/')。
     *
     * @return string
     */
    private static function getUploadDir(): string
    {
        $dir = '';
        $opt = Plugin::getOptions();
        if ($opt && !empty($opt->path)) {
            $dir = $opt->path;
        } else if (defined('__TYPECHO_UPLOAD_DIR__')) {
            $dir = __TYPECHO_UPLOAD_DIR__;
        } else {
            $dir = Plugin::UPLOAD_DIR;
        }

        // 规范化：保证以 / 开头，去除尾部 /
        $dir = '/' . ltrim($dir, '/');
        $dir = rtrim($dir, '/');
        // 安全兜底：剥离任何 ../ 或 ./ 片段，防止路径穿越
        // （即使 config 校验被绕过，或来自老配置、外部注入的 path 也保险）
        $dir = preg_replace('#(?:^|/)\.\.?(?:/|$)#', '/', $dir);
        // 合并多余斜杠，避免出现 // 造成对象 key 形如 //usr/uploads
        $dir = preg_replace('#/+#', '/', $dir);
        $dir = rtrim($dir, '/');

        return $dir === '' ? '/' : $dir;
    }

    /**
     * 根据配置的目录结构模板生成日期子目录
     *
    /**
     * 根据配置的目录结构模板生成上传子目录
     *
     * 支持变量：
     *   {year}  4位年，如 2026
     *   {month} 2位月，如 08
     *   {day}   2位日，如 19
     *   {type}  媒体类型分类，如 images / videos / audios / documents / code / archives / fonts / other
     *   {ext}   文件扩展名（小写），如 jpg / mp4 / pdf
     *
     * 配置项 dir_structure 示例：
     *   {year}/{month}           → 2026/08（默认，兼容原有行为）
     *   {type}/{year}/{month}    → images/2026/08
     *   {type}                   → images
     *   {ext}/{year}             → jpg/2026
     *   {year}/{type}            → 2026/images
     *   （空字符串）              → 不建子目录
     *
     * 返回值不带前置/和末尾/，调用处自行拼接。
     * 已上传的旧文件不受影响，仅对新上传文件生效。
     *
     * @param string $ext 文件扩展名（小写），用于 {type} 和 {ext} 变量
     * @return string 如 "images/2026/08"，空模板返回 ""
     */
    private static function getUploadSubDir(string $ext = ''): string
    {
        $opt = Plugin::getOptions();
        $template = $opt->dir_structure ?? '{year}/{month}';
        $template = trim((string)$template, '/');
        if ($template === '') {
            return '';
        }

        $date = new Date();
        $ext = strtolower($ext);
        $replace = [
            '{year}'  => $date->year,
            '{month}' => $date->month,
            '{day}'   => $date->day,
            '{type}'  => self::getMediaType($ext),
            '{ext}'   => $ext !== '' ? $ext : 'other',
        ];
        $subDir = str_replace(array_keys($replace), array_values($replace), $template);

        // 安全兜底：剥离 ../ 或 ./ 片段，合并多余斜杠
        $subDir = preg_replace('#(?:^|/)\.\.?(?:/|$)#', '/', $subDir);
        $subDir = preg_replace('#/+#', '/', $subDir);
        $subDir = trim($subDir, '/');

        return $subDir;
    }

    /**
     * 根据文件扩展名返回媒体类型分类
     *
     * 分类：images / videos / audios / documents / code / archives / fonts / other
     *
     * @param string $ext 小写扩展名
     * @return string
     */
    private static function getMediaType(string $ext): string
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'images'    => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif', 'avif', 'heic', 'heif', 'raw', 'psd', 'ai'],
                'videos'    => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp', 'rmvb'],
                'audios'    => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'ape', 'opus', 'mid', 'midi'],
                'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'odt', 'ods', 'odp', 'csv', 'epub', 'mobi'],
                'code'      => ['js', 'css', 'html', 'htm', 'php', 'json', 'xml', 'yaml', 'yml', 'sql', 'sh', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'ts', 'vue', 'jsx', 'tsx', 'go', 'rs', 'rb', 'pl', 'lua', 'swift', 'kt', 'scala', 'r', 'm', 'mm'],
                'archives'  => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'z', 'tgz', 'tbz2'],
                'fonts'     => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
            ];
        }
        foreach ($map as $type => $exts) {
            if (in_array($ext, $exts, true)) {
                return $type;
            }
        }
        return 'other';
    }

    /**
     * 获取上传文件信息
     *
     * @param array $file 上传的文件
     * @return mixed
     */
    private static function getUploadFile(array $file)
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? null));
    }

    /**
     * 清洗文件名：去除危险字符（"、<、>），把反斜杠统一成正斜杠，返回 basename
     *
     * 旧 getSafeName 通过引用同时修改入参 + 返回扩展名，语义混乱；
     * 拆分为 sanitizeFileName（清洗）与 getExtension（取扩展名）两个职责清晰的方法。
     * basename 提取避免通过文件名注入路径段（如 ../../etc）。
     *
     * @param string $name 原始文件名
     * @return string 清洗后的 basename
     */
    private static function sanitizeFileName(string $name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        return basename($name);
    }

    /**
     * 获取文件小写扩展名
     *
     * 点文件（如 .htaccess / .gitignore）视为无扩展名，避免被旧 getSafeName
     * 的 'a' 前缀诡计错误提取出 'htaccess' 作为扩展名，进而可能通过 checkFileType
     * 校验（S5 安全风险）。
     *
     * @param string $name 文件名（建议先经 sanitizeFileName 处理）
     * @return string 小写扩展名；无扩展名或点文件返回 ''
     */
    private static function getExtension(string $name): string
    {
        $base = basename($name);
        // 点文件视为无扩展名
        if ($base !== '' && $base[0] === '.') {
            return '';
        }
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        return $ext !== '' ? strtolower($ext) : '';
    }
}
