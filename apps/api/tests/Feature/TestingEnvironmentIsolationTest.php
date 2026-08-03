<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestingEnvironmentIsolationTest extends TestCase
{
    public function test_phpunit_uses_isolated_database_and_synchronous_queue_configuration(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sync', config('queue.default'));
    }
}
