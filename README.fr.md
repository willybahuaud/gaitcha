# Gaitcha

[English](README.md) · Français

Gaitcha est un captcha comportemental auto-hébergé pour PHP et JavaScript. Le visiteur coche une case ; ton serveur évalue les interactions à la souris, au clavier ou au tactile autour de celle-ci. Aucune grille d'images à résoudre, aucun compte à créer auprès d'un fournisseur de captcha.

La bibliothèque PHP gère les jetons et le score comportemental. Tu la branches sur tes formulaires et tu peux activer une preuve de travail pour ajouter un coût de calcul aux demandes de jetons. Les données d'interaction sont envoyées à ton serveur, sans passer par un fournisseur de captcha.

[Site](https://gaitcha.com/fr/) · [Démo](https://gaitcha.com/fr/#try-it) · [Guide PHP](https://gaitcha.com/fr/docs/) · [Extension WordPress](https://gaitcha.com/fr/wordpress/)

## Installation

La bibliothèque PHP demande PHP 7.4 ou plus récent. Dans ton application :

```bash
composer require willybahuaud/gaitcha
```

Compile le client JavaScript depuis une copie de **ce dépôt** :

```bash
npm ci
npm run build
```

Copie `dist/gaitcha.min.js` dans le dossier public des ressources de ton application. La bibliothèque PHP et le bundle JavaScript doivent utiliser des versions compatibles.

Sur WordPress, utilise [Gaitcha for WordPress](https://github.com/willybahuaud/gaitcha-for-wp). L'extension fournit le client et les connecteurs de formulaires.

## Brancher un formulaire

Définis un secret aléatoire d'au moins 32 caractères dans la variable d'environnement `GAITCHA_SECRET` de ton application. Garde-le côté serveur et utilise la même valeur pour l'initialisation et la validation. Tu peux générer une valeur une seule fois avec :

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Les exemples suivants supposent que ton application a chargé `vendor/autoload.php` de Composer.

### Endpoint d'initialisation

Fais pointer `/captcha/init` vers un contrôleur PHP :

```php
use Gaitcha\AbstractEndpoint;
use Gaitcha\Config;

class CaptchaEndpoint extends AbstractEndpoint
{
    /** Envoie les données d'initialisation en JSON. @param array $data Réponse. @return void */
    protected function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
    }

    /** Lit la requête, avec la solution PoW quand elle est présente. @return void */
    public function handle(): void
    {
        $request = json_decode((string) file_get_contents('php://input'), true);
        $this->sendJsonResponse($this->handleInit(is_array($request) ? $request : []));
    }
}

$config = new Config(['secret' => (string) getenv('GAITCHA_SECRET')]);
(new CaptchaEndpoint($config))->handle();
```

L'endpoint doit avoir la même origine que le formulaire. Exclus-le du cache et limite la taille et la fréquence des requêtes dans ton application ou ton serveur web.

### HTML

```html
<form data-gaitcha data-gaitcha-endpoint="/captcha/init" method="POST" action="/submit">
    <label>Nom <input type="text" name="name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <button type="submit">Envoyer</button>
</form>
<script src="/assets/gaitcha.min.js" defer></script>
```

### Traitement de la soumission

Vérifie le captcha avant de traiter le formulaire :

```php
use Gaitcha\Config;
use Gaitcha\ValidationOrchestrator;

$config = new Config(['secret' => (string) getenv('GAITCHA_SECRET')]);
$result = (new ValidationOrchestrator($config))->validate($_POST);

if ($result->isAccepted()) {
    // Poursuivre avec la validation des champs et le traitement du formulaire.
} else {
    // Proposer de réessayer. $result->getReason() sert au diagnostic.
}
```

Cet exemple minimal utilise les réglages du core : **preuve de travail et anti-rejeu désactivés**. Le [guide d'intégration PHP](https://gaitcha.com/fr/docs/) propose une configuration partagée avec les deux options actives et un stockage hors du dossier public.

## Preuve de travail et configuration

Pour activer la preuve de travail, passe `pow` à `true` dans la configuration de l'endpoint. Le premier appel reçoit un challenge SHA-256 signé. Le client le résout, puis envoie sa solution pour obtenir un jeton. Le client fourni gère ces échanges.

Le calcul s'exécute dans un Web Worker, avec un repli par tranches sur le thread principal si les workers sont bloqués. Sa durée dépend de l'appareil et de la difficulté configurée. Chaque bit supplémentaire double le nombre moyen d'essais nécessaires.

Associe la preuve de travail à `anti_replay` et à un stockage des jetons. Sans stockage, un challenge résolu reste réutilisable jusqu'à son expiration.

```php
use Gaitcha\Config;
use Gaitcha\FileTokenStore;

$config = new Config([
    'secret'      => (string) getenv('GAITCHA_SECRET'),
    'pow'         => true,
    'anti_replay' => true,
    'token_store' => new FileTokenStore('/private/writable/path/gaitcha-tokens.json'),
]);
```

Remplace le chemin par un emplacement privé et accessible en écriture sur ton serveur. `FileTokenStore` vise un trafic modéré ; un autre stockage peut implémenter `TokenStoreInterface`. Avec la PoW active, l'endpoint doit transmettre le corps JSON décodé à `handleInit()`.

| Option | Valeur du core | Rôle |
|---|---|---|
| `secret` | Obligatoire | Secret serveur d'au moins 32 caractères |
| `ttl` | `120` | Durée de validité du jeton, en secondes |
| `score_threshold` | `0.5` | Score minimum accepté, entre 0 et 1 |
| `debug` | `false` | Ajouter le détail du score au résultat |
| `no_js_fallback` | `'reject'` | Rejeter un jeton absent, ou ignorer la vérification avec `'allow'` |
| `token_field_name` | `'_ct'` | Champ caché contenant le jeton |
| `field_prefix` | `'_gc_'` | Préfixe des noms de champs aléatoires |
| `pow` | `false` | Exiger un calcul avant de délivrer un jeton |
| `pow_difficulty` | `18` | Nombre de bits à zéro demandés, de 8 à 26 |
| `pow_challenge_ttl` | `90` | Durée de validité du challenge, en secondes |
| `anti_replay` | `false` | Activer les contrôles des jetons et challenges déjà utilisés |
| `token_store` | `null` | Obligatoire quand l'anti-rejeu est actif |

L'extension WordPress active la preuve de travail et l'anti-rejeu par défaut. Ses réglages diffèrent donc de ceux de la bibliothèque autonome.

## Widget et styles

Le widget apparaît d'abord sous forme d'un emplacement non interactif. Un mouvement, un toucher, un focus ou une activité clavier lance l'initialisation. La case devient interactive quand le jeton est disponible. Le client capture le journal au moment où le visiteur la coche.

Le moteur distingue les profils souris, clavier et tactile. Il utilise les changements de trajectoire, les variations de vitesse, les délais au clavier et les caractéristiques tactiles disponibles.

L'apparence se règle indépendamment du score :

- **Thème :** `light`, `dark` ou `auto` pour suivre la préférence du système
- **Style :** `default` pour le widget classique, ou `minimal` pour des bordures plus fines et aucune ombre

Le client injecte un CSS limité à `.gaitcha-widget`. Il utilise `!important` pour résister aux styles des extensions de formulaires ; il faut en tenir compte pour tes personnalisations. Aucun fichier CSS séparé n'est nécessaire.

| Attribut HTML | Rôle |
|---|---|
| `data-gaitcha` | Initialiser automatiquement le formulaire |
| `data-gaitcha-endpoint` | URL d'initialisation ; `/captcha/init` par défaut |
| `data-gaitcha-label` | Texte dans le widget |
| `data-gaitcha-container` | ID de l'élément qui doit recevoir le widget |
| `data-gaitcha-theme` | `light`, `dark` ou `auto` |
| `data-gaitcha-style` | `default` ou `minimal` |

Pour une initialisation manuelle, retire `data-gaitcha` du formulaire :

```js
const form = document.querySelector('#contact-form');
const instance = Gaitcha.init(form, '/captcha/init', {
    label: 'Vérifier avant d’envoyer',
    theme: 'auto',
    style: 'minimal',
});
```

`instance.reset()` décoche le widget, vide le journal et demande un nouveau jeton. Utilise-le après un rejet en AJAX pour permettre une nouvelle tentative. `Gaitcha.reset(form)` réinitialise aussi un formulaire déjà branché. `instance.destroy()` retire le widget et les écouteurs de l'instance.

## Données et dépannage

Les interactions transitent du navigateur vers ton serveur. Le client ne pose pas de cookies de suivi et ne crée pas d'empreinte persistante du visiteur. L'anti-rejeu utilise un état temporaire ; les logs de ton hébergeur et de ton application sont distincts. Voir le [parcours des données](https://gaitcha.com/fr/privacy/).

JavaScript est nécessaire par défaut. `no_js_fallback: 'allow'` accepte les soumissions sans jeton, y compris automatisées. Garde `'reject'` si chaque envoi doit passer la vérification.

Les motifs de rejet incluent `token_absent`, `token_invalid`, `token_expired`, `token_already_used`, `score_insufficient` et `log_malformed`. Avant de modifier le seuil, vérifie l'expiration du jeton, les champs envoyés et la sérialisation AJAX. [Guide de dépannage](https://gaitcha.com/fr/guides/troubleshooting/).

## Limites

Les données d'interaction côté client peuvent être fabriquées : une automatisation conçue pour Gaitcha peut donc passer la vérification. La preuve de travail ajoute un coût de calcul ; elle ne prouve pas que le visiteur est humain. Utilise Gaitcha avec une limitation de débit et la validation habituelle de ton application.

Avant la mise en ligne, teste tes formulaires au clavier, sur mobile et avec les technologies d'assistance. Prévois une nouvelle tentative lorsqu'un envoi est rejeté.

## Développement

```bash
composer install
npm ci
composer test
npm run build
```

Pour la démo, lance `npm run dev` dans un terminal et `npm run serve` dans un autre, puis ouvre `http://localhost:8080`.

Signale les problèmes reproductibles dans les [issues](https://github.com/willybahuaud/gaitcha/issues). Indique la version, l'intégration et le mode de saisie. Retire les données des visiteurs et les secrets de tes exemples.

## Auteur et licence

Développé par [Willy Bahuaud](https://wabeo.fr). Distribué sous [GPL-2.0-or-later](LICENSE).
