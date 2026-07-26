<?php

namespace Tests\Unit\Unit\Identity;

use App\Services\Identity\SafeReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeReturnPathTest extends TestCase
{
    #[DataProvider('safePathProvider')]
    public function test_it_preserves_only_local_paths(string $candidate): void
    {
        $this->assertSame($candidate, (new SafeReturnPath)->normalize($candidate));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safePathProvider(): array
    {
        return [
            'root' => ['/'],
            'route' => ['/agenda/semana'],
            'query' => ['/agenda?view=week'],
            'fragment' => ['/agenda#hoy'],
            'unicode' => ['/pacientes/búsqueda'],
        ];
    }

    #[DataProvider('unsafePathProvider')]
    public function test_it_replaces_external_or_ambiguous_paths_with_root(?string $candidate): void
    {
        $this->assertSame('/', (new SafeReturnPath)->normalize($candidate));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unsafePathProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'absolute https URL' => ['https://attacker.example/steal'],
            'protocol relative URL' => ['//attacker.example/steal'],
            'encoded protocol relative URL' => ['/%2F%2Fattacker.example/steal'],
            'backslash URL' => ['/\\attacker.example/steal'],
            'encoded backslash URL' => ['/%5Cattacker.example/steal'],
            'missing leading slash' => ['agenda'],
            'control character' => ["/agenda\nLocation: https://attacker.example"],
            'oversized value' => ['/'.str_repeat('a', 1024)],
        ];
    }
}
