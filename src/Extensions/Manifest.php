<?php

declare(strict_types=1);

namespace Jankx\Flight\WordpressConcept\Extensions;

/**
 * Manifest – metadata đọc từ extensions/<id>/manifest.json.
 *
 * @package Jankx\Flight\WordpressConcept\Extensions
 */
final class Manifest
{
    public function __construct(
        public readonly string $id,
        public readonly string $dir,
        public readonly array  $data
    ) {
    }

    /**
     * Slug namespace dùng trong URL: /jankx-ajax/<slug>/<controller>/<action>.
     */
    public function ajaxSlug(): string
    {
        return (string) ($this->data['ajax_slug'] ?? '');
    }

    /**
     * Namespace PHP chứa controller, vd "Jankx\\Extensions\\Ecommerce\\Ajax\\Controller\\".
     */
    public function ajaxNamespace(): string
    {
        return (string) ($this->data['ajax_namespace'] ?? '');
    }

    /**
     * Extension có chạy trong ngữ cảnh ajax không.
     */
    public function handlesAjax(): bool
    {
        return in_array('ajax', (array) ($this->data['context'] ?? []), true);
    }

    /**
     * Đường dẫn file PHP bootstrap của extension, theo manifest key 'caller'.
     */
    public function callerFile(): string
    {
        $file = (string) ($this->data['caller']['file'] ?? '');
        if ($file === '') {
            return '';
        }

        return rtrim($this->dir, '/') . '/' . ltrim($file, '/');
    }
}
