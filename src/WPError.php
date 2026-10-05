<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

/**
 * WPError – thay cho lớp WP_Error.
 *
 * Nhiều API của WordPress trả về WP_Error thay vì ném exception (get_post,
 * wp_remote_get…). Extension kiểm tra bằng is_wp_error(), nên phải giữ đúng
 * cấu trúc: code, message, data, và các phương thức get_error_code(),
 * get_error_message(), get_error_messages(), get_error_data(), has_errors().
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class WPError
{
    /** @var array<int, array{code: mixed, message: string, data: mixed}> */
    private array $errors = [];

    /** @var mixed Dữ liệu cho lỗi đầu tiên (tương thích get_error_data()). */
    private mixed $errorData = null;

    public function __construct(mixed $code = '', string $message = '', mixed $data = '')
    {
        if ($code === '') {
            return;
        }

        $this->errors[] = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];

        $this->errorData = $data;
    }

    /**
     * Thêm một lỗi nữa vào cùng một đối tượng.
     */
    public function add(mixed $code, string $message, mixed $data = ''): void
    {
        $this->errors[] = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getErrorCodes(): array
    {
        return array_column($this->errors, 'code');
    }

    public function getErrorCode(): mixed
    {
        return $this->errors[0]['code'] ?? '';
    }

    /**
     * @return string[]
     */
    public function getErrorMessages(): array
    {
        return array_column($this->errors, 'message');
    }

    public function getErrorMessage(): string
    {
        return $this->getErrorMessages()[0] ?? '';
    }

    public function getErrorData(): mixed
    {
        return $this->errorData;
    }

    /**
     * Dữ liệu của một mã lỗi cụ thể.
     */
    public function getErrorDataByCode(mixed $code): mixed
    {
        foreach ($this->errors as $error) {
            if ($error['code'] === $code) {
                return $error['data'];
            }
        }

        return null;
    }
}
