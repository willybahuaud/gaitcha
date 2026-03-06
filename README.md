# Gaitcha

Captcha invisible, self-hosted, sans dépendance externe. Combine analyse comportementale, champ à nom aléatoire et token HMAC signé pour bloquer les bots sans friction pour les humains.

## Pourquoi

Les captchas classiques (reCAPTCHA, hCaptcha) collectent des données utilisateur et dépendent de tiers. Les alternatives proof-of-work (altcha, mCaptcha) se font contourner par Playwright. Gaitcha analyse **comment** l'utilisateur interagit avec une checkbox visible — trajectoire, timing, offset du clic — pour distinguer un humain d'un bot.

## Comment ça marche

1. Le formulaire se charge normalement, sans champ captcha
2. Au premier signal d'interaction (mousemove, touch, focus), une requête Ajax récupère un **token signé HMAC** et un **nom de champ aléatoire**
3. Une checkbox visible est injectée dynamiquement dans le formulaire
4. Le JS collecte les événements d'interaction (mouvements souris, tabs clavier, timing)
5. Au submit, le log comportemental est envoyé avec le token
6. Le serveur vérifie le token (signature + TTL) et **score le comportement** : trajectoire non-linéaire, offset du clic, variation de vitesse, timing des tabs…

Un bot clique au pixel parfait, instantanément, sans trajectoire. Un humain hésite, dévie, clique un peu à côté du centre.

## Installation

```bash
composer require willybahuaud/gaitcha
```

## Usage rapide

### PHP — Endpoint init

```php
use Gaitcha\Config;
use Gaitcha\AbstractEndpoint;

$config = new Config([
    'secret' => 'votre-cle-secrete',
]);

// Implémenter l'endpoint Ajax dans votre framework.
class CaptchaEndpoint extends AbstractEndpoint
{
    protected function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function handle(): void
    {
        $this->sendJsonResponse($this->handleInit());
    }
}

$endpoint = new CaptchaEndpoint($config);
$endpoint->handle();
```

### PHP — Validation au submit

```php
use Gaitcha\Config;
use Gaitcha\ValidationOrchestrator;

$config       = new Config(['secret' => 'votre-cle-secrete']);
$orchestrator = new ValidationOrchestrator($config);
$result       = $orchestrator->validate($_POST);

if ($result->isAccepted()) {
    // Traiter le formulaire.
} else {
    // Rejet : $result->getReason()
    // token_absent | token_invalid | token_expired
    // token_already_used | score_insufficient | log_malformed
}
```

### HTML — Formulaire

```html
<form data-gaitcha data-gaitcha-endpoint="/captcha/init" method="POST" action="/submit">
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    <button type="submit">Envoyer</button>
</form>

<script src="gaitcha.min.js"></script>
```

### JS — Init manuelle

```js
Gaitcha.init(document.querySelector('#my-form'), '/captcha/init', {
    label: 'Vérification humaine',
});
```

## Configuration

| Option | Type | Défaut | Description |
|---|---|---|---|
| `secret` | string | **requis** | Clé secrète HMAC |
| `ttl` | int | `120` | Durée de validité du token (secondes) |
| `score_threshold` | float | `0.5` | Seuil minimum du score comportemental (0.0–1.0) |
| `debug` | bool | `false` | Mode debug (détails de rejet dans la réponse) |
| `no_js_fallback` | string | `'reject'` | `'reject'` ou `'allow'` sans JavaScript |
| `token_field_name` | string | `'_ct'` | Nom du champ hidden du token |
| `field_prefix` | string | `'_gc_'` | Préfixe des noms de champs générés |
| `anti_replay` | bool | `false` | Anti-replay (nécessite un `token_store`) |
| `token_store` | TokenStoreInterface | `null` | Store pour l'anti-replay |

### Anti-replay

```php
use Gaitcha\Config;
use Gaitcha\FileTokenStore;

$config = new Config([
    'secret'      => 'votre-cle-secrete',
    'anti_replay' => true,
    'token_store'  => new FileTokenStore('/tmp/gaitcha-tokens.json'),
]);
```

## Attributs HTML

| Attribut | Description |
|---|---|
| `data-gaitcha` | Active Gaitcha sur le formulaire |
| `data-gaitcha-endpoint` | URL de l'endpoint init (défaut : `/captcha/init`) |
| `data-gaitcha-label` | Label de la checkbox (défaut : "Je ne suis pas un robot") |

## Développement

```bash
composer install
npm install

# Tests PHP
composer test

# Build JS
npm run build

# Dev (watch + serveur PHP)
npm run dev &
npm run serve
# → http://localhost:8080
```

## Scoring

Trois profils détectés automatiquement :

**Souris** — trajectoire existe (0.30), non-linéarité (0.25), offset du clic (0.25), variation de vitesse (0.20)

**Clavier** — séquence de tabs (0.35), variance timing (0.30), cohérence navigation (0.20), délai focus→check (0.15)

**Touch** — similaire au profil souris, seuils adaptés au tactile

Kill signals (score = 0 immédiat) : `dt` < 100ms, aucun mouvement avant clic, clic exactement au centre, aucun tab avant check clavier.

## Limites

- Pas infaillible contre un attaquant ciblé avec Playwright + simulation comportementale
- Nécessite JavaScript (fallback configurable pour les utilisateurs sans JS)
- Conçu pour le spam de masse, pas pour la protection de cibles haute valeur

## Licence

MIT
