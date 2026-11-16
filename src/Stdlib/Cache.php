<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

/**
 * Small cache with expiration, for the checks done on some requests only.
 *
 * The settings of Omeka are all loaded on each request, so a cache stored there
 * would be read by every visitor. The cache uses apcu when it is enabled, that
 * is shared by the processes of the server, else a file by key in the temporary
 * directory, else in a private directory of the installation. The keys are
 * prefixed by installation, since several sites may share a server.
 */
class Cache
{
    /**
     * @var string|null Directory of the files, or null to use apcu.
     */
    protected $directory;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var bool False when no storage is usable: nothing is cached.
     */
    protected $isUsable = true;

    /**
     * @param string $namespace Prefix of the keys, distinct by installation.
     * @param string|null $fallbackDirectory Used when apcu is disabled and the
     *   temporary directory is not writable. A deny-all .htaccess is added.
     * @param bool $useApcu Disable to force the files, for example in tests.
     */
    public function __construct(string $namespace, ?string $fallbackDirectory = null, bool $useApcu = true)
    {
        $this->namespace = $namespace;
        if ($useApcu && function_exists('apcu_enabled') && apcu_enabled()) {
            return;
        }
        foreach ([sys_get_temp_dir() . '/botchallenge-' . $namespace, $fallbackDirectory] as $directory) {
            if ($directory && $this->prepareDirectory($directory)) {
                $this->directory = $directory;
                return;
            }
        }
        $this->isUsable = false;
    }

    /**
     * Get the cache of the current installation.
     *
     * The keys are distinct by installation, and the fallback directory is
     * private to it.
     */
    public static function forInstallation(): self
    {
        return new self(
            substr(sha1(OMEKA_PATH), 0, 12),
            OMEKA_PATH . '/files/botchallenge-cache'
        );
    }

    /**
     * @return mixed|null Null when the key is missing or expired.
     */
    public function get(string $key)
    {
        if (!$this->isUsable) {
            return null;
        }
        if ($this->directory === null) {
            $success = false;
            $value = apcu_fetch('botchallenge:' . $this->namespace . ':' . $key, $success);
            return $success ? $value : null;
        }
        $data = @unserialize((string) @file_get_contents($this->filepath($key)), ['allowed_classes' => false]);
        return is_array($data) && ($data[0] ?? 0) > time() ? $data[1] : null;
    }

    /**
     * @param mixed $value Any scalar or array value but null.
     */
    public function set(string $key, $value, int $ttl): void
    {
        if (!$this->isUsable) {
            return;
        }
        if ($this->directory === null) {
            apcu_store('botchallenge:' . $this->namespace . ':' . $key, $value, $ttl);
            return;
        }
        @file_put_contents($this->filepath($key), serialize([time() + $ttl, $value]), LOCK_EX);
        // Purge the expired files from time to time.
        if (random_int(1, 100) === 1) {
            foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
                $data = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
                if (!is_array($data) || ($data[0] ?? 0) <= time()) {
                    @unlink($file);
                }
            }
        }
    }

    public function isUsable(): bool
    {
        return $this->isUsable;
    }

    protected function prepareDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }
        if (!is_writable($directory)) {
            return false;
        }
        // The fallback directory may be served by the web server.
        if (!file_exists($directory . '/.htaccess')) {
            @file_put_contents($directory . '/.htaccess', "Require all denied\n");
        }
        return true;
    }

    protected function filepath(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
    }
}
