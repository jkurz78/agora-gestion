<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class RecuFiscalEmis extends TenantModel
{
    use HasFactory;

    protected $table = 'recus_fiscaux_emis';

    protected $fillable = [
        'association_id',
        'numero',
        'annee_civile',
        'tiers_id',
        'transaction_ligne_id',
        'montant_centimes',
        'date_versement',
        'mode_versement',
        'forme_don',
        'article_cgi',
        'pdf_path',
        'pdf_hash',
        'emitted_at',
        'emitted_by_user_id',
        'annule_at',
        'annule_motif',
        'remplace_par_id',
    ];

    protected $casts = [
        'annee_civile' => 'integer',
        'montant_centimes' => 'integer',
        'date_versement' => 'date',
        'emitted_at' => 'datetime',
        'annule_at' => 'datetime',
    ];

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function transactionLigne(): BelongsTo
    {
        return $this->belongsTo(TransactionLigne::class);
    }

    public function emittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitted_by_user_id');
    }

    public function remplacePar(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_par_id');
    }

    public function isAnnule(): bool
    {
        return $this->annule_at !== null;
    }

    public function isActif(): bool
    {
        return ! $this->isAnnule();
    }

    public function pdfFullPath(): string
    {
        return "associations/{$this->association_id}/{$this->pdf_path}";
    }

    public function verifierIntegrite(): bool
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($this->pdfFullPath())) {
            return false;
        }

        return hash('sha256', $disk->get($this->pdfFullPath())) === $this->pdf_hash;
    }
}
