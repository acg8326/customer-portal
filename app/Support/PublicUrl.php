<?php

namespace App\Support;

/**
 * SSRF guard for user-supplied outbound URLs (e.g. n8n webhooks).
 *
 * A URL is "public" only if it uses http(s) and every IP its host resolves to
 * is a globally-routable address — blocking loopback, private, link-local, and
 * other reserved ranges (including the cloud metadata endpoint 169.254.169.254).
 */
class PublicUrl
{
    public static function isPublic(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // Strip IPv6 brackets, e.g. [::1] → ::1
        $host = trim($host, '[]');

        $ips = self::resolve($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a host to its IP addresses. If the host is already an IP literal,
     * return it as-is.
     *
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        // IPv6 records, best-effort.
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
