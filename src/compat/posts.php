<?php

/**
 * Compat: hàm post/comment/term của WordPress (global namespace).
 *
 * Các hàm ở đây là lớp vỏ mỏng đặt trên Db\Posts, Db\Meta và $wpdb – extension
 * gọi đúng tên WordPress, phần truy vấn nằm trong src/Db.
 *
 * Phạm vi: những gì một request Ajax cần. get_posts() không hỗ trợ meta_query
 * hay tax_query; nếu extension cần thì phải đi qua $wpdb.
 *
 * @package Jankx\Flight\WordpressConcept
 */

use Jankx\Flight\WordpressConcept\Db\Meta;
use Jankx\Flight\WordpressConcept\Db\Posts;
use Jankx\Flight\WordpressConcept\Db\Wpdb;
use Jankx\Flight\WordpressConcept\WPError;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

// ── Post ─────────────────────────────────────────────────────────────────────

if (! function_exists('get_posts')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, object>
     */
    function get_posts(array $args = []): array
    {
        return Posts::query($args);
    }
}

if (! function_exists('wp_insert_post')) {
    /**
     * @param  array<string, mixed> $data
     * @param  bool                  $wpError
     * @return int|object
     */
    function wp_insert_post(array $data, bool $wpError = false)
    {
        $result = Posts::insert($data);

        return $wpError ? $result : (is_wp_error($result) ? 0 : $result);
    }
}

if (! function_exists('wp_update_post')) {
    /**
     * @param array<string, mixed>|object $data
     */
    function wp_update_post(array|object $data, bool $wpError = false)
    {
        $data  = (array) $data;
        $id    = (int) ($data['ID'] ?? 0);
        unset($data['ID']);

        $result = $id > 0 ? Posts::update($id, $data) : new WP_Error('invalid_post', 'Thiếu ID bài viết.');

        return $wpError ? $result : (is_wp_error($result) ? 0 : $result);
    }
}

if (! function_exists('get_children')) {
    /**
     * @param array<string, mixed> $args
     */
    function get_children(array $args = [], string $output = 'OBJECT'): array
    {
        $args = array_merge(['post_type' => 'attachment', 'post_status' => 'inherit'], $args);

        $rows = Posts::query($args);

        if (strtoupper($output) === 'ARRAY_A') {
            return array_map(static fn (object $row): array => (array) $row, $rows);
        }

        return $rows;
    }
}

if (! function_exists('get_post_status')) {
    /**
     * @param  int|object $post
     * @return string|false
     */
    function get_post_status(int|object $post = 0): string|false
    {
        $id = $post instanceof \stdClass ? (int) $post->ID : (int) $post;

        return $id > 0 ? Posts::status($id) : false;
    }
}

if (! function_exists('get_post_mime_type')) {
    function get_post_mime_type(int|object $post = 0): string|false
    {
        $id = $post instanceof \stdClass ? (int) $post->ID : (int) $post;

        return $id > 0 ? Posts::mimeType($id) : false;
    }
}

if (! function_exists('get_post_thumbnail_id')) {
    function get_post_thumbnail_id(int|object $post = 0): int
    {
        $id = $post instanceof \stdClass ? (int) $post->ID : (int) $post;

        return $id > 0 ? Posts::thumbnailId($id) : 0;
    }
}

if (! function_exists('has_post_thumbnail')) {
    function has_post_thumbnail(int|object $post = 0): bool
    {
        return get_post_thumbnail_id($post) > 0;
    }
}

if (! function_exists('wp_count_posts')) {
    /**
     * @param string             $postType
     * @param array|string|null  $perm
     */
    function wp_count_posts(string $postType = 'post', mixed $perm = null): object
    {
        $statuses = [
            'publish' => 'publish',
            'future'  => 'future',
            'draft'   => 'draft',
            'pending' => 'pending',
            'private' => 'private',
            'trash'   => 'trash',
            'auto-draft' => 'auto-draft',
            'inherit' => 'inherit',
        ];

        return Posts::countBy($statuses, $postType);
    }
}

if (! function_exists('get_page_by_path')) {
    function get_page_by_path(string $path, string $output = 'OBJECT', string|array $postTypes = 'page'): object|null
    {
        foreach ((array) $postTypes as $postType) {
            $found = Posts::byPath($path, $postType);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}

if (! function_exists('get_page_uri')) {
    function get_page_uri(int|object $post = 0): string|false
    {
        $id      = $post instanceof \stdClass ? (int) $post->ID : (int) $post;
        $found   = Posts::find($id);

        return $found === null ? false : (string) $found->post_name;
    }
}

if (! function_exists('get_post_type_archive_link')) {
    function get_post_type_archive_link(string $postType): string|false
    {
        return post_type_exists($postType) ? home_url(get_post_type_object($postType)->rewrite->slug ?? $postType) : false;
    }
}

if (! function_exists('get_post_type_labels')) {
    function get_post_type_labels(string $postType): array
    {
        $object = get_post_type_object($postType);

        return $object === null ? [] : (array) $object->labels;
    }
}

// ── Comment ──────────────────────────────────────────────────────────────────

if (! function_exists('get_comment')) {
    function get_comment(int|object|null $comment = null, string $output = 'OBJECT'): object|null
    {
        $id = is_object($comment) ? (int) $comment->comment_ID : (int) $comment;

        if ($id <= 0 || ! isset($GLOBALS['wpdb'])) {
            return null;
        }

        $row = $GLOBALS['wpdb']->get_row(
            $GLOBALS['wpdb']->prepare(
                sprintf('SELECT * FROM %s WHERE comment_ID = %d LIMIT 1', $GLOBALS['wpdb']->comments),
                $id
            ),
            ARRAY_A
        );

        return $row === null ? null : (object) $row;
    }
}

if (! function_exists('get_comments')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, object>
     */
    function get_comments(array $args = []): array
    {
        if (! isset($GLOBALS['wpdb'])) {
            return [];
        }

        $wpdb     = $GLOBALS['wpdb'];
        $where    = [];
        $values   = [];

        if ((int) ($args['post_id'] ?? 0) > 0) {
            $where[]        = 'comment_post_ID = :post_id';
            $values[':post_id'] = (int) $args['post_id'];
        }

        if (! empty($args['status'])) {
            $statuses = (array) $args['status'];
            $names    = [];
            $index    = 0;

            foreach ($statuses as $status) {
                $names[] = ':s' . $index;
                $values[':s' . $index] = $status;
                $index++;
            }

            $where[] = 'comment_approved IN (' . implode(', ', $names) . ')';
        }

        if ((int) ($args['user_id'] ?? 0) > 0) {
            $where[]          = 'user_id = :user_id';
            $values[':user_id'] = (int) $args['user_id'];
        }

        $sql = 'SELECT * FROM ' . $wpdb->comments;

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY ' . (($args['order'] ?? 'DESC') === 'ASC' ? 'comment_date ASC' : 'comment_date DESC');

        $number = (int) ($args['number'] ?? 0);

        if ($number > 0) {
            $sql .= ' LIMIT ' . $number;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return $rows === [] ? [] : array_map(static fn (array $row): object => (object) $row, $rows);
    }
}

if (! function_exists('get_comment_meta')) {
    function get_comment_meta(int $commentId, string $key = '', bool $single = false): mixed
    {
        return Meta::get('comment', $commentId, $key, $single);
    }
}

if (! function_exists('update_comment_meta')) {
    function update_comment_meta(int $commentId, string $key, mixed $value, mixed $prevValue = ''): bool|int
    {
        return Meta::update('comment', $commentId, $key, $value);
    }
}

if (! function_exists('delete_comment_meta')) {
    function delete_comment_meta(int $commentId, string $key, mixed $value = ''): bool
    {
        return Meta::delete('comment', $commentId, $key);
    }
}

if (! function_exists('get_comment_author')) {
    function get_comment_author(int|object $commentId = 0): string
    {
        $comment = is_object($commentId) ? $commentId : get_comment($commentId);

        return $comment === null ? '' : (string) $comment->comment_author;
    }
}

if (! function_exists('comments_open')) {
    function comments_open(int|object $post = 0): bool
    {
        $id = $post instanceof \stdClass ? (int) $post->ID : (int) $post;

        if ($id <= 0) {
            return false;
        }

        return (string) Posts::field($id, 'comment_status', 'closed') === 'open';
    }
}

if (! function_exists('get_edit_comment_link')) {
    function get_edit_comment_link(int $commentId = 0, string $context = 'display'): string
    {
        return admin_url('comment.php?action=editcomment&c=' . $commentId);
    }
}

// ── Term ─────────────────────────────────────────────────────────────────────

if (! function_exists('get_object_taxonomies')) {
    /**
     * @return array<int, string>|array<int, object>
     */
    function get_object_taxonomies(string|object $objectType, string $output = 'names'): array
    {
        $postType = is_object($objectType) ? (string) $objectType->post_type : $objectType;

        $taxonomies = [];

        foreach ($GLOBALS['wp_taxonomies'] ?? [] as $name => $taxonomy) {
            if (in_array($postType, $taxonomy->object_type, true)) {
                $taxonomies[] = $output === 'objects' ? $taxonomy : $name;
            }
        }

        return $taxonomies;
    }
}

if (! function_exists('get_the_terms')) {
    /**
     * @return array<int, object>|false
     */
    function get_the_terms(int $postId, string $taxonomy): array|false
    {
        if (! isset($GLOBALS['wpdb'])) {
            return false;
        }

        $wpdb = $GLOBALS['wpdb'];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                sprintf(
                    'SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
                     FROM %s AS tr
                     INNER JOIN %s AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     INNER JOIN %s AS t ON t.term_id = tt.term_id
                     WHERE tr.object_id = :object_id AND tt.taxonomy = :taxonomy',
                    $wpdb->term_relationships,
                    $wpdb->term_taxonomy,
                    $wpdb->terms
                ),
                ['object_id' => $postId, 'taxonomy' => $taxonomy]
            ),
            ARRAY_A
        );

        return $rows === [] ? false : array_map(static fn (array $row): object => (object) $row, $rows);
    }
}

if (! function_exists('wp_get_post_terms')) {
    /**
     * @return array<int, object>
     */
    function wp_get_post_terms(int $postId, string $taxonomy = 'post_tag', array $args = []): array
    {
        $terms = get_the_terms($postId, $taxonomy);

        if ($terms === false) {
            return [];
        }

        $fields = (array) ($args['fields'] ?? 'all');

        if ($fields === ['ids'] || $fields === ['id=>name']) {
            return array_map(static fn (object $term): int => (int) $term->term_id, $terms);
        }

        return $terms;
    }
}

if (! function_exists('get_term_by')) {
    function get_term_by(string $field, string|int $value, string $taxonomy = '', string $output = 'OBJECT', string $filter = 'raw'): object|false
    {
        if (! isset($GLOBALS['wpdb'])) {
            return false;
        }

        $wpdb = $GLOBALS['wpdb'];

        $columns = ['id' => 't.term_id', 'slug' => 't.slug', 'name' => 't.name'];

        if (! isset($columns[$field])) {
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                sprintf(
                    'SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
                     FROM %s AS t INNER JOIN %s AS tt ON tt.term_id = t.term_id
                     WHERE %s = :value' . ($taxonomy !== '' ? ' AND tt.taxonomy = :taxonomy' : '') . ' LIMIT 1',
                    $wpdb->terms,
                    $wpdb->term_taxonomy,
                    $columns[$field]
                ),
                array_filter(['value' => $value, 'taxonomy' => $taxonomy], static fn (mixed $v): bool => $v !== '')
            ),
            ARRAY_A
        );

        return $row === null ? false : (object) $row;
    }
}

if (! function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy = '', string $output = 'OBJECT'): object|false
    {
        return get_term_by('id', $termId, $taxonomy, $output);
    }
}

if (! function_exists('get_category_by_slug')) {
    function get_category_by_slug(string $slug, string $output = 'OBJECT'): object|false
    {
        $term = get_term_by('slug', $slug, 'category', $output);

        if ($term !== false) {
            return $term;
        }

        $term = get_term_by('slug', $slug, 'post_tag', $output);

        return $term === false ? null : $term;
    }
}

if (! function_exists('get_terms')) {
    /**
     * Chỉ hỗ trợ taxonomy/slug/include/hide_empty – đủ cho phần Ajax.
     *
     * @return array<int, object>
     */
    function get_terms(array|string $args = [], string $deprecated = ''): array
    {
        if (! isset($GLOBALS['wpdb'])) {
            return [];
        }

        $args = is_string($args) ? ['taxonomy' => $args] : $args;
        $wpdb = $GLOBALS['wpdb'];

        $taxonomies = (array) ($args['taxonomy'] ?? []);
        $where      = [];
        $values     = [];

        if ($taxonomies !== [] && ! in_array('any', $taxonomies, true)) {
            $names = [];
            $index = 0;

            foreach ($taxonomies as $taxonomy) {
                $names[] = ':t' . $index;
                $values[':t' . $index] = $taxonomy;
                $index++;
            }

            $where[] = 'tt.taxonomy IN (' . implode(', ', $names) . ')';
        }

        if (! empty($args['slug'])) {
            $slugs = (array) $args['slug'];
            $names = [];
            $index = 0;

            foreach ($slugs as $slug) {
                $names[] = ':sl' . $index;
                $values[':sl' . $index] = $slug;
                $index++;
            }

            $where[] = 't.slug IN (' . implode(', ', $names) . ')';
        }

        if (! empty($args['include'])) {
            $ids   = array_map('intval', (array) $args['include']);
            $where[] = 't.term_id IN (' . implode(', ', $ids) . ')';
        }

        $sql = sprintf(
            'SELECT t.term_id, t.name, t.slug, tt.description, tt.parent, tt.count
             FROM %s AS t INNER JOIN %s AS tt ON tt.term_id = t.term_id',
            $wpdb->terms,
            $wpdb->term_taxonomy
        );

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if (! empty($args['hide_empty'])) {
            $sql .= ' AND tt.count > 0';
        }

        $number = (int) ($args['number'] ?? 0);

        if ($number > 0) {
            $sql .= ' LIMIT ' . $number;
        }

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return $rows === [] ? [] : array_map(static fn (array $row): object => (object) $row, $rows);
    }
}

if (! function_exists('register_taxonomy_for_object_type')) {
    function register_taxonomy_for_object_type(string $taxonomy, string $objectType): bool
    {
        $object = get_taxonomy($taxonomy);

        if ($object === false) {
            return false;
        }

        $object->add_supports($objectType);

        return true;
    }
}

if (! function_exists('wp_insert_term')) {
    /**
     * @return array{term_id: int, term_taxonomy_id: int}|WP_Error
     */
    function wp_insert_term(string $term, string $taxonomy, array $args = []): array|WP_Error
    {
        if (! isset($GLOBALS['wpdb'])) {
            return new WP_Error('db_insert_error', 'Không có kết nối cơ sở dữ liệu.');
        }

        $wpdb   = $GLOBALS['wpdb'];
        $name   = trim(wp_strip_all_tags($term));
        $slug   = $args['slug'] ?? sanitize_title($name);
        $parent = (int) ($args['parent'] ?? 0);

        $wpdb->insert($wpdb->terms, ['name' => $name, 'slug' => $slug]);
        $termId = (int) $wpdb->insert_id;

        if ($termId <= 0) {
            return new WP_Error('db_insert_error', 'Không tạo được term.');
        }

        $wpdb->insert($wpdb->term_taxonomy, [
            'term_id'     => $termId,
            'taxonomy'    => $taxonomy,
            'description' => (string) ($args['description'] ?? ''),
            'parent'      => $parent,
            'count'       => 0,
        ]);

        return ['term_id' => $termId, 'term_taxonomy_id' => (int) $wpdb->insert_id];
    }
}

if (! function_exists('wp_insert_category')) {
    /**
     * @return int|WP_Error
     */
    function wp_insert_category(array $data, bool $wpError = false)
    {
        $result = wp_insert_term((string) ($data['category_name'] ?? ''), 'category', $data);

        return $wpError ? $result : (is_wp_error($result) ? 0 : $result['term_id']);
    }
}

if (! function_exists('wp_update_term')) {
    /**
     * @return array|WP_Error
     */
    function wp_update_term(int $termId, string $taxonomy, array $args = []): array|WP_Error
    {
        if (! isset($GLOBALS['wpdb'])) {
            return new WP_Error('db_update_error', 'Không có kết nối cơ sở dữ liệu.');
        }

        $data = [];

        if (isset($args['name'])) {
            $data['name'] = trim(wp_strip_all_tags((string) $args['name']));
        }

        if (isset($args['slug'])) {
            $data['slug'] = (string) $args['slug'];
        }

        if ($data !== []) {
            $GLOBALS['wpdb']->update($GLOBALS['wpdb']->terms, $data, ['term_id' => $termId]);
        }

        if (isset($args['description'])) {
            $GLOBALS['wpdb']->update(
                $GLOBALS['wpdb']->term_taxonomy,
                ['description' => (string) $args['description']],
                ['term_id' => $termId, 'taxonomy' => $taxonomy]
            );
        }

        return ['term_id' => $termId, 'term_taxonomy_id' => 0];
    }
}

if (! function_exists('wp_set_object_terms')) {
    /**
     * @param  int|int[] $objectIds
     * @param  int[]     $termIds
     * @return array<int, int>
     */
    function wp_set_object_terms(int|array $objectIds, int|array $termIds, string $taxonomy, bool $append = false): array
    {
        if (! isset($GLOBALS['wpdb'])) {
            return [];
        }

        $wpdb     = $GLOBALS['wpdb'];
        $objectIds = (array) $objectIds;
        $termIds   = array_values(array_filter(array_map('intval', (array) $termIds)));

        $assigned = [];

        foreach ($objectIds as $objectId) {
            if (! $append) {
                $wpdb->delete($wpdb->term_relationships, ['object_id' => $objectId]);
            }

            foreach ($termIds as $termId) {
                // Bỏ qua cặp đã có để append không nhân bản.
                $exists = $wpdb->get_var($wpdb->prepare(
                    sprintf(
                        'SELECT COUNT(*) FROM %s WHERE object_id = %d AND term_taxonomy_id = (SELECT term_taxonomy_id FROM %s WHERE term_id = %d LIMIT 1)',
                        $wpdb->term_relationships,
                        $objectId,
                        $wpdb->term_taxonomy,
                        $termId
                    )
                ));

                if ((int) $exists > 0) {
                    continue;
                }

                $taxonomyId = (int) $wpdb->get_var($wpdb->prepare(
                    sprintf('SELECT term_taxonomy_id FROM %s WHERE term_id = %d AND taxonomy = %s LIMIT 1', $wpdb->term_taxonomy, $termId, $taxonomy)
                ));

                if ($taxonomyId <= 0) {
                    continue;
                }

                $wpdb->insert($wpdb->term_relationships, [
                    'object_id'        => $objectId,
                    'term_taxonomy_id' => $taxonomyId,
                    'term_order'       => 0,
                ]);

                $assigned[] = $termId;
            }
        }

        return $assigned;
    }
}

if (! function_exists('wp_set_post_terms')) {
    /**
     * @param int[] $terms
     */
    function wp_set_post_terms(int $postId, array $terms = [], string $taxonomy = 'post_tag', bool $append = false): array
    {
        return wp_set_object_terms($postId, $terms, $taxonomy, $append);
    }
}

if (! function_exists('wp_get_object_terms')) {
    /**
     * @param  int|int[] $objectIds
     * @return array<int, object>
     */
    function wp_get_object_terms(int|array $objectIds, string|array $taxonomies, array $args = []): array|WP_Error
    {
        $results = [];

        foreach ((array) $objectIds as $objectId) {
            foreach ((array) $taxonomies as $taxonomy) {
                $terms = get_the_terms($objectId, $taxonomy);

                if ($terms !== false) {
                    $results = array_merge($results, $terms);
                }
            }
        }

        return $results;
    }
}
