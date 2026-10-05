<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept;

/**
 * Text – các phép biến đổi chuỗi mà core cung cấp.
 *
 * @package Jankx\Flight\WordpressConcept
 */
final class Text
{
    /**
     * Bỏ dấu, tương đương remove_accents() của core.
     *
     * Dùng NFD rồi loại dấu tổ hợp (U+0300–U+036F) nên "Bài Viết Mới" ra
     * "Bai Viet Moi". Không dùng iconv //TRANSLIT vì nó trả ký tự lạ ("B`ai")
     * cho một số chữ, sinh ra slug không hợp lệ.
     */
    public static function stripAccents(string $text): string
    {
        // Chuỗi thuần ASCII thì không cần làm gì.
        if (preg_match('/[\x80-\xff]/', $text) !== 1) {
            return $text;
        }

        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);

            if ($decomposed !== false) {
                return preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return $ascii === false ? $text : (string) $ascii;
    }

    /**
     * Chuẩn hoá ký tự đa byte về UTF-8, giống utf8_uri_encode() rút gọn.
     */
    public static function toUtf8(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
