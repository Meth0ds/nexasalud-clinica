<?php

namespace Tests\Feature\Feature\Api\V1;

use Tests\TestCase;

class PrescriptionsApiTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
