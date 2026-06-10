<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\AbstractEndpoint;
use Gaitcha\Config;
use Gaitcha\PoWVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Endpoint concret minimal pour tester handleInit().
 */
class TestableEndpoint extends AbstractEndpoint
{
    /**
     * Expose handleInit() pour les tests.
     *
     * @param array<string, mixed>|null $request Corps de requête simulé.
     * @return array Réponse de l'endpoint.
     */
    public function init(?array $request = null): array
    {
        return $this->handleInit($request);
    }

    /**
     * {@inheritdoc}
     */
    protected function sendJsonResponse(array $data): void
    {
        // No-op en test.
    }
}

class AbstractEndpointTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-gaitcha-unit-tests!';

    /**
     * Résout un challenge par force brute (difficulté basse en test).
     *
     * @param array $challenge Challenge renvoyé par l'endpoint.
     * @return string Compteur solution en chaîne décimale.
     */
    private function solve(array $challenge): string
    {
        for ($counter = 0; $counter < 1000000; $counter++) {
            $hash  = hash('sha256', $challenge['nonce'] . '.' . $counter, true);
            $first = ord($hash[0]);

            // Difficulté 8 en test : le premier octet doit être nul.
            if ($first === 0) {
                return (string) $counter;
            }
        }

        $this->fail('PoW solution not found within iteration limit.');
    }

    public function testInitWithoutPowReturnsTokenPayload(): void
    {
        $endpoint = new TestableEndpoint(new Config(['secret' => self::SECRET]));

        $response = $endpoint->init();

        $this->assertArrayHasKey('field_name', $response);
        $this->assertArrayHasKey('token', $response);
        $this->assertArrayHasKey('ttl', $response);
        $this->assertArrayHasKey('token_field_name', $response);
        $this->assertArrayNotHasKey('pow_challenge', $response);
    }

    public function testPowEnabledRequiresRequestBody(): void
    {
        $endpoint = new TestableEndpoint(new Config([
            'secret' => self::SECRET,
            'pow'    => true,
        ]));

        $this->expectException(\LogicException::class);
        $endpoint->init();
    }

    public function testFirstCallReturnsChallengeWithoutError(): void
    {
        $endpoint = new TestableEndpoint(new Config([
            'secret'         => self::SECRET,
            'pow'            => true,
            'pow_difficulty' => 8,
        ]));

        $response = $endpoint->init([]);

        $this->assertArrayHasKey('pow_challenge', $response);
        $this->assertArrayNotHasKey('pow_error', $response);
        $this->assertArrayNotHasKey('token', $response);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response['pow_challenge']['nonce']);
    }

    public function testInvalidSolutionReturnsNewChallengeWithError(): void
    {
        $endpoint = new TestableEndpoint(new Config([
            'secret'         => self::SECRET,
            'pow'            => true,
            'pow_difficulty' => 8,
        ]));

        $challenge = $endpoint->init([])['pow_challenge'];

        $response = $endpoint->init(['pow' => [
            'nonce'      => $challenge['nonce'],
            'difficulty' => $challenge['difficulty'],
            'expires'    => $challenge['expires'],
            'signature'  => str_repeat('a', 64),
            'solution'   => '42',
        ]]);

        $this->assertArrayHasKey('pow_challenge', $response);
        $this->assertSame(PoWVerifier::REASON_INVALID, $response['pow_error']);
        $this->assertArrayNotHasKey('token', $response);
    }

    public function testValidSolutionReturnsTokenPayload(): void
    {
        $endpoint = new TestableEndpoint(new Config([
            'secret'         => self::SECRET,
            'pow'            => true,
            'pow_difficulty' => 8,
        ]));

        $challenge = $endpoint->init([])['pow_challenge'];

        $response = $endpoint->init(['pow' => [
            'nonce'      => $challenge['nonce'],
            'difficulty' => $challenge['difficulty'],
            'expires'    => $challenge['expires'],
            'signature'  => $challenge['signature'],
            'solution'   => $this->solve($challenge),
        ]]);

        $this->assertArrayHasKey('field_name', $response);
        $this->assertArrayHasKey('token', $response);
        $this->assertArrayNotHasKey('pow_challenge', $response);
    }
}
