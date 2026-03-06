<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\FileTokenStore;
use PHPUnit\Framework\TestCase;

class FileTokenStoreTest extends TestCase
{
    private string $filePath;
    private FileTokenStore $store;

    protected function setUp(): void
    {
        $this->filePath = sys_get_temp_dir() . '/gaitcha_test_' . uniqid() . '.json';
        $this->store    = new FileTokenStore($this->filePath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }

    public function testHasReturnsFalseForUnknownHash(): void
    {
        $this->assertFalse($this->store->has('unknown-hash'));
    }

    public function testAddAndHas(): void
    {
        $this->store->add('hash-123', time());

        $this->assertTrue($this->store->has('hash-123'));
        $this->assertFalse($this->store->has('hash-456'));
    }

    public function testPersistenceAcrossInstances(): void
    {
        $this->store->add('hash-persist', time());

        // Nouvelle instance sur le même fichier.
        $store2 = new FileTokenStore($this->filePath);
        $this->assertTrue($store2->has('hash-persist'));
    }

    public function testPurgeRemovesOldEntries(): void
    {
        $now = time();

        $this->store->add('old-hash', $now - 300);
        $this->store->add('new-hash', $now);

        $this->store->purge(120);

        $this->assertFalse($this->store->has('old-hash'));
        $this->assertTrue($this->store->has('new-hash'));
    }

    public function testPurgeKeepsRecentEntries(): void
    {
        $now = time();

        $this->store->add('hash-1', $now - 60);
        $this->store->add('hash-2', $now - 30);
        $this->store->add('hash-3', $now);

        $this->store->purge(120);

        $this->assertTrue($this->store->has('hash-1'));
        $this->assertTrue($this->store->has('hash-2'));
        $this->assertTrue($this->store->has('hash-3'));
    }

    public function testWorksWithNonExistentFile(): void
    {
        $store = new FileTokenStore('/tmp/gaitcha_nonexistent_' . uniqid() . '.json');

        $this->assertFalse($store->has('anything'));
    }

    public function testMultipleAdds(): void
    {
        $now = time();

        $this->store->add('hash-a', $now);
        $this->store->add('hash-b', $now);
        $this->store->add('hash-c', $now);

        $this->assertTrue($this->store->has('hash-a'));
        $this->assertTrue($this->store->has('hash-b'));
        $this->assertTrue($this->store->has('hash-c'));
    }
}
