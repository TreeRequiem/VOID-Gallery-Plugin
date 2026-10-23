# VOID-Gallery-Plugin

# VOID 图集短代码插件（VoidGallery）

为 VOID主题（3.6.0及以上版本）的文章/页面增加 `[gallery]...[/gallery]` 短代码：把短代码内的图片自动排版为 **justified 照片墙**（与主题 Gallery 独立页面模板相同的排版效果）。

* 不修改主题任何文件，停用插件即可完全还原；
* 一篇文章中可以使用 **任意多组** `[gallery]`；
* 显示宽度跟随主题超高级设置 `largePhotoSet`，与 `[photos]` 短代码行为一致；
* `[photos]` 原有的单图 / 双图并排 / 三张及以上横向滑动条能力完全不受影响。

## 依赖

* Typecho 1.2 / 1.3  
* [VOID主题（3.6.0及以上版本）](https://github.com/mikusaa/Typecho-Theme-VOID)（依赖主题内置的 `VOID_Gallery` / `VOID_PhotoSets` 前端排版与 `parseImages` 图片语义化）

## 安装

1. 将本插件目录 **重命名为 `VoidGallery`**（Typecho 要求插件目录名与类名前缀一致，目录名不能含连字符）；
2. 上传至站点 `usr/plugins/` 目录；
3. 后台「控制台 → 插件」中启用 **VoidGallery**。

## 使用方法

在文章或独立页面正文中，用 `[gallery]` 包裹一组 Markdown 图片：

```markdown
[gallery]
![照片说明](https://example.com/photo-1.jpg)
![照片说明](https://example.com/photo-2.jpg)
![照片说明](https://example.com/photo-3.jpg)
[/gallery]
```

发布后，这组图片会按照原始宽高比自动组成自适应照片墙（多行 justified 布局），与 Gallery 独立页面模板的效果一致。照片墙较高时会按行渐进显示，并提供「显示更多照片」按钮。

可以在同一篇文章中使用多组 `[gallery]`，中间穿插普通文字、标题等内容：

```markdown
第一段文字……

[gallery]
![](https://example.com/a.jpg)
![](https://example.com/b.jpg)
[/gallery]

第二段文字……

[gallery]
![](https://example.com/c.jpg)
![](https://example.com/d.jpg)
![](https://example.com/e.jpg)
[/gallery]
```

若你的 Typecho 版本会把 HTML 转义输出，可与 `[photos]` 一样用单独占行的 `!!!` 包裹（一般不需要，纯短代码不受影响）：

```markdown
!!!
[gallery]
···
[/gallery]
!!!
```

## 显示宽度

`[gallery]` 的宽度逻辑与 `[photos]` 完全一致，由主题超高级设置 `largePhotoSet` 控制（`advanceSetting.json`）：

| 设置 | 效果 |
|------|------|
| `"largePhotoSet": true` | 图集宽于正文单栏（宽屏下约 1024/1200px，居中溢出正文栏） |
| `"largePhotoSet": false` 或未设置 | 图集与正文单栏同宽 |

开启侧边栏显示（`.sidebar-show`）时，两者都会被主题 CSS 强制回退为正文栏宽。

## 与 `[photos]` 的区别

| | `[photos]` | `[gallery]`（本插件） |
|---|---|---|
| 1 张 | 居中单图 | 照片墙单行 |
| 2 张 | 等高并排（不裁切） | 照片墙一行并排 |
| 3 张及以上 | 横向滑动条（可拖动/按钮滚动） | 照片墙多行排列 |
| 多行能力 | 无 | 有 |
| 宽度 | 跟随 `largePhotoSet` | 跟随 `largePhotoSet` |

两者互不干扰，可在同一篇文章中混用。

## 注意事项

* `[gallery]` 内请只放图片（Markdown 图片语法），不要写文字段落；短代码会移除内部的段落与换行标签。
* **允许在 `[gallery]` 内嵌套 `[photos]`**：解析时会自动剥掉内层的 `[photos]`/`[/photos]` 标记，分组语义被忽略（与 Gallery 独立页面的呈现一致）——所有图片统一展平，由照片墙算法重新分行。因此可以方便地把原有 `[photos]` 排版段落直接套进 `[gallery]` 升级为照片墙；三张及以上的横滑条（strip）呈现只属于独立的 `[photos]`，不会出现在 `[gallery]` 内。
* 摘要（excerpt）中的 `[gallery]` 会被整体移除，不会在列表页残留图片或短代码文本。
* 图片点击放大（聚焦层）沿用主题既有能力，无需额外配置。
* 插件前端补丁依赖主题的 `VOID_Gallery` 对象；若未来主题大版本重构该对象，插件可能需要同步调整。

## 卸载

后台停用插件即可。已发布文章中的 `[gallery]...[/gallery]` 会还原为普通文本与图片，不会产生残留 HTML。
