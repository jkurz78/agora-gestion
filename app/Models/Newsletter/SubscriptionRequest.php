<?php

declare(strict_types=1);

namespace App\Models\Newsletter;

use App\Enums\Newsletter\DesinscriptionAction;
use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Models\Association\ApiKey;
use App\Models\TenantModel;
use App\Models\Tiers;
use App\Models\User;
use Database\Factories\Newsletter\SubscriptionRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class SubscriptionRequest extends TenantModel
{
    /** @use HasFactory<SubscriptionRequestFactory> */
    use HasFactory;

    protected $table = 'newsletter_subscription_requests';

    protected $fillable = [
        'email',
        'prenom',
        'nom',
        'status',
        'subscribed_at',
        'ip_address',
        'user_agent',
        'api_key_id',
    ];

    protected $casts = [
        'status' => SubscriptionRequestStatus::class,
        'confirmation_expires_at' => 'datetime',
        'subscribed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'ignored_at' => 'datetime',
        'desinscription_traitee_at' => 'datetime',
        'desinscription_action' => DesinscriptionAction::class,
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionRequestStatus::Confirmed);
    }

    public function regenerateConfirmationToken(): string
    {
        $clear = Str::random(48); // ~64 chars base64-url-safe
        $this->confirmation_token_hash = hash('sha256', $clear);
        $this->confirmation_expires_at = now()->addDays(
            (int) config('newsletter.confirmation_ttl_days', 7)
        );

        return $clear;
    }

    public function regenerateUnsubscribeToken(): string
    {
        $clear = Str::random(48);
        $this->unsubscribe_token_hash = hash('sha256', $clear);

        return $clear;
    }

    public function markConfirmed(): void
    {
        $this->status = SubscriptionRequestStatus::Confirmed;
        $this->confirmed_at = now();
    }

    public function markUnsubscribed(): void
    {
        $this->status = SubscriptionRequestStatus::Unsubscribed;
        $this->unsubscribed_at = now();
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function scopeInscriptionsAtraiter(Builder $query): Builder
    {
        return $query->where('status', SubscriptionRequestStatus::Confirmed)
            ->whereNull('tiers_id')
            ->whereNull('ignored_at');
    }

    public function scopeDesinscriptionsAtraiter(Builder $query): Builder
    {
        return $query->where('status', SubscriptionRequestStatus::Unsubscribed)
            ->whereNotNull('tiers_id')
            ->whereNull('desinscription_traitee_at');
    }

    protected static function newFactory(): SubscriptionRequestFactory
    {
        return SubscriptionRequestFactory::new();
    }
}
