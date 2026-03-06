<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use Gaitcha\TokenGenerator;
use PHPUnit\Framework\TestCase;

class TokenGeneratorTest extends TestCase
{
    private TokenGenerator $generator;
    private Config $config;

    protected function setUp(): void
    {
        $this->config    = new Config(['secret' => 'test-secret-key']);
        $this->generator = new TokenGenerator($this->config);
    }

    public function testGenerateFieldNameHasPrefix(): void
    {
        $name = $this->generator->generateFieldName();

        $this->assertStringStartsWith('_gc_', $name);
    }

    public function testGenerateFieldNameIsUnique(): void
    {
        $names = [];
        for ($i = 0; $i < 100; $i++) {
            $names[] = $this->generator->generateFieldName();
        }

        $this->assertCount(100, array_unique($names), 'Les field names doivent etre uniques.');
    }

    public function testGenerateFieldNameCustomPrefix(): void
    {
        $config    = new Config(['secret' => 'test', 'field_prefix' => '_custom_']);
        $generator = new TokenGenerator($config);
        $name      = $generator->generateFieldName();

        $this->assertStringStartsWith('_custom_', $name);
    }

    public function testGenerateTokenFormat(): void
    {
        $token = $this->generator->generateToken('_gc_abcd1234', 1700000000);
        $parts = explode('.', $token);

        $this->assertCount(3, $parts, 'Le token doit avoir 3 parties separees par des points.');
        $this->assertSame('_gc_abcd1234', $parts[0]);
        $this->assertSame('1700000000', $parts[1]);
        $this->assertSame(64, strlen($parts[2]), 'La signature HMAC-SHA256 fait 64 caracteres hex.');
    }

    public function testGenerateTokenSignatureIsValid(): void
    {
        $fieldName = '_gc_test1234';
        $timestamp = 1700000000;
        $token     = $this->generator->generateToken($fieldName, $timestamp);

        $expectedSignature = hash_hmac('sha256', $fieldName . '.' . $timestamp, 'test-secret-key');

        $parts = explode('.', $token);
        $this->assertSame($expectedSignature, $parts[2]);
    }

    public function testGenerateTokenDifferentSecretsProduceDifferentSignatures(): void
    {
        $config2    = new Config(['secret' => 'other-secret']);
        $generator2 = new TokenGenerator($config2);

        $token1 = $this->generator->generateToken('_gc_test', 1700000000);
        $token2 = $generator2->generateToken('_gc_test', 1700000000);

        $this->assertNotSame($token1, $token2);
    }

    public function testGenerateReturnsBothFieldNameAndToken(): void
    {
        $result = $this->generator->generate();

        $this->assertArrayHasKey('field_name', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertStringStartsWith('_gc_', $result['field_name']);
        $this->assertStringStartsWith($result['field_name'] . '.', $result['token']);
    }
}
