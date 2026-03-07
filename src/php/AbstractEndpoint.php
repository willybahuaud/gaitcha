<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Classe abstraite pour l'endpoint Ajax /captcha/init.
 *
 * L'hôte doit étendre cette classe et implémenter le routing
 * et la réponse HTTP selon son framework.
 */
abstract class AbstractEndpoint
{
    protected Config $config;
    private TokenGenerator $tokenGenerator;

    /**
     * @param Config $config Configuration Gaitcha.
     */
    public function __construct(Config $config)
    {
        $this->config         = $config;
        $this->tokenGenerator = new TokenGenerator($config);
    }

    /**
     * Génère les données pour initialiser le captcha côté client.
     *
     * @return array{field_name: string, token: string, ttl: int, token_field_name: string}
     */
    protected function handleInit(): array
    {
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
