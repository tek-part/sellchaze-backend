<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ArchitectureBoundariesTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function layerRules(): array
    {
        return [
            'models stay independent from HTTP delivery' => [
                dirname(__DIR__, 2).'/app/Models',
                ['App\\Http\\Controllers', 'App\\Http\\Requests', 'App\\Http\\Resources'],
            ],
            'organization actions stay independent from HTTP delivery' => [
                dirname(__DIR__, 2).'/app/Actions/Organizations',
                ['App\\Http\\Controllers', 'App\\Http\\Requests', 'App\\Http\\Resources'],
            ],
            'procurement actions stay independent from HTTP delivery' => [
                dirname(__DIR__, 2).'/app/Actions/Procurement',
                ['App\\Http\\Controllers', 'App\\Http\\Requests', 'App\\Http\\Resources'],
            ],
            'subscription actions stay independent from HTTP delivery' => [
                dirname(__DIR__, 2).'/app/Actions/Subscriptions',
                ['App\\Http\\Controllers', 'App\\Http\\Requests', 'App\\Http\\Resources'],
            ],
        ];
    }

    /**
     * @param  list<string>  $forbiddenDependencies
     */
    #[DataProvider('layerRules')]
    public function test_core_layers_do_not_depend_on_http_delivery(string $directory, array $forbiddenDependencies): void
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertNotFalse($source);
            foreach ($forbiddenDependencies as $dependency) {
                if (str_contains($source, $dependency)) {
                    $violations[] = $file->getPathname().' -> '.$dependency;
                }
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }
}

