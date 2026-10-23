<?php
/**
 * VOID 图集短代码：在文章/页面中用 [gallery]...[/gallery] 调用主题内置的
 * void-gallery 自动排版（justified 照片墙），支持一文多组、随主题超高级设置
 * largePhotoSet 联动加宽。不修改主题任何文件。
 *
 * 注意：上传至站点时请将本插件目录重命名为 VoidGallery
 * （Typecho 要求插件目录名与类名前缀一致，目录名中不能含连字符）。
 *
 * @package VoidGallery
 * @author TreeRequiem
 * @version 1.0.0
 * @link https://github.com/TreeRequiem/VOID-Gallery-Plugin
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class VoidGallery_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件：注册钩子。
     *
     * @return string
     */
    public static function activate()
    {
        // 主题 functions.php 中 VOID_registerContentsHook 同时兼容
        // Typecho 1.2（Widget_Abstract_Contents）与 1.3（Widget\Base\Contents）
        // 两种类名；插件做同样的双注册并按规范化句柄去重，
        // 避免类别名导致同一回调被执行两次。
        $targets = array('Widget_Abstract_Contents');
        if (class_exists('Widget\Base\Contents')) {
            $targets[] = 'Widget\Base\Contents';
        }

        $registered = array();
        foreach ($targets as $target) {
            $normalized = self::normalizeHandle($target);
            if (isset($registered[$normalized])) {
                continue;
            }
            // 正文解析：把 [gallery] 短代码转换为 void-gallery 容器
            Typecho_Plugin::factory($target)->contentEx = array('VoidGallery_Plugin', 'parseShortcode');
            // 摘要解析：移除短代码，避免摘要中残留原始文本
            Typecho_Plugin::factory($target)->excerptEx = array('VoidGallery_Plugin', 'cleanExcerpt');
            $registered[$normalized] = true;
        }

        // 前台页脚输出：多图集补丁 JS + 宽度补齐 CSS。
        // VOID 主题 includes/footer.php 末尾会调用 $this->footer()，
        // 且位置在主题主 JS 之后，脚本时序安全。
        Typecho_Plugin::factory('Widget_Archive')->footer = array('VoidGallery_Plugin', 'footer');

        return _t('VOID 图集短代码插件已启用，请确认当前使用的主题为 VOID。');
    }

    /**
     * 停用插件。
     */
    public static function deactivate() {}

    /**
     * 插件配置面板（本插件无配置项）。
     */
    public static function config(Typecho_Widget_Helper_Form $form) {}

    /**
     * 个人配置面板（本插件无配置项）。
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 正文钩子：解析 [gallery]...[/gallery] 短代码。
     *
     * 执行时机说明：Typecho 在初始化阶段加载并注册插件，主题的 functions.php
     * 在渲染阶段才被加载，因此本回调先于主题的 Contents::contentEx 执行。
     * 本回调先把短代码转换为 <article class="void-gallery"> 外壳，
     * 随后主题的 parseImages 会把外壳内的 <img> 转换为
     * figure[data-void-image-item]——即 VOID_Gallery 排版所需的语义结构。
     *
     * @param mixed  $data   原始内容
     * @param object $widget 当前内容 Widget
     * @param mixed  $last   上游钩子回调的产出（钩子链传参）
     * @return mixed
     */
    public static function parseShortcode($data, $widget, $last)
    {
        $text = self::filteredText($data, $last);
        if (!is_string($text) || false === stripos($text, '[gallery')) {
            return $text;
        }

        // 壳的 class 与主题 [photos] 短代码（Contents::parsePhotoSet）保持一致：
        // 超高级设置 largePhotoSet 为 true 时用 "photos large"（宽于正文栏），
        // 否则用 "photos"（正文栏宽）。宽度完全由主题既有 CSS 规则实现：
        //   article .photos.large { width:1024/1200px; margin-left: 负值 }
        //   .sidebar-show article .photos { width:100%!important; margin-left:0 }
        $setting = isset($GLOBALS['VOIDSetting']) && is_array($GLOBALS['VOIDSetting'])
            ? $GLOBALS['VOIDSetting'] : array();
        $wrapClass = !empty($setting['largePhotoSet']) ? 'photos large' : 'photos';

        // 正则形态与主题 parsePhotoSet 保持一致：
        // 兼容 Markdown 把独立的短代码行包进 <p> 的情况；
        // 开标签允许 [gallery] / [gallery ] / [gallery 任意备注] 等写法。
        // preg_replace_callback 为全局替换，天然支持一篇文章中任意多组。
        $reg = '/(?:<p>\s*)?\[gallery(?=\s|\])[^\]]*\](.*?)\[\/gallery\s*\](?:\s*<\/p>)?/is';

        return preg_replace_callback($reg, function ($matches) use ($wrapClass) {
            // 与主题 parsePhotoSetCallBack 相同的预处理：
            // 去掉自动换行 <br> 与段落标签，只保留图片等元素。
            // 约定 [gallery] 内只放图片（Markdown 图片语法），不写文字段落。
            $inner = preg_replace('/<br\s*\/?>/i', '', $matches[1]);
            $inner = str_replace(array('<p>', '</p>'), '', $inner);

            // 关键修复：剥离 [gallery] 内部嵌套的 [photos] / [/photos] 标记。
            //
            // 背景：gallery 的排版语义是“所有图片展平后统一 justified 分行”，
            // 内层 [photos] 的分组（single/pair/strip）在 gallery 中本就无意义
            // （Gallery 独立页面的呈现效果也是如此）。若保留这些标记，主题
            // parsePhotoSet 会在它们外面包出 data-void-photo-layout="strip"
            // 的 photos 集合 div，而 JS 阶段 VOID_PhotoSets.init() 会给 strip
            // 集合套上横滑条（void-photo-strip-frame）：
            //   - 页面首个图集由主题 VOID_Gallery.init() 处理，其内部固定顺序
            //     是 Gallery 先于 PhotoSets，flatten 能抢先解包，不受影响；
            //   - 第 2..N 个图集由本插件补丁处理，补丁晚于 PhotoSets 执行，
            //     flatten 只解包 [data-void-photo-set]，图片会残留在 strip-frame
            //     内，createSets 只认 gallery 根的直接子级 figure，找不到图片
            //     → 不产生照片墙（实测表现为残留横滑条）。
            // 在 PHP 阶段移除嵌套标记，photos 集合根本不会产生，彻底消除对
            // JS 执行顺序的依赖。(?=\s|\]) 防止误伤 [photosize] 之类的文本。
            $inner = preg_replace('/\[photos(?=\s|\])[^\]]*\]/i', '', $inner);
            $inner = preg_replace('/\[\/photos\s*\]/i', '', $inner);

            $inner = trim($inner);
            if ('' === $inner) {
                return '';
            }

            // 输出结构说明：
            // 1) 外层 div.photos[.large] 只负责提供宽度上下文：
            //    - 不带 data-void-photo-set，因此 VOID_PhotoSets 不会接管它，
            //      [photos] 短代码三张及以上生成横向滑动条的能力完全不受影响；
            //    - 在 Feed 中会被主题按 photos 容器规则解包保留图片，
            //      在摘要中会被连同内容移除——行为与 [photos] 完全一致。
            // 2) 内层 article[data-void-gallery] 是主题 VOID_Gallery 的识别标记，
            //    前端由 VOID_Gallery（首个）+ 本插件补丁（其余）完成 justified 排版。
            return '<div class="' . $wrapClass . '"><article class="void-gallery" data-void-gallery>'
                . $inner . '</article></div>';
        }, $text);
    }

    /**
     * 摘要钩子：清理短代码。
     *
     * 两种情况均被覆盖：
     * - Typecho 1.3 的摘要基于已渲染正文生成：本回调先执行时短代码已被
     *   parseShortcode 渲染为 div.photos 容器，稍后主题的 excerptEx 会用
     *   transformPhotoSetContainers 将该容器连同内容移除；
     * - 摘要基于原始正文生成：这里直接把短代码块（含内部图片标记）移除。
     *
     * @param mixed  $data   原始内容
     * @param object $widget 当前内容 Widget
     * @param mixed  $last   上游钩子回调的产出
     * @return mixed
     */
    public static function cleanExcerpt($data, $widget, $last)
    {
        $text = self::filteredText($data, $last);
        if (!is_string($text) || false === stripos($text, '[gallery')) {
            return $text;
        }

        return preg_replace(
            '/(?:<p>\s*)?\[gallery(?=\s|\])[^\]]*\].*?\[\/gallery\s*\](?:\s*<\/p>)?/is',
            '',
            $text
        );
    }

    /**
     * 前台页脚输出：宽度补齐 CSS + 多图集补丁 JS。
     *
     * 直接 echo，Typecho 会在 $this->footer() 处输出。
     */
    public static function footer()
    {
        echo <<<'HTML'
<!-- VoidGallery 插件输出：宽度补齐 + 多图集排版补丁 -->
<style>
/* 补齐宽度微差：主题 .void-gallery 自带 padding:0 24px（Gallery 独立页面需要），
   放进 photos 壳后会比纯 [photos] 图集窄 48px。壳内归零使两者严格同宽。
   该选择器不影响 Gallery 页面（其结构为 .void-gallery-page > .void-gallery）。 */
.photos > .void-gallery { padding-left: 0; padding-right: 0; }
</style>
<script>
(function () {
    'use strict';

    // 防止 footer 被重复输出（如嵌套调用）导致重复初始化
    if (window.__voidGalleryMultiPatched) return;
    window.__voidGalleryMultiPatched = true;

    /**
     * 背景与原理：
     * 主题的 VOID_Gallery 是全局单例，其 init() 用 document.querySelector
     * 只取页面中第一个 [data-void-gallery] 进行排版；第 2..N 个图集容器
     * 会被完全忽略（不会被加上 is-ready，也没有 set/row 结构）。
     *
     * 但 VOID_Gallery 的全部布局方法（normalize / createSets / layoutSet /
     * layout / refreshProgressiveDisplay / ensureLoadMoreControl 等）都只通过
     * this 访问状态。因此本补丁为每个额外的图集容器用
     * Object.create(VOID_Gallery) 派生一个代理实例，重置自有状态后复用
     * 原型上的全部布局算法，得到与主题原生完全一致的排版效果。
     */

    /**
     * 扫描并为第 2..N 个图集容器初始化排版。
     * 幂等：已处理的容器带 is-ready 会被跳过，可放心重复调用。
     */
    function initExtraGalleries() {
        if (!window.VOID_Gallery) return; // 主题 JS 未加载时静默退出
        var els = document.querySelectorAll('[data-void-gallery]');
        // 索引 0（文档中第一个图集）始终交给主题 VOID_Gallery.init() 处理，
        // 这样既不与单例冲突，也避免抢在主题初始化之前重复接管。
        for (var i = 1; i < els.length; i++) {
            var el = els[i];
            if (el.classList.contains('is-ready')) continue;
            // 防御：跳过嵌套在另一个图集容器内部的情况（无意义场景）
            if (el.parentNode && el.parentNode.closest
                && el.parentNode.closest('[data-void-gallery]')) continue;
            setupGallery(el);
        }
    }

    /**
     * 防御性修复：拆除容器内可能已存在的横滑条增强结构。
     *
     * VOID_PhotoSets.init() 可能先于本补丁执行（主题 ready 先注册先运行），
     * 它会把容器内 data-void-photo-layout="strip" 的 photos 集合套上
     * void-photo-strip-frame 并注入滚动按钮。gallery 语义下不需要横滑条：
     * 这里移除按钮、把 frame 的剩余子节点原位提升，恢复成 flattenPhotoSets
     * 可继续解包的结构。主修复在 PHP 端（剥离嵌套 [photos] 标记），本函数
     * 仅兜底“手写 HTML 塞入 photos 集合”等未经 PHP 处理的极端场景。
     *
     * @param {Element} root gallery 容器元素
     */
    function teardownStripFrames(root) {
        var frames = root.querySelectorAll('[data-void-photo-strip-frame]');
        for (var i = frames.length - 1; i >= 0; i--) {
            var frame = frames[i];
            var parent = frame.parentNode;
            if (!parent) continue;
            // 移除 PhotoSets 注入的滚动控制按钮
            var controls = frame.querySelectorAll('.void-photo-strip-controls');
            for (var j = 0; j < controls.length; j++) {
                if (controls[j].parentNode) {
                    controls[j].parentNode.removeChild(controls[j]);
                }
            }
            // 将 frame 剩余子节点（photos 集合 div 或 figure）原位提升后移除 frame
            while (frame.firstChild) {
                parent.insertBefore(frame.firstChild, frame);
            }
            parent.removeChild(frame);
        }
    }

    /**
     * 为单个图集容器派生代理实例并执行排版。
     *
     * @param {Element} el 带 data-void-gallery 的容器元素
     */
    function setupGallery(el) {
        // 先拆横滑条，再交给 normalize → flattenPhotoSets 解包 photos 集合
        teardownStripFrames(el);

        var g = Object.create(VOID_Gallery);

        // —— 初始化实例自有状态（字段与主题 init/teardown 中使用的保持一致）——
        g.root = el;
        g.sets = [];
        g.rows = [];
        g.hiddenLeadNodes = [];
        g.hiddenSets = [];
        g.revealedItemCount = 0;
        g.restoredRevealCount = 0;
        g.rafId = null;
        g.rafUsesTimeout = false;
        g.generation = 0;
        g.loadMoreControl = null;
        g.loadMoreButton = null;
        g.loadMoreLabel = null;
        g.loadMoreCount = null;
        g.loadMoreStatus = null;
        g.loadMoreHandler = null;
        g.generatedRootId = false;
        g.observedWidth = el.clientWidth;
        g.observedViewportHeight = g.getViewportHeight();

        // 主题渐进显示进度的 sessionStorage key 按 pathname 生成，
        // 同一页面多个图集会互相串扰；补丁实例不做写入与恢复。
        //（首个图集仍由主题正常存取，不受影响。）
        g.readStoredRevealCount = function () { return 0; };
        g.storeRevealCount = function () {};

        // DOM 规范化 + 分组 + 排版（全部复用原型方法）：
        // normalize 会解包 figure 外层的 <p>、移除图片之间的 <br>/空 <p>，
        // 并按“连续图片”分组为 .void-gallery-set；layout 再按贪心算法
        // 把每组排成 .void-gallery-row 并写入每张图片的宽高 CSS 变量。
        g.normalize(el);
        if (g.sets.length) {
            g.layout();

            // 图片加载完成后重排：figure 尺寸由主题 VOID_PhotoSets.hydrateFigure
            // 在 load 时写入 data-void-image-width/height（先注册先执行），
            // 我们在下一帧基于准确宽高比重新布局。
            // 注意不能使用原型上的 scheduleLayout——其回调硬编码引用
            // VOID_Gallery 单例，会错误地对首个图集重排。
            el.addEventListener('load', function (e) {
                if (e.target && e.target.tagName === 'IMG' && el.contains(e.target)) {
                    requestAnimationFrame(function () { g.layout(); });
                }
            }, true);

            // 容器宽度变化（窗口缩放、侧栏开合等）时重排
            if (window.ResizeObserver) {
                var ro = new ResizeObserver(function (entries) {
                    // PJAX 替换 DOM 后元素已脱离文档，自清理避免泄漏
                    if (!document.contains(el)) { ro.disconnect(); return; }
                    var w = entries[0] && entries[0].contentRect
                        ? entries[0].contentRect.width : el.clientWidth;
                    if (Math.abs(w - g.observedWidth) < 0.5) return;
                    g.observedWidth = w;
                    requestAnimationFrame(function () { g.layout(); });
                });
                ro.observe(el);
            }
        }
        el.classList.add('is-ready');
    }

    // —— 触发时机 ——

    // 1) 首屏：本脚本由 footer 钩子输出，位于主题主 JS 之后；
    //    主题的 ready 回调先注册先执行，setTimeout 0 再兜底，
    //    确保主题 VOID_Gallery.init() 已处理首个图集。
    function boot() { setTimeout(initExtraGalleries, 0); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // 2) PJAX：主题使用自研 VoidPjax，未公开完成事件，这里用
    //    MutationObserver 监听 DOM 替换（PJAX 只替换 #pjax-container 内容，
    //    观察 body 子树即可覆盖）。扫描幂等：is-ready 与索引 0 均被跳过，
    //    排版自身产生的 DOM 变动会再次触发观察但立即收敛，不会死循环。
    var scheduled = false;
    var observer = new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        setTimeout(function () {
            scheduled = false;
            initExtraGalleries();
        }, 50);
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // 3) 双保险：若主题的 VOID.afterPjax 以方法名形式被 PJAX 调用，
    //    包一层也能覆盖到；与 MutationObserver 并存无副作用（幂等）。
    if (window.VOID && typeof VOID.afterPjax === 'function') {
        var origAfterPjax = VOID.afterPjax;
        VOID.afterPjax = function () {
            origAfterPjax.apply(this, arguments);
            initExtraGalleries();
        };
    }
})();
</script>
HTML;
    }

    /**
     * 取钩子链中的有效文本：$last 为上游回调产出，为空时使用原始 $data。
     *
     * @param mixed $data
     * @param mixed $last
     * @return mixed
     */
    private static function filteredText($data, $last)
    {
        return (is_string($last) && '' !== $last) ? $last : $data;
    }

    /**
     * 规范化插件句柄，逻辑与主题 functions.php 中
     * VOID_normalizePluginHandle 一致，用于兼容 Typecho 1.2/1.3 类别名。
     *
     * @param string $handle
     * @return string
     */
    private static function normalizeHandle($handle)
    {
        if (defined('__TYPECHO_CLASS_ALIASES__')) {
            $alias = array_search('\\' . ltrim($handle, '\\'), __TYPECHO_CLASS_ALIASES__, true);
            if (false !== $alias) {
                $handle = $alias;
            }
        }

        if (class_exists('Typecho\Common')) {
            return Typecho\Common::nativeClassName($handle);
        }

        return trim(str_replace('\\', '_', $handle), '_');
    }
}
