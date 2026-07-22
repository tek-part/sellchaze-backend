<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\User;
use App\Services\Stores\StoreDomainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreDomainResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(string $slug, bool $withDomain = true): Store
    {
        $user = User::factory()->create();
        $store = Store::create([
            'owner_user_id' => $user->id,
            'owner_type' => 'merchant',
            'name' => ucfirst($slug),
            'slug' => $slug,
            'currency' => 'USD',
            'status' => 'active',
        ]);
        if ($withDomain) {
            StoreDomain::create([
                'store_id' => $store->id,
                'host' => $slug.'.sellchase.com',
                'type' => 'subdomain',
                'is_primary' => true,
            ]);
        }

        return $store;
    }

    public function test_resolves_a_persisted_subdomain_row(): void
    {
        $store = $this->makeStore('nike');
        $resolver = new StoreDomainResolver;

        $this->assertSame($store->id, $resolver->resolve('nike.sellchase.com')?->id);
    }

    public function test_resolution_is_case_insensitive(): void
    {
        $store = $this->makeStore('nike');
        $resolver = new StoreDomainResolver;

        $this->assertSame($store->id, $resolver->resolve('NIKE.Sellchase.COM')?->id);
    }

    public function test_slug_fallback_resolves_when_no_domain_row_exists(): void
    {
        $store = $this->makeStore('apple', withDomain: false);
        $resolver = new StoreDomainResolver;

        $this->assertSame($store->id, $resolver->resolve('apple.sellchase.com')?->id);
    }

    public function test_base_domain_itself_resolves_to_nothing(): void
    {
        $resolver = new StoreDomainResolver;

        $this->assertNull($resolver->resolve('sellchase.com'));
    }

    public function test_reserved_labels_never_resolve(): void
    {
        $resolver = new StoreDomainResolver;

        foreach (['www', 'api', 'admin', 'app', 'dashboard', 'mail', 'ftp'] as $label) {
            $this->assertNull($resolver->resolve($label.'.sellchase.com'), "Reserved label {$label} resolved");
        }
    }

    public function test_unknown_and_foreign_hosts_do_not_resolve(): void
    {
        $this->makeStore('nike');
        $resolver = new StoreDomainResolver;

        $this->assertNull($resolver->resolve('unknown.sellchase.com'));
        $this->assertNull($resolver->resolve('nike.evil.com'));       // spoofed foreign host
        $this->assertNull($resolver->resolve('a.b.sellchase.com'));   // nested subdomain
        $this->assertNull($resolver->resolve(null));
    }
}
