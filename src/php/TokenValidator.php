<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Valide un token HMAC Gaitcha.
 *
 * Vérifie la signature (hash_equals pour résister aux timing attacks)
 * et l'expiration (TTL configurable).
 */
class TokenValidator
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Valide un token et retourne le résultat.
     *
     * En cas de succès, le field name est extrait du token.
     * En cas d'échec, un ValidationResult 'rejected' est retourné.
     *
     * @param string   $token     Token complet (fieldName.timestamp.signature).
     * @param int|null $currentTime Timestamp courant (défaut : time()). Utile pour les tests.
     * @return array{valid: bool, field_name: string|null, result: ValidationResult|null}
     */
    public function validate(string $token, ?int $currentTime = null): array
    {
        $currentTime = $currentTime ?? time();
        $parts       = explode('.', $token);

        if (count($parts) !== 3) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        [$fieldName, $timestampStr, $signature] = $parts;

        if (!ctype_digit($timestampStr)) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        $timestamp = (int) $timestampStr;

        // Vérifier l'expiration avant la signature
        // pour éviter de consommer du CPU sur un token expiré.
        if (($currentTime - $timestamp) > $this->config->getTtl()) {
            return $this->invalid(ValidationResult::REASON_TOKEN_EXPIRED);
        }

        // Token dans le futur = forgé ou horloge décalée.
        if ($timestamp > $currentTime) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $fieldName . '.' . $timestampStr,
            $this->config->getSecret()
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        return [
            'valid'      => true,
            'field_name' => $fieldName,
            'result'     => null,
        ];
    }

    /**
     * Construit un résultat de validation invalide.
     *
     * @param string $reason Motif de rejet.
     * @return array{valid: bool, field_name: null, result: ValidationResult}
     */
    private function invalid(string $reason): array
    {
        return [
            'valid'      => false,
            'field_name' => null,
            'result'     => ValidationResult::rejected($reason),
        ];
    }
}
