<?php

namespace App\Services\Shipping;

use RuntimeException;

/** No upstream response body, credentials or customer data may enter errors/logs. */
class CarrierException extends RuntimeException
{
    public function __construct(public readonly bool $ambiguous, public readonly string $reason)
    {
        parent::__construct($ambiguous
            ? 'The carrier response could not be confirmed. Check the carrier dashboard before submitting again.'
            : 'The carrier rejected this request. Check the connection, address and package details.');
    }
}
