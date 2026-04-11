<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\EmailOpen;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EmailTrackingController extends Controller
{
    // 1x1 transparent GIF
    private const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function __invoke(Request $request, string $token): Response
    {
        $log = EmailLog::where('tracking_token', $token)->first();

        if ($log) {
            EmailOpen::create([
                'email_log_id' => $log->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 500) : null,
                'opened_at' => now(),
            ]);
        }

        return response(self::PIXEL, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen(self::PIXEL),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }
}
