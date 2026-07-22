<?php

namespace App\Support;

class WavexPhone
{
    /**
     * Digits only, then @c.us for individual chats.
     */
    public static function toChatId(string $input): string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        if ($digits === '') {
            throw new \InvalidArgumentException('Invalid phone number.');
        }

        return $digits.'@c.us';
    }
}
