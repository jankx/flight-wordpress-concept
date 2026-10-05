<?php

/**
 * Compat: các lớp dữ liệu của WordPress (global namespace).
 *
 * Extension kiểm tra `instanceof \WP_Post`, `instanceof \WP_User` rất nhiều
 * (130 chỗ với WP_Post). Nếu package trả stdClass thì các nhánh đó rơi vào
 * "không phải" và im lặng bỏ qua dữ liệu – hoặc tệ hơn, dựng object với
 * thuộc tính null. Nên đây phải là lớp thật, đúng tên và đúng thuộc tính.
 *
 * Thuộc tính lấy nguyên tên cột trong bảng (post_title, user_login…) vì đó là
 * thứ extension đọc. Không mô phỏng filter/sanitize của core: request Ajax
 * không render, chỉ cần dữ liệu đúng.
 *
 * @package Jankx\Flight\WordpressConcept
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public int $post_author = 0;
        public string $post_date = '';
        public string $post_date_gmt = '';
        public string $post_content = '';
        public string $post_title = '';
        public string $post_excerpt = '';
        public string $post_status = 'publish';
        public string $comment_status = 'open';
        public string $ping_status = 'open';
        public string $post_password = '';
        public string $post_name = '';
        public string $to_ping = '';
        public string $pinged = '';
        public string $post_modified = '';
        public string $post_modified_gmt = '';
        public string $post_content_filtered = '';
        public int $post_parent = 0;
        public string $guid = '';
        public int $menu_order = 0;
        public string $post_type = 'post';
        public string $post_mime_type = '';
        public string $comment_count = '0';
        public string $filter = 'raw';

        /**
         * Cột lạ ngoài danh sách trên (site có thể đã thêm cột riêng) vẫn phải
         * đọc được, nên giữ nguyên trong mảng gốc.
         *
         * @var array<string, mixed>
         */
        private array $extra = [];

        public function __construct(object|array $data = [])
        {
            foreach ((array) $data as $key => $value) {
                if ($key === 'ID' && ! isset($this->post_author)) {
                    $this->ID = (int) $value;
                    continue;
                }

                if (property_exists($this, (string) $key) && ! isset($this->extra[(string) $key])) {
                    // Ép kiểu cho đúng khai báo, tránh TypeError khi DB trả
                    // chuỗi cho cột kiểu số.
                    $declared = (new \ReflectionProperty($this, (string) $key))->getType();

                    $this->{$key} = $declared instanceof \ReflectionNamedType && $declared->getName() === 'int'
                        ? (int) $value
                        : (is_scalar($value) || $value === null ? (string) $value : $value);

                    continue;
                }

                $this->extra[(string) $key] = $value;
            }
        }

        /**
         * @return array<string, mixed>
         */
        public function to_array(): array
        {
            return array_merge(get_object_vars($this), $this->extra);
        }

        /**
         * Cho phép đọc cột lạ không khai sẵn, giống WP_Post của core (trả null
         * thay vì báo lỗi).
         */
        public function __get(string $key): mixed
        {
            return $this->extra[$key] ?? null;
        }

        public function __isset(string $key): bool
        {
            return isset($this->extra[$key]);
        }

        public function __set(string $key, mixed $value): void
        {
            $this->extra[$key] = $value;
        }
    }
}

if (! class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_pass = '';
        public string $user_nicename = '';
        public string $user_email = '';
        public string $user_url = '';
        public string $user_registered = '';
        public string $display_name = '';
        public string $user_firstname = '';
        public string $user_lastname = '';
        public string $user_description = '';
        public string $user_activation_key = '';

        /** @var array<int, string> */
        public array $roles = [];

        /** @var array<string, bool> */
        public array $allcaps = [];

        public string $filter = 'raw';

        /** @var array<string, mixed> */
        private array $extra = [];

        public function __construct(object|array $data = [], array $roles = [], array $capabilities = [])
        {
            foreach ((array) $data as $key => $value) {
                if (property_exists($this, (string) $key)) {
                    $this->{$key} = $key === 'ID' ? (int) $value : (is_scalar($value) || $value === null ? (string) $value : $value);
                    continue;
                }

                $this->extra[(string) $key] = $value;
            }

            $this->roles    = $roles;
            $this->allcaps  = $capabilities;
        }

        /**
         * Kiểm tra quyền. Không đối chiếu database mỗi lần gọi – quyền đã nạp
         * sẵn khi user được dựng.
         */
        public function has_cap(string $capability, mixed ...$args): bool
        {
            if ($capability === 'exist' || $capability === '') {
                return $this->ID > 0;
            }

            return $this->allcaps[$capability] ?? false;
        }

        public function exists(): bool
        {
            return $this->ID > 0;
        }

        public function get(string $key): mixed
        {
            return property_exists($this, $key) ? $this->{$key} : ($this->extra[$key] ?? null);
        }

        public function __get(string $key): mixed
        {
            return $this->extra[$key] ?? null;
        }

        public function __isset(string $key): bool
        {
            return isset($this->extra[$key]);
        }
    }
}

if (! class_exists('WP_Comment')) {
    class WP_Comment
    {
        public int $comment_ID = 0;
        public int $comment_post_ID = 0;
        public string $comment_author = '';
        public string $comment_author_email = '';
        public string $comment_author_url = '';
        public string $comment_author_IP = '';
        public string $comment_date = '';
        public string $comment_date_gmt = '';
        public string $comment_content = '';
        public int $comment_karma = 0;
        public string $comment_approved = '1';
        public string $comment_agent = '';
        public string $comment_type = 'comment';
        public int $comment_parent = 0;
        public int $user_id = 0;

        public function __construct(object|array $data = [])
        {
            foreach ((array) $data as $key => $value) {
                if (! property_exists($this, (string) $key)) {
                    continue;
                }

                $declared = (new \ReflectionProperty($this, (string) $key))->getType();

                $this->{$key} = $declared instanceof \ReflectionNamedType && $declared->getName() === 'int'
                    ? (int) $value
                    : (is_scalar($value) || $value === null ? (string) $value : $value);
            }
        }

        public function to_array(): array
        {
            return get_object_vars($this);
        }

        public function __get(string $key): mixed
        {
            return null;
        }
    }
}

if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $term_group = '';
        public int $term_taxonomy_id = 0;
        public string $taxonomy = '';
        public string $description = '';
        public int $parent = 0;
        public int $count = 0;
        public string $filter = 'raw';

        /** @var array<string, mixed> */
        private array $extra = [];

        public function __construct(object|array $data = [], string $taxonomy = '')
        {
            foreach ((array) $data as $key => $value) {
                if (property_exists($this, (string) $key)) {
                    $declared = (new \ReflectionProperty($this, (string) $key))->getType();

                    $this->{$key} = $declared instanceof \ReflectionNamedType && $declared->getName() === 'int'
                        ? (int) $value
                        : (is_scalar($value) || $value === null ? (string) $value : $value);
                    continue;
                }

                $this->extra[(string) $key] = $value;
            }

            if ($taxonomy !== '') {
                $this->taxonomy = $taxonomy;
            }
        }

        public function to_array(): array
        {
            return array_merge(get_object_vars($this), $this->extra);
        }

        public function __get(string $key): mixed
        {
            return $this->extra[$key] ?? null;
        }

        public function __isset(string $key): bool
        {
            return isset($this->extra[$key]);
        }
    }
}

if (! class_exists('WP_Query')) {
    /**
     * Thu nhỏ của WP_Query: đủ cho vòng lặp có_posts()/the_post() trong template
     * và cho các đoạn gọi WP_Query rồi đọc ->posts.
     *
     * Không có parse_query đầy đủ, tax_query hay meta_query của core.
     */
    class WP_Query
    {
        /** @var array<int, WP_Post> */
        public array $posts = [];

        public int $post_count = 0;
        public int $found_posts = 0;
        public int $max_num_pages = 0;
        public int $current_post = -1;
        public ?WP_Post $post = null;
        public string $request = '';

        /** @var array<int, object> */
        public array $post__in = [];

        public function __construct(array|string $query = [])
        {
            $args = is_string($query) ? ['p' => $query] : $query;

            $posts = \Jankx\Flight\WordpressConcept\Db\Posts::query($args);

            $this->posts      = array_values(array_filter($posts, static fn (mixed $post): bool => $post instanceof WP_Post));
            $this->post_count = count($this->posts);
            $this->found_posts = $this->post_count;
            $this->max_num_pages = $this->post_count > 0 ? 1 : 0;
        }

        public function have_posts(): bool
        {
            return $this->current_post + 1 < $this->post_count;
        }

        public function the_post(): void
        {
            $this->current_post++;

            if (isset($this->posts[$this->current_post])) {
                $this->post = $this->posts[$this->current_post];
                $GLOBALS['post'] = $this->post;
            }
        }

        /**
         * @return array<int, WP_Post>
         */
        public function get_posts(): array
        {
            return $this->posts;
        }

        public function rewind_posts(): void
        {
            $this->current_post = -1;
            $this->post         = null;
        }

        public function is_main_query(): bool
        {
            return false;
        }
    }
}
