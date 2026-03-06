# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projet

Gaitcha est un captcha invisible, self-hosted, sans dépendance externe. Il combine :
- **Analyse comportementale** (mouvements souris, scroll, clics, clavier) pour scorer l'humanité de l'utilisateur
- **Champ piège à name aléatoire** chargé en Ajax après un premier signal d'interaction
- **Token HMAC signé** (name + timestamp + signature) pour une validation stateless

Le core est framework-agnostic. L'intégration WordPress et les adaptateurs (CF7, Gravity, WS Form) sont hors scope v1.

## Architecture

### PHP Core (stateless, zéro dépendance)

- **TokenGenerator** — génère un token HMAC : `fieldName.timestamp.HMAC(secret, fieldName.timestamp)`
- **TokenValidator** — vérifie signature (`hash_equals`) + TTL (défaut 5 min)
- **BehavioralAnalyzer** — parse le log JSON d'événements côté client
- **Scorer** — calcule un score de probabilité bot (variété d'interactions, intervalles temporels, non-linéarité trajectoire souris, offset clics vs centre)
- **ValidationOrchestrator** — combine token + scoring, expose `validate(array $post): ValidationResult`
- **Config** — clé secrète, TTL, seuil score, fallback no-JS

Interface publique :
```php
validate(array $post): ValidationResult {
    status: 'accepted' | 'rejected',
    reason: 'token_absent' | 'token_invalid' | 'token_expired' | 'score_insufficient' | 'log_malformed',
    score: float,
    debug: array // uniquement en mode debug
}
```

### JS Core (vanilla, standalone)

- **InteractionDetector** — attend le premier signal (mousemove, touch, focus, keyboard)
- **EventLogger** — enregistre les events avec timestamps, positions, offsets
- **AjaxFetcher** — requête `/captcha/init` après premier signal → récupère name + token
- **DOMInjector** — injecte checkbox required + champ `_ct` dans le formulaire
- **LogSerializer** — sérialise le log en JSON dans un hidden field au submit

Point d'entrée : `<script src="captcha.min.js" data-endpoint="/api/captcha/init"></script>`

### Flux

1. Page chargée → formulaire nu, aucun champ captcha
2. Premier signal d'interaction → Ajax `/captcha/init` → injection checkbox + `_ct`
3. Interactions loggées en continu
4. Submit → log injecté → POST contient : champs formulaire + `_ct` + `[name]` + `[name]_log`
5. Serveur : décode `_ct` → vérifie signature + TTL → lit checkbox + log → score → accept/reject

## Contraintes clés

- **Stateless** : pas de session, pas de DB, pas de cache côté serveur
- **Sécurité** : clé secrète jamais exposée client, `hash_equals` pour timing attacks, HMAC-SHA256
- **RGPD** : aucune donnée vers tiers, aucun cookie, log comportemental non persisté après validation
- **Accessibilité** : invisible, zéro friction, checkbox avec label, navigable clavier, compatible lecteur d'écran
- **PHP sans dépendance externe, JS vanilla sans framework**

## Conventions de code

- PHP : respecter les conventions WordPress, PHPDoc sur toutes les fonctions
- JS : JSDoc sur toutes les fonctions, pas de fonctions anonymes
- Nommage explicite (pas de `$data`, `$temp`, `$x`)
- Fichiers courts, une responsabilité par fichier
- Commenter le pourquoi, pas le quoi

## Specs de référence

- `captcha-specs.md` — spécifications fonctionnelles (F1-F8), contraintes, edge cases, todo
- `captcha-maison.md` — logique détaillée, modèle de menace, flux complet
