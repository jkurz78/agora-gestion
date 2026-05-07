<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageComptable;
use App\Traits\TenantStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class Association extends Model
{
    use HasFactory;
    use TenantStorage;

    protected $table = 'association';

    /** Flag to allow a one-shot slug change; not persisted to DB. */
    public ?bool $allowSlugChange = null;

    protected $attributes = [
        'eligible_recu_fiscal' => false,
    ];

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
        'wizard_state',
        'wizard_current_step',
        'devis_validite_jours',
        'eligible_recu_fiscal',
        'regime_fiscal_don',
        'objet_recu_fiscal',
        'rescrit_fiscal_numero',
        'rescrit_fiscal_date',
        'signataire_nom',
        'signataire_qualite',
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
            'wizard_state' => 'array',
            'wizard_current_step' => 'integer',
            'devis_validite_jours' => 'integer',
            'eligible_recu_fiscal' => 'boolean',
            'rescrit_fiscal_date' => 'date',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'association_user')
            ->withPivot(['role', 'joined_at', 'revoked_at'])
            ->withTimestamps();
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

    /**
     * Returns the association logo as a base64 data URI (e.g. "data:image/png;base64,...")
     * or null if no logo is set / file missing. Used in public-facing views (newsletter
     * confirmation pages) where the user is not authenticated — the standard
     * `/tenant-assets/*` route requires auth + signed URL.
     */
    public function brandingLogoDataUri(): ?string
    {
        $path = $this->brandingLogoFullPath();
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $contents);
    }

    public function sousCategoriesFor(UsageComptable $usage): Collection
    {
        return SousCategorie::forUsage($usage)->where('association_id', $this->id)->orderBy('nom')->get();
    }
}
