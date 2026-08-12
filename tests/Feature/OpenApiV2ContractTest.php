<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiV2ContractTest extends TestCase
{
    public function test_every_documented_v2_operation_has_a_laravel_route(): void
    {
        $document = Yaml::parseFile(base_path('openapi/v2.yaml'));
        $actual = collect(Route::getRoutes()->getRoutes())->mapWithKeys(function ($route): array {
            $uri = '/'.ltrim((string) preg_replace('#^api/v2#', '', $route->uri()), '/');
            $methods = array_values(array_diff($route->methods(), ['HEAD']));

            return collect($methods)->mapWithKeys(
                fn (string $method): array => [strtolower($method).' '.$uri => true]
            )->all();
        });

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertTrue(
                    $actual->has(strtolower($method).' '.$path),
                    strtoupper($method)." {$path} is documented but has no Laravel route",
                );
                $this->assertNotEmpty($operation['operationId'] ?? null, strtoupper($method)." {$path} needs operationId");
            }
        }

        $documented = collect($document['paths'])->flatMap(function (array $operations, string $path): array {
            return collect($operations)
                ->except('parameters')
                ->keys()
                ->map(fn (string $method): string => strtolower($method).' '.$path)
                ->all();
        });
        $v2Routes = $actual->keys()->filter(fn (string $key): bool => str_contains($key, ' /organizations') || str_contains($key, ' /procurement'));
        $this->assertEmpty($v2Routes->diff($documented)->values()->all(), 'Laravel exposes undocumented v2 routes.');
    }

    public function test_contract_declares_jwt_and_organization_path_scope(): void
    {
        $document = Yaml::parseFile(base_path('openapi/v2.yaml'));

        $this->assertSame('bearer', $document['components']['securitySchemes']['bearerAuth']['scheme']);
        foreach (array_keys($document['paths']) as $path) {
            if (in_array($path, ['/organizations', '/plans'], true) || str_starts_with($path, '/directory/organizations') || str_starts_with($path, '/procurement/')) {
                continue;
            }
            $this->assertStringStartsWith('/organizations/{organization}', $path);
        }
    }
}
