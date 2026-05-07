<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\Association\ApiKey;
use App\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyNewsletterHmacSignature
{
    private const SIGNATURE_VERSION_PREFIX = 'v1=';

    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $keyId = (string) $request->headers->get('X-Key-Id', '');
        $timestamp = (string) $request->headers->get('X-Timestamp', '');
        $signature = (string) $request->headers->get('X-Signature', '');

        if ($keyId === '' || $timestamp === '' || $signature === '') {
            abort(403);
        }

        if (! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            abort(403);
        }

        $apiKey = ApiKey::findByKeyId($keyId);
        if ($apiKey === null) {
            abort(403);
        }

        $expected = self::SIGNATURE_VERSION_PREFIX.hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            (string) $apiKey->secret_encrypted
        );

        if (! hash_equals($expected, $signature)) {
            abort(403);
        }

        TenantContext::boot($apiKey->association);
        $apiKey->touchLastUsed();
        $request->attributes->set('newsletter_api_key_id', $apiKey->id);

        return $next($request);
    }
}
