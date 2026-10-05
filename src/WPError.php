<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

require_once __DIR__ . '/compat/class-wp-error.php';

/**
 * WPError – bí danh nội bộ cho lớp WP_Error của WordPress.
 *
 * Extension gọi `new WP_Error(...)` trong namespace của nó, nên PHP rơi về
 * lớp global `\WP_Error`. Lớp global khai ở compat/class-wp-error.php.
 *
 * Lớp này chỉ để code trong package viết `new WPError(...)` và được
 * `instanceof WPError` mà không phải import global; nó kế thừa \WP_Error nên
 * is_wp_error() nhận cả hai chiều.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class WPError extends \WP_Error
{
}
