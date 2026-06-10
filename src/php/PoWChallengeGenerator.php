<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Génère un challenge de preuve d'effort (PoW) signé.
 *
 * Le challenge est stateless : nonce aléatoire + difficulté + expiration,
 * le tout signé en HMAC. Le client doit trouver une solution telle que
 * sha256(nonce . '.' . solution) commence par `difficulty` bits à zéro.
 *
 * La difficulté et l'expiration sont couvertes par la signature pour
 * empêcher le client de les affaiblir.
 */
class PoWChallengeGenerator
{
    private Config $config;

    /**
     * @param Config $config Configuration Gaitcha.
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Génère un challenge PoW signé.
     *
     * @param int|null $currentTime Timestamp courant (défaut : time()). Utile pour les tests.
     * @return array{nonce: string, difficulty: int, expires: int, signature: string, algorithm: string}
     */
    public function generate(?int $currentTime = null): array
    {
        $nonce      = bin2hex(random_bytes(16));
        $difficulty = $this->config->getPowDifficulty();
        $expires    = ($currentTime ?? time()) + $this->config->getPowChallengeTtl();

        return [
            'nonce'      => $nonce,
            'difficulty' => $difficulty,
            'expires'    => $expires,
            'signature'  => $this->sign($nonce, $difficulty, $expires),
            'algorithm'  => 'sha256',
        ];
    }

    /**
     * Signe un challenge (nonce + difficulté + expiration).
     *
     * @param string $nonce      Nonce hexadécimal.
     * @param int    $difficulty Difficulté en bits.
     * @param int    $expires    Timestamp d'expiration.
     * @return string Signature HMAC-SHA256 hexadécimale.
     */
    public function sign(string $nonce, int $difficulty, int $expires): string
    {
        // Préfixe 'pow.' : sépare le domaine de signature de celui des tokens
        // (même secret, usages distincts).
        $payload = $nonce . '.' . $difficulty . '.' . $expires;

        return hash_hmac('sha256', 'pow.' . $payload, $this->config->getSecret());
    }
}
