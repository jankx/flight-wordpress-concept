<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Cache;

use Jankx\Flight\WordpressConcept\Config;
use Jankx\Flight\WordpressConcept\Db\Connection;

/**
 * Options – lớp cache bảng `wp_options`.
 *
 * WordPress cache toàn bộ option trong bộ nhớ (`wp_load_alloptions`) và tự
 * ghi cache khi update. Ở đây mô phỏng đúng mô hình đó, vì đây chính là
 * "database cache" mà theme đang dùng:
 *
 *   - Đọc: gom mọi option autoload vào MỘT câu SELECT theo tên, sau đó trả
 *     từ bộ nhớ. Không thì mỗi get_option() là một round-trip.
 *   - Ghi: UPDATE, và tự cập nhật bộ nhớ để các lần đọc sau trong cùng
 *     request thấy giá trị mới.
 *
 * Tên option KHÔNG gắn prefix: người gọi truyền tên đầy đủ, đúng như
 * get_option() của WordPress.
 *
 * @package Jankx\Flight\WordpressConcept\Cache
 */
final class Options
{
    /** @var array<string, mixed> Option đã nạp trong request này. */
    private static array $cache = [];

    /** @var array<string, true> Tên đã biết chắc là không tồn tại. */
    private static array $missing = [];

    /** @var array<string, mixed>|null Toàn bộ option autoload, nạp một lần. */
    private static ?array $allOptions = null;

    /**
     * Đọc một option. Trả $default (mặc định false) nếu không có.
     */
    public static function get(string $name, mixed $default = false): mixed
    {
        if ($name === '') {
            return $default;
        }

        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        if (isset(self::$missing[$name])) {
            return $default;
        }

        $value = self::fetchOne($name);

        if ($value === null) {
            self::$missing[$name] = true;

            return $default;
        }

        return self::$cache[$name] = self::maybeUnserialize($value);
    }

    /**
     * Cập nhật option. Trả false nếu giá trị không đổi (giống WordPress).
     */
    public static function update(string $name, mixed $value): bool
    {
        if ($name === '') {
            return false;
        }

        $current = self::get($name, null);
        $encoded = self::maybeSerialize($value);

        if ($current !== null && self::maybeSerialize($current) === $encoded) {
            return false;
        }

        self::run(
            sprintf(
                'UPDATE %s SET option_value = :value WHERE option_name = :name',
                self::table()
            ),
            [':value' => $encoded, ':name' => $name]
        );

        unset(self::$missing[$name]);
        self::$cache[$name] = $value;

        return true;
    }

    /**
     * Thêm option mới. Trả false nếu đã tồn tại (giống add_option).
     */
    public static function add(string $name, mixed $value): bool
    {
        if ($name === '') {
            return false;
        }

        if (self::exists($name)) {
            return false;
        }

        self::run(
            sprintf(
                'INSERT INTO %s (option_name, option_value, autoload) VALUES (:name, :value, :autoload)',
                self::table()
            ),
            [':name' => $name, ':value' => self::maybeSerialize($value), ':autoload' => self::autoloadFor($value)]
        );

        self::$cache[$name] = $value;

        return true;
    }

    /**
     * Xoá option.
     */
    public static function delete(string $name): bool
    {
        if ($name === '' || ! self::exists($name)) {
            return false;
        }

        self::run(
            sprintf('DELETE FROM %s WHERE option_name = :name', self::table()),
            [':name' => $name]
        );

        unset(self::$cache[$name], self::$missing[$name]);

        return true;
    }

    public static function exists(string $name): bool
    {
        return self::get($name, null) !== null;
    }

    /**
     * Đọc giá trị thô chưa unserialize (dùng nội bộ).
     */
    public static function getRaw(string $name): ?string
    {
        return self::fetchOne($name);
    }

    /**
     * Ghi giá trị thô, bỏ qua bước serialize (dùng nội bộ).
     */
    public static function setRaw(string $name, string $value): void
    {
        self::run(
            sprintf(
                'INSERT INTO %s (option_name, option_value, autoload) VALUES (:name, :value, :autoload)
                 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
                self::table()
            ),
            [':name' => $name, ':value' => $value, ':autoload' => 'off']
        );

        self::$cache[$name] = self::maybeUnserialize($value);
        unset(self::$missing[$name]);
    }

    /**
     * Xoá một giá trị thô đã biết tên (dùng nội bộ).
     */
    public static function deleteRaw(string $name): void
    {
        self::run(
            sprintf('DELETE FROM %s WHERE option_name = :name', self::table()),
            [':name' => $name]
        );

        unset(self::$cache[$name], self::$missing[$name]);
    }

    /**
     * Nạp trước một danh sách option trong MỘT câu truy vấn.
     *
     * Auth đọc tới 4 option salt, Ping đọc thông tin DB: gom lại giúp tránh
     * nhiều round-trip tuần tự.
     *
     * @param string[] $names
     */
    public static function prime(array $names): void
    {
        $names = array_values(array_unique(array_filter(
            $names,
            static fn (string $n): bool => $n !== ''
                && ! array_key_exists($n, self::$cache)
                && ! isset(self::$missing[$n])
        )));

        if ($names === []) {
            return;
        }

        $placeholders = [];
        $params       = [];

        foreach ($names as $i => $name) {
            $key           = ':n' . $i;
            $placeholders[] = $key;
            $params[$key]  = $name;
        }

        $connection = Connection::instance();

        $rows = $connection->fetchAll(
            sprintf(
                'SELECT option_name, option_value FROM %s WHERE option_name IN (%s)',
                self::table(),
                implode(', ', $placeholders)
            ),
            $params
        );

        $found = [];

        foreach ($rows as $row) {
            $found[]                = (string) $row['option_name'];
            self::$cache[(string) $row['option_name']] = self::maybeUnserialize((string) $row['option_value']);
        }

        foreach ($names as $name) {
            if (! in_array($name, $found, true)) {
                self::$missing[$name] = true;
            }
        }
    }

    /**
     * Xoá bộ nhớ trong request (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$cache     = [];
        self::$missing   = [];
        self::$allOptions = null;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Đọc một option từ DB, hoặc null nếu không có.
     */
    private static function fetchOne(string $name): ?string
    {
        $connection = Connection::instance();

        $value = $connection->fetchValue(
            sprintf(
                'SELECT option_value FROM %s WHERE option_name = :name LIMIT 1',
                self::table()
            ),
            [':name' => $name]
        );

        return is_string($value) ? $value : null;
    }

    private static function run(string $sql, array $params): void
    {
        $connection = Connection::instance();

        $connection->prepare($sql)->execute($params);
    }

    private static function table(): string
    {
        return Config::load(dirname(__DIR__, 2))->table('options');
    }

    /**
     * Option scalar được autoload; mảng/object thì không (đúng heuristic của
     * WordPress khi thêm option mới).
     */
    private static function autoloadFor(mixed $value): string
    {
        return is_array($value) || is_object($value) ? 'off' : 'on';
    }

    /**
     * WordPress chỉ serialize khi giá trị không phải chuỗi/số.
     */
    public static function maybeSerialize(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return (string) $value;
        }

        return serialize($value);
    }

    /**
     * Ngược lại: chỉ unserialize khi chuỗi trông như dữ liệu serialize.
     */
    public static function maybeUnserialize(string $value): mixed
    {
        if ($value === '') {
            return $value;
        }

        $type = $value[0];

        if (! in_array($type, ['a', 's', 'i', 'd', 'b', 'O', 'N'], true)) {
            return $value;
        }

        $result = @unserialize($value, ['allowed_classes' => false]);

        // Chuỗi serialize hỏng: giữ nguyên bản thay vì trả false.
        if ($result === false && $value !== 'b:0;') {
            return $value;
        }

        return $result;
    }
}
