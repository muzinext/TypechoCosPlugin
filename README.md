# 腾讯云对象存储插件（Typecho 版）

> 将 Typecho 博客的图片、附件等静态资源存储到腾讯云 COS（Cloud Object Storage），降低本地存储负载，配合 CDN 加速提升访问体验。

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [安装部署](#安装部署)
- [配置说明](#配置说明)
- [上传目录结构](#上传目录结构)
- [自定义域名与 CDN](#自定义域名与-cdn)
- [子账号权限配置](#子账号权限配置推荐)
- [常见问题](#常见问题)
- [更新日志](#更新日志)
- [许可证](#许可证)

---

## 功能特性

- **文件上传**：将图片、附件上传至腾讯云 COS 存储桶，自动设置公有读权限与长缓存头
- **文件修改**：在后台替换已上传的附件时，COS 存储桶内对应文件同步更新
- **文件删除**：删除附件时可选择是否同步删除 COS 上的文件
- **本地备份**：可选择在服务器本地保留一份文件副本
- **自定义域名**：支持默认 COS 域名、自定义源站域名、CDN 加速域名三种模式
- **目录结构自定义**：通过 `{year}` / `{month}` / `{day}` / `{type}` / `{ext}` 变量自由组合存放路径
- **自动回退**：COS 未配置或连接失败时，自动回退到 Typecho 原生本地存储，后台文件管理不中断
- **连通性校验**：保存配置时自动校验存储桶是否存在，配置错误会给出明确提示
- **零侵入**：通过 Typecho 1.3.0 原生插件钩子实现，不修改任何核心文件

---

## 环境要求

| 项目 | 要求 |
|------|------|
| Typecho | 1.3.0 及以上 |
| PHP | 8.0 及以上（推荐 8.2+） |
| PHP 扩展 | phar（用于加载 COS SDK）、curl |
| 腾讯云 | 已开通 COS 服务，拥有存储桶 |

---

## 安装部署

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
3. （可选）调整「上传目录结构」和「高级设置」
4. 点击保存，插件会自动校验存储桶连通性

---

## 配置说明

### 基础设置

| 配置项 | 必填 | 默认值 | 说明 |
|--------|:----:|--------|------|
| SecretId | ✅ | 无 | 腾讯云 API 密钥 ID |
| SecretKey | ✅ | 无 | 腾讯云 API 密钥 Key |
| 所属地域 | ✅ | 北京 | COS 存储桶所在地域，如 `ap-guangzhou`、`ap-shanghai` |
| 存储桶名称 | ✅ | 无 | 格式为 `BucketName-AppId`，如 `typecho-1300000000` |
| 对象存储路径 | ✅ | `usr/uploads` | 文件在 COS 中的存储前缀，建议保持默认 |
| 上传目录结构 | ❌ | `{year}/{month}` | 文件子目录模板，详见[上传目录结构](#上传目录结构) |

### 高级设置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| 访问域名 | 留空 | 留空使用默认 COS 域名；可填写自定义源站域名或 CDN 加速域名（仅填域名，不带协议前缀和末尾斜杠）|
| 本地删除同步删除 COS 文件 | 开启 | 在后台删除附件时，是否同步删除 COS 上的对应文件 |
| 在本地保存 | 关闭 | 上传文件时是否在服务器本地保留一份副本（占用磁盘，但可减少 COS 请求）|
| 删除时同步删除本地备份 | 关闭 | 删除附件时是否同步删除本地备份（需先开启「在本地保存」）|

---

## 上传目录结构

通过「上传目录结构」配置项，可自由定义文件在 COS 中的子目录路径。支持以下变量：

| 变量 | 说明 | 示例 |
|------|------|------|
| `{year}` | 4 位年份 | `2026` |
| `{month}` | 2 位月份 | `08` |
| `{day}` | 2 位日期 | `19` |
| `{type}` | 媒体类型分类 | `images` / `videos` / `documents` |
| `{ext}` | 文件扩展名（小写） | `jpg` / `mp4` / `pdf` |

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
| `{year}/{month}` | `usr/uploads/2026/08/` | 默认，按年月（兼容原有行为）|
| `{year}` | `usr/uploads/2026/` | 按年归档 |
| `{year}/{month}/{day}` | `usr/uploads/2026/08/19/` | 按年月日 |
| `{type}/{year}/{month}` | `usr/uploads/images/2026/08/` | 按类型+年月（推荐）|
| `{type}` | `usr/uploads/images/` | 仅按类型分类 |
| `{ext}/{year}` | `usr/uploads/jpg/2026/` | 按扩展名+年 |
| `{year}/{type}` | `usr/uploads/2026/images/` | 按年+类型 |
| 留空 | `usr/uploads/` | 全部平铺，不建子目录 |

> **注意**：修改目录结构仅影响**新上传**的文件，已上传的旧文件路径不变，文章中的图片引用不受影响。

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

## 常见问题

### 上传成功但前台图片 403？

1. 检查 COS 存储桶权限是否为「公有读私有写」
2. 如使用 CDN，确认未开启「时间戳 URL 鉴权」
3. 如 CDN 配置了 Referer 白名单，确认博客域名在白名单中，且未关闭「允许空 Referer」（浏览器直接访问图片时 Referer 为空）

### 后台无法上传/修改/删除文件？

插件内置回退机制，COS 连接失败时会自动走本地上传。如遇到问题请检查：
- SecretId / SecretKey 是否正确
- 存储桶名称和地域是否匹配
- 子账号是否授权了对应桶的读写权限
- 服务器能否访问 COS 服务（网络/防火墙）
- 查看 PHP `error_log` 中 `[TypechoCosPlugin]` 开头的错误日志

### 启用插件前已上传的文件怎么办？

插件不会自动迁移旧文件。需要手动将本地 `/usr/uploads/` 目录下的文件上传到 COS 对应路径（保持目录结构一致），插件才能正确访问。

### 禁用插件后图片还能显示吗？

禁用插件后，附件 URL 会恢复为本地路径。如果之前开启了「在本地保存」，图片可正常显示；否则需要将 COS 文件下载回本地对应目录。

### 修改存储桶配置后不生效？

切换存储桶、地域等核心配置时，需先**禁用插件 → 修改配置 → 重新启用**，确保旧配置缓存被清除。

### 如何开启调试日志？

在 Typecho 的 `config.inc.php` 中添加：

```php
define('TYPECHO_COS_DEBUG', 1);
```

开启后插件会将详细操作日志写入 PHP `error_log`，便于排查问题。

---

## 更新日志

### v1.0.5 (2026-08-19)

**新增功能**
- 上传目录结构自定义：支持 `{year}` / `{month}` / `{day}` / `{type}` / `{ext}` 变量组合
- 自动媒体类型识别：images / videos / audios / documents / code / archives / fonts / other 八类

**架构精简**
- 移除 COS 原生签名链接功能及相关配置
- 移除 CDN URL 鉴权（Type A/B/C/D）功能及 6 个配置项
- 移除 OB 输出缓冲刷新机制及相关缓存变量
- 清理未使用的方法与导入

**设计说明**
- 统一使用永久无签名 URL，避免文章中静态链接过期
- 推荐通过 CDN Referer 白名单实现防盗链
- 默认目录结构保持 `{year}/{month}`，升级无缝兼容

### v1.0.4 (2026-08-18)

- 新增 COS 连接失败自动回退本地存储机制
- 适配 Typecho 1.3.0 原生插件钩子，无需修改核心文件
- 升级 cos-php-sdk-v5 至 2.6.17
- 支持自定义访问域名

---

## 许可证

本项目基于 GPL-2.0 许可证开源。

- 项目地址：[https://github.com/muzinext/TypechoCosPlugin](https://github.com/muzinext/TypechoCosPlugin)
- 腾讯云 COS 文档：[https://cloud.tencent.com/document/product/436](https://cloud.tencent.com/document/product/436)
