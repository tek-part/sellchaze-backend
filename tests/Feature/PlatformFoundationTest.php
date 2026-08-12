<?php

namespace Tests\Feature;

use App\Events\DomainEventPublished;
use App\Models\FeatureFlag;
use App\Models\Organization;
use App\Services\Features\FeatureFlagService;
use App\Services\Outbox\OutboxPublisher;
use App\Services\Outbox\OutboxRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_is_preserved_and_returned_to_the_client(): void
    {
        $this->withHeader('X-Request-ID', 'sellchaze-test-123')
            ->getJson('/api/v2/plans')
            ->assertUnauthorized()
            ->assertHeader('X-Request-ID', 'sellchaze-test-123');
    }

    public function test_feature_flags_are_safe_by_default_and_support_company_overrides(): void
    {
        $organization = Organization::query()->create([
            'name' => 'Feature Company',
            'slug' => 'feature-company',
        ]);
        $service = app(FeatureFlagService::class);

        $this->assertFalse($service->enabled('unknown_flag', $organization));
        $this->assertFalse($service->enabled('theme_studio_v2', $organization));

        $flag = FeatureFlag::query()->where('key', 'theme_studio_v2')->firstOrFail();
        $organization->featureFlags()->attach($flag->id, ['enabled' => true]);

        $this->assertTrue($service->enabled('theme_studio_v2', $organization));
    }

    public function test_outbox_publisher_dispatches_and_marks_a_message_once(): void
    {
        Event::fake([DomainEventPublished::class]);
        $message = app(OutboxRecorder::class)->record(
            'ExampleChanged',
            'example',
            42,
            ['value' => 'new'],
        );

        $publisher = app(OutboxPublisher::class);
        $this->assertSame(['published' => 1, 'failed' => 0], $publisher->publishPending());
        $this->assertSame(['published' => 0, 'failed' => 0], $publisher->publishPending());

        Event::assertDispatchedTimes(DomainEventPublished::class, 1);
        $this->assertNotNull($message->fresh()->published_at);
    }

    public function test_outbox_failure_is_retried_without_losing_the_message(): void
    {
        Event::listen(DomainEventPublished::class, static function (): void {
            throw new RuntimeException('Temporary downstream failure');
        });

        $message = app(OutboxRecorder::class)->record(
            'ExampleChanged',
            'example',
            43,
            ['value' => 'retry'],
        );

        $this->assertSame(
            ['published' => 0, 'failed' => 1],
            app(OutboxPublisher::class)->publishPending(),
        );

        $message->refresh();
        $this->assertSame(1, $message->attempts);
        $this->assertNull($message->published_at);
        $this->assertNull($message->failed_at);
        $this->assertStringContainsString('Temporary downstream failure', $message->last_error);
        $this->assertTrue($message->available_at->isFuture());
    }
}
