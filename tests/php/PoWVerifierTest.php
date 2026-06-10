<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use Gaitcha\FileTokenStore;
use Gaitcha\PoWChallengeGenerator;
use Gaitcha\PoWVerifier;
use PHPUnit\Framework\TestCase;

class PoWVerifierTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-gaitcha-unit-tests!';

    /** Difficulté basse pour des tests rapides (~256 essais en moyenne). */
    private const DIFFICULTY = 8;

    private Config $config;
    private PoWChallengeGenerator $generator;
    private PoWVerifier $verifier;

    protected function setUp(): void
    {
        $this->config = new Config([
            'secret'         => self::SECRET,
            'pow'            => true,
            'pow_difficulty' => self::DIFFICULTY,
        ]);
        $this->generator = new PoWChallengeGenerator($this->config);
        $this->verifier  = new PoWVerifier($this->config);
    }

    /**
     * Résout un challenge par force brute (difficulté basse en test).
     *
     * @param array $challenge Challenge généré.
     * @return string Compteur solution en chaîne décimale.
     */
    private function solve(array $challenge): string
    {
        for ($counter = 0; $counter < 1000000; $counter++) {
            $hash = hash('sha256', $challenge['nonce'] . '.' . $counter, true);

            if ($this->countLeadingZeroBits($hash) >= $challenge['difficulty']) {
                return (string) $counter;
            }
        }

        $this->fail('PoW solution not found within iteration limit.');
    }

    /**
     * Compte les bits à zéro en tête d'un hash binaire.
     *
     * @param string $hash Hash binaire.
     * @return int Nombre de bits à zéro consécutifs.
     */
    private function countLeadingZeroBits(string $hash): int
    {
        $bits = 0;

        for ($i = 0, $length = strlen($hash); $i < $length; $i++) {
            $byte = ord($hash[$i]);

            if ($byte === 0) {
                $bits += 8;
                continue;
            }

            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $bits;
                }
                $bits++;
            }
        }

        return $bits;
    }

    /**
     * Construit une preuve complète (challenge résolu).
     *
     * @param int $now Timestamp de génération.
     * @return array Preuve { nonce, difficulty, expires, signature, solution }.
     */
    private function buildValidPow(int $now): array
    {
        $challenge = $this->generator->generate($now);

        return [
            'nonce'      => $challenge['nonce'],
            'difficulty' => $challenge['difficulty'],
            'expires'    => $challenge['expires'],
            'signature'  => $challenge['signature'],
            'solution'   => $this->solve($challenge),
        ];
    }

    public function testValidSolutionIsAccepted(): void
    {
        $now = 1700000000;
        $pow = $this->buildValidPow($now);

        $result = $this->verifier->verify($pow, $now);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
    }

    public function testMissingPowIsRejected(): void
    {
        $result = $this->verifier->verify(null);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_MISSING, $result['reason']);
    }

    public function testEmptyPowIsRejected(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_MISSING, $result['reason']);
    }

    public function testMalformedPowIsRejected(): void
    {
        $now = 1700000000;
        $pow = $this->buildValidPow($now);
        unset($pow['signature']);

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_MALFORMED, $result['reason']);
    }

    public function testInvalidNonceFormatIsRejected(): void
    {
        $now          = 1700000000;
        $pow          = $this->buildValidPow($now);
        $pow['nonce'] = 'ZZ' . substr($pow['nonce'], 2);

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_MALFORMED, $result['reason']);
    }

    public function testNonNumericSolutionIsRejected(): void
    {
        $now             = 1700000000;
        $pow             = $this->buildValidPow($now);
        $pow['solution'] = 'abc';

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_MALFORMED, $result['reason']);
    }

    public function testTamperedDifficultyIsRejected(): void
    {
        $now               = 1700000000;
        $pow               = $this->buildValidPow($now);
        $pow['difficulty'] = 1;

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_INVALID, $result['reason']);
    }

    public function testTamperedExpiryIsRejected(): void
    {
        $now            = 1700000000;
        $pow            = $this->buildValidPow($now);
        $pow['expires'] = $pow['expires'] + 3600;

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_INVALID, $result['reason']);
    }

    public function testForgedSignatureIsRejected(): void
    {
        $now              = 1700000000;
        $pow              = $this->buildValidPow($now);
        $pow['signature'] = str_repeat('a', 64);

        $result = $this->verifier->verify($pow, $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_INVALID, $result['reason']);
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $now = 1700000000;
        $pow = $this->buildValidPow($now);

        $result = $this->verifier->verify($pow, $now + 91);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_EXPIRED, $result['reason']);
    }

    public function testInsufficientSolutionIsRejected(): void
    {
        $now       = 1700000000;
        $challenge = $this->generator->generate($now);

        // Chercher un compteur qui ne satisfait PAS la difficulté.
        $badSolution = null;
        for ($counter = 0; $counter < 1000; $counter++) {
            $hash = hash('sha256', $challenge['nonce'] . '.' . $counter, true);
            if ($this->countLeadingZeroBits($hash) < $challenge['difficulty']) {
                $badSolution = (string) $counter;
                break;
            }
        }
        $this->assertNotNull($badSolution);

        $result = $this->verifier->verify([
            'nonce'      => $challenge['nonce'],
            'difficulty' => $challenge['difficulty'],
            'expires'    => $challenge['expires'],
            'signature'  => $challenge['signature'],
            'solution'   => $badSolution,
        ], $now);

        $this->assertFalse($result['valid']);
        $this->assertSame(PoWVerifier::REASON_INSUFFICIENT, $result['reason']);
    }

    public function testReplayedNonceIsRejectedWithStore(): void
    {
        $storePath = sys_get_temp_dir() . '/gaitcha-test-pow-store-' . uniqid() . '.json';
        $config    = new Config([
            'secret'         => self::SECRET,
            'pow'            => true,
            'pow_difficulty' => self::DIFFICULTY,
            'anti_replay'    => true,
            'token_store'    => new FileTokenStore($storePath),
        ]);
        $verifier = new PoWVerifier($config);

        $now = 1700000000;
        $pow = $this->buildValidPow($now);

        $first  = $verifier->verify($pow, $now);
        $second = $verifier->verify($pow, $now);

        $this->assertTrue($first['valid']);
        $this->assertFalse($second['valid']);
        $this->assertSame(PoWVerifier::REASON_REPLAYED, $second['reason']);

        @unlink($storePath);
        @unlink($storePath . '.lock');
    }

    public function testReplayIsAllowedWithoutStore(): void
    {
        $now = 1700000000;
        $pow = $this->buildValidPow($now);

        $first  = $this->verifier->verify($pow, $now);
        $second = $this->verifier->verify($pow, $now);

        $this->assertTrue($first['valid']);
        $this->assertTrue($second['valid']);
    }
}
