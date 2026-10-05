<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Hooks;

/**
 * Shortcodes – lưu shortcode trên Hooks để vẫn chạy được filter.
 *
 * WordPress lưu callback shortcode trong bảng `shortcodes` riêng, nhưng khi
 * render lại đi qua apply_filters("shortcode_{$tag}") nên extension vẫn có thể
 * can thiệp. Ở đây giữ cả hai: Hooks làm nơi lưu (để hook hoạt động như WP), và
 * một registry nhỏ để has()/remove() tra cứu được – vì Hooks::remove() cần
 * đúng instance callable, mà ta không giữ lại callable gốc của người gọi.
 *
 * @package Jankx\Flight\WordpressConcept\Hooks
 */
final class Shortcodes
{
    /** @var array<string, callable> wrapper đã đăng ký trên Hooks */
    private static array $wrappers = [];

    public static function add(string $tag, callable $callback): void
    {
        if (isset(self::$wrappers[$tag])) {
            self::remove($tag);
        }

        // Wrapper là closure, không phải callback gốc: apply_filters truyền
        // (null, $atts, $tag) giống shortcode_tag của core.
        $wrapper = static function (mixed $content = null, mixed $atts = [], string $currentTag = '') use ($callback): string {
            return (string) $callback(is_array($atts) ? $atts : [], $content, $currentTag);
        };

        self::$wrappers[$tag] = $wrapper;

        Hooks::add("shortcode_{$tag}", $wrapper, 10, 3);
    }

    public static function remove(string $tag): bool
    {
        if (! isset(self::$wrappers[$tag])) {
            return false;
        }

        Hooks::remove("shortcode_{$tag}", self::$wrappers[$tag]);
        unset(self::$wrappers[$tag]);

        return true;
    }

    public static function has(string $tag): bool
    {
        return isset(self::$wrappers[$tag]);
    }

    public static function render(string $tag, array $atts, string $content = null): string
    {
        return (string) Hooks::applyFilters("shortcode_{$tag}", [$content, $atts, $tag]);
    }

    /**
     * @return string[]
     */
    public static function tags(): array
    {
        return array_keys(self::$wrappers);
    }

    public static function reset(): void
    {
        foreach (array_keys(self::$wrappers) as $tag) {
            self::remove($tag);
        }
    }
}
