<?php

/**
 * Compat: lớp WP_Error của WordPress (global namespace).
 *
 * PHẢI đúng tên và đúng cấu trúc dữ liệu của core, vì extension đọc trực tiếp:
 *
 *     new WP_Error('code', 'message');
 *     $e->get_error_code();
 *     $e->get_error_message();
 *     isset($e->errors['code']);
 *
 * `errors` trong core là [code => [[message, data], ...]], `error_data` là
 * [code => data]. Extension đọc thẳng hai mảng này (hàng chục chỗ), nên giữ
 * sai hình dạng là hỏng ngay.
 *
 * Lớp cũ trong namespace package (Jankx\...\WPError) dùng tên phương thức
 * camelCase nên không tương thích; nay nó kế thừa lớp này.
 *
 * @package Jankx\Flight\WordpressConcept
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PEAR.NamingConventions.ValidClassName.Invalid

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, array<int, array{0: string, 1: mixed}>> code => [[message, data], …] */
        public array $errors = [];

        /** @var array<string, mixed> code => data của lỗi đầu tiên */
        public array $error_data = [];

        /** @var array<string, mixed> */
        protected array $additional_data = [];

        public function __construct(mixed $code = '', string $message = '', mixed $data = '')
        {
            if ($code === '') {
                return;
            }

            $this->add($code, $message, $data);
        }

        /**
         * Thêm một message cho một mã lỗi.
         */
        public function add(mixed $code, string $message, mixed $data = '', bool $additionalData = false): void
        {
            $key = (string) $code;

            $this->errors[$key][] = [$message, $data];

            // error_data chỉ giữ dữ liệu của lỗi đầu tiên cho mỗi mã, giống core.
            if (! isset($this->error_data[$key])) {
                $this->error_data[$key] = $data;
            }

            if ($additionalData) {
                $this->additional_data[$key] = $data;
            }
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }

        /**
         * @return array<int, string>
         */
        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code(): string
        {
            $codes = $this->get_error_codes();

            return $codes[0] ?? '';
        }

        /**
         * @return string[]
         */
        public function get_error_messages(int|string $code = ''): array
        {
            if ($code === '') {
                $all = [];

                foreach ($this->errors as $messages) {
                    foreach ($messages as [$message]) {
                        $all[] = $message;
                    }
                }

                return $all;
            }

            return array_map(
                static fn (array $entry): string => (string) $entry[0],
                $this->errors[(string) $code] ?? []
            );
        }

        public function get_error_message(int|string $code = ''): string
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }

            return $this->get_error_messages($code)[0] ?? '';
        }

        public function get_error_data(int|string $code = ''): mixed
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }

            return $this->error_data[(string) $code] ?? null;
        }

        public function has_error(int|string $code): bool
        {
            return isset($this->errors[(string) $code]);
        }

        /**
         * Dữ liệu của một mã lỗi cụ thể (khác get_error_data ở chỗ trả null
         * thay vì dữ liệu lỗi đầu tiên).
         */
        public function get_error_data_by_code(int|string $code): mixed
        {
            $entries = $this->errors[(string) $code] ?? [];

            return $entries[0][1] ?? null;
        }

        /**
         * Xoá mọi lỗi của một mã, hoặc toàn bộ nếu không truyền $code.
         */
        public function remove(int|string $code): void
        {
            if ($code === '') {
                $this->errors       = [];
                $this->error_data   = [];
                $this->additional_data = [];

                return;
            }

            unset($this->errors[(string) $code], $this->error_data[(string) $code], $this->additional_data[(string) $code]);
        }

        /**
         * Gộp lỗi từ một WP_Error khác vào đối tượng này.
         */
        public function merge_from(WP_Error $from): void
        {
            foreach ($from->errors as $code => $entries) {
                foreach ($entries as [$message, $data]) {
                    $this->add($code, (string) $message, $data);
                }
            }
        }
    }
}
