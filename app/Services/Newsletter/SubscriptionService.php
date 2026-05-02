<?php

declare(strict_types=1);

namespace App\Services\Newsletter;

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Mail\NewsletterConfirmation;
use App\Models\Association;
use App\Models\Newsletter\SubscriptionRequest;
use App\Tenant\TenantContext;
use App\Tenant\TenantScope;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionService
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {}

    public function subscribe(string $email, ?string $prenom, string $ip, string $userAgent): void
    {
        DB::transaction(function () use ($email, $prenom, $ip, $userAgent): void {
            // Cherche une ligne pending OU confirmed pour cet email dans le tenant courant
            $existing = SubscriptionRequest::where('email', $email)
                ->whereIn('status', [
                    SubscriptionRequestStatus::Pending->value,
                    SubscriptionRequestStatus::Confirmed->value,
                ])
                ->latest('id')
                ->first();

            if ($existing?->status === SubscriptionRequestStatus::Confirmed) {
                // anti-énumération : silence
                return;
            }

            if ($existing?->status === SubscriptionRequestStatus::Pending) {
                // On régénère systématiquement les 2 tokens : on n'a pas le token clair en mémoire
                // (seul le hash est en DB) donc on ne pourrait pas le réinjecter dans l'email.
                // Conséquence : le token de désinscription envoyé dans l'email précédent
                // devient invalide. Acceptable : un seul email actif à la fois par adresse.
                $confirmation = $existing->regenerateConfirmationToken();
                $unsubscribe = $existing->regenerateUnsubscribeToken();
                $existing->save();

                $this->sendConfirmation($existing, $confirmation, $unsubscribe);

                return;
            }

            // Nouveau OU re-inscription après unsubscribed → nouvelle ligne
            $request = new SubscriptionRequest([
                'email' => $email,
                'prenom' => $prenom,
                'status' => SubscriptionRequestStatus::Pending,
                'subscribed_at' => now(),
                'ip_address' => $ip,
                'user_agent' => Str::limit($userAgent, 250, ''),
            ]);
            $confirmation = $request->regenerateConfirmationToken();
            $unsubscribe = $request->regenerateUnsubscribeToken();
            $request->save();

            $this->sendConfirmation($request, $confirmation, $unsubscribe);
        });
    }

    public function confirm(SubscriptionRequest $request): void
    {
        if ($request->confirmation_expires_at?->isPast()) {
            throw new Exceptions\ConfirmationExpiredException;
        }

        $request->markConfirmed();
        $request->save();
    }

    public function unsubscribe(SubscriptionRequest $request): void
    {
        $request->markUnsubscribed();
        $request->save();
    }

    public function findByConfirmationToken(string $clearToken): ?SubscriptionRequest
    {
        return $this->findByToken('confirmation_token_hash', $clearToken);
    }

    public function findByUnsubscribeToken(string $clearToken): ?SubscriptionRequest
    {
        return $this->findByToken('unsubscribe_token_hash', $clearToken);
    }

    private function findByToken(string $column, string $clearToken): ?SubscriptionRequest
    {
        $hash = hash('sha256', $clearToken);

        /** @var SubscriptionRequest|null $row */
        $row = SubscriptionRequest::withoutGlobalScope(TenantScope::class)
            ->where($column, $hash)
            ->first();

        if ($row === null) {
            return null;
        }

        if (! TenantContext::hasBooted() || (int) TenantContext::currentId() !== (int) $row->association_id) {
            $association = Association::find($row->association_id);
            if ($association === null) {
                return null;
            }
            TenantContext::boot($association);
        }

        return $row;
    }

    private function sendConfirmation(
        SubscriptionRequest $request,
        string $confirmationClear,
        string $unsubscribeClear,
    ): void {
        $this->mailer->to($request->email)
            ->send(new NewsletterConfirmation($request, $confirmationClear, $unsubscribeClear));
    }
}
