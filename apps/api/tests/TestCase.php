<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application with an isolated test configuration even when the local container cached production settings.
     */
    public function createApplication(): Application
    {
        $environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => __DIR__.'/../storage/framework/testing/config.php',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
