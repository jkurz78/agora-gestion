<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class Association extends Model
{
    use HasFactory;
    use TenantStorage;

    protected $table = 'association';

    /** Flag to allow a one-shot slug change; not persisted to DB. */
    public ?bool $allowSlugChange = null;

    protected $fillable = [
        'nom',
        'slug',
        'adresse',
        'code_postal',
        'ville',
        'email',
        'telephone',
        'logo_path',
        'cachet_signature_path',
        'siret',
        'forme_juridique',
        'facture_conditions_reglement',
        'facture_mentions_legales',
        'facture_mentions_penalites',
        'facture_compte_bancaire_id',
        'anthropic_api_key',
        'email_from',
        'email_from_name',
        'exercice_mois_debut',
        'statut',
        'wizard_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'nom' => 'string',
            'slug' => 'string',
            'adresse' => 'string',
            'code_postal' => 'string',
            'ville' => 'string',
            'email' => 'string',
            'telephone' => 'string',
            'logo_path' => 'string',
            'cachet_signature_path' => 'string',
            'facture_compte_bancaire_id' => 'integer',
            'anthropic_api_key' => 'encrypted',
            'email_from' => 'string',
            'email_from_name' => 'string',
            'exercice_mois_debut' => 'integer',
            'statut' => 'string',
            'wizard_completed_at' => 'datetime',
        ];
    }

    /**
     * Override TenantStorage::storagePath() because Association IS the tenant
     * (uses its own id instead of association_id).
     */
    public function storagePath(string $suffix): string
    {
        if (str_contains($suffix, '..')) {
            throw new InvalidArgumentException('Path traversal interdit.');
        }

        return 'associations/'.$this->id.'/'.ltrim($suffix, '/');
    }

    /**
     * Full local-disk path for the association logo, or null if not set.
     */
    public function brandingLogoFullPath(): ?string
    {
        return $this->logo_path
            ? $this->storagePath('branding/'.basename($this->logo_path))
            : null;
    }

    /**
     * Full local-disk path for the cachet/signature, or null if not set.
     */
    public function brandingCachetFullPath(): ?string
    {
        return $this->cachet_signature_path
            ? $this->storagePath('branding/'.basename($this->cachet_signature_path))
            : null;
    }
}
