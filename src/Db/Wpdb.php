<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Db;

use Atlas\Pdo\Connection as AtlasConnection;
use Jankx\Flight\WordpressConcept\Config;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Wpdb – bản thay cho global $wpdb.
 *
 * Extension viết theo quen thuộc dùng $wpdb ở khắp nơi ($wpdb->prefix,
 * $wpdb->prepare(), $wpdb->get_results()…). Không có $wpdb thì các chỗ đó
 * chết bằng "Attempt to read property on null".
 *
 * Cài vào $GLOBALS['wpdb'] lúc boot. Dùng PDO bên dưới nên prepared statement
 * thật; prepare() của core trả về *chuỗi SQL đã escape*, hàm này giữ đúng
 * ngữ nghĩa đó để call site không phải đổi.
 *
 * @package Jankx\Flight\WordpressConcept\Db
 *
 * @property-read string $posts
 * @property-read string $postmeta
 * @property-read string $users
 * @property-read string $usermeta
 * @property-read string $comments
 * @property-read string $commentmeta
 * @property-read string $terms
 * @property-read string $termmeta
 * @property-read string $term_taxonomy
 * @property-read string $term_relationships
 * @property-read string $options
 * @property-read string $taxonomy
 */
final class Wpdb
{
    public string $prefix;
    public string $base_prefix;

    public int|false $insert_id = 0;
    public string $last_error  = '';
    public string $last_query  = '';
    public int $num_queries   = 0;

    /** Bảng nào bị extension "chạm" tới, để debug. */
    public bool $show_errors = false;

    private AtlasConnection $connection;
    private bool $suppressErrors = false;

    /** @var array<string, string> */
    private array $tables = [];

    /** Marker tạm khi escape `%%` trong prepare(). */
    private const PERCENT = "\0FWCPCT\0";

    public function __construct(AtlasConnection $connection, Config $config)
    {
        $this->connection = $connection;
        $this->prefix     = $config->tablePrefix();
        $this->base_prefix = $this->prefix;

        foreach (['posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta',
                  'terms', 'termmeta', 'term_taxonomy', 'term_relationships',
                  'options', 'taxonomy'] as $table) {
            $this->tables[$table] = $config->table($table);
        }
    }

    /**
     * Tên bảng đầy đủ, vd $wpdb->posts.
     */
    public function __get(string $name): string
    {
        return $this->tables[$name] ?? $this->prefix . $name;
    }

    public function __isset(string $name): bool
    {
        return isset($this->tables[$name]);
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * Thay %s/%d/%f bằng giá trị đã escape, trả về chuỗi SQL.
     *
     * Giữ đúng hành vi của core: %s LUÔN được bọc dấu nháy, nên phải viết
     * "WHERE post_status = %s" chứ không tự thêm nháy. `%%` là ký tự % literal.
     */
    public function prepare(string $query, mixed ...$args): string
    {
        // Core cho phép truyền mảng làm tham số duy nhất.
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        // Bảo vệ `%%` trước, nếu không regex sẽ ăn nhầm thành placeholder.
        $query = str_replace('%%', self::PERCENT, $query);

        $index = 0;

        $query = (string) preg_replace_callback(
            '/%[sdfF]/',
            function (array $match) use (&$index, $args): string {
                if (! array_key_exists($index, $args)) {
                    // Core ném lỗi khi thiếu tham số; giữ nguyên để lỗi hiện
                    // rõ ở tầng trên thay vì sinh SQL sai.
                    return $match[0];
                }

                $value = $args[$index++];

                return match ($match[0]) {
                    '%d'      => (string) (int) $value,
                    '%f', '%F' => $this->formatFloat((float) $value),
                    default   => "'" . $this->escape((string) $value) . "'",
                };
            },
            $query
        );

        return str_replace(self::PERCENT, '%', $query);
    }

    /**
     * Chạy một câu SQL thô.
     *
     * @return int|false Số dòng bị ảnh hưởng, hoặc false khi lỗi.
     */
    public function query(string $query): int|false
    {
        $this->last_query = $query;
        $this->num_queries++;

        try {
            $statement  = $this->connection->query($query);
            $this->last_error = '';

            if ($statement->columnCount() > 0) {
                $this->insert_id = (int) $this->connection->lastInsertId();
            }

            return $statement->rowCount();
        } catch (PDOException $exception) {
            $this->last_error = $exception->getMessage();

            if (! $this->suppressErrors && $this->show_errors) {
                error_log('[wpdb] ' . $this->last_error . ' — ' . $query);
            }

            return false;
        }
    }

    /**
     * @param  string          $query
     * @param  string          $output OBJECT | ARRAY_A | ARRAY_N
     * @return array<int, mixed>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $rows = $this->runSelect($query);

        return match (strtoupper($output)) {
            'ARRAY_A' => $rows,
            'ARRAY_N' => array_map('array_values', $rows),
            default   => array_map(static fn (array $row): object => (object) $row, $rows),
        };
    }

    public function get_row(string $query, string $output = 'OBJECT'): object|array|null
    {
        $rows = $this->runSelect($query, 1);

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return match (strtoupper($output)) {
            'ARRAY_A' => $row,
            'ARRAY_N' => array_values($row),
            default   => (object) $row,
        };
    }

    /**
     * @return array<int, mixed>
     */
    public function get_col(string $query, int $column = 0): array
    {
        $rows = $this->runSelect($query);

        return array_map(
            static fn (array $row) => array_values($row)[$column] ?? null,
            $rows
        );
    }

    public function get_var(string $query, int $column = 0, int $row = 0): string|int|float|null
    {
        $rows = $this->runSelect($query, $row + 1);

        if (! isset($rows[$row])) {
            return null;
        }

        return array_values($rows[$row])[$column] ?? null;
    }

    // ── Ghi dữ liệu ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data, array|null $format = null): int|false
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => $this->placeholderFor($column), $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $values = $this->bindValues($data);

        try {
            $this->connection->perform($sql, $values)->execute();
            $this->last_error = '';
            $this->insert_id  = (int) $this->connection->lastInsertId();

            return 1;
        } catch (PDOException $exception) {
            $this->last_error = $exception->getMessage();

            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(
        string $table,
        array $data,
        array $where,
        array|null $format = null,
        array|null $whereFormat = null
    ): int|false {
        $sets = [];

        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = ' . $this->placeholderFor($column);
        }

        $conditions = [];

        foreach (array_keys($where) as $column) {
            $conditions[] = $column . ' = ' . $this->placeholderFor($column);
        }

        $sql = sprintf('UPDATE %s SET %s', $table, implode(', ', $sets));

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        try {
            $affected = $this->connection->fetchAffected($sql, $this->bindValues($data + $where));
            $this->last_error = '';

            return $affected;
        } catch (PDOException $exception) {
            $this->last_error = $exception->getMessage();

            return false;
        }
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where, array|null $whereFormat = null): int|false
    {
        $conditions = [];

        foreach (array_keys($where) as $column) {
            $conditions[] = $column . ' = ' . $this->placeholderFor($column);
        }

        $sql = 'DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions);

        try {
            $affected = $this->connection->fetchAffected($sql, $this->bindValues($where));
            $this->last_error = '';

            return $affected;
        } catch (PDOException $exception) {
            $this->last_error = $exception->getMessage();

            return false;
        }
    }

    // ── Tiện ích ──────────────────────────────────────────────────────────────

    /**
     * Escape ký tự % và _ trước khi truyền vào LIKE.
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_blog_prefix(int|null $blogId = null): string
    {
        return $this->prefix;
    }

    public function get_charset_collate(): string
    {
        $database = Config::load(dirname(__DIR__, 2))->database();

        return ($database['charset'] ?? 'utf8mb4') . '_' . ($database['collate'] ?? 'unicode_ci');
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous            = $this->suppressErrors;
        $this->suppressErrors = $suppress;

        return $previous;
    }

    public function flush(): void
    {
        $this->last_error    = '';
        $this->last_query    = '';
        $this->insert_id     = 0;
        $this->num_queries   = 0;
    }

    /**
     * Bỏ $wpdb khỏi scope global (chỉ dùng cho test).
     */
    public static function reset(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    public function db_version(): string
    {
        return (string) $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runSelect(string $query, int $limit = 0): array
    {
        $this->last_query = $query;
        $this->num_queries++;

        try {
            $rows = $this->connection->fetchAll($query);
            $this->last_error = '';

            if ($limit > 0 && count($rows) > $limit) {
                $rows = array_slice($rows, 0, $limit);
            }

            return $rows;
        } catch (PDOException $exception) {
            $this->last_error = $exception->getMessage();

            return [];
        }
    }

    private function escape(string $value): string
    {
        // PDO::quote dùng đúng bộ escape của driver (chứ không phải addslashes).
        $quoted = $this->connection->getPdo()->quote($value);

        if ($quoted !== false && strlen($quoted) >= 2) {
            return substr($quoted, 1, -1);
        }

        return addslashes($value);
    }

    private function formatFloat(float $value): string
    {
        // %f/%F của core cho số thực, giữ cả phần thập phân.
        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    /**
     * Placeholder PDO cho một cột. Tên placeholder phải hợp lệ nên loại bỏ ký tự
     * lạ trong tên cột.
     */
    private function placeholderFor(string $column): string
    {
        return ':' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function bindValues(array $data): array
    {
        $values = [];

        foreach ($data as $column => $value) {
            $values[$this->placeholderFor($column)] = is_bool($value)
                ? ($value ? 1 : 0)
                : $value;
        }

        return $values;
    }
}
