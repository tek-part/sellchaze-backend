<?php

namespace App\Services\Themes;

use Illuminate\Validation\ValidationException;

class CustomCssSanitizer
{
    public function sanitize(string $css): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }
        if (strlen($css) > 50000 || preg_match('/(?:@|<\/?style|<script|javascript\s*:|expression\s*\(|behavior\s*:|-moz-binding|url\s*\()/i', $css)) {
            throw ValidationException::withMessages(['custom_css' => 'CSS contains a forbidden construct or external resource.']);
        }
        if (substr_count($css, '{') !== substr_count($css, '}')) {
            throw ValidationException::withMessages(['custom_css' => 'CSS braces are not balanced.']);
        }

        $scoped = [];
        foreach (explode('}', $css) as $rule) {
            if (trim($rule) === '') {
                continue;
            }
            [$selectors, $declarations] = array_pad(explode('{', $rule, 2), 2, null);
            if ($declarations === null || trim($declarations) === '' || str_contains($declarations, '{')) {
                throw ValidationException::withMessages(['custom_css' => 'Only flat style rules are supported.']);
            }
            $safeSelectors = array_map(function (string $selector): string {
                $selector = trim($selector);
                if ($selector === '' || preg_match('/[<>]/', $selector)) {
                    throw ValidationException::withMessages(['custom_css' => 'CSS selector is invalid.']);
                }

                return '#storefront-root '.$selector;
            }, explode(',', $selectors));
            $scoped[] = implode(', ', $safeSelectors).' {'.trim($declarations).'}';
        }

        return implode("\n", $scoped);
    }
}
