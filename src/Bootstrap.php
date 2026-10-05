<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

use Jankx\Flight\WordpressConcept\Auth\Auth;
use Jankx\Flight\WordpressConcept\Extensions\Loader;
use Jankx\Flight\WordpressConcept\Extensions\Manifest;
use Jankx\Flight\WordpressConcept\Hooks\Hooks;

/**
 * Bootstrap – điểm vào duy nhất của package.
 *
 * Thay thế toàn bộ chuỗi boot của WordPress: không wp-load.php, không
 * SHORTINIT, không plugin, không theme functions.php. Đúng ba việc –
 * cấu hình, xác thực, nạp extension.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class Bootstrap
{
    private static bool $booted = false;

    /** @var Manifest[] */
    private static array $manifests = [];

    /**
     * Chuẩn bị runtime cho một request Ajax.
     *
     * @param  string       $themeDir  Thư mục theme đang chạy (dùng để tìm extension).
     * @param  string[]     $extraDirs Thêm thư mục cần quét, vd theme con.
     * @return Manifest[]              Các extension đã nạp.
     */
    public static function boot(string $themeDir, array $extraDirs = []): array
    {
        if (self::$booted) {
            return self::$manifests;
        }

        self::$booted = true;

        // 1. Cấu hình: đọc tĩnh wp-config.php, không nạp WordPress.
        Config::load(dirname(__DIR__));

        // 1b. Constant của WordPress (DAY_IN_SECONDS, COOKIEPATH…). Cần trước
        // khi nạp extension vì chúng dùng ở khai báo class, ví dụ
        // `const CART_TTL = 30 * DAY_IN_SECONDS;`.
        Constants::defineTime();
        Constants::definePaths();

        // 1c. Global $wpdb. Extension đọc nó bằng `global $wpdb` ngay trong
        //     register_hooks(), thiếu là "Attempt to read property on null".
        //     Phải có trước khi nạp extension. Lỗi kết nối không nên giết
        //     request: endpoint nào không cần DB vẫn phải trả lời được.
        self::bootWpdb();

        // 2. Xác thực cookie trước, để hook 'init' của extension thấy đúng
        //    người dùng hiện tại.
        Auth::boot();

        // 2b. COOKIEPATH phụ thuộc site URL trong options, nên khai sau khi
        //     đã có connection.
        Constants::defineCookies();

        // 3. Nạp extension khai báo context 'ajax' rồi kích vòng đời.
        $dirs      = array_values(array_unique(array_merge([$themeDir], $extraDirs, self::childThemeDirs($themeDir))));
        $manifests = Loader::discover($dirs);

        Loader::bootAjax($manifests);

        // Extension đăng ký post type, taxonomy, product registry… ở 'init',
        // nên hook này phải chạy sau khi mọi extension đã nạp xong.
        Hooks::doAction('init');

        self::$manifests = $manifests;

        return $manifests;
    }

    /**
     * Bảng đồ namespace ajax lấy từ manifest.
     *
     * @return array<string, string>
     */
    public static function ajaxNamespaces(): array
    {
        return Loader::ajaxNamespaces(self::$manifests);
    }

    /**
     * Bỏ trạng thái boot (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$booted    = false;
        self::$manifests = [];

        Config::reset();
        Auth::reset();
        Db\Wpdb::reset();
        Cache\Options::reset();
        Loader::reset();
        Hooks::reset();
    }

    /**
     * Dựng $wpdb và cài vào scope global.
     *
     * Không có $wpdb thì phần lớn extension chết ngay ở hook, nên coi lỗi ở
     * đây là không chặn được: cứ để $GLOBALS['wpdb'] = null, endpoint không
     * cần DB vẫn chạy.
     */
    private static function bootWpdb(): void
    {
        // Dispatch Fast-AJAX bên trong WordPress (template_redirect) thì đã có
        // $wpdb thật của core: giữ nguyên, không đè bằng shim của package.
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            return;
        }

        $GLOBALS['wpdb'] = null;

        try {
            $GLOBALS['wpdb'] = new Db\Wpdb(
                Db\Connection::instance(),
                Config::load(dirname(__DIR__))
            );
        } catch (\Throwable $exception) {
            error_log('[bootstrap] không khởi tạo được $wpdb: ' . $exception->getMessage());
        }
    }

    /**
     * Thư mục theme con đang hoạt động, để extension của child theme cũng
     * được nạp.
     *
     * WordPress lưu tên theme trong option 'stylesheet'. Ở đây đọc thẳng
     * bảng options thay vì get_option(), vì không có WordPress. Lỗi kết nối
     * được bỏ qua: entry vẫn chạy được với extension của theme cha.
     *
     * @return string[]
     */
    private static function childThemeDirs(string $themeDir): array
    {
        $stylesheet = self::readOption('stylesheet');

        if ($stylesheet === null || $stylesheet === basename($themeDir)) {
            return [];
        }

        // wp-content/themes/<stylesheet>
        $candidate = dirname($themeDir) . '/' . $stylesheet;

        return is_dir($candidate) ? [$candidate] : [];
    }

    /**
     * Đọc một option, trả null nếu không đọc được.
     */
    private static function readOption(string $name): ?string
    {
        try {
            $config     = Config::load(dirname(__DIR__));
            $connection = Db\Connection::instance();

            $value = $connection->fetchValue(
                sprintf(
                    'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                    $config->table('options')
                ),
                [':name' => $name]
            );

            return is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
