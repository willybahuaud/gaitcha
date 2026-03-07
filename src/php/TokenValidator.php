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

    /**
     * @param Config $config Configuration Gaitcha.
     */
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

        // Valider le format du field name (préfixe + 8 hex).
        $prefix  = preg_quote($this->config->getFieldPrefix(), '/');
        $pattern = '/^' . $prefix . '[a-f0-9]{8}$/';

        if (!preg_match($pattern, $fieldName)) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        if (!ctype_digit($timestampStr)) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        $timestamp = (int) $timestampStr;

        // Toujours vérifier la signature en premier pour ne pas
        // fournir d'oracle (expired vs invalid) à un attaquant.
        $expectedSignature = hash_hmac(
            'sha256',
            $fieldName . '.' . $timestampStr,
            $this->config->getSecret()
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        // Token dans le futur = forgé ou horloge décalée.
        if ($timestamp > $currentTime) {
            return $this->invalid(ValidationResult::REASON_TOKEN_INVALID);
        }

        // Vérifier l'expiration après la signature.
        if (($currentTime - $timestamp) > $this->config->getTtl()) {
            return $this->invalid(ValidationResult::REASON_TOKEN_EXPIRED);
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
