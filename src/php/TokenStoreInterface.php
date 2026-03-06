<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Interface pour le stockage anti-replay des tokens.
 *
 * Permet de vérifier qu'un token n'a pas déjà été soumis.
 * L'hôte peut fournir sa propre implémentation (Redis, DB, transient WP…).
 */
interface TokenStoreInterface
{
    /**
     * Vérifie si un hash de token a déjà été soumis.
     *
     * @param string $hash Hash du token.
     * @return bool True si le token a déjà été utilisé.
     */
    public function has(string $hash): bool;

    /**
     * Enregistre un hash de token comme utilisé.
     *
     * @param string $hash      Hash du token.
     * @param int    $timestamp Timestamp de la soumission.
     */
    public function add(string $hash, int $timestamp): void;

    /**
     * Purge les entrées plus vieilles que le TTL.
     *
     * @param int      $ttl        Durée de vie en secondes.
     * @param int|null $currentTime Timestamp courant (défaut : time()).
     */
    public function purge(int $ttl, ?int $currentTime = null): void;
}
