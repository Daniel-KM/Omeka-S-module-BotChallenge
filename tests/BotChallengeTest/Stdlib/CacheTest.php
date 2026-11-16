<?php declare(strict_types=1);

namespace BotChallengeTest\Stdlib;

use BotChallenge\Stdlib\Cache;
use PHPUnit\Framework\TestCase;

/**
 * The file backend, used when apcu is disabled (and in cli).
 */
class CacheTest extends TestCase
{
    /**
     * @var string
     */
    private $dir;

    /**
     * @var string
     */
    private $namespace;

    protected function setUp(): void
    {
        $this->namespace = 'test-' . bin2hex(random_bytes(4));
        $this->dir = sys_get_temp_dir() . '/botchallenge-' . $this->namespace;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/{,.}*[!.]*', GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    public function testStoredValueIsReturned(): void
    {
        $cache = new Cache($this->namespace, null, false);
        $cache->set('a', false, 60);
        $cache->set('b', ['x' => 1], 60);
        $this->assertFalse($cache->get('a'));
        $this->assertSame(['x' => 1], $cache->get('b'));
    }

    public function testMissingOrExpiredKeyIsNull(): void
    {
        $cache = new Cache($this->namespace, null, false);
        $this->assertNull($cache->get('missing'));
        $cache->set('old', true, -1);
        $this->assertNull($cache->get('old'));
    }

    public function testKeysAreDistinctByInstallation(): void
    {
        $cache = new Cache($this->namespace, null, false);
        $cache->set('a', true, 60);
        $other = new Cache($this->namespace . '-other', null, false);
        $this->assertNull($other->get('a'));
        $otherDir = sys_get_temp_dir() . '/botchallenge-' . $this->namespace . '-other';
        @unlink($otherDir . '/.htaccess');
        @rmdir($otherDir);
    }

    public function testFallbackDirectoryIsProtected(): void
    {
        $cache = new Cache($this->namespace, null, false);
        $this->assertTrue($cache->isUsable());
        $this->assertFileExists($this->dir . '/.htaccess');
    }

    public function testUnusableStorageCachesNothing(): void
    {
        // A file blocks the directory of the cache, and there is no fallback.
        touch($this->dir);
        $cache = new Cache($this->namespace, null, false);
        $this->assertFalse($cache->isUsable());
        $cache->set('a', true, 60);
        $this->assertNull($cache->get('a'));
        unlink($this->dir);
    }
}
