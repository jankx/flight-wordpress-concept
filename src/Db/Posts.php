<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Db;

use Jankx\Flight\WordpressConcept\Cache\Options;
use Jankx\Flight\WordpressConcept\Config;
use Jankx\Flight\WordpressConcept\WPError;

/**
 * Posts – đọc bài viết trực tiếp từ bảng `wp_posts`.
 *
 * `get_post()` của core trả về đối tượng WP_Post kèm hàng chục trường đã được
 * lọc theo `post_` fields, cộng thêm filter. Ở đây trả về stdClass với đủ cột
 * của bảng – đủ dùng cho phía đọc của extension và không kéo theo toàn bộ
 * hệ thống filter của core.
 *
 * Ghi nhớ trong phạm vi request: WP cũng cache bằng wp_cache_get('post', id).
 *
 * @package Jankx\Flight\WordpressConcept\Db
 */
final class Posts
{
    /** @var array<int, object|null> */
    private static array $requestCache = [];

    /**
     * Lấy một bài viết. Trả null nếu không có.
     */
    public static function find(int $postId): ?object
    {
        if ($postId <= 0) {
            return null;
        }

        if (array_key_exists($postId, self::$requestCache)) {
            return self::$requestCache[$postId];
        }

        $row = Connection::instance()->fetchOne(
            sprintf('SELECT * FROM %s WHERE ID = :id LIMIT 1', self::table()),
            [':id' => $postId]
        );

        if ($row === null) {
            return self::$requestCache[$postId] = null;
        }

        return self::$requestCache[$postId] = (object) $row;
    }

    /**
     * Một bài viết bất kỳ: theo ID, hoặc theo slug khi truyền chuỗi.
     *
     * Khác core, hàm này KHÔNG nhận tham số $output/$filter.
     */
    public static function get(int|string $post = 0): mixed
    {
        if (is_int($post) || ctype_digit((string) $post)) {
            $found = self::find((int) $post);

            return $found ?? new WPError('invalid_post', 'Bài viết không tồn tại.');
        }

        $slug = (string) $post;

        if (trim($slug) === '') {
            return new WPError('empty_query', 'Không có slug bài viết nào được chỉ định.');
        }

        $row = Connection::instance()->fetchOne(
            sprintf(
                'SELECT * FROM %s WHERE post_name = :slug AND post_status = :status LIMIT 1',
                self::table()
            ),
            [':slug' => $slug, ':status' => 'publish']
        );

        return $row === null
            ? new WPError('invalid_post', 'Bài viết không tồn tại.')
            : (object) $row;
    }

    /**
     * Post type của một bài viết.
     */
    public static function typeOf(int $postId): string|false
    {
        $post = self::find($postId);

        return $post === null ? false : (string) $post->post_type;
    }

    /**
     * Tiêu đề đã hiển thị (giống the_title).
     */
    public static function title(int $postId): string
    {
        $post = self::find($postId);

        if ($post === null) {
            return '';
        }

        return html_entity_decode((string) $post->post_title, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Một trường bất kỳ của bài viết.
     */
    public static function field(int $postId, string $field, mixed $default = ''): mixed
    {
        $post = self::find($postId);

        if ($post === null || ! isset($post->{$field})) {
            return $default;
        }

        return $post->{$field};
    }

    /**
     * ID của bài viết đã publish theo slug.
     */
    public static function idBySlug(string $slug): int
    {
        $value = Connection::instance()->fetchValue(
            sprintf(
                'SELECT ID FROM %s WHERE post_name = :slug AND post_status = :status LIMIT 1',
                self::table()
            ),
            [':slug' => $slug, ':status' => 'publish']
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    public static function reset(): void
    {
        self::$requestCache = [];
    }

    private static function table(): string
    {
        return Config::load(dirname(__DIR__, 2))->table('posts');
    }
}
