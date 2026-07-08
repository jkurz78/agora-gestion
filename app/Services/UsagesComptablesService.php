<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UsagesComptablesService
{
    public function setFraisKilometriques(?int $compteId): void
    {
        $this->setMono(UsageComptable::FraisKilometriques, $compteId);
    }

    public function setAbandonCreance(?int $compteId): void
    {
        if ($compteId !== null) {
            $compte = Compte::findOrFail($compteId);
            if (! $compte->hasUsage(UsageComptable::Don)) {
                throw new DomainException('Le compte doit être un Don pour être désigné comme abandon de créance.');
            }
        }
        $this->setMono(UsageComptable::AbandonCreance, $compteId);
    }

    public function toggleDon(int $compteId, bool $active): void
    {
        $this->toggle(UsageComptable::Don, $compteId, $active);
        if (! $active) {
            // cascade : retirer AbandonCreance si elle était posée
            $this->toggle(UsageComptable::AbandonCreance, $compteId, false);
        }
    }

    public function toggleCotisation(int $compteId, bool $active): void
    {
        $this->toggle(UsageComptable::Cotisation, $compteId, $active);
    }

    public function toggleInscription(int $compteId, bool $active): void
    {
        $this->toggle(UsageComptable::Inscription, $compteId, $active);
    }

    /**
     * Création "from scratch" d'une ventilation : reste côté SousCategorie
     * (échafaudage DC-8, disparaît en DC-10) — le miroir Compte est matérialisé
     * par l'observer dès que code_cerfa (classe 6/7) est posé, et le trait
     * SyncCompteDepuisSousCategorie remplit compte_id sur le lien d'usage.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function createAndFlag(array $attrs, UsageComptable $usage): SousCategorie
    {
        return DB::transaction(function () use ($attrs, $usage): SousCategorie {
            $sc = SousCategorie::create(array_merge(
                ['association_id' => TenantContext::currentId()],
                $attrs,
            ));
            $this->ensureLinkSousCategorie($usage, (int) $sc->id);
            if ($usage === UsageComptable::AbandonCreance) {
                $this->ensureLinkSousCategorie(UsageComptable::Don, (int) $sc->id);
            }

            return $sc;
        });
    }

    private function setMono(UsageComptable $usage, ?int $compteId): void
    {
        DB::transaction(function () use ($usage, $compteId): void {
            UsageSousCategorie::where('usage', $usage->value)->delete();
            if ($compteId !== null) {
                Compte::findOrFail($compteId);
                $this->ensureLink($usage, $compteId);
            }
        });
    }

    private function toggle(UsageComptable $usage, int $compteId, bool $active): void
    {
        DB::transaction(function () use ($usage, $compteId, $active): void {
            $compte = Compte::findOrFail($compteId);
            if ($active) {
                $this->ensureLink($usage, $compte->id);
            } else {
                UsageSousCategorie::where('compte_id', $compte->id)
                    ->where('usage', $usage->value)
                    ->delete();
            }
        });
    }

    private function ensureLink(UsageComptable $usage, int $compteId): void
    {
        UsageSousCategorie::firstOrCreate([
            'association_id' => TenantContext::currentId(),
            'compte_id' => $compteId,
            'usage' => $usage->value,
        ]);
    }

    /**
     * Variante côté sous-catégorie pour createAndFlag() — le trait remplit
     * compte_id en miroir. Échafaudage DC-8, disparaît en DC-10.
     */
    private function ensureLinkSousCategorie(UsageComptable $usage, int $sousCategorieId): void
    {
        UsageSousCategorie::firstOrCreate([
            'association_id' => TenantContext::currentId(),
            'sous_categorie_id' => $sousCategorieId,
            'usage' => $usage->value,
        ]);
    }
}
