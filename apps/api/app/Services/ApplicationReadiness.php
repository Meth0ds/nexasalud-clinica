<?php

namespace App\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks the state required to safely accept clinical requests.
 *
 * The result is intentionally binary: dependency names and failures are kept
 * in operational logs and are never returned by the public readiness route.
 */
class ApplicationReadiness
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly RedisManager $redis,
    ) {}

    public function isReady(): bool
    {
        if (! $this->databaseIsAvailable()) {
            return false;
        }

        return $this->redisIsAvailable();
    }

    private function databaseIsAvailable(): bool
    {
        try {
            $this->database->connection()->selectOne('SELECT 1');

            return true;
        } catch (Throwable) {
            Log::warning('application.readiness_unavailable', ['dependency' => 'database']);

            return false;
        }
    }

    private function redisIsAvailable(): bool
    {
        try {
            $response = $this->redis->connection()->ping();

            if ($response === true || strtoupper((string) $response) === 'PONG') {
                return true;
            }
        } catch (Throwable) {
            // The generic event below deliberately avoids logging connection details.
        }

        Log::warning('application.readiness_unavailable', ['dependency' => 'redis']);

        return false;
    }
}
