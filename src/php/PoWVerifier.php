<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Vérifie une preuve d'effort (PoW) soumise par le client.
 *
 * Séquence : forme → signature → expiration → difficulté → anti-replay.
 * La signature est vérifiée avant l'expiration pour ne pas fournir
 * d'oracle à un attaquant (même logique que TokenValidator).
 *
 * Si un TokenStoreInterface est configuré (anti_replay), chaque nonce
 * est consommé à la première vérification réussie : une solution = un
 * init. Sans store, un challenge reste réutilisable pendant son TTL.
 */
class PoWVerifier
{
    public const REASON_MISSING      = 'pow_missing';
    public const REASON_MALFORMED    = 'pow_malformed';
    public const REASON_INVALID      = 'pow_invalid';
    public const REASON_EXPIRED      = 'pow_expired';
    public const REASON_INSUFFICIENT = 'pow_insufficient';
    public const REASON_REPLAYED     = 'pow_replayed';

    private Config $config;
    private PoWChallengeGenerator $challengeGenerator;

    /**
     * @param Config $config Configuration Gaitcha.
     */
    public function __construct(Config $config)
    {
        $this->config             = $config;
        $this->challengeGenerator = new PoWChallengeGenerator($config);
    }

    /**
     * Vérifie une preuve d'effort.
     *
     * @param mixed    $pow         Données PoW soumises (challenge + solution).
     * @param int|null $currentTime Timestamp courant (défaut : time()). Utile pour les tests.
     * @return array{valid: bool, reason: string|null}
     */
    public function verify($pow, ?int $currentTime = null): array
    {
        $currentTime = $currentTime ?? time();

        if (!is_array($pow) || $pow === []) {
            return $this->invalid(self::REASON_MISSING);
        }

        if (!$this->hasValidShape($pow)) {
            return $this->invalid(self::REASON_MALFORMED);
        }

        $nonce      = $pow['nonce'];
        $difficulty = (int) $pow['difficulty'];
        $expires    = (int) $pow['expires'];
        $solution   = (string) $pow['solution'];

        $expectedSignature = $this->challengeGenerator->sign($nonce, $difficulty, $expires);

        if (!hash_equals($expectedSignature, $pow['signature'])) {
            return $this->invalid(self::REASON_INVALID);
        }

        if ($expires < $currentTime) {
            return $this->invalid(self::REASON_EXPIRED);
        }

        $hash = hash('sha256', $nonce . '.' . $solution, true);

        if ($this->countLeadingZeroBits($hash) < $difficulty) {
            return $this->invalid(self::REASON_INSUFFICIENT);
        }

        if ($this->config->isAntiReplay()) {
            $store     = $this->config->getTokenStore();
            $nonceHash = hash('sha256', 'pow.' . $nonce);

            if ($store->checkAndAdd($nonceHash, $currentTime)) {
                return $this->invalid(self::REASON_REPLAYED);
            }
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * Vérifie la forme et les types des données PoW soumises.
     *
     * @param array<string, mixed> $pow Données PoW.
     * @return bool True si la forme est valide.
     */
    private function hasValidShape(array $pow): bool
    {
        if (
            !isset($pow['nonce'], $pow['difficulty'], $pow['expires'], $pow['signature'], $pow['solution'])
        ) {
            return false;
        }

        if (!is_string($pow['nonce']) || !preg_match('/^[a-f0-9]{32}$/', $pow['nonce'])) {
            return false;
        }

        if (!is_string($pow['signature']) || !preg_match('/^[a-f0-9]{64}$/', $pow['signature'])) {
            return false;
        }

        if (!is_int($pow['difficulty']) && !ctype_digit((string) $pow['difficulty'])) {
            return false;
        }

        if (!is_int($pow['expires']) && !ctype_digit((string) $pow['expires'])) {
            return false;
        }

        $solution = $pow['solution'];

        if (is_int($solution)) {
            $solution = (string) $solution;
        }

        if (!is_string($solution) || $solution === '' || strlen($solution) > 20 || !ctype_digit($solution)) {
            return false;
        }

        return true;
    }

    /**
     * Compte les bits à zéro en tête d'un hash binaire.
     *
     * @param string $hash Hash en binaire brut.
     * @return int Nombre de bits à zéro consécutifs depuis le début.
     */
    private function countLeadingZeroBits(string $hash): int
    {
        $bits   = 0;
        $length = strlen($hash);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($hash[$i]);

            if ($byte === 0) {
                $bits += 8;
                continue;
            }

            // Compter les zéros de tête dans ce byte puis s'arrêter.
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
     * Construit un résultat de vérification invalide.
     *
     * @param string $reason Motif de rejet.
     * @return array{valid: bool, reason: string}
     */
    private function invalid(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
