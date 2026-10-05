<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Db;

use Atlas\Pdo\Connection as AtlasConnection;
use Atlas\Query\QueryFactory;
use Jankx\Flight\WordpressConcept\Config;
use RuntimeException;

/**
 * Connection – kết nối Atlas tới database WordPress.
 *
 * Atlas không cần entity class để chạy câu query, nên các bảng có tiền tố
 * động của WordPress (wp_posts, wp_users…) được truy vấn trực tiếp. Connection
 * được tạo lazy: entry point chỉ mở kết nối khi controller thực sự query,
 * nên request không cần DB (ví dụ ping) không phải trả chi phí kết nối.
 *
 * @package Jankx\Flight\WordpressConcept\Db
 */
final class Connection
{
    private static ?AtlasConnection $connection = null;

    private static ?QueryFactory $factory = null;

    /**
     * Kết nối dùng chung cho toàn bộ request.
     *
     * @throws RuntimeException Khi cấu hình DB sai hoặc kết nối thất bại.
     */
    public static function instance(): AtlasConnection
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config  = Config::load(dirname(__DIR__, 2));
        $db      = $config->database();

        // Mysql cần timezone UTC để so sánh timestamp giống WordPress
        // (wp_date / current_time dùng UTC).
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        try {
            self::$connection = AtlasConnection::new(
                $config->dsn(),
                $db['user'],
                $db['password'],
                $options
            );
        } catch (\PDOException $exception) {
            throw new RuntimeException(
                'Không kết nối được database: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return self::$connection;
    }

    /**
     * QueryFactory tạo Select/Insert/Update/Delete đã gắn sẵn connection.
     */
    public static function factory(): QueryFactory
    {
        return self::$factory ??= new QueryFactory();
    }

    /**
     * Bắt đầu câu SELECT mới.
     */
    public static function select()
    {
        return self::factory()->newSelect(self::instance());
    }

    /**
     * Đóng connection (chỉ dùng cho test hoặc worker dài hạn).
     */
    public static function reset(): void
    {
        self::$connection = null;
        self::$factory    = null;
    }
}
