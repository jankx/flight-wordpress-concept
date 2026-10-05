<?php

/**
 * Compat: post type & taxonomy registry tối giản (global namespace).
 *
 * Extension đăng ký post type/taxonomy lúc register_hooks() rồi hỏi lại bằng
 * post_type_exists()/taxonomy_exists(). Flight không render archive hay
 * taxonomy archive, nhưng "đã đăng ký chưa" vẫn phải trả lời đúng, nếu không
 * extension sẽ đăng ký trùng hoặc bỏ nhánh code sai.
 *
 * Vì vậy chỉ giữ: đăng ký, tra cứu, hỗ trợ (supports) và cây phân cấp. Không
 * có rewrite rules, không có admin columns.
 *
 * Registry nằm trong $GLOBALS['wp_post_types'] / $GLOBALS['wp_taxonomies'] để
 * khớp với tên global của WordPress.
 *
 * @package Jankx\Flight\WordpressConcept
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid

if (! class_exists('WP_Post_Type')) {
    /**
     * Thu nhỏ của WP_Post_Type. Giữ public property vì extension đọc trực tiếp
     * (vd $postTypeObject->labels->name, ->rewrite->slug).
     */
    class WP_Post_Type
    {
        public string $name;
        public string $label = '';
        public object $labels;
        public object $description;
        public bool $public = true;
        public bool $publicly_queryable = true;
        public bool $show_ui = true;
        public bool $show_in_rest = false;
        public bool $hierarchical = false;
        public bool $has_archive = false;
        public bool $exclude_from_search = false;
        public bool $show_in_menu = true;
        public bool $show_in_nav_menus = true;
        public bool $show_in_admin_bar = true;
        public int|string $menu_position = '20';
        public array|string $capability_type = 'post';
        public array $capabilities = [];
        public string $menu_icon = '';
        public array $supports = [];  // luôn là mảng, dù caller truyền string
        public object $rewrite;
        public array $taxonomies = [];
        public bool $delete_with_user = true;
        public bool $map_meta_cap = false;
        public string $rest_base = '';
        public string $template = '';

        public function __construct(string $postType, array $args = [])
        {
            $this->name        = $postType;
            $this->label       = (string) ($args['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $postType)));
            $this->description = (object) ['raw' => (string) ($args['description'] ?? '')];
            $this->rewrite     = (object) ['slug' => $args['rewrite']['slug'] ?? $postType, 'with_front' => (bool) ($args['rewrite']['with_front'] ?? true)];

            foreach ($args as $key => $value) {
                if ($key === 'labels' || $key === 'rewrite') {
                    continue;
                }

                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            $this->labels  = (object) $this->buildLabels($args['labels'] ?? []);
            $this->supports = (array) $this->supports;
        }

        /**
         * Lấy nhãn theo key, có sẵn biến thể số nhiều như core.
         */
        public function get_label(string $key): string
        {
            $value = $this->labels->{$key} ?? '';

            return is_string($value) ? $value : '';
        }

        public function add_supports(array|string $features): void
        {
            foreach ((array) $features as $feature) {
                if (! in_array($feature, $this->supports, true)) {
                    $this->supports[] = $feature;
                }
            }
        }

        public function remove_supports(array|string $features): void
        {
            $this->supports = array_values(array_diff($this->supports, (array) $features));
        }

        public function supports(string $feature): bool
        {
            return in_array($feature, $this->supports, true);
        }

        /**
         * @param  array<string, string> $labels
         * @return array<string, string>
         */
        private function buildLabels(array $labels): array
        {
            $name  = $labels['name'] ?? $this->label;
            $built = [
                'name'          => $name,
                'singular_name' => $labels['singular_name'] ?? $name,
                'menu_name'     => $labels['menu_name'] ?? $name,
                'all_items'     => $labels['all_items'] ?? $name,
                'add_new'       => $labels['add_new'] ?? 'Thêm mới',
                'edit_item'     => $labels['edit_item'] ?? 'Sửa',
                'new_item'      => $labels['new_item'] ?? 'Mới',
                'view_item'     => $labels['view_item'] ?? 'Xem',
                'search_items'  => $labels['search_items'] ?? 'Tìm',
                'not_found'     => $labels['not_found'] ?? 'Không có',
            ];

            foreach ($labels as $key => $value) {
                $built[$key] = $value;
            }

            return $built;
        }
    }
}

if (! class_exists('WP_Taxonomy')) {
    /**
     * Thu nhỏ của WP_Taxonomy.
     */
    class WP_Taxonomy
    {
        public string $name;
        public string $label = '';
        public object $labels;
        public array $object_type = [];
        public bool $public = true;
        public bool $publicly_queryable = true;
        public bool $hierarchical = false;
        public bool $show_ui = true;
        public bool $show_in_rest = false;
        public bool $show_in_nav_menus = true;
        public bool $show_admin_column = false;
        public object $rewrite;
        public bool $query_var = false;
        public bool $show_tagcloud = true;
        public array $capabilities = [];
        public string $rest_base = '';

        public function __construct(string $taxonomy, array $objectType, array $args = [])
        {
            $this->name        = $taxonomy;
            $this->label       = (string) ($args['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $taxonomy)));
            $this->object_type = (array) $objectType;
            $this->rewrite     = (object) ['slug' => $args['rewrite']['slug'] ?? $taxonomy, 'hierarchical' => (bool) ($args['rewrite']['hierarchical'] ?? false)];

            foreach ($args as $key => $value) {
                if ($key === 'labels' || $key === 'rewrite') {
                    continue;
                }

                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            $this->labels = (object) [
                'name'          => $args['labels']['name'] ?? $this->label,
                'singular_name' => $args['labels']['singular_name'] ?? $this->label,
                'menu_name'     => $args['labels']['menu_name'] ?? $this->label,
                'all_items'     => $args['labels']['all_items'] ?? $this->label,
                'edit_item'     => $args['labels']['edit_item'] ?? 'Sửa',
                'search_items'  => $args['labels']['search_items'] ?? 'Tìm',
                'not_found'     => $args['labels']['not_found'] ?? 'Không có',
            ];
        }

        public function add_supports(array|string $features): void
        {
            $this->object_type = array_values(array_unique(array_merge($this->object_type, (array) $features)));
        }

        public function remove_supports(array|string $features): void
        {
            $this->object_type = array_values(array_diff($this->object_type, (array) $features));
        }
    }
}

if (! function_exists('register_post_type')) {
    function register_post_type(string $postType, array $args = []): WP_Post_Type|false
    {
        if (! is_string($postType) || $postType === '') {
            return false;
        }

        $registry =& $GLOBALS['wp_post_types'];
        $registry[$postType] = new WP_Post_Type($postType, $args);

        return $registry[$postType];
    }
}

if (! function_exists('post_type_exists')) {
    function post_type_exists(string $postType): bool
    {
        return is_string($postType) && isset($GLOBALS['wp_post_types'][$postType]);
    }
}

if (! function_exists('get_post_type_object')) {
    function get_post_type_object(string $postType): WP_Post_Type|null
    {
        return $GLOBALS['wp_post_types'][$postType] ?? null;
    }
}

if (! function_exists('get_post_types')) {
    /**
     * @param  array  $args     'public'/'show_ui'/… hoặc danh sách tên cụ thể.
     * @param  string $output   'names' | 'objects'
     * @param  string $operator 'and' | 'or'
     * @return array<int, string|WP_Post_Type>
     */
    function get_post_types(array $args = [], string $output = 'names', string $operator = 'and'): array
    {
        $types = $GLOBALS['wp_post_types'] ?? [];

        if ($args !== []) {
            $names  = array_keys($types);
            $wanted = array_values(array_filter($names, static function (string $name) use ($args, $types, $operator): bool {
                foreach ($args as $key => $expected) {
                    $actual = property_exists($types[$name], $key) ? $types[$name]->{$key} : null;

                    if (($actual == $expected) !== ($operator === 'and')) {
                        return false;
                    }
                }

                return true;
            }));

            $types = array_intersect_key($types, array_flip($wanted));
        }

        return $output === 'objects' ? array_values($types) : array_keys($types);
    }
}

if (! function_exists('unregister_post_type')) {
    function unregister_post_type(string $postType): bool
    {
        if (! post_type_exists($postType)) {
            return false;
        }

        unset($GLOBALS['wp_post_types'][$postType]);

        return true;
    }
}

if (! function_exists('add_post_type_support')) {
    function add_post_type_support(string $postType, string $feature, mixed ...$args): void
    {
        $object = get_post_type_object($postType);

        if ($object !== null) {
            $object->add_supports($feature);
        }
    }
}

if (! function_exists('remove_post_type_support')) {
    function remove_post_type_support(string $postType, string $feature): void
    {
        $object = get_post_type_object($postType);

        if ($object !== null) {
            $object->remove_supports($feature);
        }
    }
}

if (! function_exists('post_type_supports')) {
    function post_type_supports(string $postType, string $feature): bool
    {
        $object = get_post_type_object($postType);

        return $object !== null && $object->supports($feature);
    }
}

if (! function_exists('register_taxonomy')) {
    /**
     * @param string|string[] $objectType
     */
    function register_taxonomy(string $taxonomy, string|array $objectType, array $args = []): WP_Taxonomy|false
    {
        if (! is_string($taxonomy) || $taxonomy === '') {
            return false;
        }

        $registry =& $GLOBALS['wp_taxonomies'];
        $registry[$taxonomy] = new WP_Taxonomy($taxonomy, (array) $objectType, $args);

        // Gắn taxonomy vào post type đã biết, giống hành vi của core.
        foreach ((array) $objectType as $postType) {
            $object = get_post_type_object($postType);

            if ($object !== null && ! in_array($taxonomy, $object->taxonomies, true)) {
                $object->taxonomies[] = $taxonomy;
            }
        }

        return $registry[$taxonomy];
    }
}

if (! function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return is_string($taxonomy) && isset($GLOBALS['wp_taxonomies'][$taxonomy]);
    }
}

if (! function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): WP_Taxonomy|false
    {
        return $GLOBALS['wp_taxonomies'][$taxonomy] ?? false;
    }
}

if (! function_exists('unregister_taxonomy')) {
    function unregister_taxonomy(string $taxonomy): bool
    {
        if (! taxonomy_exists($taxonomy)) {
            return false;
        }

        unset($GLOBALS['wp_taxonomies'][$taxonomy]);

        return true;
    }
}

if (! function_exists('register_post_meta')) {
    /**
     * Meta chỉ cần ghi nhận để đăng ký lại không lỗi; đọc/ghi meta đi qua
     * update_post_meta/get_post_meta của package.
     */
    function register_post_meta(string $postType, string $metaKey, array $args = [], string|false $deprecated = null): bool
    {
        $registry =& $GLOBALS['wp_post_meta'];

        $registry[$postType][$metaKey] = $args;

        return true;
    }
}

if (! function_exists('register_meta')) {
    function register_meta(string $objectType, string $metaKey, array $args = [], string|bool $deprecated = null): bool
    {
        $registry =& $GLOBALS['wp_registered_meta'];

        $registry[$objectType][$metaKey] = $args;

        return true;
    }
}

if (! function_exists('registered_meta_key_exists')) {
    function registered_meta_key_exists(string $objectType, string $metaKey, string $subtype = ''): bool
    {
        return isset($GLOBALS['wp_registered_meta'][$objectType][$metaKey]);
    }
}
