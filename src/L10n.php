<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

/**
 * L10n – trạng thái locale và textdomain.
 *
 * Không nạp WordPress nên không có hệ thống gettext của core. Ở đây giữ đúng
 * *hợp đồng* mà extension dùng:
 *
 *   - xác định locale (site locale, user locale, locale đang switch)
 *   - đăng ký textdomain để is_textdomain_loaded() trả đúng
 *   - format số/ngày theo locale
 *
 * Bản dịch chưa được nạp: các hàm __()/_e() trả nguyên văn. Khi deploy thật,
 * gọi bindtextdomain() + textdomain() với file .mo của theme sẽ bật dịch mà
 * không phải sửa call site.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class L10n
{
    private static ?string $locale          = null;
    private static ?string $userLocale      = null;
    private static ?string $switchedLocale  = null;

    /** @var array<string, string> textdomain => thư mục đã đăng ký */
    private static array $textdomains = [];

    /**
     * Locale của site. Đọc option WPLANG như core.
     */
    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        $option = \get_option('WPLANG', '');

        return self::$locale = is_string($option) && $option !== '' ? $option : 'en_US';
    }

    /**
     * Locale của người dùng đang đăng nhập, nếu họ đặt khác locale site.
     */
    public static function userLocale(): string
    {
        if (self::$userLocale !== null) {
            return self::$userLocale;
        }

        $userId = \get_current_user_id();

        if ($userId <= 0) {
            return self::$userLocale = self::locale();
        }

        $meta = \get_user_meta($userId, 'locale', true);

        return self::$userLocale = is_string($meta) && $meta !== '' ? $meta : self::locale();
    }

    /**
     * Locale đang được switch tạm thời bằng switch_to_locale().
     */
    public static function switchedLocale(): string|false
    {
        return self::$switchedLocale ?? false;
    }

    public static function switchTo(string $locale): bool
    {
        self::$switchedLocale = $locale;

        return true;
    }

    public static function restorePrevious(): string|false
    {
        $previous = self::$switchedLocale;
        self::$switchedLocale = null;

        return $previous;
    }

    /**
     * Locale áp dụng cho request hiện tại.
     *
     * `determine_locale()` của core lấy theo thứ tự: locale đang switch →
     * locale của user (nếu có và được phép) → locale của site.
     */
    public static function determine(): string
    {
        if (self::$switchedLocale !== null) {
            return self::$switchedLocale;
        }

        if (\is_user_logged_in()) {
            return self::userLocale();
        }

        return self::locale();
    }

    public static function isSwitched(): bool
    {
        return self::$switchedLocale !== null;
    }

    /**
     * Đăng ký textdomain. Không nạp file .mo nhưng ghi nhớ để
     * is_textdomain_loaded() trả đúng.
     */
    public static function loadTextdomain(string $domain, string $path = '', string $language = ''): bool
    {
        self::$textdomains[$domain] = $path !== '' ? $path : $language;

        return true;
    }

    public static function unloadTextdomain(string $domain): bool
    {
        unset(self::$textdomains[$domain]);

        return true;
    }

    public static function isTextdomainLoaded(string $domain): bool
    {
        return isset(self::$textdomains[$domain]);
    }

    /**
     * @return string[]
     */
    public static function loadedTextdomains(): array
    {
        return array_keys(self::$textdomains);
    }

    public static function reset(): void
    {
        self::$locale         = null;
        self::$userLocale     = null;
        self::$switchedLocale = null;
        self::$textdomains    = [];
    }
}
