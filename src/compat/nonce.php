<?php

/**
 * Compat: nonce & hash (global namespace).
 *
 * KHÔNG được trả về giá trị hằng cho verify_nonce. Nonce là hàng rào CSRF của
 * các endpoint Ajax trong theme; nếu ở đây luôn trả true thì mọi yêu cầu giả
 * lập đều qua, tức là mất bảo mật âm thầm. Vì vậy bản này bám sát thuật toán
 * của WordPress: tick + action + user id + session token, HMAC bằng salt thật.
 *
 * Nonce sinh ra ở đây phải verify được cả ở request WordPress đầy đủ, và ngược
 * lại – cùng công thức, cùng salt, cùng cookie nên nonce tạo trong WP dùng
 * được cho endpoint Ajax của Flight.
 *
 * @package Jankx\Flight\WordpressConcept
 */

use Jankx\Flight\WordpressConcept\Auth\Auth;
use Jankx\Flight\WordpressConcept\Config;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

if (! function_exists('wp_hash')) {
    /**
     * HMAC của dữ liệu theo scheme (giống wp-includes/pluggable.php).
     */
    function wp_hash(string $data, string $scheme = 'auth'): string
    {
        return hash_hmac('md5', $data, wp_salt($scheme));
    }
}

if (! function_exists('wp_get_session_token')) {
    /**
     * Phần token trong cookie wordpress_logged_in_*: <login>|<hết hạn>|<token>|<hmac>.
     *
     * Token nằm trong nonce để một session đăng nhập không dùng lại được nonce
     * của session khác, kể cả khi action trùng.
     */
    function wp_get_session_token(): string
    {
        $cookieName = 'wordpress_logged_in_' . Config::load(dirname(__DIR__))->cookieHash();
        $cookie     = $_COOKIE[$cookieName] ?? '';

        if (! is_string($cookie) || $cookie === '') {
            return '';
        }

        return explode('|', $cookie)[2] ?? '';
    }
}

if (! function_exists('wp_nonce_tick')) {
    /**
     * Tick đổi mỗi nonce_life/2 giây. Nonce chỉ sống nửa vòng để khoảng cách giữa
     * lúc sinh và lúc kiểm không quá xa.
     */
    function wp_nonce_tick(): int
    {
        $nonceLife = (int) apply_filters('nonce_life', DAY_IN_SECONDS);

        return (int) ceil(time() / ($nonceLife / 2));
    }
}

if (! function_exists('wp_create_nonce')) {
    /**
     * @param int|string $action
     */
    function wp_create_nonce(int|string $action = -1): string
    {
        $uid   = Auth::id();
        $token = wp_get_session_token();

        return substr(wp_hash(wp_nonce_tick() . '|' . $action . '|' . $uid . '|' . $token, 'nonce'), -12, 10);
    }
}

if (! function_exists('wp_verify_nonce')) {
    /**
     * @param  int|string     $action
     * @return int|false 1 nếu mới sinh, 2 nếu thuộc tick trước, false nếu sai.
     */
    function wp_verify_nonce(string $nonce, int|string $action = -1): int|false
    {
        $nonce = trim($nonce);

        if ($nonce === '') {
            return false;
        }

        $uid   = Auth::id();
        $token = wp_get_session_token();
        $tick  = wp_nonce_tick();

        // Chấp nhận cả tick hiện tại lẫn tick trước để nonce không hỏng ở ranh
        // giới, đúng như core.
        if (hash_equals(substr(wp_hash($tick . '|' . $action . '|' . $uid . '|' . $token, 'nonce'), -12, 10), $nonce)) {
            return 1;
        }

        if (hash_equals(substr(wp_hash(($tick - 1) . '|' . $action . '|' . $uid . '|' . $token, 'nonce'), -12, 10), $nonce)) {
            return 2;
        }

        return false;
    }
}

if (! function_exists('wp_nonce_field')) {
    /**
     * @param bool $display true thì echo ra luôn (WP nonce_field cũng vậy).
     */
    function wp_nonce_field(int|string $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';

        if ($referer) {
            $field .= '<input type="hidden" name="_wp_http_referer" value="' . esc_attr($_SERVER['REQUEST_URI'] ?? '') . '" />';
        }

        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (! function_exists('wp_nonce_url')) {
    /**
     * @param string $actionurl
     */
    function wp_nonce_url(string $actionurl, int|string $action = -1, string $name = '_wpnonce'): string
    {
        return add_query_arg($name, wp_create_nonce($action), $actionurl);
    }
}

if (! function_exists('check_ajax_referer')) {
    /**
     * Giống core: dừng request (die) khi nonce sai.
     *
     * @param int|string $action
     * @param false|string $queryArg Tên field cần đọc, false để đọc mặc định.
     * @param bool       $stop
     */
    function check_ajax_referer(int|string $action = -1, string|false $queryArg = false, bool $stop = true): int|false
    {
        $nonce = '';

        if ($queryArg && isset($_REQUEST[$queryArg])) {
            $nonce = (string) $_REQUEST[$queryArg];
        } elseif (isset($_REQUEST['_ajax_nonce'])) {
            $nonce = (string) $_REQUEST['_ajax_nonce'];
        } elseif (isset($_REQUEST['_wpnonce'])) {
            $nonce = (string) $_REQUEST['_wpnonce'];
        }

        $result = wp_verify_nonce($nonce, $action);

        if (! $result && $stop) {
            wp_die(-1, 403);
        }

        return $result;
    }
}

if (! function_exists('check_admin_referer')) {
    function check_admin_referer(int|string $action = -1, string $queryArg = '_wpnonce'): int|false
    {
        return check_ajax_referer($action, $queryArg);
    }
}

if (! function_exists('wp_set_current_user')) {
    /**
     * Ép user hiện tại trong request (dùng cho cron/cli và test).
     */
    function wp_set_current_user(int $id, string $name = ''): object|false
    {
        return Auth::forceLogin($id);
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return Auth::id();
    }
}
