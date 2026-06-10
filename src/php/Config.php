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

    /** @var bool Exige une preuve d'effort (PoW) avant d'émettre un token. */
    private bool $pow;

    /** @var int Difficulté PoW en bits de zéros attendus (8 à 26). */
    private int $powDifficulty;

    /** @var int Durée de validité d'un challenge PoW en secondes. */
    private int $powChallengeTtl;

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
     *     @type bool   $pow              Exiger une preuve d'effort avant l'init (défaut : false).
     *     @type int    $pow_difficulty   Difficulté PoW en bits (défaut : 18, bornes 8–26).
     *     @type int    $pow_challenge_ttl Durée de validité d'un challenge en secondes (défaut : 90).
     * }
     */
    public function __construct(array $options)
    {
        if (!isset($options['secret']) || !is_string($options['secret']) || strlen($options['secret']) < 32) {
            throw new \InvalidArgumentException('Gaitcha: secret must be a string of at least 32 characters.');
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
        $this->pow             = (bool) ($options['pow'] ?? false);
        $this->powDifficulty   = (int) ($options['pow_difficulty'] ?? 18);
        $this->powChallengeTtl = (int) ($options['pow_challenge_ttl'] ?? 90);

        if ($this->ttl < 1) {
            throw new \InvalidArgumentException('Gaitcha: ttl must be a positive integer.');
        }

        if ($this->scoreThreshold < 0.0 || $this->scoreThreshold > 1.0) {
            throw new \InvalidArgumentException('Gaitcha: score_threshold must be between 0.0 and 1.0.');
        }

        if (!in_array($this->noJsFallback, ['reject', 'allow'], true)) {
            throw new \InvalidArgumentException('Gaitcha: no_js_fallback must be "reject" or "allow".');
        }

        if (strpos($this->fieldPrefix, '.') !== false) {
            throw new \InvalidArgumentException('Gaitcha: field_prefix must not contain dots.');
        }

        if (strpos($this->tokenFieldName, '.') !== false) {
            throw new \InvalidArgumentException('Gaitcha: token_field_name must not contain dots.');
        }

        if ($this->antiReplay && $this->tokenStore === null) {
            throw new \InvalidArgumentException('Gaitcha: token_store is required when anti_replay is enabled.');
        }

        if ($this->powDifficulty < 8 || $this->powDifficulty > 26) {
            throw new \InvalidArgumentException('Gaitcha: pow_difficulty must be between 8 and 26.');
        }

        if ($this->powChallengeTtl < 10) {
            throw new \InvalidArgumentException('Gaitcha: pow_challenge_ttl must be at least 10 seconds.');
        }
    }

    /**
     * @return string Clé secrète HMAC.
     */
    public function getSecret(): string
    {
        return $this->secret;
    }

    /**
     * @return int Durée de validité du token en secondes.
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * @return float Seuil minimum du score comportemental (0.0 à 1.0).
     */
    public function getScoreThreshold(): float
    {
        return $this->scoreThreshold;
    }

    /**
     * @return bool True si le mode debug est actif.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @return string 'reject' ou 'allow'.
     */
    public function getNoJsFallback(): string
    {
        return $this->noJsFallback;
    }

    /**
     * @return string Nom du champ hidden contenant le token signé.
     */
    public function getTokenFieldName(): string
    {
        return $this->tokenFieldName;
    }

    /**
     * @return string Préfixe des noms de champs générés.
     */
    public function getFieldPrefix(): string
    {
        return $this->fieldPrefix;
    }

    /**
     * @return bool True si la protection anti-replay est active.
     */
    public function isAntiReplay(): bool
    {
        return $this->antiReplay;
    }

    /**
     * @return TokenStoreInterface|null Store pour l'anti-replay, null si désactivé.
     */
    public function getTokenStore(): ?TokenStoreInterface
    {
        return $this->tokenStore;
    }

    /**
     * @return bool True si la preuve d'effort est exigée avant l'init.
     */
    public function isPow(): bool
    {
        return $this->pow;
    }

    /**
     * @return int Difficulté PoW en bits de zéros attendus.
     */
    public function getPowDifficulty(): int
    {
        return $this->powDifficulty;
    }

    /**
     * @return int Durée de validité d'un challenge PoW en secondes.
     */
    public function getPowChallengeTtl(): int
    {
        return $this->powChallengeTtl;
    }
}
