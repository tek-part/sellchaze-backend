<?php

namespace Tests\Feature;

use App\Mail\StoreCustomerPasswordResetMail;
use App\Models\Store;
use App\Models\StoreCustomerPasswordReset;
use App\Models\StoreDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Store customers reset their password through their own store: links are
 * store-scoped, single-use, expire, and never reveal whether an email exists.
 */
class StorefrontCustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Store $alpha;

    private Store $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->alpha = $this->makeStore('alpha');
        $this->beta = $this->makeStore('beta');
    }

    private function makeStore(string $slug): Store
    {
        $store = Store::create([
            'owner_user_id' => User::factory()->create()->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'host' => "{$slug}.sellchase.com", 'type' => 'subdomain', 'is_primary' => true]);

        return $store;
    }

    private function api(Store $store, string $path): string
    {
        return "http://{$store->slug}.sellchase.com/api/v1/storefront/{$path}";
    }

    private function register(Store $store, string $email = 'ada@example.com', string $password = 'password123'): string
    {
        return $this->postJson($this->api($store, 'auth/register'), ['name' => 'Ada', 'email' => $email, 'password' => $password])
            ->assertCreated()->json('token');
    }

    /** Request a link and return the token carried by the queued email. */
    private function requestLink(Store $store, string $email = 'ada@example.com'): string
    {
        $this->postJson($this->api($store, 'auth/forgot-password'), ['email' => $email])->assertOk();
        $url = null;
        Mail::assertQueued(StoreCustomerPasswordResetMail::class, function (StoreCustomerPasswordResetMail $mail) use (&$url, $store) {
            $url = $mail->url;

            return $mail->store->is($store);
        });
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    public function test_reset_link_sets_a_new_password_and_signs_out_every_session(): void
    {
        $oldToken = $this->register($this->alpha);
        $token = $this->requestLink($this->alpha);

        $this->postJson($this->api($this->alpha, 'auth/reset-password'), [
            'token' => $token, 'email' => 'ADA@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->getJson($this->api($this->alpha, 'account'), ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
        $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'ada@example.com', 'password' => 'password123'])->assertStatus(422);
        $this->postJson($this->api($this->alpha, 'auth/login'), ['email' => 'ada@example.com', 'password' => 'brand-new-pass'])->assertOk();

        // Single use.
        $this->postJson($this->api($this->alpha, 'auth/reset-password'), [
            'token' => $token, 'email' => 'ada@example.com', 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_link_points_at_the_store_and_is_branded(): void
    {
        $this->register($this->alpha);
        $this->postJson($this->api($this->alpha, 'auth/forgot-password'), ['email' => 'ada@example.com'])->assertOk();

        Mail::assertQueued(StoreCustomerPasswordResetMail::class, function (StoreCustomerPasswordResetMail $mail) {
            $this->assertStringStartsWith('https://alpha.sellchase.com/reset-password?token=', $mail->url);
            $this->assertStringContainsString('email=ada%40example.com', $mail->url);
            $html = $mail->render();
            $this->assertStringContainsString('Alpha', $html);
            $this->assertStringContainsString(e($mail->url), $html);

            return $mail->hasTo('ada@example.com');
        });
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        $known = $this->register($this->alpha);
        $this->assertNotEmpty($known);
        $this->postJson($this->api($this->alpha, 'auth/forgot-password'), ['email' => 'nobody@example.com'])
            ->assertOk()->assertJsonPath('message', 'If an account exists for this email, a reset link is on its way.');
        // An account on another store does not count here.
        $this->postJson($this->api($this->beta, 'auth/forgot-password'), ['email' => 'ada@example.com'])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_a_link_from_one_store_does_not_work_on_another(): void
    {
        $this->register($this->alpha);
        $this->register($this->beta);
        $token = $this->requestLink($this->alpha);

        $this->postJson($this->api($this->beta, 'auth/reset-password'), [
            'token' => $token, 'email' => 'ada@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
        ])->assertStatus(422);
        $this->postJson($this->api($this->beta, 'auth/login'), ['email' => 'ada@example.com', 'password' => 'password123'])->assertOk();
    }

    public function test_expired_and_superseded_links_are_rejected(): void
    {
        $this->register($this->alpha);
        $first = $this->requestLink($this->alpha);
        $this->travel(2)->minutes();
        Mail::fake();
        $second = $this->requestLink($this->alpha);

        $body = ['email' => 'ada@example.com', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];
        $this->postJson($this->api($this->alpha, 'auth/reset-password'), ['token' => $first] + $body)->assertStatus(422);

        $this->travel(61)->minutes();
        $this->postJson($this->api($this->alpha, 'auth/reset-password'), ['token' => $second] + $body)->assertStatus(422);
    }

    public function test_repeat_requests_within_a_minute_send_one_mail(): void
    {
        $this->register($this->alpha);
        $this->postJson($this->api($this->alpha, 'auth/forgot-password'), ['email' => 'ada@example.com'])->assertOk();
        $this->postJson($this->api($this->alpha, 'auth/forgot-password'), ['email' => 'ada@example.com'])->assertOk();

        Mail::assertQueuedCount(1);
        $this->assertSame(1, StoreCustomerPasswordReset::withoutGlobalScopes()->count());
    }

    public function test_only_the_token_hash_is_stored(): void
    {
        $this->register($this->alpha);
        $token = $this->requestLink($this->alpha);

        $row = StoreCustomerPasswordReset::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame($this->alpha->id, $row->store_id);
    }
}
