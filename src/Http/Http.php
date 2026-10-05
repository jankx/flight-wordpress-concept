<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Http;

use Jankx\Flight\WordpressConcept\WPError;

/**
 * Http – cài đặt tối thiểu cho HTTP API của WordPress.
 *
 * Extension dùng `wp_remote_get()` + `wp_remote_retrieve_body()` để gọi API
 * ngoài (tỷ giá tiền tệ…). Ở đây dùng cURL nếu có, không thì stream wrapper.
 *
 * CẦN BIẾT TRƯỚC: đây là đường mở ra kết nối ra ngoài từ một request AJAX,
 * không có giới hạn timeout mặc định của core và không có allowlist domain.
 * Nếu URL đến từ dữ liệu người dùng thì phải kiểm tra trước khi gọi.
 *
 * @package Jankx\Flight\WordpressConcept\Http
 */
final class Http
{
    /** Timeout mặc định (giây), theo đúng giá trị của core. */
    private const DEFAULT_TIMEOUT = 5;

    /**
     * GET. Trả mảng response, hoặc WPError.
     */
    public static function get(string $url, array $args = []): array|WPError
    {
        return self::request('GET', $url, $args);
    }

    public static function post(string $url, array $args = []): array|WPError
    {
        return self::request('POST', $url, $args);
    }

    public static function request(string $method, string $url, array $args = []): array|WPError
    {
        $method = strtoupper($method);

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'], true)) {
            return new WPError('http_request_failed', "Phương thức HTTP không hỗ trợ: {$method}");
        }

        if (! self::isAllowedScheme($url)) {
            return new WPError('http_request_failed', "URL không được phép: {$url}");
        }

        $timeout = (int) ($args['timeout'] ?? self::DEFAULT_TIMEOUT);
        $body    = $args['body'] ?? null;

        if ($body !== null && ! is_string($body) && (is_array($body) || is_object($body))) {
            $body    = http_build_query((array) $body);
            $headers = array_merge($args['headers'] ?? [], ['Content-Type' => 'application/x-www-form-urlencoded']);
        } else {
            $headers = $args['headers'] ?? [];
        }

        return function_exists('curl_init')
            ? self::viaCurl($method, $url, $headers, $body, $timeout)
            : self::viaStream($method, $url, $headers, $body, $timeout);
    }

    // ── Backends ──────────────────────────────────────────────────────────────

    private static function viaCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array|WPError
    {
        $handle = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($headerLines !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($body !== null && $method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $message = curl_error($handle);
            $code    = curl_errno($handle);
            curl_close($handle);

            return new WPError('http_request_failed', $message === '' ? 'Yêu cầu HTTP thất bại.' : $message, $code);
        }

        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerText = (string) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        return [
            'headers'  => self::parseHeaders($headerText),
            'body'     => (string) $responseBody,
            'response' => ['code' => $status, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private static function viaStream(string $method, string $url, array $headers, ?string $body, int $timeout): array|WPError
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headerLines,
                'content'       => $body ?? '',
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        // $http_response_header do stream wrapper tạo trong phạm vi hàm.
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($responseBody === false) {
            return new WPError('http_request_failed', "Không gọi được {$url} (stream wrapper).");
        }

        return [
            'headers'  => [],
            'body'     => $responseBody,
            'response' => ['code' => $status, 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Chỉ cho phép http/https. Chặn scheme lạ (file://, gopher://…) vì đây là
     * đường đi từ dữ liệu trong DB.
     */
    private static function isAllowedScheme(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'http' || $scheme === 'https';
    }

    private static function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (preg_split('/\r?\n/', trim($raw)) ?: [] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }
}
