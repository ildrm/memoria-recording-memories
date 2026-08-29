<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class RichTextSanitizer
{
    /**
     * Rich-editor elements that cannot alter the surrounding page structure.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ELEMENTS = [
        'a' => ['href', 'title'],
        'abbr' => ['title'],
        'blockquote' => [],
        'br' => [],
        'caption' => [],
        'cite' => [],
        'code' => [],
        'dd' => [],
        'del' => [],
        'div' => [],
        'dl' => [],
        'dt' => [],
        'em' => [],
        'figcaption' => [],
        'figure' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'li' => ['value'],
        'mark' => [],
        'ol' => ['reversed', 'start', 'type'],
        'p' => [],
        'pre' => [],
        'q' => [],
        's' => [],
        'small' => [],
        'span' => [],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'table' => [],
        'tbody' => [],
        'td' => ['colspan', 'headers', 'rowspan'],
        'tfoot' => [],
        'th' => ['abbr', 'colspan', 'headers', 'rowspan', 'scope'],
        'thead' => [],
        'tr' => [],
        'u' => [],
        'ul' => [],
    ];

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowRelativeLinks()
            ->forceHttpsUrls()
            ->withMaxInputLength(
                (int) config('memoria.rich_text.maximum_characters', 125000) * 4,
            );

        foreach (self::ALLOWED_ELEMENTS as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $config = $config->forceAttribute('a', 'rel', 'noopener noreferrer');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(?string $html): string
    {
        return $this->sanitizer->sanitize($html ?? '');
    }
}
