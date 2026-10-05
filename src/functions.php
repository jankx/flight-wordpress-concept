<?php

/**
 * API tương thích WordPress cho entry Ajax.
 *
 * Extension của Jankx viết theo API WordPress (`add_action`, `current_user_can`,
 * `esc_html`…). File này cung cấp các hàm đó khi không có WordPress, mỗi hàm
 * đều guard bằng function_exists() đúng như pluggable.php của core – nếu
 * WordPress đã nạp (đường fallback chạy trong WP) thì không định nghĩa lại.
 *
 * Phạm vi cố ý giới hạn: những gì extension cần để chạy trong một request Ajax
 * và trả JSON. Truy vấn nội dung (get_posts, get_user…) nằm ở các lớp riêng.
 *
 * @package Jankx\Flight\WordpressConcept
 */

declare(strict_types=1);

use Jankx\Flight\WordpressConcept\Auth\Auth;
use Jankx\Flight\WordpressConcept\Hooks\Hooks;

// ── Hooks ────────────────────────────────────────────────────────────────────

if (! function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        Hooks::add($tag, $callback, $priority, $acceptedArgs);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        Hooks::add($tag, $callback, $priority, $acceptedArgs);
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $tag, callable $callback, int $priority = 10): bool
    {
        return Hooks::remove($tag, $callback, $priority);
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $tag, callable $callback, int $priority = 10): bool
    {
        return Hooks::remove($tag, $callback, $priority);
    }
}

if (! function_exists('has_action')) {
    function has_action(string $tag, ?callable $callback = null): bool
    {
        return Hooks::has($tag, $callback);
    }
}

if (! function_exists('has_filter')) {
    function has_filter(string $tag, ?callable $callback = null): bool
    {
        return Hooks::has($tag, $callback);
    }
}

if (! function_exists('do_action')) {
    function do_action(string $tag, mixed ...$args): void
    {
        Hooks::doAction($tag, $args);
    }
}

if (! function_exists('do_action_ref_array')) {
    function do_action_ref_array(string $tag, array $args): void
    {
        Hooks::doAction($tag, $args);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return Hooks::applyFilters($tag, array_merge([$value], $args));
    }
}

if (! function_exists('apply_filters_ref_array')) {
    function apply_filters_ref_array(string $tag, array $args): mixed
    {
        return Hooks::applyFilters($tag, $args);
    }
}

if (! function_exists('did_action')) {
    function did_action(string $tag): int
    {
        return Hooks::didAction($tag);
    }
}

if (! function_exists('doing_action')) {
    function doing_action(?string $tag = null): bool
    {
        return $tag === null ? Hooks::current() !== null : Hooks::doing($tag);
    }
}

if (! function_exists('current_filter')) {
    function current_filter(): string|array|null
    {
        return Hooks::current();
    }
}

// ── Ngữ cảnh request ─────────────────────────────────────────────────────────

if (! function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return true;
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

// ── Người dùng ───────────────────────────────────────────────────────────────

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return Auth::check();
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return Auth::id();
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return Auth::can($capability);
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        return Auth::user() ?? (object) ['ID' => 0];
    }
}

// ── Escape / sanitize ────────────────────────────────────────────────────────
//
// Bản tối giản, đủ cho dữ liệu trả về JSON. Không phải bản đầy đủ của core:
// kses, context cụ thể theo hook và filter của plugin đều không có.

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return esc_attr($text);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth);
    }
}

if (! function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (! is_array($args)) {
            $args = [];
        }

        return array_merge($defaults, $args);
    }
}

// ── Dịch ─────────────────────────────────────────────────────────────────────
//
// Entry Ajax chỉ trả JSON nên không nạp textdomain: __() trả nguyên chuỗi.
// Chuỗi nào cần bản dịch phải được dịch ở tầng render (PHP khi render trang).

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo $text; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}

if (! function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}
