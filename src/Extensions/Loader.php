<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Extensions;

use Jankx\Flight\WordpressConcept\Config;

/**
 * Loader – nạp extension của theme và nối chúng vào vòng đời Ajax.
 *
 * Extension tự khai báo mình trong manifest.json thay vì đăng ký thủ công:
 *
 *   "context":         ["frontend", "ajax"]   – chạy trong entry ajax
 *   "ajax_slug":       "ecommerce"             – namespace trong URL
 *   "ajax_namespace":  "Jankx\\Extensions\\…"  – namespace PHP của controller
 *
 * Nhờ đó thêm extension AJAX không cần sửa ajax.php hay router.
 *
 * @package Jankx\Flight\WordpressConcept\Extensions
 */
final class Loader
{
    /** @var Manifest[] */
    private static array $manifests = [];

    /**
     * Quét thư mục theme tìm manifest của các extension.
     *
     * @param  string[]              $themeDirs  Thư mục theme cha và theme con.
     * @return Manifest[]
     */
    public static function discover(array $themeDirs): array
    {
        if (self::$manifests !== []) {
            return self::$manifests;
        }

        foreach ($themeDirs as $themeDir) {
            $paths = glob(rtrim($themeDir, '/') . '/extensions/*/manifest.json');
            if (! is_array($paths)) {
                continue;
            }

            foreach ($paths as $path) {
                $raw  = @file_get_contents($path);
                $data = $raw === false ? null : json_decode($raw, true);

                if (! is_array($data)) {
                    continue;
                }

                $dir = dirname($path);

                // Đăng ký PSR-4 của extension trước, để class trong manifest
                // nạp được khi caller được require.
                self::registerPsr4($dir);

                self::$manifests[] = new Manifest(
                    (string) ($data['extension_id'] ?? basename($dir)),
                    $dir,
                    $data
                );
            }
        }

        return self::$manifests;
    }

    /**
     * Nạp và kích hoạt các extension khai báo context 'ajax'.
     *
     * Phải mirror đúng vòng đời mà ThemeExtensionManager của theme dùng, nếu
     * không extension sẽ "có class nhưng không có hook":
     *
     *   1. require vendor/autoload.php của extension (nếu có)
     *   2. require file caller
     *   3. new $callerClass()      → constructor gọi init()
     *   4. set_extension_path() + set_manifest_data()
     *   5. activate()               → register_hooks(), tự chống gọi hai lần
     *
     * Thiếu bước 5 thì mọi add_action/add_filter trong register_hooks() im lặng
     * mất. Hệ quả thấy được là ProductRegistry không có lớp sản phẩm nào
     * (đăng ký qua hook 'jankx/ecommerce/register_product_types') nên thêm sản
     * phẩm vào giỏ hỏng với "Sản phẩm không hợp lệ hoặc không thể mua."
     *
     * @param Manifest[] $manifests
     */
    public static function bootAjax(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            if (! $manifest->handlesAjax() || ! $manifest->isEnabled()) {
                continue;
            }

            $file = $manifest->callerFile();
            if ($file === '' || ! is_readable($file)) {
                continue;
            }

            self::requireExtensionAutoloader($manifest->dir);

            require_once $file;

            self::instantiate($manifest);
        }
    }

    /**
     * Khởi tạo extension và đăng ký hook của nó.
     *
     * Dùng method_exists() thay vì instanceof AbstractExtension để package không
     * phụ thuộc class của theme – theme đổi lớp base thì loader vẫn chạy.
     */
    private static function instantiate(Manifest $manifest): void
    {
        $class = $manifest->callerClass();
        if ($class === '' || ! class_exists($class)) {
            return;
        }

        try {
            $extension = new $class();
        } catch (\Throwable $exception) {
            // Một extension hỏng không được làm hỏng cả request AJAX.
            return;
        }

        if (method_exists($extension, 'set_extension_path')) {
            $extension->set_extension_path($manifest->dir);
        }

        if (method_exists($extension, 'set_manifest_data')) {
            $extension->set_manifest_data($manifest->data);
        }

        if (method_exists($extension, 'set_extension_url')) {
            $extension->set_extension_url(self::extensionUrl($manifest));
        }

        // activate() là nơi duy nhất gọi register_hooks(); nó tự chống gọi lại
        // lần hai qua cờ $hooks_registered. Không gọi register_hooks() trực tiếp
        // ở đây, nếu không hook sẽ bị đăng ký hai lần khi theme đã chạy trước.
        if (method_exists($extension, 'activate')) {
            $extension->activate();
        }
    }

    private static function requireExtensionAutoloader(string $extensionDir): void
    {
        $autoload = rtrim($extensionDir, '/') . '/vendor/autoload.php';

        if (is_readable($autoload)) {
            require_once $autoload;
        }
    }

    /**
     * URL của extension, dựng từ siteurl trong config.
     *
     * Không dùng get_stylesheet_directory_uri() vì không có WordPress; URL chỉ
     * dùng cho admin/script, nên sai kiểu cũng không ảnh hưởng request AJAX.
     */
    private static function extensionUrl(Manifest $manifest): string
    {
        $base = rtrim(Config::load(dirname(__DIR__, 3))->siteUrl(), '/');

        return $base . '/extensions/' . $manifest->id;
    }

    /**
     * Bản đồ slug => PHP namespace, lấy từ manifest.
     *
     * @param  Manifest[]           $manifests
     * @return array<string, string>
     */
    public static function ajaxNamespaces(array $manifests): array
    {
        $namespaces = [];

        foreach ($manifests as $manifest) {
            $slug      = $manifest->ajaxSlug();
            $namespace = $manifest->ajaxNamespace();

            if ($slug !== '' && $namespace !== '') {
                $namespaces[strtolower($slug)] = $namespace;
            }
        }

        return $namespaces;
    }

    /**
     * Bỏ cache (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$manifests = [];
    }

    /**
     * Đăng ký PSR-4 từ composer.json của extension.
     *
     * Extension là repo riêng nên không nằm trong autoload của theme; đọc
     * composer.json rồi tự đăng ký, tránh phải chạy composer cho từng
     * extension.
     */
    private static function registerPsr4(string $extensionDir): void
    {
        $composerFile = $extensionDir . '/composer.json';
        if (! is_file($composerFile)) {
            return;
        }

        $composer = json_decode((string) file_get_contents($composerFile), true);
        $psr4     = is_array($composer) && isset($composer['autoload']['psr-4'])
            ? (array) $composer['autoload']['psr-4']
            : [];

        foreach ($psr4 as $prefix => $relPaths) {
            $prefixLen = strlen((string) $prefix);

            foreach ((array) $relPaths as $relPath) {
                $baseDir = $extensionDir . '/' . trim((string) $relPath, '/') . '/';

                spl_autoload_register(static function (string $class) use ($prefix, $prefixLen, $baseDir): void {
                    if (strncmp($class, (string) $prefix, $prefixLen) !== 0) {
                        return;
                    }

                    $file = $baseDir . str_replace('\\', '/', substr($class, $prefixLen)) . '.php';
                    if (is_file($file)) {
                        require_once $file;
                    }
                });
            }
        }
    }
}
