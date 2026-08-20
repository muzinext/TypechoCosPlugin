<?php
namespace TypechoPlugin\TypechoCosPlugin;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 图片处理工具类
 *
 * 集中管理图片相关功能：媒体类型分类、WebP 自动转换、文件名清洗等。
 * 独立成类便于后续扩展（如图片压缩、尺寸裁剪、水印等）。
 *
 * @package TypechoCosPlugin
 */
class Image
{
    /** @var array|null ext => type 关联数组缓存（O(1) 查找） */
    private static ?array $extTypeMap = null;

    // ==================== 媒体类型分类 ====================

    /**
     * 根据文件扩展名返回媒体类型分类（O(1) 关联数组查找）
     *
     * 分类：images / videos / audios / documents / code / archives / fonts / other
     *
     * @param string $ext 小写扩展名
     * @return string
     */
    public static function getMediaType(string $ext): string
    {
        if (self::$extTypeMap === null) {
            self::$extTypeMap = [];
            $categories = [
                'images'    => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif', 'avif', 'heic', 'heif', 'raw', 'psd', 'ai'],
                'videos'    => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', 'mpg', 'mpeg', '3gp', 'rmvb'],
                'audios'    => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma', 'ape', 'opus', 'mid', 'midi'],
                'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf', 'odt', 'ods', 'odp', 'csv', 'epub', 'mobi'],
                'code'      => ['js', 'css', 'html', 'htm', 'php', 'json', 'xml', 'yaml', 'yml', 'sql', 'sh', 'py', 'java', 'c', 'cpp', 'h', 'hpp', 'ts', 'vue', 'jsx', 'tsx', 'go', 'rs', 'rb', 'pl', 'lua', 'swift', 'kt', 'scala', 'r', 'm', 'mm'],
                'archives'  => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'z', 'tgz', 'tbz2'],
                'fonts'     => ['ttf', 'otf', 'woff', 'woff2', 'eot'],
            ];
            foreach ($categories as $type => $exts) {
                foreach ($exts as $e) {
                    self::$extTypeMap[$e] = $type;
                }
            }
        }
        return self::$extTypeMap[$ext] ?? 'other';
    }

    // ==================== WebP 自动转换 ====================

    /**
     * 检查当前 PHP 环境是否支持 WebP 转换
     * 优先使用 GD 库的 imagewebp()，后备使用 cwebp 命令行工具
     *
     * @return bool
     */
    public static function isWebpSupported(): bool
    {
        // 方式一：GD 原生 WebP 支持
        if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
            if (function_exists('gd_info')) {
                $gd = gd_info();
                if (!empty($gd['WebP Support'])) {
                    return true;
                }
            }
        }
        // 方式二：cwebp 命令行工具（GD 未编译 WebP 时的后备方案）
        return self::isCwebpAvailable();
    }

    /**
     * 检测 cwebp 命令行工具是否可用
     *
     * @return bool
     */
    public static function isCwebpAvailable(): bool
    {
        return self::getCwebpPath() !== null;
    }

    /**
     * 获取 cwebp 可执行文件完整路径（缓存）
     *
     * 先检查常见固定路径，再通过 command -v / which 探测，
     * 返回完整路径供 exec 使用，避免依赖 PATH 环境变量。
     *
     * @return string|null 完整路径，不可用时返回 null
     */
    public static function getCwebpPath(): ?string
    {
        static $cachedPath = null;
        static $checked = false;
        if ($checked) {
            return $cachedPath;
        }
        $checked = true;

        if (!function_exists('exec') || !function_exists('escapeshellarg')) {
            return null;
        }
        // 检查常见固定路径
        $paths = ['/usr/bin/cwebp', '/usr/local/bin/cwebp', '/opt/homebrew/bin/cwebp'];
        foreach ($paths as $p) {
            if (file_exists($p) && is_executable($p)) {
                $cachedPath = $p;
                return $cachedPath;
            }
        }
        // 通过 command -v / which 探测
        $output = [];
        $retCode = 0;
        @exec('command -v cwebp 2>/dev/null || which cwebp 2>/dev/null', $output, $retCode);
        if ($retCode === 0 && !empty($output)) {
            $found = trim($output[0]);
            if ($found !== '' && file_exists($found) && is_executable($found)) {
                $cachedPath = $found;
                return $cachedPath;
            }
        }
        return null;
    }

    /**
     * 判断指定扩展名的图片是否需要转换为 WebP
     *
     * @param string $ext 小写扩展名
     * @param object $opt 插件配置
     * @return bool
     */
    public static function isConvertibleImage(string $ext, $opt): bool
    {
        // 兼容 Checkbox 数组值和旧版 Select 字符串值
        $webpVal = $opt->webp_enable ?? 'close';
        $webpEnabled = is_array($webpVal) ? in_array('open', $webpVal, true) : ($webpVal === 'open');
        if (!$webpEnabled) {
            return false;
        }
        if (!self::isWebpSupported()) {
            return false;
        }
        if ($ext === '' || $ext === 'webp') {
            return false;
        }
        $formats = trim((string)($opt->webp_formats ?? 'jpg,jpeg,png'));
        if ($formats === '') {
            return false;
        }
        $list = array_map('trim', explode(',', strtolower($formats)));
        return in_array($ext, $list, true);
    }

    /**
     * 根据原图路径推导 WebP 文件路径（同目录同文件名，扩展名改为 .webp）
     *
     * @param string $path 原图相对路径，如 /usr/uploads/2026/08/abc.jpg
     * @return string WebP 路径，如 /usr/uploads/2026/08/abc.webp
     */
    public static function getWebpPath(string $path): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] === '.' ? '' : $info['dirname'];
        $name = $info['filename'] ?? '';
        return ($dir !== '' ? $dir . '/' : '') . $name . '.webp';
    }

    /**
     * 将图片转换为 WebP 格式
     * 优先使用 GD 库，GD 不支持 WebP 时自动回退到 cwebp 命令行工具
     *
     * @param string $srcPath 源图片本地绝对路径
     * @param string $destPath 目标 WebP 本地绝对路径
     * @param int $quality 质量 0-100
     * @param int $maxPixels 最大像素数（超过则跳过，避免内存溢出，默认 5000 万像素）
     * @return bool 转换成功返回 true
     */
    public static function convertToWebp(string $srcPath, string $destPath, int $quality, int $maxPixels = 50000000): bool
    {
        if (!file_exists($srcPath)) {
            return false;
        }

        $info = @getimagesize($srcPath);
        if ($info === false) {
            Logger::log('[WebP] getimagesize failed, not a valid image: ' . $srcPath);
            return false;
        }
        $mime = $info['mime'] ?? '';

        // 超大图片跳过，避免 GD 加载时内存溢出
        if (($info[0] ?? 0) * ($info[1] ?? 0) > $maxPixels) {
            Logger::log('[WebP] skip oversized image: ' . ($info[0] ?? 0) . 'x' . ($info[1] ?? 0));
            return false;
        }

        // GIF 动图转换会丢失动画，跳过
        if ($mime === 'image/gif') {
            return false;
        }
        // 已经是 WebP，直接复制
        if ($mime === 'image/webp') {
            return @copy($srcPath, $destPath);
        }

        // 方式一：GD 原生转换（需 GD 编译 WebP 支持）
        $gdSupported = function_exists('imagewebp') && function_exists('gd_info') && !empty(gd_info()['WebP Support']);
        if ($gdSupported && in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $img = null;
            if ($mime === 'image/jpeg') {
                $img = @imagecreatefromjpeg($srcPath);
            } else {
                $img = @imagecreatefrompng($srcPath);
                if ($img) {
                    imagepalettetotruecolor($img);
                    imagealphablending($img, true);
                    imagesavealpha($img, true);
                }
            }
            if ($img) {
                $result = @imagewebp($img, $destPath, $quality);
                imagedestroy($img);
                if ($result && file_exists($destPath) && filesize($destPath) > 0) {
                    return true;
                }
                Logger::log('[WebP] GD imagewebp() failed, falling back to cwebp. src=' . $srcPath);
                @unlink($destPath);
            } else {
                Logger::log('[WebP] GD imagecreatefrom* failed, falling back to cwebp. src=' . $srcPath . ' mime=' . $mime);
            }
        }

        // 方式二：cwebp 命令行工具（GD 不支持 WebP 时的后备方案）
        if (self::isCwebpAvailable()) {
            return self::convertToWebpViaCwebp($srcPath, $destPath, $quality, $mime);
        }

        Logger::log('[WebP] No WebP conversion method available (GD WebP=false, cwebp not found). src=' . $srcPath);
        return false;
    }

    /**
     * 使用 cwebp 命令行工具转换图片为 WebP
     *
     * @param string $srcPath 源图片路径
     * @param string $destPath 目标 WebP 路径
     * @param int $quality 质量 0-100
     * @param string $mime MIME 类型
     * @return bool
     */
    private static function convertToWebpViaCwebp(string $srcPath, string $destPath, int $quality, string $mime): bool
    {
        $cwebp = self::getCwebpPath();
        if ($cwebp === null) {
            Logger::log('[WebP] cwebp not available');
            return false;
        }
        // cwebp 支持的输入格式：JPEG, PNG, TIFF, WebP
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'], true)) {
            Logger::log('[WebP] cwebp does not support mime type: ' . $mime);
            return false;
        }
        $quality = max(0, min(100, $quality));
        $cmd = escapeshellarg($cwebp) . ' -q ' . escapeshellarg((string)$quality)
            . ' ' . escapeshellarg($srcPath)
            . ' -o ' . escapeshellarg($destPath)
            . ' 2>&1';
        $output = [];
        $retCode = 0;
        @exec($cmd, $output, $retCode);
        if ($retCode === 0 && file_exists($destPath) && filesize($destPath) > 0) {
            return true;
        }
        Logger::log('[WebP] cwebp failed (code=' . $retCode . '): ' . implode(' ', array_slice($output, -3)));
        @unlink($destPath);
        return false;
    }

    /**
     * 获取上传源文件的本地绝对路径
     * 对于 tmp_name 直接返回；对于 bytes/bits 写入临时文件后返回
     *
     * @param array $file 上传文件信息
     * @param mixed $uploadfile getUploadFile() 的返回值
     * @return string|null 本地路径，无法获取时返回 null
     */
    public static function getSourceFilePath(array $file, $uploadfile): ?string
    {
        if (isset($file['tmp_name']) && is_string($file['tmp_name']) && file_exists($file['tmp_name'])) {
            return $file['tmp_name'];
        }
        // bytes / bits 是二进制内容，写入临时文件
        if (is_string($uploadfile) && $uploadfile !== '') {
            $tmp = tempnam(sys_get_temp_dir(), 'cos_src_');
            if ($tmp !== false && @file_put_contents($tmp, $uploadfile) !== false) {
                return $tmp;
            }
        }
        return null;
    }

    /**
     * 生成唯一临时文件路径（不创建空文件，避免 tempnam 残留）
     *
     * 使用 random_bytes 生成唯一文件名，直接拼接路径，不创建文件。
     * 修复 tempnam() 创建空文件后追加扩展名导致原文件残留的问题。
     *
     * @param string $prefix 文件名前缀
     * @param string $ext 扩展名（不含点）
     * @return string
     */
    public static function tempPath(string $prefix = 'cos_', string $ext = 'tmp'): string
    {
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rand = bin2hex(uniqid((string)mt_rand(), true));
        }
        return sys_get_temp_dir() . '/' . $prefix . $rand . '.' . $ext;
    }

    /**
     * 上传 WebP 文件到 COS
     *
     * @param \Qcloud\Cos\Client $cosClient
     * @param object $opt
     * @param string $webpRelPath WebP 相对路径
     * @param string $webpAbsPath WebP 本地绝对路径
     * @return bool
     */
    public static function uploadWebpToCos($cosClient, $opt, string $webpRelPath, string $webpAbsPath): bool
    {
        $cosKey = ltrim($webpRelPath, '/');
        try {
            if (!file_exists($webpAbsPath)) {
                Logger::log('[WebP] uploadWebpToCos: source file not exists: ' . $webpAbsPath, true);
                return false;
            }
            $fileSize = filesize($webpAbsPath);
            if ($fileSize === false || $fileSize === 0) {
                Logger::log('[WebP] uploadWebpToCos: source file empty or invalid: ' . $webpAbsPath . ' size=' . var_export($fileSize, true), true);
                return false;
            }
            $fp = @fopen($webpAbsPath, 'rb');
            if (!$fp) {
                Logger::log('[WebP] uploadWebpToCos: fopen failed: ' . $webpAbsPath, true);
                return false;
            }
            try {
                $uploadFn = function () use ($cosClient, $opt, $cosKey, $fp) {
                    if (is_resource($fp)) {
                        @rewind($fp);
                    }
                    $cosClient->upload(
                        $opt->bucket,
                        $cosKey,
                        $fp,
                        array(
                            'ACL'          => 'public-read',
                            'CacheControl' => 'public, max-age=31536000, immutable',
                            'Expires'      => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
                            'ContentType'  => 'image/webp',
                        )
                    );
                };
                // 重试 1 次（网络瞬时故障）
                try {
                    $uploadFn();
                } catch (\Throwable $firstErr) {
                    Logger::log('[WebP] uploadWebpToCos 第1次失败，重试: ' . $firstErr->getMessage(), true);
                    usleep(200000);
                    $uploadFn();
                }
                Logger::log('[WebP] uploadWebpToCos: SUCCESS key=' . $cosKey . ' size=' . $fileSize);
                return true;
            } finally {
                if (is_resource($fp)) {
                    @fclose($fp);
                }
            }
        } catch (\Throwable $e) {
            Logger::log('[WebP] uploadWebpToCos FAILED: key=' . $cosKey . ' file=' . $webpAbsPath . ' error=' . get_class($e) . ': ' . $e->getMessage(), true);
            return false;
        }
    }

    // ==================== 文件名处理 ====================

    /**
     * 清洗文件名：去除危险字符（"、<、>），把反斜杠统一成正斜杠，返回 basename
     *
     * basename 提取避免通过文件名注入路径段（如 ../../etc）。
     *
     * @param string $name 原始文件名
     * @return string 清洗后的 basename
     */
    public static function sanitizeFileName(string $name): string
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
     * 校验（安全风险）。
     *
     * @param string $name 文件名（建议先经 sanitizeFileName 处理）
     * @return string 小写扩展名；无扩展名或点文件返回 ''
     */
    public static function getExtension(string $name): string
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
