<?php

declare(strict_types=1);

namespace App\Models\Newsletter;

use App\Enums\Newsletter\SubscriptionRequestStatus;
use App\Models\TenantModel;
use App\Models\Tiers;
use Database\Factories\Newsletter\SubscriptionRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SubscriptionRequest extends TenantModel
{
    /** @use HasFactory<SubscriptionRequestFactory> */
    use HasFactory;

    protected $table = 'newsletter_subscription_requests';

    protected $fillable = [
        'email',
        'prenom',
        'status',
        'subscribed_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'status' => SubscriptionRequestStatus::class,
        'confirmation_expires_at' => 'datetime',
        'subscribed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionRequestStatus::Confirmed);
    }

    protected static function newFactory(): SubscriptionRequestFactory
    {
        return SubscriptionRequestFactory::new();
    }
}
