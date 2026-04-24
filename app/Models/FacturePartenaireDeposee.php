<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutFactureDeposee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FacturePartenaireDeposee extends TenantModel
{
    use HasFactory;

    protected $table = 'factures_partenaires_deposees';

    protected $fillable = [
        'association_id',
        'tiers_id',
        'date_facture',
        'numero_facture',
        'pdf_path',
        'pdf_taille',
        'statut',
        'motif_rejet',
        'transaction_id',
        'traitee_at',
    ];

    protected function casts(): array
    {
        return [
            'date_facture' => 'date',
            'traitee_at' => 'datetime',
            'statut' => StatutFactureDeposee::class,
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
