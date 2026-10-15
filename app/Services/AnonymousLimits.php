<?php

namespace App\Services;

use App\Models\File;
use App\Models\Share;
use App\Models\UploadSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Rules for uploads from people who are not signed in (Light NC Share, D295).
 */
class AnonymousLimits
{
    public const MSG_SIZE = "Shares from people who aren't signed in can be at most 2 GB and 20 files.";
    public const MSG_RATE = "You've sent a lot of files today. Try again tomorrow.";
    public const MSG_FULL = 'Sharing is full right now. Try again later.';
    public const MSG_TOKEN = 'Your upload session has ended. Please start again.';
    public const MSG_BUNDLE = "Folders can't be sent unless you're signed in. Choose files instead.";
    public const MSG_TOKENS = 'Too many tries. Please wait a little while and try again.';

    private const TOKEN_PREFIX = 'anon.';

    /**
     * The visitor's address. CF-Connecting-IP is believed only when the direct peer is a
     * private address (the tunnel or the Docker network) and names a public address (loopback and
     * private values are ignored); otherwise the peer address is used.
     */
    public static function clientIp(string $peer, ?string $cfConnectingIp): string
    {
        if (
            $cfConnectingIp !== null
            && filter_var($cfConnectingIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && filter_var($peer, FILTER_VALIDATE_IP)
            && IpUtils::checkIp($peer, config('anonymous.trusted_proxies'))
        ) {
            return $cfConnectingIp;
        }

        return $peer;
    }

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    /** A short-lived token that only works from the address it was issued to. */
    public static function issueToken(string $ip): string
    {
        return self::TOKEN_PREFIX . Crypt::encryptString(json_encode([
            'jti' => Str::random(32),
            'iph' => self::ipHash($ip),
            'exp' => time() + config('anonymous.token_ttl_minutes') * 60,
        ]));
    }

    public static function isAnonymousToken(?string $token): bool
    {
        return $token !== null && str_starts_with($token, self::TOKEN_PREFIX);
    }

    /** The token's contents, or null when it is forged, expired or from another address. */
    public static function readToken(?string $token, string $ip): ?array
    {
        if (!self::isAnonymousToken($token)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString(substr($token, strlen(self::TOKEN_PREFIX))), true);
        } catch (DecryptException) {
            return null;
        }

        if (!is_array($data) || ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return hash_equals((string) ($data['iph'] ?? ''), self::ipHash($ip)) ? $data : null;
    }

    /** Is this address out of shares for the hour or the day? */
    public static function tooManyShares(string $ip): bool
    {
        $h = self::ipHash($ip);

        return RateLimiter::tooManyAttempts("anon-share-hour:$h", config('anonymous.per_ip_hour'))
            || RateLimiter::tooManyAttempts("anon-share-day:$h", config('anonymous.per_ip_day'));
    }

    public static function countShare(string $ip): void
    {
        $h = self::ipHash($ip);
        RateLimiter::hit("anon-share-hour:$h", 3600);
        RateLimiter::hit("anon-share-day:$h", 86400);
    }

    /** Counts a token request; false when this address asks for too many. */
    public static function allowTokenRequest(string $ip): bool
    {
        $key = 'anon-token:' . self::ipHash($ip);
        if (RateLimiter::tooManyAttempts($key, config('anonymous.tokens_per_ip_hour'))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * Bytes held by people who are not signed in: shares that have not expired plus recent
     * unfinished uploads. Uploads belonging to $exceptToken are left out.
     */
    public static function totalBytes(?string $exceptToken = null): int
    {
        $shares = (int) Share::where('anonymous', true)->where('expires_at', '>', now())->sum('size');

        $sessions = UploadSession::whereNotNull('anon_token')
            ->whereIn('status', ['pending', 'complete'])
            ->where('created_at', '>', now()->subHours(config('anonymous.stale_upload_hours')));
        if ($exceptToken) {
            $sessions->where('anon_token', '!=', $exceptToken);
        }

        return $shares + (int) $sessions->sum('filesize');
    }

    /** Unfinished or just-finished uploads from one token. */
    public static function tokenSessions(string $jti)
    {
        return UploadSession::where('anon_token', $jti)->whereIn('status', ['pending', 'complete']);
    }

    /** Delete anonymous uploads that were never turned into a share. */
    public static function purgeStale(): int
    {
        $sessions = UploadSession::whereNotNull('anon_token')
            ->where('created_at', '<', now()->subHours(config('anonymous.stale_upload_hours')))
            ->get();

        foreach ($sessions as $session) {
            $path = storage_path('app/uploads/' . $session->upload_id);
            foreach ([$path, $path . '.info'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if ($session->file_id) {
                File::where('id', $session->file_id)->delete();
            }
            $session->delete();
        }

        return $sessions->count();
    }
}
