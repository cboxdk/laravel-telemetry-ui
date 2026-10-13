<?php

declare(strict_types=1);

namespace Cbox\TelemetryUi\Connectors;

/**
 * What happened when we talked to a backend — one taxonomy shared by
 * {@see SourceException} (which never carries `Ok`) and {@see ProbeResult}.
 *
 * The point of the taxonomy is that "it didn't work" is useless to whoever has
 * to fix it: a wrong hostname, an expired CA, a bad token and a URL pointing at
 * the wrong product need four different actions.
 */
enum BackendStatus: string
{
    /** Reachable, authenticated, and answering the API we expect. Probes only. */
    case Ok = 'ok';

    /** DNS, refused connection — nothing answered. */
    case Unreachable = 'unreachable';

    /** Connected, asked, and the answer took longer than the connection's timeout. */
    case Timeout = 'timeout';

    /** The answer started and was cut off — a proxy's buffer or size limit, a backend giving up. */
    case Dropped = 'dropped';

    /** Connected, but the TLS handshake failed: untrusted CA, expired or wrong-host certificate. */
    case Tls = 'tls';

    /** Answered with 401/403 — credentials missing, wrong, or lacking permission. */
    case Unauthorized = 'unauthorized';

    /** Answered 404 on a path this backend should serve — usually a base URL missing a prefix. */
    case NotFound = 'not_found';

    /** Answered, but not with the API we expect — often the right host, wrong product. */
    case UnexpectedApi = 'unexpected_api';

    /** Anything else: a 5xx, a malformed config, an unclassified failure. */
    case Error = 'error';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }

    /**
     * A short, user-facing label. Deliberately free of backend URLs and response
     * bodies — the same discipline {@see SourceException} applies, since a probe
     * result is rendered in the same semi-trusted dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Connected',
            self::Unreachable => 'Unreachable',
            self::Timeout => 'Timed out',
            self::Dropped => 'Connection dropped',
            self::Tls => 'TLS failed',
            self::Unauthorized => 'Not authorized',
            self::NotFound => 'Not found',
            self::UnexpectedApi => 'Unexpected API',
            self::Error => 'Failed',
        };
    }

    /**
     * Classify a transport-level failure reason (a cURL/Guzzle
     * ConnectionException message) as TLS or simply unreachable.
     *
     * Matching is on message text because that is all the transport gives us;
     * it is deliberately broad, and anything unrecognised degrades to
     * Unreachable — a misclassified TLS error is a worse outcome than a
     * generic one only if it hides the certificate, so the TLS markers cover
     * both cURL's numbered codes and its prose.
     */
    public static function classifyConnectionFailure(string $reason): self
    {
        $needles = [
            'ssl', 'certificate', 'cert ', 'tls', 'self-signed', 'self signed',
            'unable to get local issuer', 'curl error 35', 'curl error 58',
            'curl error 60', 'curl error 77', 'curl error 83',
        ];

        $haystack = mb_strtolower($reason);

        // Before the TLS markers: a response cut off mid-stream over HTTPS
        // reads "OpenSSL SSL_read: unexpected eof" (cURL 56), and calling that
        // a certificate problem sent people to a certificate that was fine.
        // 18 partial body, 52 empty reply, 56 receive failure, 92 HTTP/2
        // stream reset: the backend (or the proxy before it) started to
        // answer and stopped.
        foreach (['curl error 18', 'curl error 52', 'curl error 56', 'curl error 92', 'unexpected eof', 'empty reply from server'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::Dropped;
            }
        }

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::Tls;
            }
        }

        // Timing out while still CONNECTING is a host that never answered,
        // not a slow query: cURL 7 ("Failed to connect … Connection timed
        // out"), a connect timeout ("Connection timed out after N ms"), or a
        // name lookup that never came back. Sending someone to shorten their
        // window for a firewall that drops packets helps nobody.
        foreach (['curl error 7:', 'curl error 7 ', 'failed to connect', 'connection timed out after', 'connect timed out', 'resolving timed out', 'curl error 6:', 'could not resolve'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return self::Unreachable;
            }
        }

        // cURL error 28 is "operation timed out": the backend was reached and
        // did not finish in time — a slow query, not a missing host, and the
        // fix is a shorter window or a longer timeout.
        if (str_contains($haystack, 'curl error 28') || str_contains($haystack, 'timed out')) {
            return self::Timeout;
        }

        return self::Unreachable;
    }

    /**
     * Classify an HTTP status the backend actually returned.
     */
    public static function classifyHttpStatus(int $status): self
    {
        return match (true) {
            $status === 401, $status === 403 => self::Unauthorized,
            $status === 404 => self::NotFound,
            default => self::Error,
        };
    }
}
