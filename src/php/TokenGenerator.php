<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Génère un nom de champ aléatoire et un token HMAC signé.
 *
 * Le token encode le field name + un timestamp et les signe
 * avec la clé secrète. Le serveur peut ainsi retrouver le
 * nom du champ à la validation sans stocker d'état.
 */
class TokenGenerator
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Génère un nom de champ aléatoire préfixé.
     *
     * @return string Ex: "_gc_a3f8k2m1"
     */
    public function generateFieldName(): string
    {
        $random = bin2hex(random_bytes(4));
        return $this->config->getFieldPrefix() . $random;
    }

    /**
     * Génère un token HMAC signé pour un field name donné.
     *
     * Format : fieldName.timestamp.signature
     *
     * @param string   $fieldName Nom du champ à encoder dans le token.
     * @param int|null $timestamp Timestamp de génération (défaut : time()). Utile pour les tests.
     * @return string Token signé.
     */
    public function generateToken(string $fieldName, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $payload   = $fieldName . '.' . $timestamp;
        $signature = hash_hmac('sha256', $payload, $this->config->getSecret());

        return $payload . '.' . $signature;
    }

    /**
     * Génère un field name + token en une seule opération.
     *
     * @return array{field_name: string, token: string}
     */
    public function generate(): array
    {
        $fieldName = $this->generateFieldName();

        return [
            'field_name' => $fieldName,
            'token'      => $this->generateToken($fieldName),
        ];
    }
}
