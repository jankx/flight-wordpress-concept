<?php

/**
 * Bootstrap của package flight-wordpress-concept.
 *
 * Package này CỐ TÌNH không nạp tự động qua composer `files`. Lý do:
 *
 *   Nó khai các hàm/lớp global đúng tên WordPress (add_action, WP_Post,
 *   WP_Error, wp_insert_category…). Core khai cùng tên ở nhiều nơi mà KHÔNG
 *   guard lại – ví dụ wp_insert_category() trong wp-admin/includes/taxonomy.php.
 *   Ai khai trước thì người kia fatal "Cannot redeclare". Nếu nạp bằng
 *   autoload.files thì package sẽ chạy trên MỌI request, kể cả trang
 *   WordPress thật, và chính nó trở thành nguyên nhân fatal.
 *
 * Vì vậy chỉ nạp khi thực sự cần: Fast-AJAX entry (ajax.php) hoặc một flow
 * tuỳ biến nào đó gọi require tường minh. Khi đang ở trong WordPress thì
 * hàm này no-op, dùng API thật của core.
 *
 * Dùng:
 *   require __DIR__ . '/vendor/jankx/flight-wordpress-concept/bootstrap.php';
 *
 * @package Jankx\Flight\WordpressConcept
 */

declare(strict_types=1);

// Đang chạy trong WordPress (đã có ABSPATH và hệ hook của core).
// Điều kiện dùng cả hai vì chính package cũng định nghĩa ABSPATH khi chạy
// standalone (Constants::definePaths) – chỉ dựa vào ABSPATH sẽ nhầm lần
// gọi thứ hai trong chính request standalone.
if (defined('ABSPATH') && function_exists('add_action')) {
    return;
}

// Đã nạp rồi thì khỏi làm gì thêm (require nhiều lần trong một request).
// Dùng constant chứ không dùng hàm: khai hàm ở top-level sẽ bị PHP hoist lên
// compile time, nên nó thành "đã định nghĩa" dù nhánh return ở trên đã chạy.
if (defined('JANKX_FWP_LOADED')) {
    return;
}

define('JANKX_FWP_LOADED', true);

require_once __DIR__ . '/src/functions.php';
