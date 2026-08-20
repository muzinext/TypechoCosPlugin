# 腾讯云 COS 对象存储插件 for Typecho

> 将 Typecho 博客的图片、附件等静态资源存储到腾讯云 COS（Cloud Object Storage），降低本地存储负载，配合 CDN 加速提升访问体验。支持上传目录自定义、图片自动转 WebP（双引擎）、本地路径同步等实用功能。

[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-blue)](https://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-green)](#许可证)
[![Version](https://img.shields.io/badge/version-1.1.0-orange)](#更新日志)

---

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [部署流程](#部署流程)
- [配置说明](#配置说明)
- [上传目录结构](#上传目录结构)
- [WebP 自动转换](#webp-自动转换)
- [自定义域名与 CDN](#自定义域名与-cdn)
- [子账号权限配置](#子账号权限配置推荐)
- [代码架构](#代码架构)
- [常见问题](#常见问题)
- [更新日志](#更新日志)
- [许可证](#许可证)

---

## 功能特性

### 核心功能

- **文件上传**：将图片、附件上传至腾讯云 COS 存储桶，自动设置公有读权限与长缓存头（`Cache-Control: max-age=31536000, immutable`）
- **文件修改**：后台替换已上传附件时，COS 存储桶内对应文件同步覆盖更新
- **文件删除**：删除附件时可选择是否同步删除 COS 上的文件（含 WebP 衍生文件）
- **自动回退**：COS 未配置或连接失败时，自动回退到 Typecho 原生本地存储，后台文件管理不中断
- **网络重试**：COS 上传、删除、WebP 上传均内置 1 次自动重试（间隔 200ms），应对网络瞬时波动
- **连通性校验**：保存配置时自动校验存储桶是否存在及本地路径可写性，校验失败在后台以黄色警告条提示用户（不阻断保存），同时写入错误日志

### 存储与路径

- **本地备份**：可选择在服务器本地保留一份文件副本
- **本地路径同步**：开启后本地存储路径与远程 COS 路径保持一致
- **目录结构自定义**：通过 `{year}` / `{month}` / `{day}` / `{type}` / `{ext}` 变量自由组合存放路径
- **媒体类型自动分类**：images / videos / audios / documents / code / archives / fonts / other 八类

### WebP 自动转换

- **双引擎转换**：优先使用 GD 库 `imagewebp()`，GD 不支持 WebP 时自动回退到 `cwebp` 命令行工具
- **智能链接返回**：转换并上传成功后，附件记录直接存储 WebP 路径，编辑器自动返回 WebP 格式链接；转换失败则存储原格式路径，确保图片可访问
- **三端同步**：上传、修改、删除均同步处理 WebP 衍生文件
- **安全保护**：超过 5000 万像素的超大图片自动跳过（避免内存溢出），GIF 动图自动跳过（避免丢失动画）

### 域名与访问

- **三种域名模式**：默认 COS 域名、自定义源站域名、CDN 加速域名
- **永久无签名链接**：所有访问 URL 均为静态无签名链接，不会过期

### 安全与可维护性

- **SecretKey 密码框**：配置页面使用 Password 输入框，不明文显示密钥
- **日志脱敏**：错误日志自动屏蔽 SecretId、SecretKey、COS 签名参数等敏感信息
- **文件名防护**：清洗危险字符，`basename()` 防路径穿越，点文件（`.htaccess`）视为无扩展名
- **零侵入**：通过 Typecho 1.3.0 原生插件钩子实现，不修改任何核心文件

---

## 环境要求

| 项目 | 要求 |
|------|------|
| Typecho | 1.3.0 及以上 |
| PHP | 8.0 及以上（推荐 8.2+） |
| PHP 扩展 | phar（加载 COS SDK 必需）、curl、GD（图片处理） |
| WebP 转换 | GD 编译 WebP 支持，或安装 `webp` 包（提供 `cwebp` 命令）且 PHP `exec()` 可用 |
| 腾讯云 | 已开通 COS 服务，拥有存储桶 |

---

## 部署流程

### 第一步：创建 COS 存储桶

1. 登录 [腾讯云 COS 控制台](https://console.cloud.tencent.com/cos/bucket)
2. 点击「创建存储桶」
3. 填写名称（格式为 `BucketName-AppId`，如 `typecho-1300000000`）
4. 选择**所属地域**（建议与服务器同地域，减少回源延迟）
5. **访问权限**选择「公有读私有写」
6. 点击创建

> 公有读私有写：任何人可读取文件，但只有授权账号可写入/删除。博客图片属于公开内容，此权限最合适。

### 第二步：获取 API 密钥

1. 进入 [CAM - 访问管理](https://console.cloud.tencent.com/cam/user)
2. 推荐创建**子账号**（安全），关联策略 `QcloudCOSDataFullControl`，或参考[下文](#子账号权限配置推荐)配置最小权限
3. 创建后获取 `SecretId` 和 `SecretKey`（SecretKey 仅显示一次，请妥善保存）

### 第三步：安装插件

1. 将 `TypechoCosPlugin/` 文件夹上传至 Typecho 站点的 `/usr/plugins/` 目录
2. 确保目录结构为 `/usr/plugins/TypechoCosPlugin/Plugin.php`
3. 登录 Typecho 后台 → 「控制台」→「插件」
4. 找到「腾讯云对象存储（COS）插件」，点击「启用」

### 第四步：配置插件

1. 启用后点击「设置」进入配置面板
2. 填写「基础设置」中的 SecretId、SecretKey、所属地域、存储桶名称
3. （可选）调整「上传目录结构」「同步修改本地存储路径」
4. （可选）配置「高级设置」中的访问域名、请求超时、WebP 转换、本地备份等
5. 点击保存，插件会自动校验存储桶连通性和本地路径可写性

### 第五步：验证

1. 写一篇文章或在文件管理上传一张图片
2. 确认 COS 控制台对应路径下出现该文件
3. 确认前台图片正常显示
4. 如开启了 WebP，确认 COS 同目录下多了一个 `.webp` 文件，且编辑器插入的链接为 `.webp` 结尾

---

## 配置说明

### 基础设置

| 配置项 | 必填 | 默认值 | 说明 |
|--------|:----:|--------|------|
| SecretId | 是 | 无 | 腾讯云 API 密钥 ID |
| SecretKey | 是 | 无 | 腾讯云 API 密钥 Key（密码框输入，页面不明文显示） |
| 所属地域 | 是 | 北京 | COS 存储桶所在地域，如 `ap-guangzhou`、`ap-shanghai` |
| 存储桶名称 | 是 | 无 | 格式为 `BucketName-AppId`，如 `typecho-1300000000` |
| 对象存储路径 | 是 | `usr/uploads` | 文件在 COS 中的存储前缀，建议保持默认（无需以 `/` 开头） |
| 同步修改本地存储路径 | 否 | 开启 | 开启后本地存储路径与远程 COS 路径一致；关闭时本地上传使用 Typecho 默认 `/usr/uploads` |
| 上传目录结构 | 否 | `{year}/{month}` | 文件子目录模板，详见[上传目录结构](#上传目录结构) |

### 高级设置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| 访问域名 | 留空 | 留空使用默认 COS 域名；可填写自定义源站域名或 CDN 加速域名（仅填域名，不带协议前缀和末尾斜杠） |
| 请求超时(秒) | 5 | COS API 请求总超时时间，网络较差时可适当增大 |
| 上传图片自动转换 WebP | 开启 | 开启后上传图片时自动转换为 WebP 并一同上传，详见[WebP 自动转换](#webp-自动转换) |
| WebP 转换质量 | 80 | 0-100，推荐 75-85，越大画质越好文件越大 |
| 需要转换为 WebP 的格式 | `jpg,jpeg,png` | 逗号分隔的扩展名列表 |
| 本地删除同步删除 COS 文件 | 开启 | 在后台删除附件时，是否同步删除 COS 上的文件 |
| 在本地保存 | 开启 | 上传文件时是否在服务器本地保留一份副本（占用磁盘，但可减少 COS 请求） |
| 删除时同步删除本地备份 | 关闭 | 删除附件时是否同步删除本地备份（需先开启「在本地保存」） |

---

## 上传目录结构

通过「上传目录结构」配置项，可自由定义文件在 COS 中的子目录路径。

### 支持的变量

| 变量 | 说明 | 示例值 |
|------|------|--------|
| `{year}` | 4 位年份 | `2026` |
| `{month}` | 2 位月份 | `08` |
| `{day}` | 2 位日期 | `19` |
| `{type}` | 媒体类型分类 | `images` / `videos` / `documents` / `code` 等 |
| `{ext}` | 文件扩展名（小写） | `jpg` / `mp4` / `pdf` / `js` |

### `{type}` 分类规则

| 分类 | 包含的扩展名 |
|------|-------------|
| **images** | jpg, jpeg, png, gif, bmp, webp, svg, ico, tiff, avif, heic, psd, ai |
| **videos** | mp4, avi, mov, wmv, flv, mkv, webm, m4v, mpg, 3gp, rmvb |
| **audios** | mp3, wav, ogg, flac, aac, m4a, wma, ape, opus, mid |
| **documents** | pdf, doc, docx, xls, xlsx, ppt, pptx, txt, md, csv, epub, rtf |
| **code** | js, css, html, php, json, xml, sql, py, java, c, cpp, ts, vue, go, rs |
| **archives** | zip, rar, 7z, tar, gz, bz2, xz |
| **fonts** | ttf, otf, woff, woff2, eot |
| **other** | 以上未覆盖的扩展名 |

### 配置示例

| 填写内容 | 实际路径 | 适用场景 |
|---------|---------|---------|
| `{year}/{month}` | `usr/uploads/2026/08/` | 默认，按年月 |
| `{year}` | `usr/uploads/2026/` | 按年归档 |
| `{year}/{month}/{day}` | `usr/uploads/2026/08/19/` | 按年月日 |
| `{type}/{year}/{month}` | `usr/uploads/images/2026/08/` | 按类型+年月（推荐） |
| `{type}` | `usr/uploads/images/` | 仅按类型分类 |
| `{ext}/{year}` | `usr/uploads/jpg/2026/` | 按扩展名+年 |
| 留空 | `usr/uploads/` | 全部平铺，不建子目录 |

> **注意**：修改目录结构仅影响**新上传**的文件，已上传的旧文件路径不变。

---

## WebP 自动转换

开启后，上传 jpg/png 等图片时，插件会自动将图片转换为 WebP 格式，然后**连同原图一起上传**到 COS。转换成功后，编辑器返回 WebP 链接。

### 工作流程

```
上传 jpg/png 图片
    ↓
原图上传到 COS（路径不变，如 /usr/uploads/2026/08/abc.jpg）
    ↓
WebP 转换（GD 优先 → cwebp 后备）
    ↓
WebP 上传到 COS（同目录同文件名，如 /usr/uploads/2026/08/abc.webp）
    ↓
转换+上传成功？→ 附件记录存储 .webp 路径，编辑器返回 .webp 链接
转换失败？→ 附件记录存储原格式路径，编辑器返回原格式链接（确保可访问）
    ↓
清理临时文件
```

**转换成功时附件记录直接存储 WebP 路径**（存入数据库 `text` 字段的 JSON 中），Typecho 从该路径生成访问 URL，自然就是 WebP 链接。转换失败时存储原格式路径，不影响图片访问。

### 双引擎说明

| 引擎 | 条件 | 优点 | 缺点 |
|------|------|------|------|
| **GD 库** | PHP 编译 GD 时含 `--with-webp` | 纯 PHP，无需 exec | 部分编译环境（如 oneinstack）默认不含 WebP |
| **cwebp 命令行** | 服务器安装 `webp` 包 + PHP `exec()` 可用 | 转换质量高，不依赖 PHP 编译选项 | 需要 exec() 函数 |

插件自动检测可用引擎，优先 GD，不可用时回退 cwebp，两者都不可用时跳过转换只上传原图。

### 安装 cwebp（GD 不支持 WebP 时）

```bash
# Debian/Ubuntu
apt-get install -y webp

# 验证
cwebp -version
```

确保 PHP `exec()` 函数未被禁用（检查 `php.ini` 的 `disable_functions`）。

### 三端同步

| 操作 | 原图 | WebP |
|------|------|------|
| **上传** | 上传到 COS | 自动转换并上传到 COS，成功后返回 WebP 链接 |
| **修改** | 覆盖上传新原图 | 重新转换并覆盖旧 WebP |
| **删除** | 删除 COS 原图 | 同步删除对应的 WebP 文件（不依赖 WebP 功能开关，避免残留） |

### 环境要求与降级

- **GD 方式**：需 PHP GD 库 + `gd_info()['WebP Support'] = true`
- **cwebp 方式**：需 `webp` 包 + PHP `exec()` 可用
- **自动降级**：两种方式都不可用时，自动跳过转换，只上传原图，不报错
- **GIF 处理**：自动跳过（动图转 WebP 会丢失动画）
- **超大图片**：超过 5000 万像素（约 7000×7000）自动跳过，避免内存溢出
- **转换失败**：单张图片转换失败只记录日志，原图仍正常上传，编辑器返回原格式链接

### 前端使用 WebP

- 插件上传后编辑器直接插入 WebP 链接，无需额外配置
- 如使用 CDN，可开启「图片自适应」功能根据 `Accept: image/webp` 头自动返回 WebP，与本插件的本地转换二选一即可，不要重复转换

---

## 自定义域名与 CDN

插件支持三种域名模式，生成的访问 URL 均为**永久无签名链接**。

### 模式一：默认 COS 域名（最简单）

- 「访问域名」留空
- URL 形式：`https://{bucket}.cos.{region}.myqcloud.com/usr/uploads/xxx.jpg`
- 无需额外配置，开箱即用
- 缺点：无 CDN 加速，访问速度取决于用户到 COS 节点的距离

### 模式二：自定义源站域名（直达 COS）

1. COS 控制台 → 存储桶 → 域名与传输管理 → 自定义源站域名 → 添加域名
2. DNS 服务商给该域名添加 CNAME 记录，指向 COS 默认域名
3. 插件「访问域名」填写该域名
4. URL 形式：`https://cos.example.com/usr/uploads/xxx.jpg`

### 模式三：CDN 加速域名（推荐）

1. [CDN 控制台](https://console.cloud.tencent.com/cdn) → 域名管理 → 添加域名
2. 源站类型选「COS 源」，选择你的存储桶
3. DNS 给加速域名添加 CNAME，指向 CDN 分配的 CNAME 地址
4. 插件「访问域名」填写 CDN 加速域名
5. URL 形式：`https://cdn.example.com/usr/uploads/xxx.jpg`

#### 防盗链配置（推荐）

在 CDN 控制台 → 域名管理 → 访问控制 → **Referer 防盗链**：

- 类型选「白名单」
- 填写你的博客域名，如 `example.com`、`www.example.com`
- 「允许空 Referer」建议关闭

> **不要开启 CDN 时间戳 URL 鉴权**。博客图片是公开内容，文章中存储的是静态 URL，时间戳鉴权会导致链接过期后图片 403。Referer 白名单已能满足绝大多数博客的防盗链需求。

---

## 子账号权限配置（推荐）

出于安全考虑，不建议使用主账号 SecretId/Key。可创建子账号并授予单桶最小权限：

```json
{
  "version": "2.0",
  "statement": [
    {
      "effect": "allow",
      "action": [
        "cos:HeadBucket",
        "cos:GetBucket",
        "cos:PutObject",
        "cos:PostObject",
        "cos:GetObject",
        "cos:HeadObject",
        "cos:CopyObject",
        "cos:PutObjectACL",
        "cos:GetObjectACL",
        "cos:DeleteObject",
        "cos:InitiateMultipartUpload",
        "cos:ListMultipartUploads",
        "cos:ListParts",
        "cos:UploadPart",
        "cos:CompleteMultipartUpload",
        "cos:AbortMultipartUpload"
      ],
      "resource": [
        "qcs::cos:ap-guangzhou:uid/1300000000:typecho-1300000000/*",
        "qcs::cos:ap-guangzhou:uid/1300000000:typecho-1300000000"
      ]
    }
  ]
}
```

将以下值替换为你自己的：

- `ap-guangzhou` → 你的存储桶地域
- `1300000000` → 你的主账号 AppId
- `typecho-1300000000` → 你的存储桶名称

> 如自定义策略配置报错，可直接使用系统预设策略 `QcloudCOSDataFullControl`。

---

## 代码架构

插件采用多类分离设计，便于维护和扩展：

| 文件 | 职责 |
|------|------|
| `Plugin.php` | 插件入口、钩子注册、配置处理、共享状态（单例缓存） |
| `Action.php` | COS 上传/修改/删除、URL 生成、客户端初始化、目录逻辑、路径校验、COS 重试机制 |
| `ConfigPanel.php` | 后台配置面板 HTML 表单渲染（Tab 切换：使用说明 / 基础设置 / 高级设置） |
| `Image.php` | 图片处理：媒体分类、WebP 双引擎转换（GD + cwebp）、文件名清洗 |
| `Logger.php` | 统一日志输出（敏感信息脱敏 + 调试级别控制），解耦各模块间日志依赖 |
| `phar/cos-sdk-v5-7.phar` | 腾讯云 COS PHP SDK v5（phar 打包，无需 composer） |
| `statics/` | 配置面板 CSS/JS 资源 |

### 关键设计

- **薄委托**：`Plugin.php` 的钩子方法仅做转发，实际逻辑在 `Action` 类
- **单例缓存**：COS Client 和插件配置在单次请求内缓存，避免重复初始化
- **失败兜底**：所有 COS 操作均有 `try/catch`，失败时自动回退本地上传或记录日志
- **配置保存零异常**：`configHandle` 绝不抛出异常，桶校验/路径校验失败仅推送警告通知，确保保存后页面正常跳转
- **WebP 路径直存**：转换成功时附件记录直接存储 WebP 路径，Typecho 自然生成 WebP URL；转换失败时存原格式路径，确保可访问
- **COS 操作重试**：上传、删除、WebP 上传均内置 1 次自动重试（间隔 200ms），应对网络瞬时波动
- **URL 路径编码**：生成访问 URL 时对路径分段做 `rawurlencode`，兼容中文/空格等特殊字符文件名
- **随机数容错**：文件名生成优先使用 `random_bytes`（CSPRNG），极端环境下回退 `uniqid`，避免因缺少 CSPRNG 导致上传失败
- **日志脱敏**：所有写入 `error_log` 的内容自动屏蔽 SecretId/SecretKey/COS 签名参数等敏感信息

---

## 常见问题

### 上传成功但前台图片 403？

1. 检查 COS 存储桶权限是否为「公有读私有写」
2. 如使用 CDN，确认未开启「时间戳 URL 鉴权」
3. 如 CDN 配置了 Referer 白名单，确认博客域名在白名单中

### 后台无法上传/修改/删除文件？

插件内置回退机制，COS 连接失败时会自动走本地上传。如遇到问题请检查：

- SecretId / SecretKey 是否正确
- 存储桶名称和地域是否匹配
- 子账号是否授权了对应桶的读写权限
- 服务器能否访问 COS 服务（网络/防火墙）
- 查看 PHP `error_log` 中 `[TypechoCosPlugin]` 开头的错误日志

### 开启「同步修改本地存储路径」后上传失败？

1. 保存配置时插件会自动校验本地路径可写性，不可写会在后台显示黄色警告条
2. 检查 web 服务器用户（www-data/nginx）对目标目录的父目录是否有写入权限
3. 如使用共享主机，确保目标路径不在 `open_basedir` 限制之外

### 启用插件前已上传的文件怎么办？

插件不会自动迁移旧文件。需要手动将本地 `/usr/uploads/` 目录下的文件上传到 COS 对应路径（保持目录结构一致），插件才能正确访问。

### 禁用插件后图片还能显示吗？

禁用插件后，附件 URL 会恢复为本地路径。如果之前开启了「在本地保存」，图片可正常显示；否则需要将 COS 文件下载回本地对应目录。

### 修改存储桶配置后不生效？

切换存储桶、地域等核心配置时，需先**禁用插件 → 修改配置 → 重新启用**，确保旧配置缓存被清除。

### WebP 转换没有生效？

1. 确认「上传图片自动转换 WebP」已开启
2. 确认文件扩展名在「需要转换为 WebP 的格式」列表中（默认 `jpg,jpeg,png`）
3. 确认转换引擎可用：
   - GD 方式：`php -r "var_dump(gd_info()['WebP Support']);"` 应输出 `bool(true)`
   - cwebp 方式：`cwebp -version` 有输出，且 PHP `exec()` 未被禁用
4. 确认图片像素未超过 5000 万（超大图片自动跳过）
5. 查看 PHP `error_log` 中是否有 `[TypechoCosPlugin]` 相关的 WebP 错误日志

### 如何开启调试日志？

在 Typecho 的 `config.inc.php` 中添加：

```php
define('TYPECHO_COS_DEBUG', 1);
```

开启后插件会将详细操作日志写入 PHP `error_log`，便于排查问题。

---

## 更新日志

### v1.1.0 (2026-08-20)

**WebP 链接机制重构**
- 转换成功时附件记录直接存储 WebP 路径（存入数据库 `text` 字段），Typecho 自然生成 WebP URL，彻底解决编辑器返回原格式链接的问题
- 转换失败时存储原格式路径，确保图片可访问
- `modifyHandle` 支持附件路径为 WebP 时自动推导原始路径用于上传新文件
- `deleteHandle` 支持双向清理：路径为 WebP 时同步删除原始文件，路径为原格式时同步删除 WebP

**配置面板优化**
- 所有布尔选项（同步修改本地存储路径、本地删除同步删除COS文件、在本地保存、删除时同步删除本地备份、上传图片自动转换WebP）统一改为 Checkbox 勾选框样式
- 默认值调整：同步修改本地存储路径默认开启、在本地保存默认开启、WebP 转换默认开启

**Bug 修复**
- 修复 `deleteHandle` 中附件路径为 WebP 时本地备份删除路径错误（原图备份残留）
- 修复 `loadSdk` 中 `set_error_handler` 异常时未恢复导致全局错误处理器泄漏
- 修复 cwebp 命令使用裸命令 `cwebp` 依赖 PATH 环境变量的问题，改为缓存并使用检测到的完整路径
- 修复 Plugin.php 文档块版本号与 `PLUGIN_VERSION` 常量不同步；`USER_AGENT` 改用常量引用

**性能与健壮性**
- COS 上传、删除、WebP 上传均加入 1 次自动重试（间隔 200ms），应对网络瞬时波动
- 提取 `uploadToCos()` 公共方法，消除 `uploadHandle`/`modifyHandle` 重复代码
- 合并 `attachmentHandle`/`attachmentDataHandle` 为 `resolveAttachmentUrl()`，消除重复逻辑
- `buildObjectUrl` 对路径分段做 `rawurlencode`，兼容中文/空格等特殊字符文件名
- 移除从未调用的死代码 `doesObjectExist()`
- 日志脱敏正则移除 `ak`/`sk`/`key`/`sign` 等过短泛匹配词，避免误脱敏 `keyword=`、`apikey=` 等正常参数

### v1.0.9 (2026-08-19)

**核心功能**
- 文件上传至腾讯云 COS，自动设置公有读权限与长缓存头
- 文件修改/删除同步 COS，支持删除时同步清理 WebP 衍生文件
- 本地备份可选，支持本地路径与 COS 路径同步
- 自定义访问域名（默认 COS 域名 / 自定义源站域名 / CDN 加速域名）
- 上传目录结构自定义（`{year}`/`{month}`/`{day}`/`{type}`/`{ext}` 变量）
- COS 连接失败自动回退本地上传，后台不中断

**WebP 自动转换**
- 上传 jpg/png 等图片自动转换为 WebP 并一同上传 COS
- 双引擎转换：优先 GD 库，GD 不支持 WebP 时自动回退 cwebp 命令行工具
- 转换并上传成功后，编辑器返回 WebP 链接
- 支持转换质量配置、可转换格式列表配置
- 超大图片（>5000 万像素）自动跳过，避免内存溢出
- GIF 动图自动跳过（避免丢失动画）

**配置与安全**
- 保存配置时自动校验存储桶连通性与本地路径可写性，失败推送后台警告通知（不阻断保存）
- SecretKey 使用密码框输入，页面不明文显示
- 日志敏感信息（密钥、签名参数）自动脱敏
- 文件名清洗防路径穿越，点文件视为无扩展名
- 请求超时可配置
- 配置保存零异常：任何校验失败均不抛出异常，确保页面正常跳转

**健壮性与修复**
- 修复 Typecho 1.3.0 兼容：`Upload::UPLOAD_DIR` 常量已移除，改用插件内常量
- 修复 bytes/bits 方式上传时 MIME 类型检测失效
- 修复 WebP 临时文件残留（`tempnam()` 空文件问题）
- 修复关闭 WebP 后删除图片不清理 `.webp` 残留
- 修复配置面板 JS 表单选择器空指针异常（Tab 切换失效）
- `defaultUploadHandle` / `defaultModifyHandle` 中 `filesize()` 加错误抑制
- `deleteHandle` 路径空值保护，异常数据不导致 Fatal Error
- `Logger::maskSensitive()` 正则失败时返回原消息而非 null
- `buildObjectUrl()` 移除未使用参数
- `webp_formats` 配置允许输入带空格的格式列表
- `modifyHandle` 本地备份逻辑复用 `saveLocalBackup()`，消除重复代码
- 全局常量 `pluginName` 改为类常量 `Plugin::NAME`，避免命名冲突
- `makeLocalAttachmentUrl()` URL 校验改用 `filter_var(FILTER_VALIDATE_URL)`
- cwebp 探测增加 `which` 命令后备，兼容 busybox/Alpine 等极简环境
- 文件名生成 `random_bytes()` 异常回退 `uniqid()`，无 CSPRNG 环境不中断上传

**架构**
- 多类分离：Plugin（入口）/ Action（核心逻辑）/ Image（图片处理）/ Logger（日志）/ ConfigPanel（配置面板）
- COS SDK 通过 phar 加载，无需 composer
- 适配 Typecho 1.3.0 原生插件钩子，零核心文件修改

---

## 许可证

本项目基于 GPL-2.0 许可证开源。

- 项目地址：[GitHub](https://github.com/muzinext/TypechoCosPlugin)
- 腾讯云 COS 文档：[https://cloud.tencent.com/document/product/436](https://cloud.tencent.com/document/product/436)
