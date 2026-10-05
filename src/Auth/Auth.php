<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Auth;

use Jankx\Flight\WordpressConcept\Config;
use Jankx\Flight\WordpressConcept\Db\Connection;

/**
 * Auth – xác thực người dùng từ cookie WordPress mà không nạp WordPress.
 *
 * Thuật toán bám sát wp_validate_auth_cookie() của core:
 *
 *   pass_frag = substr(user_pass, 8, 4)   (phpass/bcrypt)
 *            | substr(user_pass, -4)      (hash dài, vd argon2)
 *   key       = hash_hmac('md5', "$user_login|$pass_frag|$expiration|$token", salt)
 *   hash      = hash_hmac('sha256', "$user_login|$expiration|$token", key)
 *
 * Session token phải tồn tại trong user meta `session_tokens`, đúng như
 * WP_Session_Tokens::verify().
 *
 * Vì sao đọc cookie thay vì vài lại hỏi WordPress: đây là entry thay thế
 * WordPress core, nên nó phải tự xác thực được phiên đăng nhập đang có.
 * Người dùng không phải đăng nhập lại, và cũng không cần bản sao session
 * riêng cho AJAX.
 *
 * Mọi nhánh thất bại đều fail closed: không có salt hợp lệ, cookie sai,
 * token hết hạn → coi như khách.
 *
 * @package Jankx\Flight\WordpressConcept\Auth
 */
final class Auth
{
    /** Nới hạn cookie thêm 1 giờ cho request Ajax, giống wp_doing_ajax(). */
    private const AJAX_EXPIRY_GRACE = 3600;

    private static bool $booted = false;

    private static ?object $user = null;

    /** @var array<string, bool> */
    private static array $capabilities = [];

    /**
     * Xác thực một lần cho mỗi request.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        $config = Config::load(dirname(__DIR__, 2));

        $user = self::resolveFromCookies($config);
        if ($user === null) {
            return;
        }

        self::$user        = $user;
        self::$capabilities = self::loadCapabilities($user, $config);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function id(): int
    {
        return self::$user !== null ? (int) self::$user->ID : 0;
    }

    public static function user(): ?object
    {
        return self::$user;
    }

    /**
     * @return array<string, bool>
     */
    public static function capabilities(): array
    {
        return self::$capabilities;
    }

    public static function can(string $capability): bool
    {
        if ($capability === '' || $capability === 'exist') {
            return self::check();
        }

        return self::$capabilities[$capability] ?? false;
    }

    /**
     * Bỏ cache (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$booted       = false;
        self::$user         = null;
        self::$capabilities = [];
    }

    // ── Cookie validation ─────────────────────────────────────────────────────

    private static function resolveFromCookies(Config $config): ?object
    {
        $cookieHash = self::cookieHash($config);
        if ($cookieHash === '') {
            return null;
        }

        $isSsl = self::isSsl();

        // Thứ tự khớp với wp_validate_auth_cookie(): logged_in trước, rồi
        // tới auth/secure_auth.
        $schemes = ['logged_in'];

        if ($isSsl) {
            $schemes[] = 'secure_auth';
        } else {
            $schemes[] = 'auth';
        }

        foreach ($schemes as $scheme) {
            $cookieName = self::cookieName($scheme, $cookieHash);
            $cookie    = $_COOKIE[$cookieName] ?? '';
            if (! is_string($cookie) || $cookie === '') {
                continue;
            }

            $user = self::validate($cookie, $scheme, $config);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    private static function cookieName(string $scheme, string $cookieHash): string
    {
        return match ($scheme) {
            'logged_in'  => 'wordpress_logged_in_' . $cookieHash,
            'secure_auth' => 'wordpress_sec_' . $cookieHash,
            default      => 'wordpress_' . $cookieHash,
        };
    }

    /**
     * Xác thực một giá trị cookie theo đúng thuật toán của core.
     */
    private static function validate(string $cookie, string $scheme, Config $config): ?object
    {
        $parts = explode('|', $cookie, 4);
        if (count($parts) !== 4) {
            return null;
        }

        [$username, $expiration, $token, $hmac] = $parts;

        if ($username === '' || $token === '' || $hmac === '') {
            return null;
        }

        $expiration = (int) $expiration;
        if ($expiration === 0) {
            return null;
        }

        if ($expiration + self::AJAX_EXPIRY_GRACE < time()) {
            return null;
        }

        $salt = self::resolveSalt($scheme, $config);
        if ($salt === '') {
            return null;
        }

        $user = self::findUserByLogin($username);
        if ($user === null) {
            return null;
        }

        // Đoạn pass_frag phải khớp chính xác với wp_validate_auth_cookie().
        $userPass = (string) $user->user_pass;

        if (str_starts_with($userPass, '$P$') || str_starts_with($userPass, '$2y$')) {
            $passFrag = substr($userPass, 8, 4);
        } else {
            $passFrag = substr($userPass, -4);
        }

        $key  = hash_hmac('md5', $username . '|' . $passFrag . '|' . $expiration . '|' . $token, $salt);
        $hash = hash_hmac('sha256', $username . '|' . $expiration . '|' . $token, $key);

        if (! hash_equals($hash, $hmac)) {
            return null;
        }

        if (! self::verifySessionToken($token, (int) $user->ID, $config)) {
            return null;
        }

        return $user;
    }

    /**
     * Kiểm tra token còn tồn tại trong user meta và chưa hết hạn.
     *
     * WP_Session_Tokens lưu theo key hash('sha256', $token).
     */
    private static function verifySessionToken(string $token, int $userId, Config $config): bool
    {
        $raw = self::getUserMeta($userId, 'session_tokens', $config);
        if (! is_array($raw) || $raw === []) {
            return false;
        }

        $verifier = hash('sha256', $token);
        if (! isset($raw[$verifier]) || ! is_array($raw[$verifier])) {
            return false;
        }

        $expiration = (int) ($raw[$verifier]['expiration'] ?? 0);

        return $expiration > time();
    }

    /**
     * Salt của một scheme, theo đúng thứ tự wp_salt() dùng.
     *
     * Ưu tiên constant trong wp-config. Nếu constant đó trùng nhau hoặc còn là
     * placeholder (rất phổ biến với site mới dùng wp-config mặc định) thì
     * WordPress bỏ qua và đọc từ site option – ta cũng phải làm vậy, nếu không
     * mọi cookie đăng nhập của site đó sẽ bị từ chối.
     *
     * Không tự sinh salt mới như WP làm khi thiếu: entry này chỉ đọc, và sinh
     * secret trong một request GET sẽ tạo ra side effect khó kiểm soát. Thiếu
     * salt thì fail closed.
     */
    private static function resolveSalt(string $scheme, Config $config): string
    {
        if ($config->hasRealSalt($scheme)) {
            return $config->salt($scheme);
        }

        $key  = '';
        $salt = '';

        // wp_salt(): riêng scheme 'auth' lấy SECRET_KEY / SECRET_SALT làm giá
        // trị dự phòng trước khi đọc option.
        if ($scheme === 'auth') {
            if ($config->hasRealValue('SECRET_KEY')) {
                $key = $config->get('SECRET_KEY');
            }
            if ($config->hasRealValue('SECRET_SALT')) {
                $salt = $config->get('SECRET_SALT');
            }
        }

        if ($key === '') {
            $key = self::readOption($scheme . '_key');
        }

        if ($salt === '') {
            $salt = self::readOption($scheme . '_salt');
        }

        return $key . $salt;
    }

    /**
     * Đọc một option; trả chuỗi rỗng nếu không có.
     *
     * Tên KHÔNG gắn table prefix: wp_salt() gọi get_site_option(), và trên
     * single-site get_network_option() rơi về get_option() nên option salt
     * được lưu không prefix ('logged_in_key', không phải 'wp_logged_in_key').
     * Đọc có prefix sẽ luôn trả rỗng và mọi phiên đăng nhập bị từ chối.
     */
    private static function readOption(string $name): string
    {
        $connection = Connection::instance();
        $config     = Config::load(dirname(__DIR__, 2));

        $value = $connection->fetchValue(
            sprintf(
                'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                $config->table('options')
            ),
            [':name' => $name]
        );

        return is_string($value) ? $value : '';
    }

    // ── User + capabilities ───────────────────────────────────────────────────
    private static function findUserByLogin(string $login): ?object
    {
        $connection = Connection::instance();
        $config     = Config::load(dirname(__DIR__, 2));

        $row = $connection->fetchOne(
            sprintf(
                'SELECT ID, user_login, user_email, user_pass, user_nicename, display_name
                 FROM %s WHERE user_login = :login LIMIT 1',
                $config->table('users')
            ),
            [':login' => $login]
        );

        return $row === null ? null : (object) $row;
    }

    /**
     * Gom quyền từ các role của user, cộng với quyền của super admin.
     *
     * @return array<string, bool>
     */
    private static function loadCapabilities(object $user, Config $config): array
    {
        $connection = Connection::instance();

        $roles = self::unserializeOption(
            $connection->fetchValue(
                sprintf(
                    'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                    $config->table('options')
                ),
                [':name' => $config->table('user_roles')]
            )
        );

        $userRoles = self::getUserMeta((int) $user->ID, $config->table('capabilities'), $config);
        if (! is_array($userRoles)) {
            $userRoles = [];
        }

        $capabilities = [];

        foreach (array_keys($userRoles) as $role) {
            foreach ($roles[$role]['capabilities'] ?? [] as $capability) {
                $capabilities[$capability] = true;
            }
        }

        // Super admin của site mạng đăng ký trong option 'site_admins'.
        $superAdmins = self::unserializeOption(
            $connection->fetchValue(
                sprintf(
                    'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                    $config->table('options')
                ),
                [':name' => 'site_admins']
            )
        );

        if (is_array($superAdmins) && in_array((int) $user->ID, array_map('intval', $superAdmins), true)) {
            foreach ($roles as $role) {
                foreach ($role['capabilities'] ?? [] as $capability) {
                    $capabilities[$capability] = true;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Đọc user meta. WordPress serialize giá trị mảng bằng PHP, nên đọc
     * phải unserialize – giới hạn class để không nạp object từ DB.
     */
    private static function getUserMeta(int $userId, string $key, Config $config): mixed
    {
        $connection = Connection::instance();

        $value = $connection->fetchValue(
            sprintf(
                'SELECT meta_value FROM %s WHERE user_id = :uid AND meta_key = :key LIMIT 1',
                $config->table('usermeta')
            ),
            [':uid' => $userId, ':key' => $key]
        );

        if ($value === false || $value === null) {
            return null;
        }

        return self::unserializeOption($value);
    }

    /**
     * Option cũng lưu bằng PHP serialize cho mảng.
     */
    private static function unserializeOption(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Chuỗi serialize của PHP luôn bắt đầu bằng ký tự kiểu dữ liệu.
        $type = $value[0];
        if (! in_array($type, ['a', 's', 'i', 'd', 'b', 'O', 'N'], true)) {
            return $value;
        }

        $result = @unserialize($value, ['allowed_classes' => false]);

        // Chuỗi serialize hỏng: trả về nguyên bản thay vì false.
        if ($result === false && $value !== 'b:0;') {
            return $value;
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * COOKIEHASH = md5(siteurl). wp-config thường không khai siteurl nên
     * fallback sang option 'siteurl'.
     */
    private static function cookieHash(Config $config): string
    {
        $siteUrl = $config->siteUrl();
        if ($siteUrl !== '') {
            return md5($siteUrl);
        }

        $connection = Connection::instance();

        $stored = $connection->fetchValue(
            sprintf(
                'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                $config->table('options')
            ),
            [':name' => 'siteurl']
        );

        return is_string($stored) && $stored !== '' ? md5($stored) : '';
    }

    private static function isSsl(): bool
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
