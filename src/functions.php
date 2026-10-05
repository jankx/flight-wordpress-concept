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
use Jankx\Flight\WordpressConcept\Config;
use Jankx\Flight\WordpressConcept\Db;
use Jankx\Flight\WordpressConcept\Http;
use Jankx\Flight\WordpressConcept\L10n;
use Jankx\Flight\WordpressConcept\Cache;
use Jankx\Flight\WordpressConcept\Hooks\Hooks;
use Jankx\Flight\WordpressConcept\Hooks\Shortcodes;
use Jankx\Flight\WordpressConcept\Text;
use Jankx\Flight\WordpressConcept\WPError;

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

// ── Options / Transient (cache trên bảng wp_options) ─────────────────────────

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return Cache\Options::get($option, $default);
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value): bool
    {
        return Cache\Options::update($option, $value);
    }
}

if (! function_exists('add_option')) {
    function add_option(string $option, mixed $value = ''): bool
    {
        return Cache\Options::add($option, $value);
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        return Cache\Options::delete($option);
    }
}

if (! function_exists('get_site_option')) {
    /**
     * Trên single-site get_network_option() của core rơi về get_option() nên
     * tên option không có prefix. Xem Auth::readOption().
     */
    function get_site_option(string $option, mixed $default = false): mixed
    {
        return Cache\Options::get($option, $default);
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return Cache\Transient::get($transient);
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        return Cache\Transient::set($transient, $value, $expiration);
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        return Cache\Transient::delete($transient);
    }
}

// ── Môi trường ───────────────────────────────────────────────────────────────

if (! function_exists('is_ssl')) {
    function is_ssl(): bool
    {
        if (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}

// ── WP_Error ──────────────────────────────────────────────────────────────────

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        // Kiểm tra theo lớp global: cả WP_Error do extension tạo và WPError
        // của package đều phải qua.
        return $thing instanceof \WP_Error;
    }
}

// ── Bài viết ──────────────────────────────────────────────────────────────────

if (! function_exists('get_post')) {
    function get_post(int|string|object|null $post = null, string $output = 'OBJECT'): mixed
    {
        if ($post === null) {
            // Core dùng $post toàn cục khi không truyền; ở đây không có.
            return new WPError('empty_query', 'Không có bài viết nào được chỉ định.');
        }

        // Đã là đối tượng bài viết rồi (WP_Post, hoặc stdClass do chính hàm này
        // trả về trước đó) – không cần và lại query DB.
        if (is_object($post)) {
            return $post;
        }

        $found = Db\Posts::get($post);

        if ($output === 'ARRAY_A' || $output === 'ARRAY_N') {
            if ($found instanceof WPError) {
                return $found;
            }

            $array = (array) $found;

            return $output === 'ARRAY_A' ? $array : array_values($array);
        }

        return $found;
    }
}

if (! function_exists('get_post_type')) {
    function get_post_type(int|string|object|null $post = null): string|false
    {
        if (is_object($post)) {
            return isset($post->post_type) ? (string) $post->post_type : false;
        }

        if (is_string($post) && ! ctype_digit($post)) {
            $found = Db\Posts::get($post);

            return $found instanceof WPError ? false : (string) $found->post_type;
        }

        $id = $post === null ? 0 : (int) $post;

        return Db\Posts::typeOf($id);
    }
}

if (! function_exists('get_post_field')) {
    function get_post_field(string $field, int|object|null $post = null, string $context = 'display'): string
    {
        $id = is_object($post) ? (int) $post->ID : (int) $post;

        return (string) Db\Posts::field($id, $field, '');
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int|object|null $post = null): string
    {
        if (is_object($post)) {
            return isset($post->post_title)
                ? html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8')
                : '';
        }

        return Db\Posts::title((int) $post);
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int|object|null $post = null): string|false
    {
        $id = is_object($post) ? (int) $post->ID : (int) $post;

        if ($id <= 0) {
            return false;
        }

        $slug = (string) Db\Posts::field($id, 'post_name', '');

        return $slug === '' ? false : home_url('/' . $slug . '/');
    }
}

// ── Meta ──────────────────────────────────────────────────────────────────────

if (! function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
    {
        return Db\Meta::get('user', $userId, $key, $single);
    }
}

if (! function_exists('update_user_meta')) {
    function update_user_meta(int $userId, string $key, mixed $value, mixed $prevValue = ''): bool
    {
        return Db\Meta::update('user', $userId, $key, $value);
    }
}

if (! function_exists('add_user_meta')) {
    function add_user_meta(int $userId, string $key, mixed $value, bool $unique = false): bool
    {
        return Db\Meta::add('user', $userId, $key, $value);
    }
}

if (! function_exists('delete_user_meta')) {
    function delete_user_meta(int $userId, string $key, mixed $value = ''): bool
    {
        return Db\Meta::delete('user', $userId, $key);
    }
}

if (! function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        return Db\Meta::get('post', $postId, $key, $single);
    }
}

if (! function_exists('update_post_meta')) {
    function update_post_meta(int $postId, string $key, mixed $value, mixed $prevValue = ''): bool
    {
        return Db\Meta::update('post', $postId, $key, $value);
    }
}

if (! function_exists('add_post_meta')) {
    function add_post_meta(int $postId, string $key, mixed $value, bool $unique = false): bool
    {
        return Db\Meta::add('post', $postId, $key, $value);
    }
}

if (! function_exists('delete_post_meta')) {
    function delete_post_meta(int $postId, string $key, mixed $value = ''): bool
    {
        return Db\Meta::delete('post', $postId, $key);
    }
}

if (! function_exists('get_term_meta')) {
    function get_term_meta(int $termId, string $key = '', bool $single = false): mixed
    {
        return Db\Meta::get('term', $termId, $key, $single);
    }
}

if (! function_exists('update_term_meta')) {
    function update_term_meta(int $termId, string $key, mixed $value, mixed $prevValue = ''): bool
    {
        return Db\Meta::update('term', $termId, $key, $value);
    }
}

// ── Object cache ──────────────────────────────────────────────────────────────

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
    {
        return Cache\ObjectCache::get($key, $group === '' ? 'default' : $group, $force);
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        return Cache\ObjectCache::set($key, $value, $group === '' ? 'default' : $group, $expire);
    }
}

if (! function_exists('wp_cache_add')) {
    function wp_cache_add(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        return Cache\ObjectCache::add($key, $value, $group === '' ? 'default' : $group, $expire);
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return Cache\ObjectCache::delete($key, $group === '' ? 'default' : $group);
    }
}

if (! function_exists('wp_cache_delete_group')) {
    function wp_cache_delete_group(string $group): bool
    {
        return Cache\ObjectCache::deleteGroup($group);
    }
}

if (! function_exists('wp_cache_incr_group')) {
    function wp_cache_incr_group(string $group, int $offset = 1): void
    {
        Cache\ObjectCache::incrGroup($group, $offset);
    }
}

if (! function_exists('wp_cache_flush')) {
    function wp_cache_flush(): bool
    {
        return Cache\ObjectCache::flush();
    }
}

// ── HTTP ──────────────────────────────────────────────────────────────────────

if (! function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array|WPError
    {
        return Http\Http::get($url, $args);
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|WPError
    {
        return Http\Http::post($url, $args);
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array|WPError $response): string
    {
        if (is_wp_error($response) || ! isset($response['body'])) {
            return '';
        }

        return (string) $response['body'];
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WPError $response): int|string
    {
        if (is_wp_error($response)) {
            return '';
        }

        return (int) ($response['response']['code'] ?? 0);
    }
}

if (! function_exists('wp_remote_retrieve_response_message')) {
    function wp_remote_retrieve_response_message(array|WPError $response): string
    {
        if (is_wp_error($response)) {
            return '';
        }

        return (string) ($response['response']['message'] ?? '');
    }
}

if (! function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers(array|WPError $response): array
    {
        if (is_wp_error($response)) {
            return [];
        }

        return (array) ($response['headers'] ?? []);
    }
}

// ── Chuẩn hoá ────────────────────────────────────────────────────────────────

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title, string $fallbackTitle = '', string $context = 'save'): string
    {
        $title = strip_tags($title);
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = Text::stripAccents($title);
        $title = strtolower($title);

        // Bỏ ký tự bị bỏ trong slug của WordPress.
        $title = preg_replace('/[^a-z0-9\s\-_]/', '', $title) ?? '';
        $title = preg_replace('/[\s_]+/', '-', $title) ?? '';
        $title = preg_replace('/-+/', '-', $title) ?? '';

        $title = trim($title, '-');

        if ($title === '' && $fallbackTitle !== '') {
            return sanitize_title($fallbackTitle);
        }

        return $title;
    }
}



// ── Locale & textdomain ───────────────────────────────────────────────────────

if (! function_exists('get_locale')) {
    function get_locale(): string
    {
        return L10n::locale();
    }
}

if (! function_exists('get_user_locale')) {
    function get_user_locale(int $userId = 0): string
    {
        if ($userId > 0 && function_exists('get_user_meta')) {
            $meta = get_user_meta($userId, 'locale', true);

            if (is_string($meta) && $meta !== '') {
                return $meta;
            }
        }

        return L10n::userLocale();
    }
}

if (! function_exists('determine_locale')) {
    function determine_locale(): string
    {
        return L10n::determine();
    }
}

if (! function_exists('is_locale_switched')) {
    function is_locale_switched(): bool
    {
        return L10n::isSwitched();
    }
}

if (! function_exists('switch_to_locale')) {
    function switch_to_locale(string $locale): bool
    {
        return L10n::switchTo($locale);
    }
}

if (! function_exists('restore_previous_locale')) {
    function restore_previous_locale(): string|false
    {
        return L10n::restorePrevious();
    }
}

if (! function_exists('load_theme_textdomain')) {
    function load_theme_textdomain(string $domain, string $path = ''): bool
    {
        return L10n::loadTextdomain($domain, $path);
    }
}

if (! function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, string|false $deprecated = false, string $path = ''): bool
    {
        // Core đổi thứ tự tham số ở 6.7: (domain, path, deprecated).
        if (is_bool($deprecated) && $path === '') {
            return L10n::loadTextdomain($domain);
        }

        return L10n::loadTextdomain($domain, $path);
    }
}

if (! function_exists('load_textdomain')) {
    function load_textdomain(string $domain, string $mofile, string $language = ''): bool
    {
        return L10n::loadTextdomain($domain, dirname($mofile), $language);
    }
}

if (! function_exists('unload_textdomain')) {
    function unload_textdomain(string $domain): bool
    {
        return L10n::unloadTextdomain($domain);
    }
}

if (! function_exists('is_textdomain_loaded')) {
    function is_textdomain_loaded(string $domain): bool
    {
        return L10n::isTextdomainLoaded($domain);
    }
}

if (! function_exists('get_available_languages')) {
    function get_available_languages(string $domain = 'default'): array
    {
        $path = defined('WP_LANG_DIR') ? WP_LANG_DIR . '/languages' : '';

        if ($path === '' || ! is_dir($path)) {
            return [];
        }

        $languages = [];

        foreach ((array) glob($path . '/*.mo') as $file) {
            $languages[] = basename((string) $file, '.mo');
        }

        return $languages;
    }
}

if (! function_exists('translate_with_gettext_context')) {
    function translate_with_gettext_context(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('translate')) {
    function translate(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(float $number, int $decimals = 0): string
    {
        $thousandSep = (string) get_option('jankx_currency_thousand_sep', ',');
        $decimalSep  = (string) get_option('jankx_currency_decimal_sep', '.');

        return number_format($number, $decimals, $decimalSep, $thousandSep);
    }
}

if (! function_exists('date_i18n')) {
    function date_i18n(string $format, int|false $timestamp = false, bool $gmt = false): string
    {
        $timestamp = $timestamp === false ? time() : $timestamp;

        return gmdate($format, $timestamp);
    }
}

// ── URL ───────────────────────────────────────────────────────────────────────

if (! function_exists('home_url')) {
    function home_url(string $path = '', string $scheme = null): string
    {
        $base = rtrim(Config::load(dirname(__DIR__))->siteUrl(), '/');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('site_url')) {
    function site_url(string $path = '', string $scheme = null): string
    {
        return home_url($path);
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return home_url('/wp-admin/' . ltrim($path, '/'));
    }
}

if (! function_exists('includes_url')) {
    function includes_url(string $path = ''): string
    {
        return home_url('/wp-includes/' . ltrim($path, '/'));
    }
}

if (! function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        return home_url('/wp-content/' . ltrim($path, '/'));
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string|false
    {
        return Config::load(dirname(__DIR__))->salt($scheme);
    }
}

// ── Escape & format ───────────────────────────────────────────────────────────

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        // Chỉ giữ lại thẻ được phép ở nội dung bài viết.
        $allowed = '<a><p><br><b><strong><i><em><u><s><ul><ol><li><blockquote>'
            . '<h1><h2><h3><h4><h5><h6><img><figure><figcaption><table><thead>'
            . '<tbody><tr><th><td><code><pre><span><div><hr><code>';

        return strip_tags($content, $allowed);
    }
}

if (! function_exists('wp_kses')) {
    function wp_kses(string $content, array|string $allowedHtml = [], array $allowedProtocols = []): string
    {
        return is_array($allowedHtml) ? strip_tags($content, '<' . implode('><', $allowedHtml) . '>') : strip_tags($content);
    }
}

if (! function_exists('wpautop')) {
    function wpautop(string $content, bool $br = true): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($content)) ?: [];

        $html = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $html .= '<p>' . ($br ? nl2br($paragraph) : $paragraph) . "</p>\n";
        }

        return $html === '' ? '' : $html;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $content, bool $removeBreaks = false): string
    {
        $content = strip_tags($content);

        return $removeBreaks ? trim(preg_replace('/[\r\n\t ]+/', ' ', $content) ?? $content) : $content;
    }
}

if (! function_exists('wp_specialchars_decode')) {
    function wp_specialchars_decode(string $content, int $quoteStyle = ENT_NOQUOTES): string
    {
        return html_entity_decode($content, $quoteStyle, 'UTF-8');
    }
}

if (! function_exists('wp_trim_words')) {
    function wp_trim_words(string $content, int $numWords = 55, string $more = null): string
    {
        $more ??= '…';

        $words = preg_split('/\s+/', trim(wp_strip_all_tags($content))) ?: [];

        if (count($words) <= $numWords) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, $numWords)) . $more;
    }
}

// ── Shortcode ─────────────────────────────────────────────────────────────────

if (! function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        Shortcodes::add($tag, $callback);
    }
}

if (! function_exists('remove_shortcode')) {
    function remove_shortcode(string $tag): void
    {
        Shortcodes::remove($tag);
    }
}

if (! function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool
    {
        if (! is_string($content) || $content === '') {
            return false;
        }

        return preg_match('/\[' . preg_quote($tag, '/') . '\b/', $content) === 1;
    }
}

if (! function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool
    {
        return Shortcodes::has($tag);
    }
}

if (! function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out  = [];

        foreach ($pairs as $name => $default) {
            $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
        }

        return $out;
    }
}

if (! function_exists('do_shortcode')) {
    function do_shortcode(string $content, bool $ignoreHtml = false): string
    {
        if (! is_string($content) || $content === '') {
            return '';
        }

        // Chỉ hỗ trợ shortcode không có tham số lồng nhau – đủ cho shortcode
        // hiển thị của extension. Không cố parse lồng nhau.
        return (string) preg_replace_callback(
            '/\[([a-z0-9_\-]+)([^\]]*)\]/i',
            static function (array $match): string {
                $tag  = $match[1];
                $atts = shortcode_parse_atts($match[2] ?? '');

                if (! shortcode_exists($tag)) {
                    return $match[0];
                }

                return Shortcodes::render($tag, $atts);
            },
            $content
        );
    }
}

if (! function_exists('shortcode_parse_atts')) {
    function shortcode_parse_atts(string $text): array
    {
        $atts = [];
        $text = preg_replace('/[\[\]]/', '', $text) ?? '';

        if (preg_match_all('/([\w\-]+)\s*=\s*"([^"]*)"|([\w\-]+)\s*=\s*\'([^\']*)\'|([\w\-]+)\s*=\s*(\S+)/', $text, $m, PREG_SET_ORDER) === false) {
            return $atts;
        }

        foreach ($m as $set) {
            if (! empty($set[1])) {
                $atts[strtolower($set[1])] = stripcslashes($set[2]);
            } elseif (! empty($set[3])) {
                $atts[strtolower($set[3])] = stripcslashes($set[4]);
            } elseif (isset($set[5])) {
                $atts[strtolower($set[5])] = stripcslashes($set[6]);
            }
        }

        return $atts;
    }
}

// ── Script & style ────────────────────────────────────────────────────────────
//
// Standalone không có hàng đầu HTML nên các hàm này chỉ ghi nhận đăng ký.
// Controller vẫn gọi được mà không sập.

if (! function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, array $args = []): bool
    {
        return true;
    }
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, array $args = []): void
    {
    }
}

if (! function_exists('wp_register_style')) {
    function wp_register_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): bool
    {
        return true;
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): void
    {
    }
}

if (! function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $objectName, array $l10n): bool
    {
        return true;
    }
}

if (! function_exists('wp_script_is')) {
    function wp_script_is(string $handle, string $status = 'enqueued'): bool
    {
        return false;
    }
}

if (! function_exists('wp_style_is')) {
    function wp_style_is(string $handle, string $status = 'enqueued'): bool
    {
        return false;
    }
}

if (! function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        return true;
    }
}

if (! function_exists('wp_dequeue_script')) {
    function wp_dequeue_script(string $handle): void
    {
    }
}

if (! function_exists('wp_deregister_script')) {
    function wp_deregister_script(string $handle): void
    {
    }
}

// ── Cron ──────────────────────────────────────────────────────────────────────
//
// Không có hàng đời cron ngoài WordPress. Các hàm lịch được ghi vào
// transient để vẫn đọc lại được trong phạm vi hệ thống, nhưng không có gì
// thực thi chúng.

if (! function_exists('wp_get_schedules')) {
    function wp_get_schedules(): array
    {
        return [
            'hourly'     => ['interval' => HOUR_IN_SECONDS, 'display' => 'Mỗi giờ'],
            'twicedaily' => ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'Hai lần mỗi ngày'],
            'daily'      => ['interval' => DAY_IN_SECONDS, 'display' => 'Mỗi ngày'],
        ];
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        $events = get_option('cron', []);

        return $events['jankx/' . $hook]['timestamp'] ?? false;
    }
}

if (! function_exists('wp_get_schedule')) {
    function wp_get_schedule(string $hook, array $args = []): string|false
    {
        $events = get_option('cron', []);

        return $events['jankx/' . $hook]['schedule'] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $events = (array) get_option('cron', []);

        $events['jankx/' . $hook] = [
            'timestamp' => $timestamp,
            'schedule'  => $recurrence,
        ];

        return update_option('cron', $events);
    }
}

if (! function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
    {
        $events = (array) get_option('cron', []);

        $events['jankx/' . $hook] = [
            'timestamp' => $timestamp,
            'schedule'  => false,
        ];

        return update_option('cron', $events);
    }
}

if (! function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook, array $args = []): bool
    {
        $events = (array) get_option('cron', []);

        unset($events['jankx/' . $hook]);

        return update_option('cron', $events);
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        wp_unschedule_event(time(), $hook, $args);

        return 1;
    }
}

// ── Tiện ích ──────────────────────────────────────────────────────────────────

if (! function_exists('wp_parse_args')) {
    function wp_parse_args(mixed $args, array $defaults = []): array
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (! is_array($args)) {
            parse_str((string) $args, $args);
        }

        return array_merge($defaults, $args);
    }
}

if (! function_exists('wp_list_pluck')) {
    function wp_list_pluck(array $list, string $field, int|string|null $indexKey = null): array
    {
        $out = [];

        foreach ($list as $key => $item) {
            $value = is_array($item) ? ($item[$field] ?? null) : ($item->{$field} ?? null);

            if ($indexKey === null) {
                $out[] = $value;
                continue;
            }

            $index = is_array($item) ? ($item[$indexKey] ?? null) : ($item->{$indexKey} ?? null);

            $out[$index ?? $key] = $value;
        }

        return $out;
    }
}

if (! function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return random_int($min, $max);
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        if ($specialChars) {
            $chars .= '!@#$%^&*()';
        }

        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

// ── __return_* ────────────────────────────────────────────────────────────────
//
// Helper chuẩn của WordPress, extension dùng rất nhiều cho add_filter('x',
// '__return_false'). Định nghĩa cả nhóm cho đủ, thiếu một cái là hook chết.

if (! function_exists('__return_true')) {
    function __return_true(): bool
    {
        return true;
    }
}

if (! function_exists('__return_false')) {
    function __return_false(): bool
    {
        return false;
    }
}

if (! function_exists('__return_null')) {
    function __return_null(): mixed
    {
        return null;
    }
}

if (! function_exists('__return_zero')) {
    function __return_zero(): int
    {
        return 0;
    }
}

if (! function_exists('__return_empty_array')) {
    function __return_empty_array(): array
    {
        return [];
    }
}

if (! function_exists('__return_empty_string')) {
    function __return_empty_string(): string
    {
        return '';
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|float|null|false
    {
        return parse_url($url, $component);
    }
}

// Lop global cua WordPress (WP_Block_Type_Registry…) phai nam o global namespace.
require_once __DIR__ . '/compat/blocks.php';
require_once __DIR__ . '/compat/post-types.php';

// ── Escape & sanitize ─────────────────────────────────────────────────────────

if (! function_exists('esc_url_raw')) {
    /**
     * Chuẩn hoá URL để lưu/so sánh. Không html-encode (khác esc_url, vốn dùng
     * khi in ra HTML).
     *
     * @param string $url       URL cần chuẩn hoá.
     * @param array  $protocols Giao thức được phép; mặc định giống core.
     */
    function esc_url_raw(string $url, array $protocols = null): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        // Bỏ ký tự điều khiển, chúng chỉ dùng để lách kiểm tra kiểu URL.
        $url = preg_replace('/[\x00-\x20\x7F]/', '', $url) ?? '';

        if ($url === '') {
            return '';
        }

        $protocols = $protocols ?? ['http', 'https', 'mailto', 'tel', 'ftp', 'ftps'];
        $scheme    = parse_url($url, PHP_URL_SCHEME);

        // URL không có scheme được giữ nguyên (đường dẫn tương đối, //host…).
        if ($scheme === null) {
            return $url;
        }

        if (! in_array(strtolower($scheme), array_map('strtolower', $protocols), true)) {
            return '';
        }

        // Bỏ backslash: browsers hiểu "\/" và "//" như nhau, nên \\evil.com là
        // một cách vượt qua kiểm tra scheme.
        return str_replace('\\', '', $url);
    }
}

if (! function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html($text);
    }
}

if (! function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr($text);
    }
}

if (! function_exists('sanitize_textarea_field')) {
    /**
     * Bỏ tag nhưng giữ nguyên xuống dòng – khác sanitize_text_field.
     */
    function sanitize_textarea_field(string $str): string
    {
        $filtered = strip_tags($str);
        $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered) ?? $filtered;

        return trim($filtered);
    }
}

if (! function_exists('sanitize_user')) {
    /**
     * @param bool $strict Giữ đúng chữ thường (dùng cho username so khớp tuyệt đối).
     */
    function sanitize_user(string $username, bool $strict = false): string
    {
        $username = strip_tags($username);
        $username = preg_replace('/&.+?;/', '', $username) ?? $username;

        if ($strict) {
            $username = preg_replace('/[^a-z0-9 _.\-@]/i', '', $username) ?? $username;
        }

        return trim(preg_replace('/\s+/', ' ', $username) ?? $username);
    }
}

if (! function_exists('sanitize_html_class')) {
    /**
     * @param string $class    Chuỗi class cần làm sạch.
     * @param string $fallback Giá trị trả về nếu kết quả rỗng.
     */
    function sanitize_html_class(string $class, string $fallback = ''): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]/', '', $class) ?? '';

        return $sanitized !== '' ? $sanitized : $fallback;
    }
}

if (! function_exists('sanitize_file_name')) {
    /**
     * Bỏ ký tự không an toàn cho tên file. WP giữ thêm một số ký tự Unicode hợp
     * lệ; ở đây bám theo bản ASCII để tên file luôn an toàn trên mọi hệ thống.
     */
    function sanitize_file_name(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._\-]/', '', $filename) ?? '';

        // Không cho tên file rỗng, "." hoặc "..".
        if ($filename === '' || in_array($filename, ['.', '..'], true)) {
            return '';
        }

        return $filename;
    }
}

if (! function_exists('sanitize_hex_color')) {
    /**
     * Trả về mã màu #rgb/#rrggbb nếu hợp lệ, ngược lại $default.
     */
    function sanitize_hex_color(string $color, string $default = ''): string
    {
        return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color) === 1 ? $color : $default;
    }
}

if (! function_exists('trailingslashit')) {
    function trailingslashit(string $value, string $type = 'single'): string
    {
        return untrailingslashit($value) . '/';
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('user_trailingslashit')) {
    function user_trailingslashit(string $url, string $type = ''): string
    {
        return trailingslashit($url);
    }
}

if (! function_exists('wp_unique_filename')) {
    /**
     * Tên file chưa tồn tại trong thư mục đích.
     *
     * @param string $dir      Thư mục đích.
     * @param string $filename Tên file mong muốn.
     * @param array  $unique   Bộ chống trùng trả về WP_REST_Attachments_Controller.
     */
    function wp_unique_filename(string $dir, string $filename, array $unique = []): string
    {
        $safe = sanitize_file_name($filename);

        if ($safe === '') {
            $safe = 'file';
        }

        $candidate = $safe;
        $suffix    = 1;
        $stem      = pathinfo($safe, PATHINFO_FILENAME);
        $extension = pathinfo($safe, PATHINFO_EXTENSION);

        while (file_exists($dir . '/' . $candidate)) {
            $candidate = $stem . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
            $suffix++;
        }

        return $candidate;
    }
}
require_once __DIR__ . '/compat/nonce.php';
require_once __DIR__ . '/compat/urls.php';
require_once __DIR__ . '/compat/class-wp-error.php';
require_once __DIR__ . '/compat/posts.php';
require_once __DIR__ . '/compat/class-core.php';
