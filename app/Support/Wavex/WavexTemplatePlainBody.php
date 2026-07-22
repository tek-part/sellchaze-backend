<?php

namespace App\Support\Wavex;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * TipTap / HTML message bodies → WhatsApp-friendly plain text (paragraphs, lists, *bold*, _italic_, ~strike~).
 */
final class WavexTemplatePlainBody
{
    public static function fromHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = trim($html);
        if (! self::looksLikeHtml($html)) {
            return self::finalizeWhitespace(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8">'.'<html><body>'.$html.'</body></html>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return self::fallbackStrip($html);
        }

        $text = self::serializeChildren($body, 'block');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(self::finalizeWhitespace($text));
    }

    private static function looksLikeHtml(string $s): bool
    {
        return (bool) preg_match('/<[a-z][\s\S]*>/i', $s);
    }

    private static function fallbackStrip(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(self::finalizeWhitespace($text));
    }

    private static function finalizeWhitespace(string $text): string
    {
        $text = preg_replace("/\R+/u", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n[ \t]+/u", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $text;
    }

    private static function serializeChildren(DOMElement $parent, string $parentEnv): string
    {
        $out = '';
        foreach ($parent->childNodes as $child) {
            $out .= self::serializeNode($child, $parentEnv);
        }

        return $out;
    }

    private static function serializeNode(DOMNode $node, string $parentEnv): string
    {
        if ($node instanceof DOMText) {
            return $node->data;
        }
        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);

        return match ($tag) {
            'br' => "\n",
            'p' => self::blockAfterParagraph(self::collapseInnerWhitespace(self::serializeChildren($node, 'inline')), $parentEnv),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => self::blockAfterBlock(self::collapseInnerWhitespace(self::serializeChildren($node, 'inline'))),
            'blockquote' => self::blockAfterBlock(self::collapseInnerWhitespace(self::serializeChildren($node, 'inline'))),
            'ul' => self::serializeList($node, false),
            'ol' => self::serializeList($node, true),
            'li' => '',
            'hr' => "\n---\n\n",
            'pre' => self::blockAfterBlock(self::serializePre($node)),
            'strong', 'b' => self::wrapWaBold(self::serializeChildren($node, 'inline')),
            'em', 'i' => self::wrapWaItalic(self::serializeChildren($node, 'inline')),
            's', 'strike', 'del' => self::wrapWaStrike(self::serializeChildren($node, 'inline')),
            'code' => self::wrapInlineCode(self::serializeChildren($node, 'inline')),
            'a' => self::serializeChildren($node, 'inline'),
            default => self::serializeChildren($node, $parentEnv),
        };
    }

    private static function serializePre(DOMElement $pre): string
    {
        $inner = '';
        foreach ($pre->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'code') {
                $inner .= self::serializeChildren($child, 'inline');
            } else {
                $inner .= self::serializeNode($child, 'inline');
            }
        }

        $inner = str_replace("\r\n", "\n", $inner);

        return "```\n".rtrim($inner, "\n")."\n```";
    }

    private static function blockAfterParagraph(string $inner, string $parentEnv): string
    {
        if ($inner === '') {
            return '';
        }

        return $parentEnv === 'li' ? $inner."\n" : $inner."\n\n";
    }

    private static function blockAfterBlock(string $inner): string
    {
        if ($inner === '') {
            return '';
        }

        return $inner."\n\n";
    }

    private static function collapseInnerWhitespace(string $s): string
    {
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s;
        $s = preg_replace("/\n{3,}/u", "\n\n", $s) ?? $s;

        return trim($s);
    }

    private static function wrapWaBold(string $s): string
    {
        $t = trim($s);

        return $t === '' ? '' : '*'.$t.'*';
    }

    private static function wrapWaItalic(string $s): string
    {
        $t = trim($s);

        return $t === '' ? '' : '_'.$t.'_';
    }

    private static function wrapWaStrike(string $s): string
    {
        $t = trim($s);

        return $t === '' ? '' : '~'.$t.'~';
    }

    private static function wrapInlineCode(string $s): string
    {
        $t = trim($s);

        return $t === '' ? '' : '`'.$t.'`';
    }

    private static function serializeList(DOMElement $list, bool $ordered): string
    {
        $n = 1;
        $lines = [];
        foreach ($list->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }
            $prefix = $ordered ? ($n++).'. ' : '• ';
            $itemText = self::serializeLiContent($child);
            $itemText = trim($itemText);
            if ($itemText === '') {
                continue;
            }
            $lines[] = $prefix.$itemText;
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines)."\n\n";
    }

    private static function serializeLiContent(DOMElement $li): string
    {
        $parts = [];
        foreach ($li->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                $parts[] = "\n".trim(self::serializeNode($child, 'block'));

                continue;
            }
            $parts[] = self::serializeNode($child, 'li');
        }

        return trim(implode('', $parts));
    }
}
