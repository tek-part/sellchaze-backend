<?php

namespace App\Services\Themes;

/** Resolve monetary wording in legacy theme defaults without rewriting merchant copy. */
class ThemeAnnouncementCurrency
{
    public function render(string $text, mixed $default, string $currency, string $locale): string
    {
        $currency = strtoupper($currency);
        $label = $locale === 'ar' ? match ($currency) {
            'USD' => 'دولار أمريكي',
            'SAR' => 'ريال سعودي',
            'AED' => 'درهم إماراتي',
            'EGP' => 'جنيه مصري',
            'EUR' => 'يورو',
            'GBP' => 'جنيه إسترليني',
            default => $currency,
        } : $currency;

        if (str_contains($text, '{{currency}}')) {
            return str_replace('{{currency}}', $label, $text);
        }

        $defaults = is_array($default) ? array_values($default) : [$default];
        if (! in_array($text, $defaults, true)) {
            return $text;
        }

        // Historical defaults contain a Saudi symbol or an unlabelled shipping threshold.
        $rendered = str_replace('ر.س', $label, $text);
        $rendered = preg_replace('/\bSAR\b/u', $currency, $rendered) ?? $rendered;
        if ($rendered !== $text) {
            return $rendered;
        }

        return preg_replace('/\b(over\s+)([0-9]+(?:[.,][0-9]+)*)\b/u', '$1$2 '.$currency, $text) ?? $text;
    }
}
