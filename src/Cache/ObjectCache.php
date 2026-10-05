<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Cache;

/**
 * ObjectCache – thay cho wp_cache_*.
 *
 * Mặc định CHỈ cache trong phạm vi một request. Đây là đúng mức tối thiểu mà
 * `wp_cache_*` cần để có nghĩa cho code gọi nó: nếu không có Redis hay
 * Memcached thì WordPress cũng không giữ gì qua các request (wp_cache_* trả
 * false và code rơi vào nhánh query DB).
 *
 * Vì vậy coi đây là "no-op persistent cache" – an toàn, không bao giờ trả dữ
 * liệu cũ. Muốn có cache xuyên request thì thay backend, không sửa call site.
 *
 * @package Jankx\Flight\WordpressConcept\Cache
 */
final class ObjectCache
{
    /** @var array<string, array<string, mixed>> key => [group => value] */
    private static array $cache = [];

    /** @var array<string, array<string, true>> group => [key => true] */
    private static array $groupIndex = [];

    private static int $hits   = 0;
    private static int $misses = 0;

    public static function get(string $key, string $group = 'default', bool $force = false, mixed $found = null): mixed
    {
        if ($force) {
            self::delete($key, $group);
        }

        $bucket = self::$cache[$group][$key] ?? null;

        if ($bucket === null) {
            self::$misses++;

            return false;
        }

        self::$hits++;

        return $bucket;
    }

    public static function set(string $key, mixed $value, string $group = 'default', int $expire = 0): bool
    {
        self::$cache[$group][$key]          = $value;
        self::$groupIndex[$group][$key]    = true;

        return true;
    }

    public static function add(string $key, mixed $value, string $group = 'default', int $expire = 0): bool
    {
        if (self::get($key, $group) !== false) {
            return false;
        }

        return self::set($key, $value, $group, $expire);
    }

    public static function delete(string $key, string $group = 'default'): bool
    {
        if (! isset(self::$cache[$group][$key])) {
            return false;
        }

        unset(self::$cache[$group][$key], self::$groupIndex[$group][$key]);

        return true;
    }

    /**
     * Xoá một nhóm cache theo tên. WordPress dùng cơ chế "invalidation group"
     * để khi bảng nguồn đổi thì nhóm liên quan bị xoá hết.
     */
    public static function deleteGroup(string $group): bool
    {
        $had = isset(self::$cache[$group]) && self::$cache[$group] !== [];

        unset(self::$cache[$group], self::$groupIndex[$group]);

        return $had;
    }

    /**
     * Xoá toàn bộ cache của request.
     */
    public static function flush(): bool
    {
        self::$cache      = [];
        self::$groupIndex = [];

        return true;
    }

    /**
     * Tăng số kiểm tra của một group (tương thích wp_cache_incr_group).
     */
    public static function incrGroup(string $group, int $offset = 1): void
    {
        // Không có dữ liệu thật để tăng; giữ để call site không vỡ.
    }

    public static function hits(): int
    {
        return self::$hits;
    }

    public static function misses(): int
    {
        return self::$misses;
    }
}
