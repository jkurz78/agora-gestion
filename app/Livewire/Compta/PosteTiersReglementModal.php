<?php

declare(strict_types=1);

namespace App\Livewire\Compta;

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

final class PosteTiersReglementModal extends Component
{
    public int $exercice;

    public ?int $ligneId = null;

    public string $montant = '';

    public string $dateReglement = '';

    public string $mode = '';

    public ?int $compteBancaireId = null;

    public string $sensTresorerie = '';

    public string $titre = '';

    public string $posteOrigine = '';

    #[On('poste-tiers-reglement:ouvrir')]
    public function ouvrir(int $ligneId, int $exercice): void
    {
        $poste = app(PostesTiersOuvertsService::class)->trouver($ligneId, $exercice);
        $periode = app(ExerciceService::class)->dateRange($exercice);
        $aujourdhui = CarbonImmutable::today();

        $this->resetValidation();
        $this->exercice = $exercice;
        $this->ligneId = $ligneId;
        $this->montant = number_format($poste->soldeCentimes / 100, 2, ',', ' ');
        $this->dateReglement = $this->dateBorneDansExercice($aujourdhui, $periode['start'], $periode['end']);
        $this->mode = '';
        $this->compteBancaireId = null;
        $this->sensTresorerie = $poste->numeroCompte === '401' ? 'depense' : 'recette';
        $this->titre = 'Règlement tiers';
        $this->posteOrigine = implode(' — ', array_filter([
            $poste->numeroPiece,
            $poste->reference,
            $poste->libelle,
        ]));

        $this->dispatch('poste-tiers-reglement-modal-open');
    }

    public function enregistrer(): void
    {
        $this->resetValidation();

        if (! $this->compteBancaireRequis()) {
            $this->compteBancaireId = null;
        }

        $this->validate([
            'montant' => ['required', 'string'],
            'dateReglement' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::enum(ModePaiement::class)],
            'compteBancaireId' => [
                Rule::requiredIf($this->compteBancaireRequis()),
                'nullable',
                'integer',
                Rule::exists('comptes_bancaires', 'id')
                    ->where('association_id', (int) TenantContext::currentId())
                    ->where('actif_recettes_depenses', 1)
                    ->where('saisie_automatisee', 0),
            ],
        ], [
            'montant.required' => 'Le montant du règlement est obligatoire.',
            'dateReglement.required' => 'La date du règlement est obligatoire.',
            'dateReglement.date_format' => 'La date du règlement est invalide.',
            'mode.required' => 'Le mode de paiement est obligatoire.',
            'mode.enum' => 'Le mode de paiement est invalide.',
            'compteBancaireId.required' => 'Ce mode de règlement nécessite un compte bancaire.',
            'compteBancaireId.exists' => 'Le compte bancaire sélectionné est introuvable.',
        ]);

        if ($this->ligneId === null) {
            $this->addError('reglement', 'Aucun poste tiers n’est sélectionné.');

            return;
        }

        try {
            $montantCentimes = $this->montantEnCentimes();
        } catch (InvalidArgumentException) {
            $this->addError('montant', 'Le montant du règlement est invalide.');

            return;
        }

        $poste = app(PostesTiersOuvertsService::class)->trouver($this->ligneId, $this->exercice);
        if ($montantCentimes <= 0) {
            $this->addError('montant', 'Le montant du règlement doit être strictement positif.');

            return;
        }

        if ($montantCentimes > $poste->soldeCentimes) {
            $this->addError('montant', 'Le montant du règlement ne peut pas dépasser le solde restant.');

            return;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->dateReglement);
        $periode = app(ExerciceService::class)->dateRange($this->exercice);
        if ($date->lt($periode['start']) || $date->gt($periode['end'])) {
            $this->addError(
                'dateReglement',
                'La date du règlement doit appartenir à l’exercice '
                .app(ExerciceService::class)->label($this->exercice).'.',
            );

            return;
        }

        try {
            app(PosteTiersReglementService::class)->regler(new PosteTiersReglementData(
                ligneId: $this->ligneId,
                montantCentimes: $montantCentimes,
                date: $date,
                mode: ModePaiement::from($this->mode),
                compteBancaireId: $this->compteBancaireId,
                exercice: $this->exercice,
            ));
        } catch (InvalidArgumentException $exception) {
            $this->addError('reglement', $exception->getMessage());

            return;
        } catch (RuntimeException $exception) {
            $this->addError('reglement', $exception->getMessage());

            return;
        }

        $this->dispatch('poste-tiers-reglement:enregistre');
        $this->fermer();
    }

    public function fermer(): void
    {
        $this->dispatch('poste-tiers-reglement-modal-close');
        $this->reset([
            'ligneId',
            'montant',
            'dateReglement',
            'mode',
            'compteBancaireId',
            'sensTresorerie',
            'titre',
            'posteOrigine',
        ]);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.compta.poste-tiers-reglement-modal', [
            'aideCompteBancaire' => $this->aideCompteBancaire(),
            'compteBancaireRequis' => $this->compteBancaireRequis(),
            'comptesBancaires' => CompteBancaire::query()->saisieManuelle()->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
    }

    public function compteBancaireRequis(): bool
    {
        $mode = ModePaiement::tryFrom($this->mode);

        if ($mode === null) {
            return false;
        }

        if (in_array($mode, [ModePaiement::Virement, ModePaiement::Cb, ModePaiement::Prelevement], true)) {
            return true;
        }

        return $mode === ModePaiement::Cheque && $this->sensTresorerie === 'depense';
    }

    public function updatedMode(): void
    {
        $this->resetValidation('compteBancaireId');

        if (! $this->compteBancaireRequis()) {
            $this->compteBancaireId = null;

            return;
        }

        if ($this->compteBancaireId !== null && $this->compteBancaireSelectionnable($this->compteBancaireId)) {
            return;
        }

        $this->compteBancaireId = $this->compteBancairePropose();
    }

    private function montantEnCentimes(): int
    {
        $montant = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $this->montant);

        return MontantDecimal::versCentimes(str_replace(',', '.', $montant));
    }

    private function dateBorneDansExercice(
        CarbonImmutable $date,
        CarbonImmutable $debut,
        CarbonImmutable $fin,
    ): string {
        if ($date->lt($debut)) {
            return $debut->toDateString();
        }

        if ($date->gt($fin)) {
            return $fin->toDateString();
        }

        return $date->toDateString();
    }

    private function aideCompteBancaire(): string
    {
        $mode = ModePaiement::tryFrom($this->mode);

        if ($mode === null) {
            return 'Requis pour virement, carte, prélèvement et chèque fournisseur ; inutile pour espèces et chèque reçu.';
        }

        if ($this->compteBancaireRequis()) {
            return 'Ce mode mouvemente directement un compte 512 : choisissez la banque concernée.';
        }

        if ($mode === ModePaiement::Especes) {
            return 'Les espèces utilisent le compte caisse 530 : aucun compte bancaire n’est nécessaire.';
        }

        if ($mode === ModePaiement::Cheque && $this->sensTresorerie === 'recette') {
            return 'Le chèque reçu est porté en 5112 “chèques à encaisser” jusqu’à remise en banque.';
        }

        return 'Sélectionnez un compte bancaire uniquement lorsqu’il est nécessaire au règlement.';
    }

    private function compteBancairePropose(): ?int
    {
        return $this->dernierCompteBancaireUtilise()
            ?? $this->compteBancaireParDefaut();
    }

    private function dernierCompteBancaireUtilise(): ?int
    {
        $compteId = Transaction::query()
            ->whereNotNull('compte_id')
            ->where('journal', 'banque')
            ->whereHas('compte', function (Builder $query): void {
                $query
                    ->where('actif_recettes_depenses', true)
                    ->where('saisie_automatisee', false);
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('compte_id');

        return $compteId !== null ? (int) $compteId : null;
    }

    private function compteBancaireParDefaut(): ?int
    {
        $associationId = TenantContext::currentId();
        if ($associationId === null) {
            return null;
        }

        $compteId = Association::query()
            ->whereKey($associationId)
            ->value('facture_compte_bancaire_id');

        if ($compteId === null || ! $this->compteBancaireSelectionnable((int) $compteId)) {
            return null;
        }

        return (int) $compteId;
    }

    private function compteBancaireSelectionnable(int $compteBancaireId): bool
    {
        return CompteBancaire::query()
            ->saisieManuelle()
            ->whereKey($compteBancaireId)
            ->exists();
    }
}
