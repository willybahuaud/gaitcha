<?php

/**
 * Serveur de dev Gaitcha.
 *
 * Usage : php -S localhost:8080 -t dev dev/server.php
 *
 * Routes :
 *   GET  /            → dev/index.html
 *   POST /captcha/init → génère un token + field name
 *   POST /submit       → valide la soumission et affiche le résultat
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Gaitcha\Config;
use Gaitcha\AbstractEndpoint;
use Gaitcha\ValidationOrchestrator;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Servir les fichiers statiques.
if ($uri === '/gaitcha.min.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/../dist/gaitcha.min.js');
    return;
}

// Page d'accueil.
if ($uri === '/' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=UTF-8');
    readfile(__DIR__ . '/index.html');
    return;
}

$config = new Config([
    'secret' => 'dev-secret-key-change-me-before-production!',
    'ttl'    => 120,
    'debug'  => true,
]);

// Endpoint init.
if ($uri === '/captcha/init' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $endpoint = new class($config) extends AbstractEndpoint {
        /**
         * {@inheritdoc}
         */
        protected function sendJsonResponse(array $data): void
        {
            header('Content-Type: application/json');
            echo json_encode($data);
        }

        /**
         * Traite la requête init.
         */
        public function handle(): void
        {
            $data = $this->handleInit();
            $this->sendJsonResponse($data);
        }
    };

    $endpoint->handle();
    return;
}

// Endpoint submit (validation).
if ($uri === '/submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $orchestrator = new ValidationOrchestrator($config);
    $result       = $orchestrator->validate($_POST);

    header('Content-Type: application/json');
    echo json_encode($result->toArray($config->isDebug()), JSON_PRETTY_PRINT);
    return;
}

// 404.
http_response_code(404);
echo '404 Not Found';
