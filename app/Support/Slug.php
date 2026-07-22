<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Slug helper with light Arabic transliteration and a uniqueness loop.
 * Used by store catalog (products/categories); per-store uniqueness is enforced
 * by the caller via the $exists callback.
 */
class Slug
{
    private const AR_MAP = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ء' => '', 'ؤ' => 'w', 'ئ' => 'y',
        'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'th', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh',
        'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
        'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'h',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    public static function make(string $value, string $fallback = 'item'): string
    {
        $slug = Str::slug(strtr($value, self::AR_MAP));

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * Generate a slug unique per the caller's namespace.
     *
     * @param  callable(string):bool  $exists  returns true if the slug is taken
     */
    public static function unique(string $value, callable $exists, string $fallback = 'item'): string
    {
        $base = self::make($value, $fallback);
        $slug = $base;
        $i = 1;
        while ($exists($slug)) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
