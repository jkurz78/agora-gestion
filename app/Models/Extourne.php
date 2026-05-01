<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Extourne extends TenantModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'transaction_origine_id',
        'transaction_extourne_id',
        'rapprochement_lettrage_id',
        'association_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_origine_id' => 'integer',
            'transaction_extourne_id' => 'integer',
            'rapprochement_lettrage_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function origine(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_origine_id');
    }

    public function extourne(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_extourne_id');
    }

    public function lettrage(): BelongsTo
    {
        return $this->belongsTo(RapprochementBancaire::class, 'rapprochement_lettrage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
