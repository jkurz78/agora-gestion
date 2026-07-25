<?php

declare(strict_types=1);

namespace App\Livewire\Compta;

use App\DTOs\Compta\PosteTiersReglementData;
use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Services\ExerciceService;
use App\Support\MontantDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
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

        $this->validate([
            'montant' => ['required', 'string'],
            'dateReglement' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::enum(ModePaiement::class)],
            'compteBancaireId' => ['nullable', 'integer', Rule::exists('comptes_bancaires', 'id')],
        ], [
            'montant.required' => 'Le montant du règlement est obligatoire.',
            'dateReglement.required' => 'La date du règlement est obligatoire.',
            'dateReglement.date_format' => 'La date du règlement est invalide.',
            'mode.required' => 'Le mode de paiement est obligatoire.',
            'mode.enum' => 'Le mode de paiement est invalide.',
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
        $this->reset(['ligneId', 'montant', 'dateReglement', 'mode', 'compteBancaireId', 'titre', 'posteOrigine']);
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.compta.poste-tiers-reglement-modal', [
            'comptesBancaires' => CompteBancaire::query()->saisieManuelle()->orderBy('nom')->get(),
            'modesPaiement' => ModePaiement::cases(),
        ]);
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
}
