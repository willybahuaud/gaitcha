<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Implémentation fichier du store anti-replay.
 *
 * Stocke les hashes de tokens dans un fichier JSON avec
 * verrouillage exclusif pour éviter les race conditions.
 *
 * Adapté pour des sites à trafic modéré. Pour du trafic
 * élevé, préférer une implémentation Redis ou DB.
 */
class FileTokenStore implements TokenStoreInterface
{
    /** Nombre maximum d'entrées dans le store. */
    private const MAX_ENTRIES = 10000;

    /** @var string Chemin du fichier de stockage. */
    private string $filePath;

    /**
     * @param string $filePath Chemin du fichier de stockage JSON.
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $hash): bool
    {
        $entries = $this->load();
        return isset($entries[$hash]);
    }

    /**
     * {@inheritdoc}
     */
    public function add(string $hash, int $timestamp): void
    {
        $this->withLock(function (array &$entries) use ($hash, $timestamp): void {
            $entries[$hash] = $timestamp;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function purge(int $ttl, ?int $currentTime = null): void
    {
        $cutoff = ($currentTime ?? time()) - $ttl;

        $this->withLock(function (array &$entries) use ($cutoff): void {
            foreach ($entries as $key => $timestamp) {
                if ($timestamp <= $cutoff) {
                    unset($entries[$key]);
                }
            }
        });
    }

    /**
     * Vérifie et ajoute un hash de façon atomique (check-and-set).
     *
     * Résout la race condition TOCTOU entre has() et add().
     *
     * @param string $hash      Hash du token.
     * @param int    $timestamp Timestamp de la soumission.
     * @return bool True si le hash existait déjà (replay détecté).
     */
    public function checkAndAdd(string $hash, int $timestamp): bool
    {
        $alreadyExists = false;

        $this->withLock(function (array &$entries) use ($hash, $timestamp, &$alreadyExists): void {
            if (isset($entries[$hash])) {
                $alreadyExists = true;
                return;
            }
            $entries[$hash] = $timestamp;
        });

        return $alreadyExists;
    }

    /**
     * Charge les données depuis le fichier.
     *
     * @return array<string, int> Entrées hash → timestamp.
     */
    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Exécute une opération avec verrouillage exclusif sur le fichier.
     *
     * Garantit l'atomicité des opérations read-modify-write.
     *
     * @param callable $operation Reçoit une référence sur les entrées.
     */
    private function withLock(callable $operation): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $lockPath = $this->filePath . '.lock';
        $lockFp   = fopen($lockPath, 'c');

        if ($lockFp === false) {
            throw new \RuntimeException("Gaitcha: impossible d'ouvrir le fichier lock : {$lockPath}");
        }

        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            throw new \RuntimeException("Gaitcha: impossible d'acquérir le lock exclusif : {$lockPath}");
        }

        try {
            $entries = $this->load();

            // Sécurité : limiter le nombre d'entrées.
            if (count($entries) > self::MAX_ENTRIES) {
                // Garder les plus récentes.
                arsort($entries);
                $entries = array_slice($entries, 0, self::MAX_ENTRIES, true);
            }

            $operation($entries);

            // Écriture atomique via fichier temporaire + rename.
            // Pas de LOCK_EX ici — le lock est déjà tenu sur le .lock file.
            $tmpPath = $this->filePath . '.tmp';
            file_put_contents($tmpPath, json_encode($entries, JSON_THROW_ON_ERROR));
            chmod($tmpPath, 0600);
            rename($tmpPath, $this->filePath);
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }
}
