<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** One write policy for all merchant product-description locales. */
final class ProductDescription
{
    public static function clean(?string $html, bool $formatting = false): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeMedias()
            ->withMaxInputLength(20000);
        foreach (['p', 'br', 'hr', 'span', 'div', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'mark', 'sub', 'sup', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr'] as $tag) {
            $config = $config->allowElement($tag, $formatting && in_array($tag, ['p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'li'], true) ? ['style'] : []);
        }
        $config = $config->allowElement('a', ['href', 'title', 'target'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->allowElement('td', ['colspan', 'rowspan'])->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('img', ['src', 'alt', 'title'])->forceAttribute('img', 'loading', 'lazy')
            ->allowElement('video', ['src', 'title', 'poster'])
            ->forceAttribute('video', 'controls', '')->forceAttribute('video', 'playsinline', '')
            ->forceAttribute('video', 'preload', 'metadata');

        $clean = (new HtmlSanitizer($config))->sanitize($html);

        return $formatting ? RichTextFormatting::cleanStyles($clean) : $clean;
    }
}
