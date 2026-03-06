# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Gaitcha est un captcha self-hosted, sans dépendance externe. Il combine :
- **Analyse comportementale** (trajectoire souris, timing tabs, offset clic) pour scorer l'humanité de l'utilisateur
- **Checkbox visible à name aléatoire** chargée en Ajax après un premier signal d'interaction
- **Token HMAC signé** (name + timestamp + signature) pour une validation stateless
- **Anti-replay optionnel** : stockage des tokens soumis pendant le TTL

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
- **BehavioralScorer** — 3 profils (mouse, keyboard, touch) avec poids fixes et kill signals, score 0.0–1.0
- **ValidationOrchestrator** — pipeline complet : token → anti-replay → parse log → scoring → résultat
- **ValidationResult** — value object immutable (status, reason, score, debug)
- **TokenStoreInterface / FileTokenStore** — anti-replay opt-in
- **AbstractEndpoint** — classe abstraite pour l'endpoint Ajax init

### JS Core — `src/js/` (bundle → `dist/gaitcha.min.js`)

- **gaitcha.js** — entry point, auto-init `form[data-gaitcha]`, expose `Gaitcha.init()`
- **GaitchaForm** — orchestre tout pour un formulaire (une instance par form)
- **InteractionDetector** — premier signal (mousemove, touchstart, focus, keydown)
- **EventLogger** — buffer circulaire 30 moves, throttle 50ms, freeze au check
- **AjaxFetcher** — fetch token + auto-refresh à 75% du TTL
- **DOMInjector** — injecte checkbox visible + label + hidden fields (_ct, _log)
- **LogSerializer** — sérialise le payload au submit

### Dev — `dev/`

- **server.php** — router PHP avec `/captcha/init` et `/submit`
- **index.html** — page test avec 2 formulaires

### Tests — `tests/php/`

PHPUnit 9. Couvre Config, TokenGenerator, TokenValidator, BehavioralLogParser, BehavioralScorer, ValidationOrchestrator, FileTokenStore.

## Scoring comportemental

**Profil mouse** : trajectoire (0.30), non-linéarité (0.25), offset clic (0.25), variation vitesse (0.20)
**Profil keyboard** : séquence tabs (0.35), variance timing (0.30), cohérence navigation (0.20), délai focus→check (0.15)
**Profil touch** : similaire mouse, seuils adaptés

**Kill signals** (score = 0) : dt < 100ms, 0 moves avant clic, offset (0,0) exact, aucun tab avant check clavier.

## Flux

1. Page chargée → formulaire nu
2. Premier signal d'interaction → Ajax `/captcha/init` → injection checkbox + `_ct` + `_log`
3. Events collectés dans buffer circulaire, gelés au check de la checkbox
4. Submit → log sérialisé → POST : champs form + `_ct` + `[name]` + `[name]_log`
5. Serveur : token → anti-replay → parse log → score → accept/reject

## Conventions de code

- PHP : PSR-4, conventions WordPress, PHPDoc sur toutes les fonctions
- JS : ESM (bundlé en IIFE), JSDoc sur toutes les fonctions, pas de fonctions anonymes
- Nommage explicite, fichiers courts, une responsabilité par fichier
- Commenter le pourquoi, pas le quoi

## Specs de référence

- `captcha-specs.md` — spécifications fonctionnelles (F1-F8), contraintes, edge cases, todo
- `captcha-maison.md` — logique détaillée, modèle de menace, flux complet
