<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

/**
 * Small cache with expiration, for the checks done on some requests only.
 *
 * The settings of Omeka are all loaded on each request, so a cache stored there
 * would be read by every visitor. The cache uses apcu when it is enabled, that
 * is shared by the processes of the server, else a file by key in a directory.
 */
class Cache
{
    /**
     * @var string|null Directory of the files, or null to use apcu.
     */
    protected $directory;

    /**
     * @param string|null $directory Force the files in this directory, for
     *   example in tests. By default, apcu is used when enabled.
     */
    public function __construct(?string $directory = null)
    {
        if ($directory === null
            && !(function_exists('apcu_enabled') && apcu_enabled())
        ) {
            $directory = sys_get_temp_dir() . '/botchallenge-cache';
        }
        $this->directory = $directory;
    }

    /**
     * @return mixed|null Null when the key is missing or expired.
     */
    public function get(string $key)
    {
        if ($this->directory === null) {
            $success = false;
            $value = apcu_fetch('botchallenge:' . $key, $success);
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
        if ($this->directory === null) {
            apcu_store('botchallenge:' . $key, $value, $ttl);
            return;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
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

    protected function filepath(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
    }
}
