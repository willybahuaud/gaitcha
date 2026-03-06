# Specs fonctionnelles — Captcha maison

## Périmètre

Ce document couvre uniquement le **core** : la logique de protection anti-bot, indépendante de tout environnement hôte. L'intégration WordPress et les adaptateurs pour plugins tiers (CF7, Gravity, WS Form…) sont hors scope de cette version.

---

## Fonctionnalités attendues

### F1 — Génération du token

- Le système doit pouvoir générer un token à la demande
- Le token doit encoder : un identifiant de champ aléatoire, un timestamp de génération
- Le token doit être signé avec une clé secrète via HMAC
- Le token doit être opaque pour le client — il peut lire le name en clair, mais ne peut pas forger un token valide sans la clé
- La clé secrète ne doit jamais transiter côté client

### F2 — Validation du token

- Le système doit pouvoir valider un token reçu en POST
- La validation doit vérifier : intégrité de la signature, expiration (TTL configurable)
- Un token expiré doit être rejeté
- Un token dont la signature ne correspond pas doit être rejeté
- La validation doit être stateless — aucun stockage serveur requis

### F3 — Collecte comportementale côté client

- Un script JS doit écouter les interactions utilisateur sur la page : mouvements souris, événements touch, scroll, clics, interactions clavier
- Chaque événement doit être horodaté
- Les clics doivent inclure la position et l'offset par rapport au centre de l'élément cible
- Le script ne doit se déclencher qu'après un premier signal d'interaction humaine
- Le script ne doit pas être chargé ni actif avant ce premier signal

### F4 — Chargement différé du champ (Ajax)

- Le champ protégé ne doit pas être présent dans le HTML initial de la page
- Une requête doit être émise après le premier signal d'interaction pour récupérer : le name aléatoire du champ, le token signé
- Les champs doivent être injectés dynamiquement dans le formulaire après réception
- Si la requête échoue, le comportement de fallback doit être défini (configurable : bloquer ou laisser passer)

### F5 — Analyse comportementale côté serveur

- Le système doit analyser le log d'événements reçu en POST
- L'analyse doit produire un score numérique
- Le score doit tenir compte : du nombre d'interactions distinctes, de la durée totale d'interaction, de la variété des types d'événements, de la naturalité des trajectoires (non-linéarité), de l'offset des clics par rapport au centre des éléments
- Un seuil de score minimum doit être configurable
- En dessous du seuil, la soumission doit être rejetée

### F6 — Validation globale

- La validation finale doit combiner : validation du token (F2) + score comportemental (F5)
- Elle doit exposer une interface simple : entrée = données POST brutes, sortie = succès ou échec avec motif
- Les motifs de rejet doivent être distincts et identifiables : token absent, token invalide, token expiré, score insuffisant, log absent ou malformé

### F7 — Configuration

- La clé secrète HMAC doit être configurable
- Le TTL du token doit être configurable
- Le seuil de score comportemental doit être configurable
- Le comportement en cas d'absence de JS doit être configurable (bloquer ou laisser passer)
- Les paramètres doivent avoir des valeurs par défaut raisonnables

### F8 — Logging et debug

- Le système doit pouvoir fonctionner en mode debug
- En mode debug, les motifs de rejet doivent être détaillés et loggés
- En production, les motifs de rejet ne doivent pas être exposés au client

---

## Contraintes

### Contraintes techniques

- Le core PHP ne doit avoir aucune dépendance externe
- Le core JS doit être un module standalone, sans framework ni librairie
- Le core ne doit faire aucune hypothèse sur l'environnement hôte (pas de couplage WordPress, pas de `$_SESSION`, pas de globals)
- L'endpoint Ajax est le seul point de couplage avec l'environnement hôte — il doit être abstrait

### Contraintes de sécurité

- La clé secrète ne doit jamais être exposée côté client
- Le token doit avoir une durée de vie courte (défaut suggéré : 5 minutes)
- La comparaison des signatures doit être résistante aux timing attacks (`hash_equals`)
- Le log comportemental ne doit contenir aucune donnée personnelle identifiable

### Contraintes RGPD / vie privée

- Aucune donnée ne doit être envoyée à un serveur tiers
- Le log comportemental (positions, timestamps) ne doit pas être persisté au-delà de la validation
- Aucun cookie ne doit être posé par le core

### Contraintes d'accessibilité

- Aucune interaction supplémentaire ne doit être demandée à l'utilisateur
- Le champ injecté doit être utilisable au clavier
- Le système doit fonctionner avec un lecteur d'écran (la checkbox doit avoir un label approprié)

---

## Comportements limites

### Utilisateur sans JS

- Le champ protégé n'est jamais injecté
- La soumission arrive sans token ni log
- Comportement configurable : rejet par défaut, ou fallback défini par l'hôte

### Soumission directe (sans navigateur)

- Le token est absent ou non forgeable
- Rejet systématique

### Rechargement de page / soumission tardive

- Le token est expiré
- Rejet avec motif `token_expired`

### Interactions robotiques détectées

- Token valide mais score insuffisant
- Rejet avec motif `score_insufficient`

### Réseau lent / échec Ajax

- Le champ n'est pas injecté à temps
- Comportement de fallback configurable

---

## Todo — core

### PHP

- [ ] Définir l'interface publique du validateur (`validate(array $post): ValidationResult`)
- [ ] Implémenter la génération de name aléatoire
- [ ] Implémenter la génération du token HMAC (name + timestamp + signature)
- [ ] Implémenter la validation du token (signature + TTL)
- [ ] Implémenter le parser de log comportemental
- [ ] Implémenter le scorer comportemental
- [ ] Implémenter l'orchestrateur de validation globale
- [ ] Implémenter le système de configuration avec valeurs par défaut
- [ ] Implémenter le mode debug / logging
- [ ] Définir et implémenter l'interface abstraite pour l'endpoint Ajax
- [ ] Écrire les tests unitaires : génération token, validation token, scoring
- [ ] Écrire les tests d'intégration : flux complet accept / reject

### JS

- [ ] Implémenter la détection du premier signal d'interaction
- [ ] Implémenter la collecte des events (mousemove, scroll, click, touch, keyboard)
- [ ] Implémenter le fetch Ajax de récupération du token et du name
- [ ] Implémenter l'injection dynamique des champs dans le formulaire
- [ ] Implémenter la sérialisation du log au submit
- [ ] Gérer les cas d'erreur Ajax (timeout, échec réseau)
- [ ] Vérifier le comportement avec lecteur d'écran

### Qualité

- [ ] Définir le format de `ValidationResult` (code, motif, score)
- [ ] Documenter l'interface publique PHP
- [ ] Documenter les options de configuration
- [ ] Tester sur un formulaire HTML vanilla (hors WordPress)
- [ ] Tester le comportement sans JS
- [ ] Tester avec Playwright non configuré (vérifier le rejet)
- [ ] Tester avec Playwright configuré avec simulation comportementale (documenter la limite)

---

## Hors scope (v1 core)

- Intégration WordPress
- Adaptateurs CF7, Gravity Forms, WS Form
- Interface d'administration
- Statistiques et tableau de bord
- Publication Packagist
- Mode invisible sans checkbox (proof-of-work pur)
