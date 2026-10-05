<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

use RuntimeException;

/**
 * Config – đọc cấu hình WordPress mà KHÔNG boot WordPress.
 *
 * wp-config.php không được require vì file đó kết thúc bằng
 * `require_once ABSPATH . 'wp-settings.php'`. Thay vào đó file được đọc
 * tĩnh (static parse) để lấy đúng những gì entry cần: thông tin kết nối
 * DB, table prefix, và các salt dùng cho auth cookie.
 *
 * Không có WordPress nào được nạp, không plugin nào chạy – đây là điểm
 * khác biệt lớn nhất so với SHORTINIT + wp-load.php.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class Config
{
    /** Constant trong wp-config.php cần đọc. */
    private const DB_CONSTANTS = [
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_HOST',
        'DB_CHARSET',
        'DB_COLLATE',
    ];

    /** Các scheme salt dùng cho auth cookie. */
    private const SALT_SCHEMES = ['auth', 'secure_auth', 'logged_in'];

    /** Giá trị mặc định của wp-config mới tạo – không phải secret thật. */
    private const PLACEHOLDER_SALT = 'put your unique phrase here';

    /** Các nhóm constant mà wp_salt() dùng để phát hiện giá trị trùng nhau. */
    private const SALT_CONSTANT_GROUPS = ['AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET'];

    private string $configPath;

    /** @var array<string, string> */
    private array $constants = [];

    private string $tablePrefix = 'wp_';

    private static ?self $instance = null;

    private function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->parse();
    }

    /**
     * Nạp cấu hình, tự dò ngược lên từ $startDir để tìm wp-config.php.
     *
     * @param  string $startDir Thường là __DIR__ của entry point (theme dir).
     */
    public static function load(string $startDir): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $dir  = rtrim($startDir, '/\\');
        $path = null;

        // Theme nằm ở wp-content/themes/<slug> nên wp-config ở trên 3–5 cấp.
        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir . '/wp-config.php';
            if (is_readable($candidate)) {
                $path = $candidate;
                break;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        if ($path === null) {
            throw new RuntimeException(sprintf(
                'Không tìm thấy wp-config.php từ %s trở lên.',
                $startDir
            ));
        }

        return self::$instance = new self($path);
    }

    /**
     * Nạp cấu hình đã biết đường dẫn (dùng cho test hoặc khi đã biết path).
     */
    public static function fromPath(string $configPath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (! is_readable($configPath)) {
            throw new RuntimeException("Không đọc được wp-config.php: {$configPath}");
        }

        return self::$instance = new self($configPath);
    }

    /**
     * Bỏ cache instance (chỉ dùng trong test).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ── Parsing ───────────────────────────────────────────────────────────────

    /**
     * Đọc tĩnh file: define('X', '...') và $table_prefix.
     *
     * Chỉ nhận giá trị chuỗi ký tự đơn. wp-config thực tế gần như luôn ở dạng
     * này; biểu thức động (nối chuỗi, hằng số) bị bỏ qua có chủ đích để không
     * phải thực thi code của site.
     */
    private function parse(): void
    {
        $source = (string) file_get_contents($this->configPath);

        // define( 'DB_NAME', 'nibitour' );
        if (preg_match_all(
            '/\bdefine\s*\(\s*([\'"])([A-Z0-9_]+)\1\s*,\s*([\'"])(.*?)\3\s*\)\s*;/s',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $this->constants[$match[2]] = $this->unescape($match[4]);
            }
        }

        // $table_prefix = 'wp_';
        if (preg_match('/\$table_prefix\s*=\s*([\'"])(.*?)\1\s*;/', $source, $match)) {
            $this->tablePrefix = $this->unescape($match[2]);
        }
    }

    /**
     * Giải escape chuỗi kiểu PHP cho giá trị literal trong wp-config.
     */
    private function unescape(string $value): string
    {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function path(): string
    {
        return $this->configPath;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Tên bảng đầy đủ, ví dụ table('posts') → 'wp_posts'.
     */
    public function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    /**
     * @return array{name: string, user: string, password: string, host: string, charset: string, collate: string}
     */
    public function database(): array
    {
        $name = $this->constants['DB_NAME'] ?? '';
        if ($name === '') {
            throw new RuntimeException('Thiếu DB_NAME trong wp-config.php.');
        }

        return [
            'name'     => $name,
            'user'     => $this->constants['DB_USER'] ?? '',
            'password' => $this->constants['DB_PASSWORD'] ?? '',
            'host'     => $this->constants['DB_HOST'] ?? 'localhost',
            'charset'  => $this->constants['DB_CHARSET'] ?? 'utf8mb4',
            'collate'  => $this->constants['DB_COLLATE'] ?? '',
        ];
    }

    /**
     * DSN cho PDO.
     */
    public function dsn(): string
    {
        $db = $this->database();

        // DB_HOST có thể kèm cổng và socket: '127.0.0.1:3307'.
        $host     = $db['host'];
        $port     = '';
        $socket   = '';
        $hostname = $host;

        if (str_contains($host, ':')) {
            [$maybeHost, $maybePort] = explode(':', $host, 2);
            if (is_numeric($maybePort)) {
                $hostname = $maybeHost;
                $port     = ';port=' . $maybePort;
            } else {
                // Dạng 'localhost:/path/to/mysql.sock'
                $socket = ';unix_socket=' . $maybePort;
                $hostname = $maybeHost;
            }
        }

        return sprintf(
            'mysql:host=%s%s%s;dbname=%s;charset=%s',
            $hostname,
            $port,
            $socket,
            $db['name'],
            $db['charset']
        );
    }

    /**
     * Tên constant định nghĩa trong wp-config.php (không cần biết giá trị).
     */
    public function has(string $constant): bool
    {
        return isset($this->constants[$constant]) && $this->constants[$constant] !== '';
    }

    public function get(string $constant, string $default = ''): string
    {
        return $this->constants[$constant] ?? $default;
    }

    /**
     * Salt lấy thẳng từ constant trong wp-config.
     *
     * Có thể là giá trị rỗng, hoặc placeholder mặc định của WordPress. Dùng
     * riêng giá trị này sẽ sai với site để nguyên placeholder – hãy gọi
     * hasRealSalt() trước, hoặc để Auth tự fallback sang options.
     *
     * @param string $scheme auth|secure_auth|logged_in
     */
    public function salt(string $scheme): string
    {
        if (! in_array($scheme, self::SALT_SCHEMES, true)) {
            return '';
        }

        $prefix = strtoupper($scheme);
        $key    = $this->constants[$prefix . '_KEY'] ?? '';
        $salt   = $this->constants[$prefix . '_SALT'] ?? '';

        return $key . $salt;
    }

    /**
     * Salt trong wp-config có dùng được không.
     *
     * WordPress bỏ qua các constant bị trùng nhau hoặc còn là placeholder
     * 'put your unique phrase here', rồi đọc từ options. wp_salt() làm đúng
     * việc đó – nếu không mô phỏng, mọi cookie đăng nhập trên site đó sẽ bị
     * từ chối.
     */
    public function hasRealSalt(string $scheme): bool
    {
        if (! in_array($scheme, self::SALT_SCHEMES, true)) {
            return false;
        }

        $prefix = strtoupper($scheme);

        return $this->hasRealValue($prefix . '_KEY')
            && $this->hasRealValue($prefix . '_SALT');
    }

    /**
     * Constant salt/key này có dùng được như một secret thật không.
     *
     * Theo wp_salt(): giá trị bị loại nếu rỗng, còn placeholder mặc định, hoặc
     * bị trùng với một constant salt khác (hai dòng đều để placeholder là
     * trường hợp rất hay gặp).
     *
     * @param string $constant Ví dụ LOGGED_IN_KEY, SECRET_SALT.
     */
    public function hasRealValue(string $constant): bool
    {
        $value = $this->constants[$constant] ?? '';

        if ($value === '' || $value === self::PLACEHOLDER_SALT) {
            return false;
        }

        return $this->countConstantsWithValue($value) < 2;
    }

    /**
     * Có bao nhiêu constant salt/key đang dùng chung một giá trị.
     */
    private function countConstantsWithValue(string $value): int
    {
        $count = 0;

        foreach (self::SALT_CONSTANT_GROUPS as $first) {
            foreach (['KEY', 'SALT'] as $second) {
                if (($this->constants[$first . '_' . $second] ?? '') === $value) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Site URL dùng để tính COOKIEHASH.
     *
     * Giá trị trả về giữ nguyên như khai trong wp-config, không bỏ dấu '/'
     * cuối: default-constants.php của WordPress dùng đúng giá trị option
     * 'siteurl' để tính md5, nên cắt bớt ký tự sẽ ra COOKIEHASH khác.
     *
     * @return string Rỗng nếu không khai trong wp-config – khi đó Auth đọc
     *               từ option 'siteurl'.
     */
    public function siteUrl(): string
    {
        foreach (['WP_HOME', 'WP_SITEURL'] as $constant) {
            $value = $this->constants[$constant] ?? '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * COOKIEHASH – md5(siteurl), giống default-constants.php của WordPress.
     *
     * @param string|null $fallbackSiteUrl Dùng khi wp-config không khai URL.
     */
    public function cookieHash(?string $fallbackSiteUrl = null): string
    {
        $siteUrl = $this->siteUrl();
        if ($siteUrl === '' && $fallbackSiteUrl !== null) {
            $siteUrl = $fallbackSiteUrl;
        }

        return md5($siteUrl);
    }
}
