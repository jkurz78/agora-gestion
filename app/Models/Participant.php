<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DroitImage;
use App\Models\DocumentPrevisionnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

final class Participant extends Model
{
    protected $fillable = [
        'tiers_id',
        'operation_id',
        'type_operation_tarif_id',
        'date_inscription',
        'est_helloasso',
        'helloasso_item_id',
        'helloasso_order_id',
        'notes',
        'refere_par_id',
        'nom_jeune_fille',
        'nationalite',
        'adresse_par_nom',
        'adresse_par_prenom',
        'adresse_par_etablissement',
        'adresse_par_telephone',
        'adresse_par_email',
        'adresse_par_adresse',
        'adresse_par_code_postal',
        'adresse_par_ville',
        'droit_image',
        'mode_paiement_choisi',
        'moyen_paiement_choisi',
        'autorisation_contact_medecin',
        'rgpd_accepte_at',
        'medecin_tiers_id',
        'therapeute_tiers_id',
    ];

    protected function casts(): array
    {
        return [
            'date_inscription' => 'date',
            'est_helloasso' => 'boolean',
            'type_operation_tarif_id' => 'integer',
            'droit_image' => DroitImage::class,
            'autorisation_contact_medecin' => 'boolean',
            'rgpd_accepte_at' => 'datetime',
            'refere_par_id' => 'integer',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function typeOperationTarif(): BelongsTo
    {
        return $this->belongsTo(TypeOperationTarif::class);
    }

    public function referePar(): BelongsTo
    {
        return $this->belongsTo(Tiers::class, 'refere_par_id');
    }

    public function medecinTiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class, 'medecin_tiers_id');
    }

    public function therapeuteTiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class, 'therapeute_tiers_id');
    }

    public function donneesMedicales(): HasOne
    {
        return $this->hasOne(ParticipantDonneesMedicales::class);
    }

    public function presences(): HasMany
    {
        return $this->hasMany(Presence::class);
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(Reglement::class);
    }

    public function formulaireToken(): HasOne
    {
        return $this->hasOne(FormulaireToken::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function documentsPrevisionnels(): HasMany
    {
        return $this->hasMany(DocumentPrevisionnel::class);
    }

    protected static function booted(): void
    {
        self::deleting(function (Participant $participant) {
            $dir = "participants/{$participant->id}";
            if (Storage::disk('local')->exists($dir)) {
                Storage::disk('local')->deleteDirectory($dir);
            }
        });
    }
}
