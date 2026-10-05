<?php

/**
 * Compat: URL của REST, plugin, tài khoản và media (global namespace).
 *
 * Extension dựng link tới endpoint REST và tới trang đăng nhập để trả về cho
 * phía JS. Giá trị chỉ cần đúng hình dạng URL – request Ajax không đi qua các
 * endpoint này – nhưng phải dùng siteUrl thật, không hard-code.
 *
 * @package Jankx\Flight\WordpressConcept
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

if (! function_exists('rest_url')) {
    function rest_url(string $path = '', string $scheme = 'rest'): string
    {
        $base = home_url('/wp-json');

        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('get_rest_url')) {
    function get_rest_url(int|null $blogId = null, string $path = '', string $scheme = 'rest'): string
    {
        return rest_url($path, $scheme);
    }
}

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        $base = home_url('/wp-content/plugins');

        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (! function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = '', bool $forceReauth = false): string
    {
        $url = home_url('/wp-login.php');

        return $redirect === '' ? $url : add_query_arg('redirect_to', urlencode($redirect), $url);
    }
}

if (! function_exists('wp_registration_url')) {
    function wp_registration_url(): string
    {
        return home_url('/wp-login.php?action=register');
    }
}

if (! function_exists('wp_lostpassword_url')) {
    function wp_lostpassword_url(string $redirect = ''): string
    {
        $url = home_url('/wp-login.php?action=lostpassword');

        return $redirect === '' ? $url : add_query_arg('redirect_to', urlencode($redirect), $url);
    }
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachmentId): string|false
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        if (! is_string($file) || $file === '') {
            return false;
        }

        return home_url('/wp-content/uploads/' . ltrim($file, '/'));
    }
}

if (! function_exists('wp_get_attachment_image_url')) {
    /**
     * @param string $size Kích thước đã đăng ký trong WP; Flight không tạo
     *                     biến thể ảnh nên luôn trả về ảnh gốc.
     */
    function wp_get_attachment_image_url(int $attachmentId, string $size = 'thumbnail', bool $icon = false): string|false
    {
        return wp_get_attachment_url($attachmentId);
    }
}

if (! function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url(int|null $postId = null, string $size = 'post-thumbnail'): string|false
    {
        $thumbnailId = (int) get_post_thumbnail_id($postId ?? 0);

        return $thumbnailId > 0 ? wp_get_attachment_url($thumbnailId) : false;
    }
}

if (! function_exists('get_avatar_url')) {
    /**
     * Trả về avatar đã lưu trong meta, không gọi dịch vụ ngoài.
     *
     * @param mixed $id_or_email User ID, email hoặc đối tượng comment.
     * @param array $args        'size' và 'default'.
     */
    function get_avatar_url(mixed $idOrEmail, array $args = []): string
    {
        $size    = (int) ($args['size'] ?? 96);
        $default = (string) ($args['default'] ?? 'mystery');

        if (is_numeric($idOrEmail)) {
            $avatar = get_user_meta((int) $idOrEmail, 'wp_user_avatar', true);
        } elseif (is_string($idOrEmail) && is_email($idOrEmail)) {
            $avatar = get_user_meta((int) get_user_by('email', $idOrEmail), 'wp_user_avatar', true);
        } elseif (is_object($idOrEmail) && isset($idOrEmail->user_id)) {
            $avatar = get_user_meta((int) $idOrEmail->user_id, 'wp_user_avatar', true);
        } else {
            $avatar = '';
        }

        if (is_string($avatar) && $avatar !== '') {
            return $avatar;
        }

        // Gravatar theo email, giống hành vi mặc định của WordPress.
        $email = is_string($idOrEmail) && is_email($idOrEmail) ? $idOrEmail : '';
        $hash  = $email === '' ? '' : md5(strtolower(trim($email)));

        return 'https://secure.gravatar.com/avatar/' . $hash . '?s=' . $size . '&d=' . rawurlencode($default);
    }
}

if (! function_exists('get_privacy_policy_url')) {
    function get_privacy_policy_url(): string
    {
        $pageId = (int) get_option('wp_page_for_privacy_policy', 0);

        return $pageId > 0 ? get_permalink($pageId) : home_url('/privacy-policy');
    }
}

if (! function_exists('add_query_arg')) {
    /**
     * Thêm/siêu tham số vào query string, giữ nguyên phần còn lại của URL.
     *
     * @param string|array $key   Một cặp hoặc mảng cặp.
     * @param string       $value Chỉ dùng khi $key là chuỗi.
     * @param string|false $url   URL gốc; mặc định lấy từ $_SERVER.
     * @return string|false
     */
    function add_query_arg(mixed $key, mixed $value = '', string|false $url = false)
    {
        if (is_array($key)) {
            $pairs = $key;
        } elseif ($url === false || $url === '') {
            $url   = 'http' . (is_ssl() ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
            $pairs = [$key => $value];
        } else {
            $pairs = [$key => $value];
        }

        $fragment = '';

        if (str_contains((string) $url, '#')) {
            [$url, $fragment] = explode('#', (string) $url, 2);
            $fragment         = '#' . $fragment;
        }

        $parts = explode('?', (string) $url, 2);
        $base  = $parts[0];
        parse_str($parts[1] ?? '', $query);

        foreach ($pairs as $name => $item) {
            if ($item === false || $item === null) {
                unset($query[$name]);
                continue;
            }

            $query[$name] = $item;
        }

        $queryString = http_build_query($query);

        return $base . ($queryString === '' ? '' : '?' . $queryString) . $fragment;
    }
}

if (! function_exists('remove_query_arg')) {
    /**
     * @param string|string[] $key
     */
    function remove_query_arg(string|array $key, string|false $url = false)
    {
        $url  = $url === false || $url === '' ? ($_SERVER['REQUEST_URI'] ?? '') : $url;
        $keys = array_flip((array) $key);

        $fragment = '';

        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment         = '#' . $fragment;
        }

        $parts = explode('?', $url, 2);
        parse_str($parts[1] ?? '', $query);

        foreach ($keys as $name) {
            unset($query[$name]);
        }

        $queryString = http_build_query($query);

        return $parts[0] . ($queryString === '' ? '' : '?' . $queryString) . $fragment;
    }
}
