<?php
namespace TypechoPlugin\TypechoCosPlugin;

use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class ConfigPanel
{
    public static function render(Form $form): void
    {
        $rootUrl = htmlspecialchars(Helper::options()->rootUrl, ENT_QUOTES, 'UTF-8');
        ?>
        <link rel="stylesheet" href="<?php echo $rootUrl . '/usr/plugins/' . pluginName; ?>/statics/css/joe.config.min.css">
        <script src="<?php echo $rootUrl . '/usr/plugins/' . pluginName; ?>/statics/js/joe.config.min.js"></script>
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
                    <?php
                    if (version_compare(PHP_VERSION, '8.0.0', '<')) {
                        echo '<li style="color:red; font-weight:800;">本插件推荐在 PHP 8.0+ 环境下运行（适配 Typecho 1.3.0），低版本可能存在兼容性问题。<br></li>';
                    } ?>
                    <li>插件基于腾讯云 cos-php-sdk-v5 开发，若发现插件不可用，请到 <a target="_blank" href="https://github.com/Tencent-Cloud-Plugins/tencentcloud-typecho-plugin-cos">GitHub发布地址</a> 检查是否有更新，或者提交Issues<br></li>
                    <li>插件会验证配置的正确性，如填写错误会报错<br></li>
                    <li>插件会自动替换之前文件的链接，若启用插件前已上传文件，为保证正常显示，请自行将其上传至COS相同路径<br></li>
                    <li>禁用插件会恢复为本地路径，为保证正常显示，请自行将数据从COS下载至相同路径<br></li>
                    <li>重新更改插件中关于COS桶的配置需要先禁用插件，再重新设置<br></li>
                </ol>
            </div>
            <?php
            $secid = new Text('secid', NULL, '', _t('SecretId(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretId'));
            $secid->setAttribute('class', 'joe_content joe_base');
            $secid->addRule('required', _t('SecretId不能为空！'));

            $sekey = new Text('sekey', NULL, '', _t('SecretKey(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretKey'));
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
            // 安全：限定只能包含字母、数字、/、_、-，阻断 ../ 路径穿越
            // 注意 Typecho Form Element::addRule(...$rules) 的第一个参数是规则方法名（如 required/regexp），
            // 不能直接传正则字符串，否则 Validate::run 会把正则当方法名调用导致 Server Error。
            // 正确写法：addRule('regexp', 消息, 正则模式)，对应 Validate::regexp(string $str, string $pattern)
            $path->addRule('regexp', _t('对象存储路径只能包含字母、数字、/、_、-'), '/^[a-zA-Z0-9\/_\-]+$/');

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
            // 安全：只允许字母、数字、/、_、-、{、}，阻断路径穿越和特殊字符
            $dir_structure->addRule('regexp', _t('目录结构只能包含字母、数字、/、_、- 及 {year}/{month}/{day}/{type}/{ext} 变量'), '/^[a-zA-Z0-9\/_\-\{\}]*$/');

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
            // S3：domain 格式校验，阻断 javascript:、//、ftp: 等畸形输入拼入 URL 造成 XSS / 错误跳转
            // 允许：可选 https:// 前缀 + 域名/IPv4/IPv6 + 可选端口 + 可选子路径
            $domain->addRule(
                'regexp',
                _t('访问域名格式不正确：只允许 http(s):// 前缀 + 域名 + 可选端口/子路径，例 cos.example.com 或 https://cdn.example.com/static'),
                '/^(https?:\/\/)?(\[?[a-zA-Z0-9.\-:]+\]?)(:[0-9]+)?(\/[a-zA-Z0-9._\-\/]*)?$/'
            );

            $remote_sync = new Select('remote_sync', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'open', _t('本地删除同步删除COS文件'), _t('在文件管理删除文件时，是否同步删除COS上的对应文件'));
            $remote_sync->setAttribute('class', 'joe_content joe_advanced');

            $local = new Select('local', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'close', _t('在本地保存'), _t('在本地保存一份副本，会占用本地存储空间'));
            $local->setAttribute('class', 'joe_content joe_advanced');

            $local_sync = new Select('local_sync', array(
                'open' => _t('开启'),
                'close' => _t('关闭'),
            ), 'close', _t('删除时同步删除本地备份'), _t('在文件管理删除文件时，是否同步删除本地备份的对应文件（须开启“在本地保存”）'));
            $local_sync->setAttribute('class', 'joe_content joe_advanced');

            $form->addInput($secid);
            $form->addInput($sekey);
            $form->addInput($region);
            $form->addInput($bucket);
            $form->addInput($path);
            $form->addInput($dir_structure);
            $form->addInput($domain);
            $form->addInput($remote_sync);
            $form->addInput($local);
            $form->addInput($local_sync);
    }
}
