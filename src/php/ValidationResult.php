<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Résultat de validation Gaitcha.
 *
 * Value object immutable retourné par le ValidationOrchestrator.
 */
class ValidationResult
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public const REASON_TOKEN_ABSENT       = 'token_absent';
    public const REASON_TOKEN_INVALID      = 'token_invalid';
    public const REASON_TOKEN_EXPIRED      = 'token_expired';
    public const REASON_TOKEN_ALREADY_USED = 'token_already_used';
    public const REASON_SCORE_INSUFFICIENT = 'score_insufficient';
    public const REASON_LOG_MALFORMED      = 'log_malformed';

    /** @var string 'accepted' ou 'rejected'. */
    private string $status;

    /** @var string|null Motif de rejet (null si accepted). */
    private ?string $reason;

    /** @var float Score comportemental (0.0 à 1.0). */
    private float $score;

    /** @var array<string, mixed> Détails debug (vide en production). */
    private array $debug;

    /**
     * @param string              $status 'accepted' ou 'rejected'.
     * @param string|null         $reason Motif de rejet.
     * @param float               $score  Score comportemental.
     * @param array<string, mixed> $debug  Détails debug.
     */
    public function __construct(string $status, ?string $reason = null, float $score = 0.0, array $debug = [])
    {
        $this->status = $status;
        $this->reason = $reason;
        $this->score  = $score;
        $this->debug  = $debug;
    }

    /**
     * Crée un résultat "accepted".
     *
     * @param float               $score Score comportemental.
     * @param array<string, mixed> $debug Détails debug.
     * @return self
     */
    public static function accepted(float $score, array $debug = []): self
    {
        return new self(self::STATUS_ACCEPTED, null, $score, $debug);
    }

    /**
     * Crée un résultat "rejected".
     *
     * @param string              $reason Motif de rejet.
     * @param float               $score  Score comportemental.
     * @param array<string, mixed> $debug  Détails debug.
     * @return self
     */
    public static function rejected(string $reason, float $score = 0.0, array $debug = []): self
    {
        return new self(self::STATUS_REJECTED, $reason, $score, $debug);
    }

    /**
     * @return bool True si la soumission est acceptée.
     */
    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    /**
     * @return string 'accepted' ou 'rejected'.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return string|null Motif de rejet, null si accepted.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return float Score comportemental (0.0 à 1.0).
     */
    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDebug(): array
    {
        return $this->debug;
    }

    /**
     * Sérialise le résultat en tableau.
     *
     * @param bool $includeDebug Inclure les données debug.
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDebug = false): array
    {
        $result = [
            'status' => $this->status,
            'reason' => $this->reason,
            'score'  => $this->score,
        ];

        if ($includeDebug) {
            $result['debug'] = $this->debug;
        }

        return $result;
    }
}
