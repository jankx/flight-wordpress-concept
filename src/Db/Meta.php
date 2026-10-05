<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Db;

use Jankx\Flight\WordpressConcept\Cache\Options;
use Jankx\Flight\WordpressConcept\Config;

/**
 * Meta – đọc/ghi user meta và post meta.
 *
 * Khác `wp_options`, bảng meta của WordPress KHÔNG cache toàn cục: mỗi lần đọc
 * là một query (WordPress vẫn cache qua object cache nếu có Redis). Ở đây chỉ
 * ghi nhớ trong phạm vi một request cho trường hợp đọc lại nhiều lần, và bộ nhớ
 * đó bị bỏ khi bắt đầu request mới nên không có nguy cơ đọc dữ liệu cũ.
 *
 * Tên meta được WordPress serialize (mảng) – giữ nguyên cơ chế đó.
 *
 * @package Jankx\Flight\WordpressConcept\Db
 */
final class Meta
{
    /** @var array<string, mixed> Ghi nhớ trong request: "post:12:color" => giá trị. */
    private static array $requestCache = [];

    /**
     * Bảng meta, cột chứa id đối tượng và cột khóa chính cho mỗi loại.
     *
     * Lưu ý: riêng usermeta dùng `umeta_id`; postmeta/termmeta/commentmeta đều
     * dùng `meta_id`. Cột khóa chính quyết định thứ tự đọc, và WordPress đọc
     * theo thứ tự tăng dần nên dòng đầu tiên cũng là giá trị mà update_metadata
     * sẽ ghi đè.
     */
    private const TYPES = [
        'user'    => ['table' => 'usermeta', 'id' => 'user_id', 'pk' => 'umeta_id'],
        'post'    => ['table' => 'postmeta', 'id' => 'post_id', 'pk' => 'meta_id'],
        'term'    => ['table' => 'termmeta', 'id' => 'term_id', 'pk' => 'meta_id'],
        'comment' => ['table' => 'commentmeta', 'id' => 'comment_id', 'pk' => 'meta_id'],
    ];

    /**
     * Đọc meta. Trả $default nếu không có.
     */
    public static function get(string $type, int $objectId, string $key = '', bool $single = false): mixed
    {
        if ($objectId <= 0) {
            return $single ? '' : [];
        }

        if ($key === '') {
            return self::allOf($type, $objectId);
        }

        $cacheKey = $type . ':' . $objectId . ':' . $key;

        if (array_key_exists($cacheKey, self::$requestCache)) {
            $rows = self::$requestCache[$cacheKey];

            return $single ? self::first($rows) : self::values($rows);
        }

        $rows = self::fetch($type, $objectId, $key);

        self::$requestCache[$cacheKey] = $rows;

        return $single ? self::first($rows) : self::values($rows);
    }

    /**
     * Ghi meta, thêm nếu chưa có (giống update_metadata với $prev_value rỗng).
     */
    public static function update(string $type, int $objectId, string $key, mixed $value): bool
    {
        if ($objectId <= 0 || $key === '') {
            return false;
        }

        $meta     = self::spec($type);
        $table    = self::table($meta['table']);
        $existing = self::fetch($type, $objectId, $key);

        $encoded = Options::maybeSerialize($value);

        // Đã có đúng một dòng với cùng giá trị → không cần ghi lại.
        if (count($existing) === 1 && $existing[0]['meta_value'] === $encoded) {
            return false;
        }

        $connection = Connection::instance();

        if ($existing === []) {
            $connection
                ->prepare(
                    sprintf(
                        'INSERT INTO %s (%s, meta_key, meta_value) VALUES (:id, :key, :value)',
                        $table,
                        $meta['id']
                    )
                )
                ->execute([':id' => $objectId, ':key' => $key, ':value' => $encoded]);
        } else {
            // WordPress update_metadata chỉ sửa dòng đầu tiên.
            $connection
                ->prepare(
                    sprintf(
                        'UPDATE %s SET meta_value = :value WHERE %s = :id AND meta_key = :key
                         ORDER BY %s ASC LIMIT 1',
                        $table,
                        $meta['id'],
                        $meta['pk']
                    )
                )
                ->execute([':value' => $encoded, ':id' => $objectId, ':key' => $key]);
        }

        self::forget($type, $objectId, $key);

        return true;
    }

    /**
     * Thêm meta (cho phép nhiều dòng cùng key).
     */
    public static function add(string $type, int $objectId, string $key, mixed $value): bool
    {
        if ($objectId <= 0 || $key === '') {
            return false;
        }

        $meta = self::spec($type);

        Connection::instance()
            ->prepare(
                sprintf(
                    'INSERT INTO %s (%s, meta_key, meta_value) VALUES (:id, :key, :value)',
                    self::table($meta['table']),
                    $meta['id']
                )
            )
            ->execute([':id' => $objectId, ':key' => $key, ':value' => Options::maybeSerialize($value)]);

        self::forget($type, $objectId, $key);

        return true;
    }

    /**
     * Xoá toàn bộ meta của một key.
     */
    public static function delete(string $type, int $objectId, string $key): bool
    {
        if ($objectId <= 0 || $key === '') {
            return false;
        }

        $meta = self::spec($type);

        Connection::instance()
            ->prepare(
                sprintf(
                    'DELETE FROM %s WHERE %s = :id AND meta_key = :key',
                    self::table($meta['table']),
                    $meta['id']
                )
            )
            ->execute([':id' => $objectId, ':key' => $key]);

        self::forget($type, $objectId, $key);

        return true;
    }

    /**
     * Xoá sạch bộ nhớ trong request (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$requestCache = [];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Toàn bộ meta của một đối tượng, gom key lặp thành mảng.
     */
    private static function allOf(string $type, int $objectId): array
    {
        $meta = self::spec($type);

        $rows = Connection::instance()->fetchAll(
            sprintf(
                'SELECT meta_key, meta_value FROM %s WHERE %s = :id ORDER BY %s ASC',
                self::table($meta['table']),
                $meta['id'],
                $meta['pk']
            ),
            [':id' => $objectId]
        );

        $out = [];

        foreach ($rows as $row) {
            $key           = (string) $row['meta_key'];
            $value         = Options::maybeUnserialize((string) $row['meta_value']);

            if (! array_key_exists($key, $out)) {
                $out[$key] = $value;
                continue;
            }

            // WordPress gộp các dòng trùng key thành mảng.
            if (! is_array($out[$key]) || ! array_is_list($out[$key])) {
                $out[$key] = [$out[$key]];
            }
            $out[$key][] = $value;
        }

        return $out;
    }

    /**
     * Các dòng meta thô của một key, đã unserialize value.
     *
     * @return array<int, array{meta_value: string}>
     */
    private static function fetch(string $type, int $objectId, string $key): array
    {
        $meta = self::spec($type);

        $rows = Connection::instance()->fetchAll(
            sprintf(
                'SELECT meta_value FROM %s WHERE %s = :id AND meta_key = :key ORDER BY %s ASC',
                self::table($meta['table']),
                $meta['id'],
                $meta['pk']
            ),
            [':id' => $objectId, ':key' => $key]
        );

        return array_map(
            static fn (array $row): array => [
                'meta_value' => Options::maybeUnserialize((string) $row['meta_value']),
            ],
            $rows
        );
    }

    private static function first(array $rows): mixed
    {
        return $rows[0]['meta_value'] ?? '';
    }

    /**
     * Danh sách giá trị (đã bỏ cột meta_key/meta_id), đúng như core trả về khi
     * $single = false: mảng các giá trị, không phải mảng các hàng.
     */
    private static function values(array $rows): array
    {
        return array_column($rows, 'meta_value');
    }

    private static function forget(string $type, int $objectId, string $key): void
    {
        unset(self::$requestCache[$type . ':' . $objectId . ':' . $key]);
    }

    private static function spec(string $type): array
    {
        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Loại meta không hỗ trợ: {$type}");
        }

        return self::TYPES[$type];
    }

    private static function table(string $name): string
    {
        return Config::load(dirname(__DIR__, 2))->table($name);
    }
}
