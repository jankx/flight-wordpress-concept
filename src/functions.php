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
use Jankx\Flight\WordpressConcept\Db;
use Jankx\Flight\WordpressConcept\Http;
use Jankx\Flight\WordpressConcept\Cache;
use Jankx\Flight\WordpressConcept\Hooks\Hooks;
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
        return $thing instanceof WPError;
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


