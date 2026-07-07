<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * DC-1 du programme « dissolution sous_categories → comptes ».
 *
 * Une famille NOMME un préfixe à 2 chiffres (classes 6/7 du PCG). Le
 * rattachement compte → famille est DÉRIVÉ par préfixe (`Compte::famille()`)
 * — jamais de FK. Remplace la notion de `Categorie` (nom "NN - Libellé")
 * comme regroupement de premier niveau.
 */
final class Famille extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'association_id',
        'code',
        'nom',
    ];

    /** Libellé d'affichage « 70 — Ventes et prestations ». */
    public function libelle(): string
    {
        return $this->code.' — '.$this->nom;
    }

    /** true si famille de dépenses (6x), false si recettes (7x) — typage par construction. */
    public function estDepense(): bool
    {
        return str_starts_with($this->code, '6');
    }

    /**
     * Familles indexées par code pour un ensemble de comptes (chargement groupé, zéro N+1).
     *
     * @param  Collection<int, Compte>  $comptes
     * @return Collection<string, Famille>
     */
    public static function pourComptes(Collection $comptes): Collection
    {
        $codes = $comptes->map(fn (Compte $c): string => substr($c->numero_pcg, 0, 2))->unique()->values();

        return self::whereIn('code', $codes)->get()->keyBy('code');
    }
}
