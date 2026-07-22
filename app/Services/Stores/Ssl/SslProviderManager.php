<?php

namespace App\Services\Stores\Ssl;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the configured SSL provider.
 *
 * Adding a provider is one class plus one map entry — nothing in the domain
 * lifecycle, jobs or scheduler changes. `extend()` lets an application service
 * provider register a bespoke provider without touching this file at all.
 */
class SslProviderManager
{
    /** @var array<string, class-string<SslProvider>> */
    private array $providers = [
        'none' => NullSslProvider::class,
        'acme' => AcmeSslProvider::class,
        'letsencrypt' => AcmeSslProvider::class, // alias: LE is one ACME CA among many
        'cloudflare' => CloudflareSslProvider::class,
        'caddy' => CaddySslProvider::class,
        'reverse-proxy' => ReverseProxySslProvider::class,
    ];

    /** @var array<string, callable(Container): SslProvider> */
    private array $custom = [];

    public function __construct(private readonly Container $container) {}

    /** Register a provider at runtime (e.g. from a service provider). */
    public function extend(string $name, callable $factory): void
    {
        $this->custom[strtolower($name)] = $factory;
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_values(array_unique([...array_keys($this->providers), ...array_keys($this->custom)]));
    }

    public function driver(?string $name = null): SslProvider
    {
        $name = strtolower($name ?: $this->defaultDriver());

        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($this->container);
        }

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException("Unknown SSL provider [{$name}].");
        }

        return $this->container->make($this->providers[$name]);
    }

    public function defaultDriver(): string
    {
        return (string) config('sellchase.storefront.ssl.provider', 'none');
    }
}
