<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UsageComptable;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

final class UsagesComptablesService
{
    public function setFraisKilometriques(?int $sousCategorieId): void
    {
        $this->setMono(UsageComptable::FraisKilometriques, $sousCategorieId);
    }

    public function setAbandonCreance(?int $sousCategorieId): void
    {
        if ($sousCategorieId !== null) {
            $sc = SousCategorie::findOrFail($sousCategorieId);
            if (! $sc->hasUsage(UsageComptable::Don)) {
                throw new DomainException('La sous-catégorie doit être un Don pour être désignée comme abandon de créance.');
            }
        }
        $this->setMono(UsageComptable::AbandonCreance, $sousCategorieId);
    }

    public function toggleDon(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Don, $sousCategorieId, $active);
        if (! $active) {
            // cascade : retirer AbandonCreance si elle était posée
            $this->toggle(UsageComptable::AbandonCreance, $sousCategorieId, false);
        }
    }

    public function toggleCotisation(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Cotisation, $sousCategorieId, $active);
    }

    public function toggleInscription(int $sousCategorieId, bool $active): void
    {
        $this->toggle(UsageComptable::Inscription, $sousCategorieId, $active);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function createAndFlag(array $attrs, UsageComptable $usage): SousCategorie
    {
        return DB::transaction(function () use ($attrs, $usage): SousCategorie {
            $sc = SousCategorie::create(array_merge(
                ['association_id' => TenantContext::currentId()],
                $attrs,
            ));
            $this->ensureLink($usage, $sc->id);
            if ($usage === UsageComptable::AbandonCreance) {
                $this->ensureLink(UsageComptable::Don, $sc->id);
            }

            return $sc;
        });
    }

    private function setMono(UsageComptable $usage, ?int $sousCategorieId): void
    {
        DB::transaction(function () use ($usage, $sousCategorieId): void {
            UsageSousCategorie::where('usage', $usage->value)->delete();
            if ($sousCategorieId !== null) {
                SousCategorie::findOrFail($sousCategorieId);
                $this->ensureLink($usage, $sousCategorieId);
            }
        });
    }

    private function toggle(UsageComptable $usage, int $sousCategorieId, bool $active): void
    {
        DB::transaction(function () use ($usage, $sousCategorieId, $active): void {
            $sc = SousCategorie::findOrFail($sousCategorieId);
            if ($active) {
                $this->ensureLink($usage, $sc->id);
            } else {
                UsageSousCategorie::where('sous_categorie_id', $sc->id)
                    ->where('usage', $usage->value)
                    ->delete();
            }
        });
    }

    private function ensureLink(UsageComptable $usage, int $sousCategorieId): void
    {
        UsageSousCategorie::firstOrCreate([
            'association_id' => TenantContext::currentId(),
            'sous_categorie_id' => $sousCategorieId,
            'usage' => $usage->value,
        ]);
    }
}
