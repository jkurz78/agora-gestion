<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrigineANouveau;
use App\Exceptions\Compta\ANouveauInvalideException;
use App\Models\ANouveauGeneration;
use App\Models\Association;
use App\Models\User;
use App\Services\Compta\ANouveau\ANouveauPreview;
use App\Services\Compta\ANouveau\ANouveauService;
use App\Services\Compta\ANouveau\BootstrapANouveauService;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

final class BootstrapANouveauCommand extends Command
{
    protected $signature = 'compta:bootstrap-an
                            {--association= : Identifiant de l’association à reprendre}
                            {--acteur= : Identifiant ou courriel de l’acteur}
                            {--exercice= : Exercice cible, par exemple 2025}
                            {--dry-run : Affiche l’aperçu sans aucune écriture}
                            {--confirmer : Confirme explicitement la création}
                            {--meme-jour= : Arbitrage des mouvements à la date du solde initial (inclus ou exclus)}';

    protected $description = 'Prépare ou crée la reprise initiale contrôlée des à-nouveaux.';

    public function __construct(
        private readonly BootstrapANouveauService $bootstrapService,
        private readonly ANouveauService $aNouveauService,
        private readonly ExerciceService $exerciceService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $associationOption = $this->option('association');
        if ($associationOption === null) {
            $this->error('L’option --association est obligatoire.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run') === (bool) $this->option('confirmer')) {
            $this->error('Choisissez soit --dry-run, soit --confirmer.');

            return self::FAILURE;
        }

        $modeMemeJour = $this->option('meme-jour');
        if ($modeMemeJour !== null && ! in_array($modeMemeJour, ['inclus', 'exclus'], true)) {
            $this->error('--meme-jour doit valoir inclus ou exclus.');

            return self::FAILURE;
        }

        $association = Association::query()->find((int) $associationOption);
        if ($association === null) {
            $this->error('Association introuvable.');

            return self::FAILURE;
        }

        $precedente = TenantContext::current();

        try {
            TenantContext::clear();
            TenantContext::boot($association);

            $exercice = $this->option('exercice') !== null
                ? (int) $this->option('exercice')
                : $this->exerciceService->current();

            if (ANouveauGeneration::activePourCible($exercice) !== null) {
                $this->error("Une génération active existe déjà pour l’exercice {$exercice}.");

                return self::FAILURE;
            }

            $arbitrages = $modeMemeJour !== null ? ['meme_jour' => $modeMemeJour] : [];
            try {
                $preview = $this->bootstrapService->preview($exercice, $arbitrages);
            } catch (ANouveauInvalideException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->afficherApercu($preview);

            if ((bool) $this->option('dry-run')) {
                $this->info('DRY-RUN terminé : aucune écriture créée.');

                return self::SUCCESS;
            }

            $acteurOption = $this->option('acteur');
            if ($acteurOption === null) {
                $this->error('L’option --acteur est obligatoire avec --confirmer.');

                return self::FAILURE;
            }

            $acteur = ctype_digit((string) $acteurOption)
                ? User::query()->find((int) $acteurOption)
                : User::query()->where('email', (string) $acteurOption)->first();
            if ($acteur === null) {
                $this->error('Acteur introuvable.');

                return self::FAILURE;
            }
            if (! $acteur->associations()->whereKey($association->id)->exists()) {
                $this->error('L’acteur sélectionné n’appartient pas à cette association.');

                return self::FAILURE;
            }

            $generation = $this->aNouveauService->persister(
                $preview,
                OrigineANouveau::RepriseInitiale,
                $acteur,
            );

            $this->info("Reprise initiale créée : génération #{$generation->id}, pièce #{$generation->transaction_id}.");

            return self::SUCCESS;
        } finally {
            TenantContext::clear();
            if ($precedente !== null) {
                TenantContext::boot($precedente);
            }
        }
    }

    private function afficherApercu(ANouveauPreview $preview): void
    {
        $this->info("Reprise initiale vers l’exercice {$preview->exerciceCible} au {$preview->dateCible->format('d/m/Y')}");

        $details = $this->bootstrapService->detailsBancaires();
        if ($details !== []) {
            $this->table(
                ['Compte', 'Solde initial', 'Date référence', 'Mouvement même jour', 'Montant proposé'],
                array_map(static fn (array $ligne): array => [
                    $ligne['numero_pcg'],
                    $ligne['solde_initial'],
                    $ligne['date_reference'] ?? '—',
                    $ligne['mouvement_reference'],
                    $ligne['montant_propose'],
                ], $details),
            );
        }

        $this->table(
            ['Compte', 'Libellé', 'Tiers', 'Débit', 'Crédit'],
            array_map(static fn (array $ligne): array => [
                $ligne['numero_pcg'],
                $ligne['libelle'],
                $ligne['tiers_id'] ?? '—',
                $ligne['debit'],
                $ligne['credit'],
            ], $preview->lignes),
        );
        $this->line("Totaux : débit {$preview->totalDebit} — crédit {$preview->totalCredit}");
    }
}
