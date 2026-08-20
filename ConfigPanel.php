<?php
namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class ConfigPanel
{
    public static function render(Form $form): void
    {
        $rootUrl = htmlspecialchars(Helper::options()->rootUrl, ENT_QUOTES, 'UTF-8');
        $githubUrl = 'https://github.com/muzinext/TypechoCosPlugin';
        ?>
        <link rel="stylesheet" href="<?php echo $rootUrl . '/usr/plugins/' . Plugin::NAME; ?>/statics/css/joe.config.min.css">
        <script src="<?php echo $rootUrl . '/usr/plugins/' . Plugin::NAME; ?>/statics/js/joe.config.min.js"></script>
        <div class="joe_config">
            <div>
                <div class="joe_config__aside">
                    <div class="logo">腾讯云COS</div>
                    <ul class="tabs">
                        <li class="item active" data-current="joe_notice">使用说明</li>
                        <li class="item" data-current="joe_base">基础设置</li>
                        <li class="item" data-current="joe_advanced">高级设置</li>
                    </ul>
                </div>
            </div>
            <span id="joe_version" style="display: none;" data-version="<?php echo Plugin::PLUGIN_VERSION; ?>"></span>
            <div class="joe_config__notice">
                <p class="title">使用说明</p>
                <ol>
                    <?php if (version_compare(PHP_VERSION, '8.0.0', '<')): ?>
                        <li style="color:red; font-weight:800;">本插件推荐在 PHP 8.0+ 环境下运行（适配 Typecho 1.3.0），低版本可能存在兼容性问题。<br></li>
                    <?php endif; ?>
                    <li>插件基于腾讯云 cos-php-sdk-v5 开发，若发现插件不可用，请到 <a target="_blank" href="<?php echo $githubUrl; ?>">GitHub发布地址</a> 检查是否有更新，或者提交Issues<br></li>
                    <li>插件会验证配置的正确性，如填写错误会报错<br></li>
                    <li>插件会自动替换之前文件的链接，若启用插件前已上传文件，为保证正常显示，请自行将其上传至COS相同路径<br></li>
                    <li>禁用插件会恢复为本地路径，为保证正常显示，请自行将数据从COS下载至相同路径<br></li>
                    <li>重新更改插件中关于COS桶的配置需要先禁用插件，再重新设置<br></li>
                </ol>
            </div>
            <?php

            // ==================== 基础设置 ====================

            $secid = new Text('secid', NULL, '', _t('SecretId(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretId'));
            $secid->setAttribute('class', 'joe_content joe_base');
            $secid->addRule('required', _t('SecretId不能为空！'));

            // 修复：使用 Password 类型，避免 SecretKey 在页面明文显示
            $sekey = new Password('sekey', NULL, '', _t('SecretKey(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretKey（密码框输入，页面不明文显示）'));
            $sekey->setAttribute('class', 'joe_content joe_base');
            $sekey->addRule('required', _t('SecretKey不能为空！'));

            $region = new Select(
                'region',
                array(
                    'ap-beijing' => _t('北京'),
                    'ap-beijing-1' => _t('天津'),
                    'ap-nanjing' => _t('南京'),
                    'ap-shanghai' => _t('上海'),
                    'ap-guangzhou' => _t('广州'),
                    'ap-chengdu' => _t('成都'),
                    'ap-chongqing' => _t('重庆'),
                    'ap-shenzhen-fsi' => _t('深圳金融'),
                    'ap-shanghai-fsi' => _t('上海金融'),
                    'ap-beijing-fsi' => _t('北京金融'),
                    'ap-hongkong' => _t('香港'),
                    'ap-singapore' => _t('新加坡'),
                    'ap-mumbai' => _t('孟买'),
                    'ap-jakarta' => _t('雅加达'),
                    'ap-seoul' => _t('首尔'),
                    'ap-bangkok' => _t('曼谷'),
                    'ap-tokyo' => _t('东京'),
                    'na-toronto' => _t('多伦多'),
                    'na-siliconvalley' => _t('硅谷（美西）'),
                    'na-ashburn' => _t('弗吉尼亚（美东）'),
                    'sa-saopaulo' => _t('圣保罗'),
                    'eu-frankfurt' => _t('法兰克福'),
                    'eu-moscow' => _t('莫斯科'),
                ),
                'ap-beijing-1',
                _t('所属地域(必需)')
            );
            $region->setAttribute('class', 'joe_content joe_base');

            $bucket = new Text('bucket', NULL, '', _t('存储桶名称(必需)'), _t('格式为 BucketName-Appid ，如 typecho-12345678 ,可在 <a target="_blank" href="https://console.cloud.tencent.com/cos/bucket">腾讯云控制台</a> 获取'));
            $bucket->setAttribute('class', 'joe_content joe_base');
            $bucket->addRule('required', _t('存储桶名称不能为空！'));

            $path = new Text('path', NULL, 'usr/uploads', _t('对象存储路径(必需)'), _t('默认为 usr/uploads，建议不要修改（无需以/开头）'));
            $path->setAttribute('class', 'joe_content joe_base');
            $path->addRule('regexp', _t('对象存储路径只能包含字母、数字、/、_、-'), '/^[a-zA-Z0-9\/_\-]+$/');

            $sync_local_path = new Checkbox('sync_local_path', array(
                'open' => _t('同步修改本地存储路径'),
            ), array('open'), _t(''), _t('勾选后，本地存储路径与远程 COS 路径保持一致（即使用上方配置的对象存储路径）；不勾选时本地上传使用 Typecho 默认路径 <code>/usr/uploads</code>。'));
            $sync_local_path->setAttribute('class', 'joe_content joe_base');

            $dir_structure = new Text(
                'dir_structure',
                NULL,
                '{year}/{month}',
                _t('上传目录结构'),
                _t('支持变量：<code>{year}</code>（年）、<code>{month}</code>（月）、<code>{day}</code>（日）、<code>{type}</code>（媒体类型）、<code>{ext}</code>（扩展名）。<br>'
                    . '<b>{type}</b> 自动分类：images / videos / audios / documents / code / archives / fonts / other<br>'
                    . '常用示例：<br>'
                    . '&nbsp;&nbsp;• 按年月（默认）：<code>{year}/{month}</code> → usr/uploads/2026/08/<br>'
                    . '&nbsp;&nbsp;• 按类型+年月：<code>{type}/{year}/{month}</code> → usr/uploads/images/2026/08/<br>'
                    . '&nbsp;&nbsp;• 仅按类型：<code>{type}</code> → usr/uploads/images/<br>'
                    . '&nbsp;&nbsp;• 按扩展名+年：<code>{ext}/{year}</code> → usr/uploads/jpg/2026/<br>'
                    . '&nbsp;&nbsp;• 按年+类型：<code>{year}/{type}</code> → usr/uploads/2026/images/<br>'
                    . '&nbsp;&nbsp;• 不建子目录：<code>留空</code> → usr/uploads/<br>'
                    . '<b>修改后仅影响新上传的文件，已上传的文件路径不变。</b>')
            );
            $dir_structure->setAttribute('class', 'joe_content joe_base');
            $dir_structure->addRule('regexp', _t('目录结构只能包含字母、数字、/、_、- 及 {year}/{month}/{day}/{type}/{ext} 变量'), '/^[a-zA-Z0-9\/_\-\{\}]*$/');

            // ==================== 高级设置 ====================

            $domain = new Text(
                'domain',
                NULL,
                '',
                _t('访问域名（若配置错误无法正常访问）'),
                _t('留空则使用默认域名，如：https://images-sh-123456789.cos.ap-shanghai.myqcloud.com<br>
        可使用自定义源站域名，例如：cos.example.com（请注意不要以 http:// 或 https:// 开头，仅填写域名即可，默认以https访问）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36638">官方文档</a><br>
        可使用自定义CDN域名，例如：cos.example.com（请注意不要以 http:// 或 https:// 开头，仅填写域名即可，默认以https访问）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36637">官方文档</a>
        ')
            );
            $domain->setAttribute('class', 'joe_content joe_advanced');
            $domain->addRule(
                'regexp',
                _t('访问域名格式不正确：只允许 http(s):// 前缀 + 域名 + 可选端口/子路径，例 cos.example.com 或 https://cdn.example.com/static'),
                '/^(https?:\/\/)?(\[?[a-zA-Z0-9.\-:]+\]?)(:[0-9]+)?(\/[a-zA-Z0-9._\-\/]*)?$/'
            );

            // 新增：请求超时配置项
            $timeout = new Text('timeout', NULL, '5', _t('请求超时(秒)'), _t('COS API 请求总超时时间，默认 5 秒。网络较差时可适当增大'));
            $timeout->setAttribute('class', 'joe_content joe_advanced');
            $timeout->addRule('regexp', _t('超时必须是正整数'), '/^[1-9][0-9]*$/');

            $webp_enable = new Checkbox('webp_enable', array(
                'open' => _t('上传图片自动转换 WebP'),
            ), array('open'), _t(''), _t('勾选后，上传 jpg/png 等图片时自动转换为 WebP 格式，连同原图一起上传到 COS。修改、删除时同步处理 WebP 文件。<br>优先使用 GD 库转换；GD 不支持 WebP 时自动回退到 cwebp 命令行工具（需服务器安装 <code>webp</code> 包且 PHP <code>exec()</code> 可用）。两者都不可用时自动跳过转换，不影响原图上传。'));
            $webp_enable->setAttribute('class', 'joe_content joe_advanced');

            $webp_quality = new Text(
                'webp_quality',
                NULL,
                '80',
                _t('WebP 转换质量（0-100）'),
                _t('WebP 压缩质量，数值越大画质越好但文件越大。推荐 75-85，默认 80。')
            );
            $webp_quality->setAttribute('class', 'joe_content joe_advanced');
            $webp_quality->addRule('regexp', _t('质量必须是 0-100 的整数'), '/^(100|[1-9]?[0-9])$/');

            $webp_formats = new Text(
                'webp_formats',
                NULL,
                'jpg,jpeg,png',
                _t('需要转换为 WebP 的格式'),
                _t('逗号分隔的扩展名列表，默认 <code>jpg,jpeg,png</code>。<br>建议不要包含 gif（动图转换会丢失动画）和 webp（已是目标格式）。')
            );
            $webp_formats->setAttribute('class', 'joe_content joe_advanced');
            $webp_formats->addRule('regexp', _t('格式只能包含小写字母、数字、逗号和空格'), '/^[a-z0-9, ]+$/');

            $remote_sync = new Checkbox('remote_sync', array(
                'open' => _t('本地删除同步删除COS文件'),
            ), array('open'), _t(''), _t('在文件管理删除文件时，同步删除 COS 上的对应文件'));
            $remote_sync->setAttribute('class', 'joe_content joe_advanced');

            $local = new Checkbox('local', array(
                'open' => _t('在本地保存'),
            ), array('open'), _t(''), _t('在本地保存一份副本，会占用本地存储空间'));
            $local->setAttribute('class', 'joe_content joe_advanced');

            $local_sync = new Checkbox('local_sync', array(
                'open' => _t('删除时同步删除本地备份'),
            ), NULL, _t(''), _t('在文件管理删除文件时，同步删除本地备份的对应文件（须开启“在本地保存”）'));
            $local_sync->setAttribute('class', 'joe_content joe_advanced');

            $form->addInput($secid);
            $form->addInput($sekey);
            $form->addInput($region);
            $form->addInput($bucket);
            $form->addInput($path);
            $form->addInput($sync_local_path);
            $form->addInput($dir_structure);
            $form->addInput($domain);
            $form->addInput($timeout);
            $form->addInput($webp_enable);
            $form->addInput($webp_quality);
            $form->addInput($webp_formats);
            $form->addInput($remote_sync);
            $form->addInput($local);
            $form->addInput($local_sync);
    }
}
