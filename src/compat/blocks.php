<?php

/**
 * Compat: block registry tối giản (global namespace, đúng tên lớp của WP).
 *
 * Flight chạy Ajax, không bao giờ render block, nhưng extension vẫn gọi
 * register_block_type_from_metadata() lúc register_hooks() và dùng
 * WP_Block_Type_Registry::get_instance()->is_registered() làm chốt "đã đăng
 * ký chưa" để tránh đăng ký trùng.
 *
 * Vậy nên chỉ cần hai thứ: một registry nhớ tên block, và hàm đăng ký ghi tên
 * vào đó. Không cần biên dịch block.json, không cần render_callback thật.
 *
 * Không khai báo namespace ở đây và cũng không được nạp khi WordPress đang có
 * sẵn lớp thật.
 *
 * @package Jankx\Flight\WordpressConcept
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid

if (! class_exists('WP_Block_Type')) {
    /**
     * Thu nhỏ của WP_Block_Type: chỉ giữ những gì extension đọc tới.
     */
    class WP_Block_Type
    {
        public string $name;
        public string $title = '';
        public string $category = '';
        public string $description = '';
        public mixed $render_callback = null;
        public array $attributes = [];
        public array $supports = [];
        public array $styles = [];
        public array $editor_script_handles = [];
        public array $editor_style_handles = [];
        public array $script_handles = [];
        public array $style_handles = [];
        public array $view_script_handles = [];
        public array $variations = [];
        public bool $is_dynamic = false;
        public array $attributes_schema = [];
        public mixed $uses_context = null;

        public function __construct(string $blockName, array $args = [])
        {
            $this->name = $blockName;

            foreach ($args as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }

            if ($this->render_callback !== null) {
                $this->is_dynamic = true;
            }
        }

        public function is_dynamic(): bool
        {
            return $this->is_dynamic;
        }

        public function set_props(array $args): void
        {
            foreach ($args as $key => $value) {
                if (property_exists($this, $key)) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}

if (! class_exists('WP_Block_Type_Registry')) {
    /**
     * Singleton đúng như core, extension gọi get_instance() làm nguồn sự thật
     * về việc block đã đăng ký hay chưa.
     */
    class WP_Block_Type_Registry
    {
        /** @var WP_Block_Type_Registry|null */
        private static ?WP_Block_Type_Registry $instance = null;

        /** @var array<string, WP_Block_Type> */
        private array $registeredBlockTypes = [];

        public static function get_instance(): WP_Block_Type_Registry
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public function register(string|WP_Block_Type $name, array $args = []): WP_Block_Type
        {
            $blockType = $name instanceof WP_Block_Type
                ? $name
                : new WP_Block_Type($name, $args);

            $this->registeredBlockTypes[$blockType->name] = $blockType;

            return $blockType;
        }

        public function unregister(string $name): WP_Block_Type|false
        {
            if (! $this->is_registered($name)) {
                return false;
            }

            $blockType = $this->registeredBlockTypes[$name];
            unset($this->registeredBlockTypes[$name]);

            return $blockType;
        }

        public function get_registered(string $name): WP_Block_Type|null
        {
            return $this->registeredBlockTypes[$name] ?? null;
        }

        public function get_all_registered(): array
        {
            return $this->registeredBlockTypes;
        }

        public function get_all_registered_names(): array
        {
            return array_keys($this->registeredBlockTypes);
        }

        public function is_registered(string $name): bool
        {
            return isset($this->registeredBlockTypes[$name]);
        }

        /**
         * Chỉ dùng cho test.
         */
        public static function reset_instance(): void
        {
            self::$instance = null;
        }
    }
}

if (! function_exists('register_block_type')) {
    /**
     * @param string|WP_Block_Type $name
     */
    function register_block_type(string|WP_Block_Type $name, array $args = []): WP_Block_Type|false
    {
        return WP_Block_Type_Registry::get_instance()->register($name, $args);
    }
}

if (! function_exists('register_block_type_from_metadata')) {
    /**
     * Bỏ qua đọc block.json: chỉ cần tên để registry trả lời is_registered().
     *
     * @param string $file_or_folder Đường dẫn block.json hoặc thư mục block.
     * @param array  $args           Tham số bổ sung, giống hàm của core.
     */
    function register_block_type_from_metadata(string $file_or_folder, array $args = []): WP_Block_Type|false
    {
        $metadataPath = str_ends_with($file_or_folder, '.json')
            ? $file_or_folder
            : rtrim($file_or_folder, '/') . '/block.json';

        $metadata = is_readable($metadataPath)
            ? (array) (json_decode((string) file_get_contents($metadataPath), true) ?: [])
            : [];

        // Tên block trong block.json thường là "jankx/cart", đã đủ để làm khoá.
        $name = (string) ($args['name'] ?? $metadata['name'] ?? basename(dirname($metadataPath)));

        return WP_Block_Type_Registry::get_instance()->register($name, array_merge($metadata, $args));
    }
}

if (! function_exists('unregister_block_type')) {
    function unregister_block_type(string $name): WP_Block_Type|false
    {
        return WP_Block_Type_Registry::get_instance()->unregister($name);
    }
}
