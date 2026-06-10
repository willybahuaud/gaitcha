<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use Gaitcha\PoWChallengeGenerator;
use PHPUnit\Framework\TestCase;

class PoWChallengeGeneratorTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-gaitcha-unit-tests!';

    private Config $config;
    private PoWChallengeGenerator $generator;

    protected function setUp(): void
    {
        $this->config = new Config([
            'secret'            => self::SECRET,
            'pow'               => true,
            'pow_difficulty'    => 12,
            'pow_challenge_ttl' => 90,
        ]);
        $this->generator = new PoWChallengeGenerator($this->config);
    }

    public function testChallengeShape(): void
    {
        $now       = 1700000000;
        $challenge = $this->generator->generate($now);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $challenge['nonce']);
        $this->assertSame(12, $challenge['difficulty']);
        $this->assertSame($now + 90, $challenge['expires']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $challenge['signature']);
        $this->assertSame('sha256', $challenge['algorithm']);
    }

    public function testSignatureIsDeterministic(): void
    {
        $challenge = $this->generator->generate(1700000000);

        $this->assertSame(
            $this->generator->sign($challenge['nonce'], $challenge['difficulty'], $challenge['expires']),
            $challenge['signature']
        );
    }

    public function testSignatureDependsOnEveryField(): void
    {
        $signature = $this->generator->sign('aabbccddeeff00112233445566778899', 12, 1700000090);

        $this->assertNotSame($signature, $this->generator->sign('00bbccddeeff00112233445566778899', 12, 1700000090));
        $this->assertNotSame($signature, $this->generator->sign('aabbccddeeff00112233445566778899', 13, 1700000090));
        $this->assertNotSame($signature, $this->generator->sign('aabbccddeeff00112233445566778899', 12, 1700000091));
    }

    public function testNoncesAreUnique(): void
    {
        $first  = $this->generator->generate();
        $second = $this->generator->generate();

        $this->assertNotSame($first['nonce'], $second['nonce']);
    }
}
