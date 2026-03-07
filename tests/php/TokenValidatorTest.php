<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use Gaitcha\TokenGenerator;
use Gaitcha\TokenValidator;
use Gaitcha\ValidationResult;
use PHPUnit\Framework\TestCase;

class TokenValidatorTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-gaitcha-unit-tests!';

    private Config $config;
    private TokenGenerator $generator;
    private TokenValidator $validator;

    protected function setUp(): void
    {
        $this->config    = new Config(['secret' => self::SECRET, 'ttl' => 120]);
        $this->generator = new TokenGenerator($this->config);
        $this->validator = new TokenValidator($this->config);
    }

    public function testValidTokenIsAccepted(): void
    {
        $now   = 1700000000;
        $token = $this->generator->generateToken('_gc_aabb1234', $now);

        $result = $this->validator->validate($token, $now);

        $this->assertTrue($result['valid']);
        $this->assertSame('_gc_aabb1234', $result['field_name']);
        $this->assertNull($result['result']);
    }

    public function testTokenValidJustBeforeTtl(): void
    {
        $now   = 1700000000;
        $token = $this->generator->generateToken('_gc_ccdd5678', $now);

        // Valider à TTL - 1 seconde.
        $result = $this->validator->validate($token, $now + 119);

        $this->assertTrue($result['valid']);
    }

    public function testTokenExpiredAtExactTtl(): void
    {
        $now   = 1700000000;
        $token = $this->generator->generateToken('_gc_ccdd5678', $now);

        // Valider à TTL + 1 seconde.
        $result = $this->validator->validate($token, $now + 121);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_EXPIRED, $result['result']->getReason());
    }

    public function testTokenExpired(): void
    {
        $now   = 1700000000;
        $token = $this->generator->generateToken('_gc_ccdd5678', $now);

        $result = $this->validator->validate($token, $now + 300);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_EXPIRED, $result['result']->getReason());
    }

    public function testTokenWithForgedSignature(): void
    {
        $token = '_gc_abcd1234.1700000000.forgedsignatureforgedsignatureforgedsignatureforgedsignature';

        $result = $this->validator->validate($token, 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testTokenWithWrongSecret(): void
    {
        $otherConfig    = new Config(['secret' => 'wrong-secret-key-for-gaitcha-testing!!']);
        $otherGenerator = new TokenGenerator($otherConfig);

        $token  = $otherGenerator->generateToken('_gc_eeff0011', 1700000000);
        $result = $this->validator->validate($token, 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testMalformedTokenTooFewParts(): void
    {
        $result = $this->validator->validate('only.two', 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testMalformedTokenTooManyParts(): void
    {
        $result = $this->validator->validate('a.b.c.d', 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testMalformedTokenNonNumericTimestamp(): void
    {
        $result = $this->validator->validate('_gc_abcd1234.notanumber.sig', 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testTokenWithInvalidFieldNameFormat(): void
    {
        // Field name ne match pas le pattern _gc_[a-f0-9]{8}.
        $token = $this->generator->generateToken('_gc_ZZZZ9999', 1700000000);

        $result = $this->validator->validate($token, 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testTokenInTheFuture(): void
    {
        $now   = 1700000000;
        $token = $this->generator->generateToken('_gc_ddee7788', $now + 100);

        $result = $this->validator->validate($token, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }

    public function testEmptyToken(): void
    {
        $result = $this->validator->validate('', 1700000000);

        $this->assertFalse($result['valid']);
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result['result']->getReason());
    }
}
