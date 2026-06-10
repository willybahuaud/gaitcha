<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Classe abstraite pour l'endpoint Ajax /captcha/init.
 *
 * L'hôte doit étendre cette classe et implémenter le routing
 * et la réponse HTTP selon son framework.
 *
 * Avec la preuve d'effort (pow) activée, l'endpoint fonctionne en
 * deux phases sur la même route :
 *   1. Requête sans preuve valide → réponse { pow_challenge: {...} }
 *   2. Requête avec { pow: { ...challenge, solution } } valide → init normal
 */
abstract class AbstractEndpoint
{
    protected Config $config;
    private TokenGenerator $tokenGenerator;
    private PoWChallengeGenerator $challengeGenerator;
    private PoWVerifier $powVerifier;

    /**
     * @param Config $config Configuration Gaitcha.
     */
    public function __construct(Config $config)
    {
        $this->config             = $config;
        $this->tokenGenerator     = new TokenGenerator($config);
        $this->challengeGenerator = new PoWChallengeGenerator($config);
        $this->powVerifier        = new PoWVerifier($config);
    }

    /**
     * Génère les données pour initialiser le captcha côté client.
     *
     * Si la preuve d'effort est activée, le corps de la requête (JSON décodé)
     * doit être transmis. Sans preuve valide, un challenge est renvoyé à la
     * place du token — le client le résout puis rappelle l'endpoint.
     *
     * @param array<string, mixed>|null $request Corps JSON décodé de la requête (requis si pow activée).
     * @return array Payload init complet, ou { pow_challenge: {...} } si la preuve manque ou est invalide.
     * @throws \LogicException Si pow est activée et que le corps de requête n'est pas transmis.
     */
    protected function handleInit(?array $request = null): array
    {
        if ($this->config->isPow()) {
            if ($request === null) {
                throw new \LogicException(
                    'Gaitcha: handleInit() requires the decoded JSON request body when pow is enabled.'
                );
            }

            $verification = $this->powVerifier->verify($request['pow'] ?? null);

            if (!$verification['valid']) {
                $response = ['pow_challenge' => $this->challengeGenerator->generate()];

                // Indiquer pourquoi la preuve a échoué seulement si une
                // tentative a été soumise (pas au premier appel à vide).
                if ($verification['reason'] !== PoWVerifier::REASON_MISSING) {
                    $response['pow_error'] = $verification['reason'];
                }

                return $response;
            }
        }

        $generated = $this->tokenGenerator->generate();

        return [
            'field_name'       => $generated['field_name'],
            'token'            => $generated['token'],
            'ttl'              => $this->config->getTtl(),
            'token_field_name' => $this->config->getTokenFieldName(),
        ];
    }

    /**
     * Envoie la réponse JSON au client.
     *
     * À implémenter par l'hôte selon son framework.
     *
     * @param array $data Données à envoyer.
     */
    abstract protected function sendJsonResponse(array $data): void;
}
