# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Gaitcha est un captcha self-hosted, sans dépendance externe. Il combine :
- **Analyse comportementale** (trajectoire souris, timing tabs, offset clic) pour scorer l'humanité de l'utilisateur
- **Checkbox visible à name aléatoire** chargée en Ajax après un premier signal d'interaction
- **Placeholder injecté au chargement** : mêmes dimensions que le widget final (zéro layout shift), non interactif, n'expose ni name ni token
- **Preuve d'effort (PoW) opt-in** : challenge HMAC signé à résoudre (Web Worker) avant que le serveur n'émette un token — rend coûteux le harvesting massif
- **Token HMAC signé** (name + timestamp + signature) pour une validation stateless
- **Anti-replay optionnel** : stockage des tokens soumis pendant le TTL (consomme aussi les nonces PoW)

Le core est framework-agnostic. L'intégration WordPress et les adaptateurs (CF7, Gravity, WS Form) sont hors scope v1.

## Commandes

```bash
# Tests PHP
composer test

# Build JS (esbuild → dist/gaitcha.min.js)
npm run build

# Dev JS (watch mode)
npm run dev

# Serveur de dev (PHP built-in)
npm run serve
# → http://localhost:8080

# Un seul test
vendor/bin/phpunit tests/php/BehavioralScorerTest.php
vendor/bin/phpunit --filter testHumanMouseInteractionScoresHigh
```

## Architecture

### PHP Core — `src/php/` (namespace `Gaitcha\`)

- **Config** — configuration avec defaults (secret, ttl 120s, seuil 0.5, debug, fallback no-JS, anti-replay)
- **TokenGenerator** — field name aléatoire préfixé `_gc_` + token HMAC `fieldName.timestamp.signature`
- **TokenValidator** — vérifie signature (`hash_equals`) + TTL
- **BehavioralLogParser** — parse et valide le log JSON client
- **BehavioralScorer** — 3 profils (mouse 10 signaux, keyboard 9 signaux, touch 7 signaux) avec multi-profil, kill signals et score 0.0–1.0
- **ValidationOrchestrator** — pipeline complet : token → anti-replay → parse log → scoring → résultat
- **ValidationResult** — value object immutable (status, reason, score, debug)
- **TokenStoreInterface / FileTokenStore** — anti-replay opt-in avec `checkAndAdd()` atomique
- **PoWChallengeGenerator** — challenge PoW stateless (nonce + difficulté + expiration, signés HMAC)
- **PoWVerifier** — vérifie forme → signature → expiration → difficulté → replay du nonce
- **AbstractEndpoint** — endpoint Ajax init en deux phases : sans preuve valide → `{ pow_challenge }`, avec preuve → payload init (exige le body JSON décodé quand `pow` est actif)

### JS Core — `src/js/` (bundle → `dist/gaitcha.min.js`)

- **gaitcha.js** — entry point, auto-init `form[data-gaitcha]`, expose `Gaitcha.init()`
- **GaitchaForm** — orchestre tout pour un formulaire (une instance par form)
- **InteractionDetector** — premier signal (mousemove, touchstart, focus, keydown)
- **EventLogger** — buffer circulaire 30 moves, throttle 50ms, dwell time keydown/keyup, coalesced events count, screenDx/screenDy, freeze au check
- **PoWSolver** — résout les challenges PoW : SHA-256 inline dans un Web Worker créé via Blob (fonctions auto-contenues sérialisées par toString()), fallback main thread par tranches si CSP bloque les workers
- **AjaxFetcher** — fetch token en deux phases (résout le pow_challenge si renvoyé, max 3 tentatives) + auto-refresh à 75% du TTL (re-résout une PoW à chaque refresh)
- **DOMInjector** — placeholder pending au chargement, puis upgrade en place : checkbox visible + label + hidden fields (_ct, _log) dans le widget. Themes light/dark/auto x styles default/minimal via CSS custom properties
- **LogSerializer** — sérialise le payload au submit

### Demo — `demo/`

- **server.php** — router PHP avec `/captcha/init` et `/submit`
- **index.html** — page test avec 2 formulaires

### Tests — `tests/php/`

PHPUnit 9. Couvre Config, TokenGenerator, TokenValidator, BehavioralLogParser, BehavioralScorer, ValidationOrchestrator, FileTokenStore.

## Scoring comportemental

Scoring multi-profil : le profil primaire est determiné par le type de check event,
mais un profil secondaire est scoré si des données existent. Score final = max des deux.

**Profil mouse (10 signaux)** : trajectory (0.05), non_linearity (0.05), offset (0.10), speed_variation (0.05), angular_jitter (0.05), direction_reversals (0.15), endpoint_deceleration (0.15), speed_autocorrelation (0.15), cdp_screen_delta (0.10), coalesced_ratio (0.05)

**Profil keyboard (9 signaux)** : tab_sequence (0.05), timing_variance (0.05), coherence (0.05), focus_to_check (0.10), dwell_variance (0.20), rollover_rate (0.20), timing_entropy (0.10), correction_bonus (0.10), timing_autocorrelation (0.15)

**Profil touch (7 signaux)** : similaire mouse sans les signaux anti-CDP, seuils adaptés

**Kill signals** (score = 0) : dt < 100ms, 0 moves avant clic, offset (0,0) exact, aucun tab avant check clavier.

## Flux

1. Page chargée → placeholder injecté (état pending, non interactif, rien à scraper)
2. Premier signal d'interaction → collecte d'events démarrée + Ajax `/captcha/init`
3. Si `pow` actif : réponse `{ pow_challenge }` → résolution en Web Worker → nouvel appel init avec `{ pow: { ...challenge, solution } }`
4. Réponse init → upgrade du placeholder en place : checkbox + `_ct` + `_log`
5. Events collectés dans buffer circulaire, gelés au check de la checkbox
6. Submit → log sérialisé → POST : champs form + `_ct` + `[name]` + `[name]_log`
7. Serveur : token → anti-replay → parse log → score → accept/reject

## Conventions de code

- PHP : PSR-4, conventions WordPress, PHPDoc sur toutes les fonctions
- JS : ESM (bundlé en IIFE), JSDoc sur toutes les fonctions, pas de fonctions anonymes
- Nommage explicite, fichiers courts, une responsabilité par fichier
- Commenter le pourquoi, pas le quoi

