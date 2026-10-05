<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Hooks;

/**
 * Hooks – hệ hook tương thích WordPress, chạy không cần WordPress.
 *
 * Extension của Jankx viết `add_action('init', ...)`, `apply_filters('jankx/
 * ecommerce/...')` theo đúng ngữ nghĩa WordPress. Bộ dispatcher này giữ nguyên
 * ngữ nghĩa đó (sort theo priority, giới hạn số đối số theo accepted_args,
 * cho phép đăng ký cùng callback ở nhiều priority) để code extension chạy
 * được nguyên vẹn khi AJAX không còn WordPress.
 *
 * Không có filter trong WordPress (ví dụ `salt`, `auth_cookie_valid`) được
 * mô phỏng lại ở đây – security decision của Auth không đi qua hook, để
 * việc thêm hook không thể vô hiệu hoá việc xác thực cookie.
 *
 * @package Jankx\Flight\WordpressConcept\Hooks
 */
final class Hooks
{
    /** @var array<string, array<int, array<string, array{callback: callable, args: int}>>> */
    private static array $hooks = [];

    /** @var array<string, int> */
    private static array $counts = [];

    /** @var string[] Hook đang được thực thi, để hỗ trợ doing_action(). */
    private static array $current = [];

    /**
     * Đăng ký callback cho một hook.
     *
     * @param string   $tag           Tên hook.
     * @param callable $callback      Callback.
     * @param int      $priority      Số nhỏ chạy trước.
     * @param int      $acceptedArgs  Số đối số tối đa truyền vào.
     */
    public static function add(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        self::$hooks[$tag][$priority][self::callbackId($callback)] = [
            'callback' => $callback,
            'args'     => $acceptedArgs,
        ];

        // Sắp xếp lại theo priority tăng dần, giữ thứ tự đăng ký trong cùng
        // priority (PHP giữ thứ tự chèn khi ksort trên cùng key).
        if (count(self::$hooks[$tag]) > 1) {
            ksort(self::$hooks[$tag], SORT_NUMERIC);
        }
    }

    public static function remove(string $tag, callable $callback, int $priority = 10): bool
    {
        $id = self::callbackId($callback);

        if (! isset(self::$hooks[$tag][$priority][$id])) {
            return false;
        }

        unset(self::$hooks[$tag][$priority][$id]);

        return true;
    }

    public static function has(string $tag, ?callable $callback = null): bool
    {
        if (! isset(self::$hooks[$tag])) {
            return false;
        }

        if ($callback === null) {
            return true;
        }

        $id = self::callbackId($callback);
        foreach (self::$hooks[$tag] as $callbacks) {
            if (isset($callbacks[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chạy action: mọi callback nhận đối số, giá trị trả về bị bỏ qua.
     *
     * @param array<int, mixed> $args
     */
    public static function doAction(string $tag, array $args = []): void
    {
        self::$current[] = $tag;

        foreach (self::callbacksFor($tag) as $entry) {
            self::invoke($entry, $args);
        }

        array_pop(self::$current);

        self::$counts[$tag] = (self::$counts[$tag] ?? 0) + 1;
    }

    /**
     * Chạy filter: giá trị đầu tiên đi qua chuỗi callback.
     *
     * @param array<int, mixed> $args Đối số đầu là giá trị cần lọc.
     */
    public static function applyFilters(string $tag, array $args = []): mixed
    {
        if ($args === []) {
            $args = [null];
        }

        self::$current[] = $tag;

        foreach (self::callbacksFor($tag) as $entry) {
            $args[0] = self::invoke($entry, $args);
        }

        array_pop(self::$current);

        self::$counts[$tag] = (self::$counts[$tag] ?? 0) + 1;

        return $args[0];
    }

    /**
     * Số lần hook đã chạy.
     */
    public static function didAction(string $tag): int
    {
        return self::$counts[$tag] ?? 0;
    }

    /**
     * Hook đang chạy; null khi không có.
     *
     * @return string|string[]|null
     */
    public static function current(): string|array|null
    {
        if (self::$current === []) {
            return null;
        }

        return count(self::$current) === 1 ? self::$current[0] : self::$current;
    }

    /**
     * Đang chạy hook $tag hay không.
     */
    public static function doing(string $tag): bool
    {
        return in_array($tag, self::$current, true);
    }

    /**
     * Xoá toàn bộ hook (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        self::$hooks   = [];
        self::$counts  = [];
        self::$current = [];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array<int, array{callback: callable, args: int}>
     */
    private static function callbacksFor(string $tag): array
    {
        return self::$hooks[$tag] ?? [];
    }

    /**
     * @param array{callback: callable, args: int} $entry
     * @param array<int, mixed>                     $args
     */
    private static function invoke(array $entry, array $args): mixed
    {
        return ($entry['callback'])(...array_slice($args, 0, $entry['args']));
    }

    /**
     * Khoá ổn định cho một callback, để remove/has hoạt động với cả
     * closure, string function name, "Class::method" và [object, 'method'].
     */
    private static function callbackId(callable $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            $target = is_object($callback[0]) ? spl_object_hash($callback[0]) : $callback[0];

            return $target . '::' . $callback[1];
        }

        // Closure hoặc object invokable
        return spl_object_hash($callback);
    }
}
