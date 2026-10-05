<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Db;

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

        return self::$requestCache[$postId] = new \WP_Post($row);
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
            : new \WP_Post($row);
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

    /**
     * Tạo bài viết mới.
     *
     * @param array<string, mixed> $data post_title, post_content, post_status…
     * @return int|WPError ID bài viết, hoặc lỗi.
     */
    public static function insert(array $data): int|WPError
    {
        $defaults = [
            'post_status'    => 'draft',
            'post_type'      => 'post',
            'post_author'    => 1,
            'post_content'   => '',
            'post_title'     => '',
            'post_excerpt'   => '',
            'post_name'      => '',
            'post_parent'    => 0,
            'menu_order'     => 0,
            'comment_status' => 'open',
            'ping_status'    => 'open',
            'to_ping'        => '',
            'pinged'         => '',
        ];

        $row = array_merge($defaults, $data);

        // Slug rỗng thì lấy từ tiêu đề, giống core.
        if ($row['post_name'] === '' && $row['post_title'] !== '') {
            $row['post_name'] = sanitize_title((string) $row['post_title']);
        }

        $row['post_date']     ??= gmdate('Y-m-d H:i:s');
        $row['post_date_gmt'] = $row['post_date'];
        $row['post_modified'] = $row['post_date'];
        $row['post_modified_gmt'] = $row['post_date'];

        $columns      = array_keys($row);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
        $values       = [];

        foreach ($row as $column => $value) {
            $values[':' . $column] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }

        try {
            Connection::instance()->perform(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    self::table(),
                    implode(', ', $columns),
                    implode(', ', $placeholders)
                ),
                $values
            )->execute();
        } catch (\PDOException $exception) {
            return new WPError('db_insert_error', $exception->getMessage());
        }

        $id = (int) Connection::instance()->lastInsertId();
        self::$requestCache[$id] = null; // buộc đọc lại ở lần sau

        return $id;
    }

    /**
     * Cập nhật bài viết có sẵn.
     *
     * @param array<string, mixed> $data
     * @return int|WPError Số dòng đã đổi, 0 nếu không có gì đổi, hoặc lỗi.
     */
    public static function update(int $postId, array $data): int|WPError
    {
        if (self::find($postId) === null) {
            return new WPError('invalid_post', 'Bài viết không tồn tại.');
        }

        if ($data === []) {
            return 0;
        }

        if (isset($data['post_modified'])) {
            $data['post_modified_gmt'] = $data['post_modified'];
        }

        $sets   = [];
        $values = [];

        foreach ($data as $column => $value) {
            $sets[]                  = $column . ' = :' . $column;
            $values[':' . $column]   = is_bool($value) ? ($value ? 1 : 0) : $value;
        }

        $values[':_id'] = $postId;

        try {
            $affected = Connection::instance()->fetchAffected(
                sprintf('UPDATE %s SET %s WHERE ID = :_id', self::table(), implode(', ', $sets)),
                $values
            );
        } catch (\PDOException $exception) {
            return new WPError('db_update_error', $exception->getMessage());
        }

        unset(self::$requestCache[$postId]);

        return $affected;
    }

    /**
     * Truy vấn danh sách bài viết theo điều kiện của get_posts().
     *
     * Chỉ nhận các tham số thực sự dùng trong request Ajax; không cố phục vụ
     * get_posts() đầy đủ của core (fields, meta_query, tax_query…).
     *
     * @param  array<string, mixed> $args
     * @return array<int, object>
     */
    public static function query(array $args = []): array
    {
        $defaults = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'paged'          => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'author'         => 0,
            'include'        => [],
            'exclude'        => [],
            'post_parent'    => null,
            's'              => '',
            'offset'         => 0,
        ];

        $args   = array_merge($defaults, $args);
        $where  = [];
        $values = [];

        $postTypes = (array) $args['post_type'];

        if ($postTypes !== [] && ! in_array('any', $postTypes, true)) {
            $names = [];
            $index = 0;

            foreach ($postTypes as $type) {
                $names[] = ':pt' . $index;
                $values[':pt' . $index] = $type;
                $index++;
            }

            $where[] = 'post_type IN (' . implode(', ', $names) . ')';
        }

        $statuses = (array) $args['post_status'];

        if ($statuses !== [] && ! in_array('any', $statuses, true)) {
            $names = [];
            $index = 0;

            foreach ($statuses as $status) {
                $names[] = ':st' . $index;
                $values[':st' . $index] = $status;
                $index++;
            }

            $where[] = 'post_status IN (' . implode(', ', $names) . ')';
        }

        if ((int) $args['include'] !== 0 && is_numeric($args['include'])) {
            $where[]        = 'ID = :include';
            $values[':include'] = (int) $args['include'];
        }

        if (is_array($args['include']) && $args['include'] !== []) {
            $names = [];
            $index = 0;

            foreach ($args['include'] as $id) {
                $names[] = ':inc' . $index;
                $values[':inc' . $index] = (int) $id;
                $index++;
            }

            $where[] = 'ID IN (' . implode(', ', $names) . ')';
        }

        if (is_array($args['exclude']) && $args['exclude'] !== []) {
            $names = [];
            $index = 0;

            foreach ($args['exclude'] as $id) {
                $names[] = ':exc' . $index;
                $values[':exc' . $index] = (int) $id;
                $index++;
            }

            $where[] = 'ID NOT IN (' . implode(', ', $names) . ')';
        }

        if ((int) $args['author'] > 0) {
            $where[]            = 'post_author = :author';
            $values[':author']  = (int) $args['author'];
        }

        if ($args['post_parent'] !== null) {
            $where[]                 = 'post_parent = :parent';
            $values[':parent']       = (int) $args['post_parent'];
        }

        if (is_string($args['s']) && $args['s'] !== '') {
            $where[]        = '(post_title LIKE :search OR post_content LIKE :search)';
            $values[':search'] = '%' . $args['s'] . '%';
        }

        $sql = 'SELECT * FROM ' . self::table();

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        // Chỉ nhận cột orderby an toàn – không nội suy tên cột từ input.
        $columns = [
            'date'     => 'post_date',
            'ID'       => 'ID',
            'id'       => 'ID',
            'title'    => 'post_title',
            'name'     => 'post_name',
            'modified' => 'post_modified',
            'menu_order' => 'menu_order',
            'rand'     => 'RAND()',
        ];

        $sql .= ' ORDER BY ' . ($columns[$args['orderby']] ?? 'post_date');
        $sql .= ' ' . (strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC');

        $perPage = (int) $args['posts_per_page'];
        $limit   = ' LIMIT ' . ($perPage > 0 ? $perPage : 10);

        if ((int) $args['offset'] > 0) {
            $limit .= ' OFFSET ' . (int) $args['offset'];
        } elseif ($perPage > 0 && (int) $args['paged'] > 1) {
            $limit .= ' OFFSET ' . ($perPage * ((int) $args['paged'] - 1));
        }

        try {
            $rows = Connection::instance()->fetchAll($sql . $limit, $values);
        } catch (\PDOException) {
            return [];
        }

        return array_map(static fn (array $row): \WP_Post => new \WP_Post($row), $rows);
    }

    /**
     * Số bài viết theo post type và trạng thái.
     *
     * @param array<string, string> $statuses
     * @return object Đối tượng đếm, giống wp_count_posts().
     */
    public static function countBy(array $statuses, string $postType): object
    {
        $counts = array_fill_keys(array_keys($statuses), 0);

        try {
            $rows = Connection::instance()->fetchAll(
                sprintf(
                    'SELECT post_status, COUNT(*) AS total FROM %s WHERE post_type = :type GROUP BY post_status',
                    self::table()
                ),
                [':type' => $postType]
            );
        } catch (\PDOException) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $status = (string) $row['post_status'];

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return (object) $counts;
    }

    /**
     * Bài viết con trực tiếp của một bài viết.
     *
     * @return array<int, object>
     */
    public static function children(int $parentId, string $postType = 'attachment', string $status = 'inherit'): array
    {
        return self::query([
            'post_type'   => $postType,
            'post_parent' => $parentId,
            'post_status' => $status,
        ]);
    }

    /**
     * MIME type của file đính kèm.
     */
    public static function mimeType(int $postId): string|false
    {
        $value = self::field($postId, 'post_mime_type', '');

        return is_string($value) && $value !== '' ? $value : false;
    }

    /**
     * ID ảnh đại diện của bài viết.
     */
    public static function thumbnailId(int $postId): int
    {
        $value = Meta::get('post', $postId, '_thumbnail_id', true);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Trạng thái hiện tại của bài viết.
     */
    public static function status(int $postId): string|false
    {
        $post = self::find($postId);

        return $post === null ? false : (string) $post->post_status;
    }

    /**
     * Bài viết theo đường dẫn phân cấp, vd 'cha/con'.
     */
    public static function byPath(string $path, string $postType = 'page'): object|null
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return null;
        }

        $parent = 0;
        $found  = null;

        foreach ($segments as $slug) {
            $found = Connection::instance()->fetchOne(
                sprintf(
                    'SELECT * FROM %s WHERE post_name = :slug AND post_type = :type AND post_parent = :parent LIMIT 1',
                    self::table()
                ),
                [':slug' => $slug, ':type' => $postType, ':parent' => $parent]
            );

            if ($found === null) {
                return null;
            }

            $parent = (int) $found['ID'];
        }

        return $found === null ? null : new \WP_Post($found);
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
