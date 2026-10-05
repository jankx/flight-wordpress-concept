<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

use Jankx\Flight\WordpressConcept\Cache\Options;

/**
 * Constants – khai các constant mà code WordPress-style khác vẫn dùng.
 *
 * `Cart` của extension khai `const CART_TTL = 30 * DAY_IN_SECONDS;`. Trong
 * PHP, constant không định nghĩa sẽ ném lỗi "Undefined constant" thay vì rơi
 * về global nếu namespace con chưa có – nên phải khai sẵn ở global scope.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class Constants
{
    /**
     * Hằng số thời gian của WordPress. Giá trị cố định nên khai lúc autoload,
     * không tốn query nào.
     */
    public static function defineTime(): void
    {
        self::defineOnce('MINUTE_IN_SECONDS', 60);
        self::defineOnce('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
        self::defineOnce('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
        self::defineOnce('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
        self::defineOnce('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
        self::defineOnce('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
    }

    /**
     * Hằng số đường dẫn, mô phỏng wp-load.php.
     *
     * Rất nhiều file của theme (và của extension) khai ở đầu:
     *
     *     if (! defined('ABSPATH')) { exit('Cheating huh?'); }
     *
     * Đó là cách chống truy cập trực tiếp file PHP, không phải kiểm tra nào
     * về runtime – nhưng nếu thiếu ABSPATH thì các file đó tự exit và request
     * chết ngay giữa chừng. Khai giá trị đúng vị trí là hợp lệ và giữ nguyên
     * cơ chế bảo vệ đó.
     */
    public static function definePaths(): void
    {
        // wp-content nằm cạnh themes/; suy ra từ vị trí package đang chạy
        // (…/wp-content/themes/<theme>/vendor/jankx/flight-wordpress-concept).
        $packageDir = dirname(__DIR__);
        $themeDir   = dirname($packageDir, 4);
        $contentDir = dirname($themeDir, 2);

        self::defineOnce('ABSPATH', $themeDir . '/');
        self::defineOnce('WPINC', 'wp-includes');
        self::defineOnce('WP_CONTENT_DIR', $contentDir);
        self::defineOnce('WP_PLUGIN_DIR', $contentDir . '/plugins');
        self::defineOnce('WPMU_PLUGIN_DIR', $contentDir . '/mu-plugins');
    }

    /**
     * Hằng số cookie, lấy từ site URL.
     *
     * wp_cookie_constants() khai COOKIEPATH theo path của site (site nằm trong
     * subfolder thì path phải khớp, nếu không cookie không được gửi lên) và
     * để COOKIE_DOMAIN rỗng để browser gắn cookie cho đúng host hiện tại.
     */
    public static function defineCookies(): void
    {
        $config  = Config::load(dirname(__DIR__));
        $siteUrl = $config->siteUrl();

        if ($siteUrl === '') {
            $siteUrl = (string) Options::get('siteurl', '');
        }

        $path = $siteUrl !== '' ? (string) parse_url($siteUrl, PHP_URL_PATH) : '';
        $path = $path === '' ? '/' : $path;

        self::defineOnce('SITECOOKIEPATH', $path);
        self::defineOnce('COOKIEPATH', $path);
        self::defineOnce('COOKIE_DOMAIN', $config->get('WP_COOKIE_DOMAIN', ''));
        self::defineOnce('ADMIN_COOKIE_PATH', $path . 'wp-admin');
    }

    private static function defineOnce(string $name, mixed $value): void
    {
        if (! defined($name)) {
            define($name, $value);
        }
    }
}
