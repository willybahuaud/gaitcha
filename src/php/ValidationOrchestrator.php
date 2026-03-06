<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Orchestre la validation complète d'une soumission Gaitcha.
 *
 * Séquence : token présent → signature + TTL → anti-replay → parse log → scoring → résultat.
 */
class ValidationOrchestrator
{
    private Config $config;
    private TokenValidator $tokenValidator;
    private BehavioralLogParser $logParser;
    private BehavioralScorer $scorer;

    public function __construct(Config $config)
    {
        $this->config         = $config;
        $this->tokenValidator = new TokenValidator($config);
        $this->logParser      = new BehavioralLogParser();
        $this->scorer         = new BehavioralScorer();
    }

    /**
     * Valide une soumission de formulaire.
     *
     * @param array<string, mixed> $post    Données POST brutes ($_POST).
     * @param int|null             $currentTime Timestamp courant (pour les tests).
     * @return ValidationResult
     */
    public function validate(array $post, ?int $currentTime = null): ValidationResult
    {
        $tokenFieldName = $this->config->getTokenFieldName();
        $debug          = [];

        // 1. Vérifier la présence du token.
        if (empty($post[$tokenFieldName])) {
            // Pas de token = pas de JS ou soumission directe.
            if ($this->config->getNoJsFallback() === 'allow') {
                return ValidationResult::accepted(0.0, ['fallback' => 'no_js_allowed']);
            }
            return $this->reject(ValidationResult::REASON_TOKEN_ABSENT, 0.0, $debug);
        }

        // 2. Valider le token (signature + TTL).
        $tokenResult = $this->tokenValidator->validate($post[$tokenFieldName], $currentTime);

        if (!$tokenResult['valid']) {
            return $this->reject(
                $tokenResult['result']->getReason(),
                0.0,
                $debug
            );
        }

        $fieldName = $tokenResult['field_name'];

        // 3. Anti-replay optionnel.
        if ($this->config->isAntiReplay()) {
            $store     = $this->config->getTokenStore();
            $tokenHash = hash('sha256', $post[$tokenFieldName]);

            $store->purge($this->config->getTtl(), $currentTime);

            if ($store->has($tokenHash)) {
                return $this->reject(ValidationResult::REASON_TOKEN_ALREADY_USED, 0.0, $debug);
            }

            $store->add($tokenHash, $currentTime ?? time());
        }

        // 4. Lire et parser le log comportemental.
        $logFieldName = $fieldName . '_log';
        $logRaw       = $post[$logFieldName] ?? '';

        if (empty($logRaw)) {
            return $this->reject(ValidationResult::REASON_LOG_MALFORMED, 0.0, $debug);
        }

        $parseResult = $this->logParser->parse($logRaw);

        if (!$parseResult['valid']) {
            $debug['parse_error'] = $parseResult['reason'];
            return $this->reject(ValidationResult::REASON_LOG_MALFORMED, 0.0, $debug);
        }

        // 5. Scorer le log.
        $scoreResult   = $this->scorer->score($parseResult['data']);
        $score         = $scoreResult['score'];
        $debug['scoring'] = $scoreResult;

        // 6. Comparer au seuil.
        if ($score < $this->config->getScoreThreshold()) {
            return $this->reject(ValidationResult::REASON_SCORE_INSUFFICIENT, $score, $debug);
        }

        return $this->accept($score, $debug);
    }

    /**
     * Crée un résultat accepted (avec ou sans debug).
     *
     * @param float $score Score comportemental.
     * @param array $debug Détails debug.
     * @return ValidationResult
     */
    private function accept(float $score, array $debug): ValidationResult
    {
        return ValidationResult::accepted(
            $score,
            $this->config->isDebug() ? $debug : []
        );
    }

    /**
     * Crée un résultat rejected (avec ou sans debug).
     *
     * @param string $reason Motif de rejet.
     * @param float  $score  Score comportemental.
     * @param array  $debug  Détails debug.
     * @return ValidationResult
     */
    private function reject(string $reason, float $score, array $debug): ValidationResult
    {
        return ValidationResult::rejected(
            $reason,
            $score,
            $this->config->isDebug() ? $debug : []
        );
    }
}
