<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/** Static HTML/CSS is displayed in an opaque, isolated frame, never in the merchant shell. */
final class ProductLandingHtml
{
    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])->allowRelativeLinks()
            ->allowMediaSchemes(['https', 'http'])->allowRelativeMedias()->withMaxInputLength(20000);
        foreach (['section', 'article', 'header', 'footer', 'main', 'aside', 'p', 'br', 'hr', 'span', 'div', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'mark', 'sub', 'sup', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'details', 'summary'] as $tag) {
            $config = $config->allowElement($tag, ['class', 'style', 'id', 'title', 'dir', 'aria-label']);
        }
        $config = $config->allowElement('a', ['href', 'title', 'class', 'style'])->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')->forceAttribute('a', 'target', '_blank')
            ->allowElement('button', ['class', 'style', 'data-purchase'])->forceAttribute('button', 'type', 'button')
            ->allowElement('td', ['class', 'style', 'colspan', 'rowspan'])->allowElement('th', ['class', 'style', 'colspan', 'rowspan', 'scope'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'class', 'style'])->forceAttribute('img', 'loading', 'lazy')
            ->allowElement('video', ['src', 'poster', 'class', 'style'])->forceAttribute('video', 'controls', '')->forceAttribute('video', 'playsinline', '')->forceAttribute('video', 'preload', 'metadata');

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
