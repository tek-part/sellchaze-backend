<?php

namespace Tests\Unit;

use App\Services\Commerce\PaymentAmounts;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentAmountsTest extends TestCase
{
    public function test_provider_units_are_exact_for_two_three_and_zero_decimal_currencies(): void
    {
        $this->assertSame(10025, PaymentAmounts::minor('100.25', 'EGP'));
        $this->assertSame(100250, PaymentAmounts::minor('100.25', 'KWD', true));
        $this->assertSame(100251, PaymentAmounts::minor('100.251', 'BHD'));
        $this->assertSame(100, PaymentAmounts::minor('100.00', 'JPY', true));
        $this->assertSame(500, PaymentAmounts::minor('5.00', 'ISK', true));
        $this->assertSame(500, PaymentAmounts::minor('5.00', 'UGX', true));
    }

    public function test_fractional_zero_decimal_currency_is_rejected_instead_of_rounded(): void
    {
        $this->expectException(ValidationException::class);
        PaymentAmounts::minor('100.25', 'JPY');
    }

    public function test_stripe_special_whole_units_are_enforced(): void
    {
        $this->expectException(ValidationException::class);
        PaymentAmounts::minor('5.25', 'ISK', true);
    }
}
