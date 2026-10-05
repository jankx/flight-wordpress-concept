<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Cache;

/**
 * Transient – cache có hạn, lưu trên bảng `wp_options`.
 *
 * Không có external object cache thì đây đúng là cách WordPress lưu
 * transient: hai option `_transient_{key}` (giá trị) và
 * `_transient_timeout_{key}` (thời điểm hết hạn). Hành vi bên dưới bám sát
 * `wp-includes/option.php` để extension không phải phân biệt mình đang chạy
 * trong WordPress hay không.
 *
 * Lưu ý: vì nằm trên options nên đây là cache DB, nhanh hơn query bảng dữ liệu
 * chính nhưng không nhanh hơn Redis. Xem Cache/TransientInterface để đổi backend.
 *
 * @package Jankx\Flight\WordpressConcept\Cache
 */
final class Transient
{
    private const PREFIX        = '_transient_';
    private const TIMEOUT_PREFIX = '_transient_timeout_';

    /**
     * Đọc transient. Trả false nếu không có hoặc đã hết hạn.
     */
    public static function get(string $key): mixed
    {
        if ($key === '') {
            return false;
        }

        $timeout = Options::get(self::TIMEOUT_PREFIX . $key, null);

        // WordPress xoá cả hai option khi phát hiện hết hạn, để lần đọc sau
        // không phải chạm DB nữa.
        if ($timeout !== null && (int) $timeout < time()) {
            self::delete($key);

            return false;
        }

        $value = Options::get(self::PREFIX . $key, null);

        return $value ?? false;
    }

    /**
     * Ghi transient.
     *
     * @param int $ttl 0 = không hạn (đúng nghĩa của WordPress).
     */
    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        if ($key === '') {
            return false;
        }

        // ttl <= 0 nghĩa là vĩnh viễn: xoá option timeout cũ nếu có.
        if ($ttl > 0) {
            Options::setRaw(self::TIMEOUT_PREFIX . $key, (string) (time() + $ttl));
        } else {
            Options::deleteRaw(self::TIMEOUT_PREFIX . $key);
        }

        Options::setRaw(self::PREFIX . $key, Options::maybeSerialize($value));

        return true;
    }

    /**
     * Xoá transient và option timeout của nó.
     */
    public static function delete(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $existed = Options::exists(self::PREFIX . $key);

        Options::deleteRaw(self::PREFIX . $key);
        Options::deleteRaw(self::TIMEOUT_PREFIX . $key);

        return $existed;
    }

    /**
     * Nạp trước nhiều transient trong một vòng (ít round-trip hơn).
     *
     * @param string[] $keys
     */
    public static function prime(array $keys): void
    {
        $names = [];

        foreach ($keys as $key) {
            $names[] = self::PREFIX . $key;
            $names[] = self::TIMEOUT_PREFIX . $key;
        }

        Options::prime($names);
    }
}
