<?php declare(strict_types=1);

namespace BotChallenge\Stdlib;

use Laminas\Http\PhpEnvironment\Request;

/**
 * Get the ip of the client, handling the common proxy headers.
 */
class ClientIp
{
    /**
     * The headers are declarative, so they are trusted only as much as the
     * proxy in front of Omeka is: without a proxy, they should be stripped by
     * the server.
     */
    public static function fromRequest(Request $request): string
    {
        $headers = $request->getHeaders();

        if ($headers->has('X-Forwarded-For')) {
            $ips = $headers->get('X-Forwarded-For')->getFieldValue();
            $ip = trim(explode(',', $ips)[0]);
            if ($ip !== '') {
                return $ip;
            }
        }

        if ($headers->has('X-Real-IP')) {
            $ip = trim($headers->get('X-Real-IP')->getFieldValue());
            if ($ip !== '') {
                return $ip;
            }
        }

        return (string) $request->getServer('REMOTE_ADDR', '127.0.0.1');
    }
}
