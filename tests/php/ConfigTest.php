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
        $this->expectExceptionMessage('secret is required');
        new Config([]);
    }

    public function testDefaultValues(): void
    {
        $config = new Config(['secret' => 'test-secret']);

        $this->assertSame('test-secret', $config->getSecret());
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
            'secret'           => 'my-secret',
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

    public function testInvalidNoJsFallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_js_fallback');
        new Config(['secret' => 'test', 'no_js_fallback' => 'invalid']);
    }

    public function testAntiReplayRequiresStore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('token_store is required');
        new Config(['secret' => 'test', 'anti_replay' => true]);
    }
}
