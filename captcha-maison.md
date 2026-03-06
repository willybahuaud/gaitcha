# Captcha maison — logique et fonctionnement

## Le problème de départ

Les captchas classiques (reCAPTCHA, hCaptcha) posent trois problèmes :
- **vie privée** : ils collectent des données utilisateur et dépendent de tiers
- **accessibilité** : puzzles visuels, défis cognitifs
- **friction** : expérience utilisateur dégradée

Les alternatives open-source (altcha, Cap, mCaptcha) reposent sur du **proof-of-work CPU** : le navigateur résout un puzzle SHA-256. Efficace contre le spam de masse, mais un bot Playwright passe sans problème puisqu'il exécute du vrai JS dans un vrai Chromium.

mosparo analyse le contenu des champs mais est inutile sur un formulaire minimaliste (juste un email, par exemple).

---

## L'idée

Construire un captcha **invisible, sans dépendance externe, self-hosted**, qui combine :

1. **Preuve de travail comportementale** — l'utilisateur a-t-il vraiment interagi avec la page ?
2. **Champ piège à name aléatoire** — impossible à deviner sans charger la page
3. **Token signé HMAC** — le serveur vérifie l'authenticité sans stocker d'état

---

## Composants

### 1. Log comportemental

Un script JS écoute les events utilisateur et les enregistre dans un tableau :

```json
[
  { "type": "mousemove", "t": 1234, "x": 340, "y": 210 },
  { "type": "scroll",    "t": 2100, "y": 320 },
  { "type": "click",     "t": 2800, "x": 145, "y": 88, "offset": { "x": -3, "y": 2 } }
]
```

Ce log est sérialisé et injecté dans un **input hidden** au moment du submit.

Le serveur analyse ce log et calcule un score :
- au moins N interactions distinctes
- intervalle de temps cohérent (ni trop court, ni robotique)
- trajectoires souris non-linéaires
- offset du clic par rapport au centre exact de l'élément (un humain ne clique jamais au pixel près)

**La précision parfaite est le signe d'un robot. L'imperfection humaine est la preuve d'un humain.**

---

### 2. Checkbox à name aléatoire

Une checkbox `required` dont le `name` est inconnu à l'avance :

```html
<input type="checkbox" name="xk92mz" required>
```

- Le name est généré côté serveur à chaque session
- Il est **chargé en Ajax**, uniquement après un premier signal d'interaction humaine (`mousemove`, `touchstart`, `focus`)
- Un bot qui poste directement sans navigateur ne connaît pas le name → rejet
- Un bot générique qui scanne les champs `required` trouve la checkbox, mais la coche mécaniquement (clic au pixel parfait, sans trajectoire) → détecté par le score comportemental
- Un crawler qui n'exécute pas le JS ne voit jamais le champ → rejet

---

### 3. Token HMAC — le cœur du système

#### Problème
Le name est aléatoire. Au submit, comment le serveur sait-il quel champ chercher dans `$_POST` ?

#### Solution
Le serveur ne stocke rien. Il encode le name dans un **token signé** envoyé avec la réponse Ajax, dans un champ caché à name fixe (`_ct`).

Structure du token :

```
xk92mz.1709123456.HMAC(secret, "xk92mz.1709123456")
```

Trois parties séparées par un point :
- `xk92mz` — le name du champ, en clair
- `1709123456` — timestamp de génération (TTL)
- `HMAC(...)` — signature avec le secret serveur

#### Au submit

```php
[$field_name, $timestamp, $signature] = explode('.', $_POST['_ct']);

// 1. Vérifier l'expiration (TTL : 5 minutes)
if (time() - $timestamp > 300) { reject('expired'); }

// 2. Vérifier la signature
$expected = hash_hmac('sha256', "$field_name.$timestamp", SECRET);
if (!hash_equals($expected, $signature)) { reject('invalid token'); }

// 3. Lire la checkbox et le log comportemental
$checkbox   = $_POST[$field_name] ?? null;
$behavior   = $_POST[$field_name . '_log'] ?? null;

// 4. Scorer le log
$score = analyze_behavior(json_decode($behavior));
if ($score < THRESHOLD) { reject('bot detected'); }
```

#### Pourquoi c'est solide

- Le name voyage **en clair** dans `_ct` — mais signé. Un bot voit le name, mais ne peut pas **forger un nouveau token** avec un name différent sans connaître le secret.
- Le serveur est **stateless** : pas de session, pas de base de données, pas de cache.
- Le TTL est embarqué dans le token : chaque chargement de page génère un token valide 5 minutes. Mémoriser le name ne sert à rien.

---

## Flux complet

```
1. Utilisateur charge la page
   → formulaire HTML classique, aucun champ captcha visible

2. Premier signal d'interaction (mousemove / touch / focus)
   → requête Ajax vers /captcha/init
   → serveur génère un name aléatoire + token HMAC
   → injection dynamique de la checkbox et du champ caché _ct dans le DOM

3. L'utilisateur interagit
   → le JS logge tous les events dans un tableau

4. L'utilisateur soumet
   → le log comportemental est injecté dans un input hidden
   → POST envoyé avec : les champs du formulaire + _ct + [name_aléatoire] + [name_aléatoire]_log

5. Validation serveur
   → décode _ct → récupère le field_name → vérifie signature + TTL
   → lit $_POST[$field_name] et $_POST[$field_name . '_log']
   → analyse le log comportemental → score
   → accepte ou rejette
```

---

## Ce que ça bloque

| Menace | Résultat |
|---|---|
| Soumission directe sans navigateur | ❌ — name inconnu, _ct non forgeable |
| Bot JS basique sans interactions | ❌ — log vide, score insuffisant |
| Bot générique qui coche tous les `required` | ❌ — clic parfait au centre détecté |
| Crawler sans exécution JS | ❌ — Ajax jamais déclenché |
| Playwright non configuré | ❌ — pas de trajectoire, clic mécanique |
| Playwright configuré avec simulation comportementale | ✅ passe — mais effort ciblé, hors scope du spam générique |

---

## Ce que ça n'est pas

- Pas infaillible contre un attaquant **très motivé** et ciblé
- Pas de remplacement pour une vraie protection sur une cible à haute valeur
- Pas compatible **no-JS** (comme tous les captchas modernes sans exception)

Pour les utilisateurs sans JS : fallback possible avec une question arithmétique simple générée côté serveur.

---

## Avantages

- **Zéro dépendance externe**
- **Self-hosted**
- **Stateless** côté serveur
- **Invisible** pour l'utilisateur
- **RGPD friendly** — aucune donnée envoyée à un tiers
- **Accessible** — aucune interaction supplémentaire demandée
- **Léger** — quelques Ko de JS, un endpoint PHP
