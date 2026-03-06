<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Implémentation fichier du store anti-replay.
 *
 * Stocke les hashes de tokens dans un fichier JSON.
 * Adapté pour des sites à trafic modéré. Pour du trafic
 * élevé, préférer une implémentation Redis ou DB.
 */
class FileTokenStore implements TokenStoreInterface
{
    /** @var string Chemin du fichier de stockage. */
    private string $filePath;

    /** @var array<string, int> Cache mémoire : hash → timestamp. */
    private array $entries = [];

    /** @var bool Indique si les données ont été chargées. */
    private bool $loaded = false;

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
        $this->load();
        return isset($this->entries[$hash]);
    }

    /**
     * {@inheritdoc}
     */
    public function add(string $hash, int $timestamp): void
    {
        $this->load();
        $this->entries[$hash] = $timestamp;
        $this->save();
    }

    /**
     * {@inheritdoc}
     */
    public function purge(int $ttl, ?int $currentTime = null): void
    {
        $this->load();
        $cutoff = ($currentTime ?? time()) - $ttl;

        $this->entries = array_filter(
            $this->entries,
            function (int $timestamp) use ($cutoff): bool {
                return $timestamp > $cutoff;
            }
        );

        $this->save();
    }

    /**
     * Charge les données depuis le fichier.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!file_exists($this->filePath)) {
            $this->entries = [];
            return;
        }

        $content = file_get_contents($this->filePath);
        if ($content === false) {
            $this->entries = [];
            return;
        }

        $data = json_decode($content, true);
        $this->entries = is_array($data) ? $data : [];
    }

    /**
     * Sauvegarde les données dans le fichier.
     */
    private function save(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->filePath,
            json_encode($this->entries, JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    }
}
