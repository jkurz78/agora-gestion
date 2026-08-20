<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\UsageCompte;
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

    /**
     * Compte de contrepartie des gratuités accordées (709A par défaut).
     *
     * Contrairement à setAbandonCreance, aucun pré-requis d'usage n'est exigé :
     * une gratuité est une contrepartie de produit, pas un sous-cas de don.
     */
    public function setGratuite(?int $compteId): void
    {
        $this->setMono(UsageComptable::Gratuite, $compteId);
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
     * Création d'un compte de résultat directement flaggé d'un usage.
     *
     * @param  array{intitule: string, numero_pcg: string}  $attrs
     */
    public function createAndFlag(array $attrs, UsageComptable $usage): Compte
    {
        $numero = trim((string) $attrs['numero_pcg']);
        $classeAttendue = $usage->polarite() === TypeCategorie::Depense ? 6 : 7;

        if ($numero === '' || (int) substr($numero, 0, 1) !== $classeAttendue) {
            throw new DomainException("Le numéro de compte doit être de classe {$classeAttendue} pour cet usage.");
        }

        return DB::transaction(function () use ($attrs, $usage, $numero, $classeAttendue): Compte {
            $associationId = TenantContext::currentId();

            // Réutilise un compte existant plutôt que de heurter l'unicité
            // (association_id, numero_pcg) — plus accueillant qu'une violation SQL.
            $compte = Compte::withoutGlobalScopes()
                ->where('association_id', $associationId)
                ->where('numero_pcg', $numero)
                ->first();

            if ($compte === null) {
                $compte = Compte::create([
                    'association_id' => $associationId,
                    'numero_pcg' => $numero,
                    'intitule' => $attrs['intitule'],
                    'classe' => $classeAttendue,
                    'actif' => true,
                    'est_systeme' => false,
                    'pour_inscriptions' => $usage === UsageComptable::Inscription,
                    'lettrable' => false,
                ]);
            }

            $this->ensureLink($usage, (int) $compte->id);
            if ($usage === UsageComptable::AbandonCreance) {
                $this->ensureLink(UsageComptable::Don, (int) $compte->id);
            }

            return $compte;
        });
    }

    private function setMono(UsageComptable $usage, ?int $compteId): void
    {
        DB::transaction(function () use ($usage, $compteId): void {
            UsageCompte::where('usage', $usage->value)->delete();
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
                UsageCompte::where('compte_id', $compte->id)
                    ->where('usage', $usage->value)
                    ->delete();
            }
        });
    }

    private function ensureLink(UsageComptable $usage, int $compteId): void
    {
        UsageCompte::firstOrCreate([
            'association_id' => TenantContext::currentId(),
            'compte_id' => $compteId,
            'usage' => $usage->value,
        ]);
    }
}
