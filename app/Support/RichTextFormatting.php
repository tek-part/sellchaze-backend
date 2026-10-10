<?php

namespace App\Support;

/** Canonicalize only text colors/alignment, after the structural HTML sanitizer. */
final class RichTextFormatting
{
    public static function color(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#[a-f0-9]{6}$/', $value)) {
            return $value;
        }
        if (preg_match('/^#([a-f0-9])([a-f0-9])([a-f0-9])$/', $value, $parts)) {
            return '#'.$parts[1].$parts[1].$parts[2].$parts[2].$parts[3].$parts[3];
        }
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $value, $parts) && max((int) $parts[1], (int) $parts[2], (int) $parts[3]) <= 255) {
            return sprintf('#%02x%02x%02x', $parts[1], $parts[2], $parts[3]);
        }

        return null;
    }

    public static function style(string $input): string
    {
        $safe = [];
        foreach (explode(';', $input) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = strtolower(trim($value));
            if (in_array($property, ['color', 'background-color'], true) && ($color = self::color($value)) !== null) {
                $safe[$property] = $color;
            }
            if ($property === 'text-align' && in_array($value, ['left', 'right', 'center', 'justify', 'start', 'end'], true)) {
                $safe[$property] = $value;
            }
        }

        return implode(';', array_map(fn ($key, $value) => $key.':'.$value, array_keys($safe), $safe));
    }

    public static function cleanStyles(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8"><html><body><div id="formatting-root">'.$html.'</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element->hasAttribute('style')) {
                continue;
            }
            $style = self::style($element->getAttribute('style'));
            if ($style === '') {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $style);
            }
        }
        $root = $document->getElementById('formatting-root');
        if ($root === null) {
            return '';
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }
}
