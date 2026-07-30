<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OpenApiContractCoverageTest extends TestCase
{
    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportPath = storage_path('app/openapi/openapi-contract-test.json');
        config()->set('scramble.cache.store', 'array');
    }

    protected function tearDown(): void
    {
        File::delete($this->exportPath);

        parent::tearDown();
    }

    public function test_the_generated_openapi_document_covers_every_versioned_api_operation(): void
    {
        $exitCode = Artisan::call('scramble:export', [
            '--path' => 'storage/app/openapi/openapi-contract-test.json',
        ]);

        $this->assertSame(0, $exitCode);

        /** @var array{openapi: string, paths: array<string, array<string, mixed>>} $document */
        $document = json_decode(File::get($this->exportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.0', $document['openapi']);

        $missingOperations = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $path = '/'.substr($uri, strlen('api/v1/'));

            foreach (array_intersect($route->methods(), ['DELETE', 'GET', 'PATCH', 'POST', 'PUT']) as $method) {
                if (! isset($document['paths'][$path][strtolower($method)])) {
                    $missingOperations[] = sprintf('%s %s', $method, $path);
                }
            }
        }

        $this->assertSame([], $missingOperations, 'OpenAPI is missing versioned API operations: '.implode(', ', $missingOperations));
    }
}
