<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

/**
 * Forward-confirmed reverse dns: the ip resolves to a host of the domain, and
 * this host resolves back to the ip.
 *
 * A user agent is declarative, so a bot is verified by its network: a reverse
 * record alone can be set by anyone owning the ip, the forward check proves
 * that the domain owner agrees.
 */
class ReverseDns
{
    /**
     * @var callable
     */
    protected $reverse;

    /**
     * @var callable
     */
    protected $forward;

    public function __construct(?callable $reverse = null, ?callable $forward = null)
    {
        $this->reverse = $reverse ?? static fn (string $ip): string => (string) @gethostbyaddr($ip);
        $this->forward = $forward ?? static function (string $host): array {
            $ips = [];
            foreach ((array) (@dns_get_record($host, DNS_A | DNS_AAAA) ?: []) as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
            return array_values(array_filter($ips));
        };
    }

    /**
     * Does the ip belong to the domain, on a label boundary?
     */
    public function verify(string $ip, string $domain): bool
    {
        $ipBin = @inet_pton($ip);
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));
        if ($ipBin === false || $domain === '') {
            return false;
        }
        $host = strtolower(rtrim(($this->reverse)($ip), '.'));
        if ($host === '' || $host === $ip) {
            return false;
        }
        if ($host !== $domain && substr($host, -strlen($domain) - 1) !== '.' . $domain) {
            return false;
        }
        foreach (($this->forward)($host) as $forwardIp) {
            if (@inet_pton((string) $forwardIp) === $ipBin) {
                return true;
            }
        }
        return false;
    }
}
