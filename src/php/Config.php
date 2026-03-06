<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Configuration Gaitcha avec valeurs par défaut.
 *
 * La clé secrète (secret) est le seul paramètre obligatoire.
 * Tous les autres ont des defaults raisonnables.
 */
class Config
{
    /** @var string Clé secrète HMAC — ne doit jamais transiter côté client. */
    private string $secret;

    /** @var int Durée de validité du token en secondes. */
    private int $ttl;

    /** @var float Seuil minimum du score comportemental (0.0 à 1.0). */
    private float $scoreThreshold;

    /** @var bool Active le mode debug (détails de rejet loggés). */
    private bool $debug;

    /** @var string Comportement sans JS : 'reject' ou 'allow'. */
    private string $noJsFallback;

    /** @var string Nom du champ hidden contenant le token signé. */
    private string $tokenFieldName;

    /** @var string Préfixe des noms de champs générés (évite les collisions). */
    private string $fieldPrefix;

    /** @var bool Active la protection anti-replay (nécessite un TokenStoreInterface). */
    private bool $antiReplay;

    /** @var TokenStoreInterface|null Store pour l'anti-replay. */
    private ?TokenStoreInterface $tokenStore;

    /**
     * @param array<string, mixed> $options {
     *     @type string $secret          Clé secrète HMAC (obligatoire).
     *     @type int    $ttl             Durée de validité du token en secondes (défaut : 120).
     *     @type float  $score_threshold Seuil de score comportemental (défaut : 0.5).
     *     @type bool   $debug           Mode debug (défaut : false).
     *     @type string $no_js_fallback  'reject' ou 'allow' (défaut : 'reject').
     *     @type string $token_field_name Nom du champ token (défaut : '_ct').
     *     @type string $field_prefix    Préfixe des champs générés (défaut : '_gc_').
     *     @type bool   $anti_replay     Activer l'anti-replay (défaut : false).
     *     @type TokenStoreInterface $token_store Store anti-replay (défaut : null).
     * }
     */
    public function __construct(array $options)
    {
        if (empty($options['secret'])) {
            throw new \InvalidArgumentException('Gaitcha: secret is required.');
        }

        $this->secret         = $options['secret'];
        $this->ttl            = (int) ($options['ttl'] ?? 120);
        $this->scoreThreshold = (float) ($options['score_threshold'] ?? 0.5);
        $this->debug          = (bool) ($options['debug'] ?? false);
        $this->noJsFallback   = $options['no_js_fallback'] ?? 'reject';
        $this->tokenFieldName = $options['token_field_name'] ?? '_ct';
        $this->fieldPrefix    = $options['field_prefix'] ?? '_gc_';
        $this->antiReplay     = (bool) ($options['anti_replay'] ?? false);
        $this->tokenStore     = $options['token_store'] ?? null;

        if ($this->antiReplay && $this->tokenStore === null) {
            throw new \InvalidArgumentException('Gaitcha: token_store is required when anti_replay is enabled.');
        }

        if (!in_array($this->noJsFallback, ['reject', 'allow'], true)) {
            throw new \InvalidArgumentException('Gaitcha: no_js_fallback must be "reject" or "allow".');
        }
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function getScoreThreshold(): float
    {
        return $this->scoreThreshold;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function getNoJsFallback(): string
    {
        return $this->noJsFallback;
    }

    public function getTokenFieldName(): string
    {
        return $this->tokenFieldName;
    }

    public function getFieldPrefix(): string
    {
        return $this->fieldPrefix;
    }

    public function isAntiReplay(): bool
    {
        return $this->antiReplay;
    }

    public function getTokenStore(): ?TokenStoreInterface
    {
        return $this->tokenStore;
    }
}
