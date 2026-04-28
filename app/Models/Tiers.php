<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class Tiers extends TenantModel implements AuthenticatableContract
{
    use Authenticatable, HasFactory;

    protected $fillable = [
        'association_id',
        'type',
        'nom',
        'prenom',
        'entreprise',
        'email',
        'telephone',
        'adresse_ligne1',
        'code_postal',
        'ville',
        'pays',
        'pour_depenses',
        'pour_recettes',
        'est_helloasso',
        'helloasso_nom',
        'helloasso_prenom',
        'email_optout',
    ];

    protected function casts(): array
    {
        return [
            'pour_depenses' => 'boolean',
            'pour_recettes' => 'boolean',
            'est_helloasso' => 'boolean',
            'email_optout' => 'boolean',
        ];
    }

    /**
     * Tiers n'a pas de colonne password — retourne une chaîne vide
     * pour satisfaire le contrat Authenticatable sans lever d'exception.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    /**
     * Tiers n'a pas de colonne remember_token — désactivé.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op — pas de remember_token sur Tiers
    }

    public function getNomAttribute(?string $value): ?string
    {
        return $value !== null ? mb_strtoupper($value) : null;
    }

    public function displayName(): string
    {
        if ($this->type === 'entreprise') {
            return $this->entreprise ?? $this->nom;
        }

        return trim(($this->prenom ? $this->prenom.' ' : '').$this->nom);
    }

    /**
     * Pour un tiers entreprise, renvoie le contact 'Nom Prénom' (ou null si vide).
     * Pour un tiers particulier, renvoie null (le nom est déjà la ligne principale).
     * Permet d'afficher 2 lignes sur les documents (devis, facture) :
     *   ligne 1 : raison sociale via displayName()
     *   ligne 2 : 'Contact : ' . displayContact()
     */
    public function displayContact(): ?string
    {
        if ($this->type !== 'entreprise') {
            return null;
        }

        $contact = trim(($this->prenom ? $this->prenom.' ' : '').($this->nom ?? ''));

        return $contact !== '' ? $contact : null;
    }

    /**
     * Return disambiguation suffixes for a collection of tiers.
     * Only homonymes get a non-empty suffix.
     *
     * @param  Collection<int, Tiers>  $collection
     * @return array<int, string> Map of tiers ID => suffix (empty string if no disambiguation needed)
     */
    public static function disambiguationSuffixes(Collection $collection): array
    {
        $grouped = $collection->groupBy(fn (Tiers $t): string => mb_strtolower($t->displayName()));
        $suffixes = [];

        foreach ($grouped as $group) {
            if ($group->count() === 1) {
                $suffixes[$group->first()->id] = '';

                continue;
            }

            foreach ($group as $t) {
                $suffixes[$t->id] = self::findSuffix($t, $group);
            }
        }

        return $suffixes;
    }

    /**
     * Build a disambiguated label for each tiers in a collection.
     * When multiple tiers share the same displayName(), a suffix is appended:
     * email > ville > code_postal > (#id) as last resort.
     *
     * @param  Collection<int, Tiers>  $collection
     * @return array<int, string> Map of tiers ID => disambiguated label
     */
    public static function disambiguate(Collection $collection): array
    {
        // Group by displayName to find homonymes
        $grouped = $collection->groupBy(fn (Tiers $t): string => mb_strtolower($t->displayName()));

        $labels = [];

        foreach ($grouped as $group) {
            if ($group->count() === 1) {
                $t = $group->first();
                $labels[$t->id] = $t->displayName();

                continue;
            }

            // Homonymes — find the best discriminator
            foreach ($group as $t) {
                $suffix = self::findSuffix($t, $group);
                $labels[$t->id] = $t->displayName().($suffix !== '' ? ' ('.$suffix.')' : '');
            }
        }

        return $labels;
    }

    /**
     * Find the best disambiguation suffix for a tiers within a group of homonymes.
     *
     * @param  Collection<int, Tiers>  $group
     */
    private static function findSuffix(Tiers $tiers, Collection $group): string
    {
        // Try email
        if (! empty($tiers->email)) {
            $othersEmails = $group->where('id', '!=', $tiers->id)->pluck('email')->filter()->map(fn ($e) => mb_strtolower($e))->all();
            if (! in_array(mb_strtolower($tiers->email), $othersEmails, true)) {
                return $tiers->email;
            }

            // Email exists but is not unique — still use it, it's informative
            return $tiers->email;
        }

        // Try ville
        if (! empty($tiers->ville)) {
            return $tiers->ville;
        }

        // Try code_postal
        if (! empty($tiers->code_postal)) {
            return $tiers->code_postal;
        }

        // Last resort: ID
        return '#'.$tiers->id;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
