<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

use Laminas\Http\PhpEnvironment\Request;

/**
 * Get the ip of the client, handling the common proxy headers.
 *
 * The headers X-Forwarded-For and X-Real-IP can be written by anybody, so they
 * are used only when the direct peer is a trusted proxy. By default, a proxy is
 * trusted when it has a private or loopback address, like a reverse proxy in
 * the same host or network (Traefik, nginx, Docker). Without proxy, the direct
 * peer is a public address and the headers are ignored.
 */
class ClientIp
{
    /**
     * @param string[] $trustedProxies Ips or cidr ranges of the proxies. When
     * empty, the private and loopback addresses are trusted.
     */
    public static function fromRequest(Request $request, array $trustedProxies = []): string
    {
        $remoteIp = (string) $request->getServer('REMOTE_ADDR', '');
        if (!static::isValidIp($remoteIp)) {
            return '127.0.0.1';
        }
        if (!static::isTrusted($remoteIp, $trustedProxies)) {
            return $remoteIp;
        }

        $headers = $request->getHeaders();

        // Each proxy appends the ip of its peer, so the client is the first ip
        // from the right that is not a trusted proxy. The left part may be
        // forged by the client.
        if ($headers->has('X-Forwarded-For')) {
            $ips = array_reverse(array_map('trim', explode(',', $headers->get('X-Forwarded-For')->getFieldValue())));
            $clientIp = $remoteIp;
            foreach ($ips as $ip) {
                if (!static::isValidIp($ip)) {
                    break;
                }
                $clientIp = $ip;
                if (!static::isTrusted($ip, $trustedProxies)) {
                    break;
                }
            }
            return $clientIp;
        }

        if ($headers->has('X-Real-IP')) {
            $ip = trim($headers->get('X-Real-IP')->getFieldValue());
            if (static::isValidIp($ip)) {
                return $ip;
            }
        }

        return $remoteIp;
    }

    public static function isTrusted(string $ip, array $trustedProxies = []): bool
    {
        if (!$trustedProxies) {
            // Private, loopback and reserved addresses.
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        foreach ($trustedProxies as $cidr) {
            if (static::ipInCidr($ip, trim((string) $cidr))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an ip address is within a cidr range or is a single ip.
     */
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        [$range, $bits] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $rangeBin = @inet_pton($range);
        if ($ipBin === false || $rangeBin === false || strlen($ipBin) !== strlen($rangeBin)) {
            return false;
        }

        $bits = (int) $bits;
        $totalBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $totalBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes && substr($ipBin, 0, $bytes) !== substr($rangeBin, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if (!$remainder) {
            return true;
        }
        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return ($ipBin[$bytes] & $mask) === ($rangeBin[$bytes] & $mask);
    }

    protected static function isValidIp(string $ip): bool
    {
        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
