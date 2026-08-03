<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModePaiement;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\User;
use App\Services\Compta\EcritureGenerator;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Seed de démonstration : 60 dépenses + 20 recettes janvier–février 2026.
 */
final class TransactionsJanFevSeeder extends Seeder
{
    /** @var list<ModePaiement> */
    private array $modes = [
        ModePaiement::Virement,
        ModePaiement::Cheque,
        ModePaiement::Especes,
        ModePaiement::Cb,
        ModePaiement::Prelevement,
    ];

    /** @var list<string> */
    private array $libellesDepense = [
        'Location salle janvier', 'Fournitures bureau', 'Frais bancaires',
        'Repas équipe', 'Transport participants', 'Honoraires formateur',
        'Supervision mensuelle', 'Petit équipement', 'Frais déplacement',
        'Animation séance', 'Location centre équestre', 'Bilan pré-thérapeutique',
        'Hébergement serveur', 'Logiciel gestion', 'Achat matériel',
        'Restauration groupe', 'Frais postaux', 'Sessions inter-ateliers',
        'Développement site', 'Réparation équipement',
    ];

    /** @var list<string> */
    private array $libellesRecette = [
        'Parcours thérapeutique janv.', 'Formation groupe', 'Subvention trimestrielle',
        'Vente produits', 'Prestation encadrement', 'Don reçu',
        'Intérêts compte', 'Cotisation membre', 'Abandon de créance',
        'Recette exceptionnelle',
    ];

    public function run(): void
    {
        if (! TenantContext::hasBooted()) {
            TenantContext::boot(Association::query()->sole());
        }

        $tiers = $this->resolveTiers();
        $operations = Operation::query()->get();
        $comptesDepense = Compte::where('classe', 6)->where('actif', true)->get();
        $comptesRecette = Compte::where('classe', 7)->where('actif', true)->get();
        $compteTresorerie = Compte::bancaires()->orderBy('numero_pcg')->firstOrFail();
        $compteBancaireId = $compteTresorerie->compte_bancaire_id;
        $userId = User::query()->orderBy('id')->value('id');

        if ($comptesDepense->isEmpty() || $comptesRecette->isEmpty()) {
            throw new \LogicException('Le plan comptable doit contenir des comptes actifs de classes 6 et 7.');
        }

        $this->ensureCaisseAccount();
        $generator = app(EcritureGenerator::class);

        for ($i = 0; $i < 60; $i++) {
            $mode = $this->modes[$i % count($this->modes)];
            $tiersCourant = $tiers->random();
            $compteVentilation = $comptesDepense->random();
            $operation = $i % 5 === 0 || $operations->isEmpty() ? null : $operations->random();
            $montant = $this->randomMontant(50, 2500);
            $libelle = $this->pick($this->libellesDepense).' '.($i + 1);

            $transaction = $generator->pourDepenseComptant(
                tiers: $tiersCourant,
                ventilations: [[
                    'compte' => $compteVentilation,
                    'montant' => $montant,
                    'operation_id' => $operation?->id,
                    'seance' => $operation !== null && $i % 3 === 0 ? random_int(1, 3) : null,
                ]],
                mode: $mode,
                compteTresorerie: $compteTresorerie,
                date: new CarbonImmutable($this->randomDate()),
                libelle: $libelle,
            );

            $transaction->update([
                'tiers_id' => $tiersCourant->id,
                'reference' => 'REF-D-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'compte_id' => $mode === ModePaiement::Especes ? null : $compteBancaireId,
                'saisi_par' => $userId,
            ]);
        }

        for ($i = 0; $i < 20; $i++) {
            $mode = $this->modes[$i % count($this->modes)];
            $tiersCourant = $tiers->random();
            $compteVentilation = $comptesRecette->random();
            $operation = $i % 4 === 0 || $operations->isEmpty() ? null : $operations->random();
            $montant = $this->randomMontant(100, 5000);
            $libelle = $this->pick($this->libellesRecette).' '.($i + 1);

            $transaction = $generator->pourRecetteComptant(
                tiers: $tiersCourant,
                ventilations: [[
                    'compte' => $compteVentilation,
                    'montant' => $montant,
                    'operation_id' => $operation?->id,
                    'seance' => $operation !== null && $i % 3 === 0 ? random_int(1, 3) : null,
                ]],
                mode: $mode,
                compteTresorerie: $compteTresorerie,
                date: new CarbonImmutable($this->randomDate()),
                libelle: $libelle,
            );

            $transaction->update([
                'tiers_id' => $tiersCourant->id,
                'reference' => 'REF-R-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'compte_id' => $mode === ModePaiement::Especes ? null : $compteBancaireId,
                'saisi_par' => $userId,
            ]);
        }
    }

    /** @return Collection<int, Tiers> */
    private function resolveTiers(): Collection
    {
        $tiers = Tiers::query()->get();
        if ($tiers->isNotEmpty()) {
            return $tiers;
        }

        return new Collection([
            Tiers::create([
                'association_id' => (int) TenantContext::currentId(),
                'type' => 'particulier',
                'nom' => 'Tiers démo',
                'pour_depenses' => true,
                'pour_recettes' => true,
            ]),
        ]);
    }

    private function ensureCaisseAccount(): void
    {
        Compte::firstOrCreate(
            [
                'association_id' => (int) TenantContext::currentId(),
                'numero_pcg' => '530',
            ],
            [
                'intitule' => 'Caisse (espèces)',
                'classe' => 5,
                'actif' => true,
                'est_systeme' => true,
                'pour_inscriptions' => false,
                'lettrable' => true,
            ],
        );
    }

    /** @param list<string> $values */
    private function pick(array $values): string
    {
        return $values[array_rand($values)];
    }

    private function randomDate(): string
    {
        $month = random_int(0, 1) === 0 ? '01' : '02';
        $maxDay = $month === '02' ? 28 : 31;

        return '2026-'.$month.'-'.str_pad((string) random_int(1, $maxDay), 2, '0', STR_PAD_LEFT);
    }

    private function randomMontant(int $min, int $max): float
    {
        return round(random_int($min * 100, $max * 100) / 100, 2);
    }
}
