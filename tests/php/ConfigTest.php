<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testRequiresSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secret must be a string of at least 32 characters');
        new Config([]);
    }

    public function testRejectsShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secret must be a string of at least 32 characters');
        new Config(['secret' => 'too-short']);
    }

    public function testDefaultValues(): void
    {
        $secret = 'test-secret-key-for-gaitcha-unit-tests!';
        $config = new Config(['secret' => $secret]);

        $this->assertSame($secret, $config->getSecret());
        $this->assertSame(120, $config->getTtl());
        $this->assertSame(0.5, $config->getScoreThreshold());
        $this->assertFalse($config->isDebug());
        $this->assertSame('reject', $config->getNoJsFallback());
        $this->assertSame('_ct', $config->getTokenFieldName());
        $this->assertSame('_gc_', $config->getFieldPrefix());
        $this->assertFalse($config->isAntiReplay());
        $this->assertNull($config->getTokenStore());
    }

    public function testCustomValues(): void
    {
        $config = new Config([
            'secret'           => 'test-secret-key-for-gaitcha-unit-tests!',
            'ttl'              => 300,
            'score_threshold'  => 0.7,
            'debug'            => true,
            'no_js_fallback'   => 'allow',
            'token_field_name' => '_tok',
            'field_prefix'     => '_gx_',
        ]);

        $this->assertSame(300, $config->getTtl());
        $this->assertSame(0.7, $config->getScoreThreshold());
        $this->assertTrue($config->isDebug());
        $this->assertSame('allow', $config->getNoJsFallback());
        $this->assertSame('_tok', $config->getTokenFieldName());
        $this->assertSame('_gx_', $config->getFieldPrefix());
    }

    public function testRejectsZeroTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttl must be a positive integer');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'ttl' => 0]);
    }

    public function testRejectsInvalidScoreThreshold(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('score_threshold must be between');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'score_threshold' => 1.5]);
    }

    public function testRejectsDotInFieldPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field_prefix must not contain dots');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'field_prefix' => '_gc.']);
    }

    public function testRejectsDotInTokenFieldName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('token_field_name must not contain dots');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'token_field_name' => '_c.t']);
    }

    public function testInvalidNoJsFallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_js_fallback');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'no_js_fallback' => 'invalid']);
    }

    public function testAntiReplayRequiresStore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('token_store is required');
        new Config(['secret' => 'test-secret-key-for-gaitcha-unit-tests!', 'anti_replay' => true]);
    }
}
