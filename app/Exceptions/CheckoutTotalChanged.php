<?php

namespace App\Exceptions;

use RuntimeException;

/** The order would cost something other than the total the shopper confirmed; nothing was placed. */
class CheckoutTotalChanged extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $items  the order's lines in the quote endpoint's shape
     * @param  array{phone: ?string, email: ?string}  $guest  the contact the order was priced with
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $couponCode,
        public readonly array $guest,
    ) {
        parent::__construct('The order total changed.');
    }
}
