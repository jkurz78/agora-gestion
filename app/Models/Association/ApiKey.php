<?php

declare(strict_types=1);

namespace App\Models\Association;

use App\Models\Association;
use Database\Factories\Association\ApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $table = 'association_api_keys';

    protected $fillable = [
        'association_id',
        'key_id',
        'secret_encrypted',
        'label',
        'scopes',
    ];

    protected $casts = [
        'secret_encrypted' => 'encrypted',
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public static function findByKeyId(string $keyId): ?self
    {
        return self::active()->where('key_id', $keyId)->first();
    }

    public function revoke(): void
    {
        $this->revoked_at = now();
        $this->save();
    }

    public function touchLastUsed(): void
    {
        $this->last_used_at = now();
        $this->saveQuietly();
    }

    protected static function newFactory(): ApiKeyFactory
    {
        return ApiKeyFactory::new();
    }
}
