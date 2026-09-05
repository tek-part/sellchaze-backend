<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_baseline_security_headers_are_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/v2/plans');

        $response
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        self::assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $response->headers->get('Content-Security-Policy-Report-Only'),
        );

        // Enforced framing policy: self + the dashboard origin + tenant hosts on the base domain.
        $enforced = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringStartsWith("frame-ancestors 'self'", $enforced);
        self::assertStringContainsString('https://*.'.config('sellchase.storefront.base_domain'), $enforced);
    }
}
