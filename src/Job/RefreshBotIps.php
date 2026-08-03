<?php declare(strict_types=1);

namespace BotChallenge\Job;

use BotChallenge\Stdlib\BotIps;
use Omeka\Job\AbstractJob;

/**
 * Refresh the bundled ip ranges of the search engines from their sources.
 *
 * The lists move (Google added ranges recently), so a bundled list silently
 * ages: without a periodic refresh, an engine that changed its ranges receives
 * the challenge again and stops indexing the site.
 *
 * A source that is unreachable or that returns a truncated list is skipped: the
 * previous list is kept, since replacing it with an empty one would deindex the
 * site.
 */
class RefreshBotIps extends AbstractJob
{
    /**
     * Minimum number of ranges expected from a source to replace a list.
     */
    const MIN_IPS = 1;

    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $settings = $services->get('Omeka\Settings');

        $refreshed = 0;
        foreach (array_keys(BotIps::BOTS) as $bot) {
            if ($this->shouldStop()) {
                return;
            }
            $url = BotIps::sourceUrl($bot);
            if (!$url) {
                continue;
            }
            $ips = $this->fetchIps($url, BotIps::isJsonSource($bot));
            // A source that returns much less than the bundled list is a
            // truncated or altered answer, not a real removal of ranges.
            $current = count(BotIps::ips($bot));
            if (count($ips) < self::MIN_IPS || ($current > 4 && count($ips) < $current / 2)) {
                $logger->warn(
                    'BotChallenge: the source of {bot} is unreachable or empty ({url}): the bundled ranges are kept.', // @translate
                    ['bot' => $bot, 'url' => $url]
                );
                continue;
            }
            if (!$this->store($bot, $ips)) {
                $logger->warn(
                    'BotChallenge: the ranges of {bot} could not be written in {path}.', // @translate
                    ['bot' => $bot, 'path' => BotIps::path($bot)]
                );
                continue;
            }
            ++$refreshed;
            $logger->info(
                'BotChallenge: {total} ranges updated for {bot}.', // @translate
                ['total' => count($ips), 'bot' => $bot]
            );
        }

        // Rebuild the merged list read by the listener of each request.
        $bots = $settings->get('botchallenge_exception_bots') ?: BotIps::DEFAULT_BOTS;
        $exceptionIps = $settings->get('botchallenge_exception_ips') ?: [];
        $settings->set('botchallenge_exception_ips_all', array_values(array_unique(array_merge(
            BotIps::ipsForBots($bots),
            $exceptionIps
        ))));

        $logger->notice(
            'BotChallenge: {total} source(s) refreshed.', // @translate
            ['total' => $refreshed]
        );
    }

    /**
     * @return string[]
     */
    protected function fetchIps(string $url, bool $isJson): array
    {
        $content = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 30, 'user_agent' => 'Omeka S BotChallenge'],
        ]));
        if (!is_string($content) || $content === '') {
            return [];
        }

        if ($isJson) {
            $data = json_decode($content, true);
            $prefixes = is_array($data) ? ($data['prefixes'] ?? []) : [];
            $ips = [];
            foreach ($prefixes as $prefix) {
                $ip = $prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? null;
                if ($ip) {
                    $ips[] = $ip;
                }
            }
        } else {
            $ips = array_map('trim', explode("\n", strtr($content, ["\r" => "\n"])));
        }

        $ips = array_filter($ips, fn ($ip) => (bool) preg_match('~^[0-9a-fA-F.:]+(/\d{1,3})?$~', (string) $ip));
        sort($ips);
        return array_values(array_unique($ips));
    }

    /**
     * @param string[] $ips
     */
    protected function store(string $bot, array $ips): bool
    {
        $filepath = BotIps::path($bot);
        if (file_exists($filepath) ? !is_writable($filepath) : !is_writable(dirname($filepath))) {
            return false;
        }
        return (bool) file_put_contents($filepath, implode("\n", $ips) . "\n");
    }
}
