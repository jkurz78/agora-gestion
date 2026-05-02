<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SubscribeNewsletterRequest;
use App\Services\Newsletter\Exceptions\ConfirmationExpiredException;
use App\Services\Newsletter\SubscriptionService;
use App\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class NewsletterSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $service,
    ) {}

    public function subscribe(SubscribeNewsletterRequest $request): JsonResponse
    {
        if ($request->isHoneypotTriggered) {
            return response()->json(['status' => 'pending_double_optin']);
        }

        $this->service->subscribe(
            email: (string) $request->validated('email'),
            prenom: $request->validated('prenom'),
            ip: (string) $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return response()->json(['status' => 'pending_double_optin']);
    }

    public function confirm(string $token): View|Response
    {
        $row = $this->service->findByConfirmationToken($token);
        if ($row === null) {
            abort(404);
        }

        try {
            $this->service->confirm($row);
        } catch (ConfirmationExpiredException) {
            return response()->view(
                'newsletter.expired',
                ['association' => TenantContext::current()],
                410,
            );
        }

        return view('newsletter.confirmed', [
            'association' => TenantContext::current(),
        ]);
    }

    public function unsubscribe(string $token): View
    {
        $row = $this->service->findByUnsubscribeToken($token);
        if ($row === null) {
            abort(404);
        }

        $this->service->unsubscribe($row);

        return view('newsletter.unsubscribed', [
            'association' => TenantContext::current(),
        ]);
    }
}
