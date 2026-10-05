<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Extensions;

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
     * Nạp bootstrap (caller) của các extension khai báo context 'ajax'.
     *
     * Extension gọi add_action() trong hàm khởi tạo, nên phải nạp trước khi
     * kích 'init'.
     *
     * @param Manifest[] $manifests
     */
    public static function bootAjax(array $manifests): void
    {
        foreach ($manifests as $manifest) {
            if (! $manifest->handlesAjax()) {
                continue;
            }

            $file = $manifest->callerFile();
            if ($file === '' || ! is_readable($file)) {
                continue;
            }

            require_once $file;
        }
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
