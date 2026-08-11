<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Livewire\Immobilisations\Concerns\WithDureeSelector;
use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Tiers;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationIndex extends Component
{
    use WithDureeSelector;

    // ── État de la modale ────────────────────────────────────────
    public bool $showModal = false;

    public string $libelle = '';

    public int $quantite = 1;

    public string $compte_id = '';

    public string $compte_amortissement_id = '';

    public ?int $tiers_id = null;

    public string $montant = '';

    public string $date_achat = '';

    public string $date_mise_en_service = '';

    public int $duree_mois = 60;

    public string $notes = '';

    public string $flashMessage = '';

    public string $flashType = '';

    public function ouvrirModal(): void
    {
        $this->authorize('create', Immobilisation::class);

        // Créé à la demande (pas au provisionnement du tenant) : idempotent,
        // donc sans effet de bord si le kit existe déjà.
        ImmobilisationComptesSeeder::seed();

        $this->reset([
            'libelle', 'quantite', 'compte_id', 'compte_amortissement_id',
            'tiers_id', 'montant', 'date_achat', 'date_mise_en_service', 'notes',
        ]);
        $this->quantite = 1;
        $this->initDureeChoix(60);
        $this->date_achat = app(ExerciceService::class)->defaultDate();
        $this->date_mise_en_service = $this->date_achat;
        $this->resetValidation();
        $this->showModal = true;
    }

    public function fermerModal(): void
    {
        $this->showModal = false;
    }

    /** Dérive le compte 281X du compte 21X choisi (2154 → 28154). */
    public function updatedCompteId(string $value): void
    {
        $compte = Compte::find((int) $value);

        if ($compte === null) {
            return;
        }

        $derive = ImmobilisationComptesSeeder::compteAmortissementPour($compte);
        $this->compte_amortissement_id = $derive === null ? '' : (string) $derive->id;
    }

    /** La date d'achat pilote la mise en service tant que celle-ci n'a pas été touchée. */
    public function updatedDateAchat(string $value): void
    {
        if ($this->date_mise_en_service === '' || $this->date_mise_en_service < $value) {
            $this->date_mise_en_service = $value;
        }
    }

    public function enregistrer(): void
    {
        $this->authorize('create', Immobilisation::class);

        $this->validate([
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1'],
            'compte_id' => ['required', 'exists:comptes,id'],
            'compte_amortissement_id' => ['required', 'exists:comptes,id'],
            'tiers_id' => ['required', 'exists:tiers,id'],
            'montant' => ['required', 'numeric', 'gt:0'],
            'date_achat' => ['required', 'date'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'libelle' => 'libellé',
            'compte_id' => 'compte d’immobilisation',
            'compte_amortissement_id' => 'compte d’amortissement',
            'tiers_id' => 'fournisseur',
            'date_achat' => 'date d’achat',
            'date_mise_en_service' => 'date de mise en service',
            'duree_mois' => 'durée',
        ]);

        try {
            app(ImmobilisationService::class)->acquerir(
                tiers: Tiers::findOrFail((int) $this->tiers_id),
                libelle: $this->libelle,
                quantite: $this->quantite,
                compte: Compte::findOrFail((int) $this->compte_id),
                compteAmortissement: Compte::findOrFail((int) $this->compte_amortissement_id),
                montant: number_format((float) $this->montant, 2, '.', ''),
                dateAchat: CarbonImmutable::parse($this->date_achat),
                dateMiseEnService: CarbonImmutable::parse($this->date_mise_en_service),
                dureeMois: $this->duree_mois,
                modePaiement: null,
                compteTresorerie: null,
                notes: $this->notes === '' ? null : $this->notes,
            );
        } catch (MiseEnServiceAnterieureException $e) {
            $this->addError('date_mise_en_service', $e->getMessage());

            return;
        }

        $this->showModal = false;
        $this->flashMessage = 'Immobilisation enregistrée.';
        $this->flashType = 'success';
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function render(): View
    {
        /** @var Collection<int, Immobilisation> $immobilisations */
        $immobilisations = Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->get();

        return view('livewire.immobilisations.immobilisation-index', [
            'immobilisations' => $immobilisations,
            'totalBrutCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->montantAcquisitionCentimes()
            ),
            'totalCumulCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->cumulAmortiCentimes()
            ),
            'totalNetCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->valeurNetteCentimes()
            ),
            'comptesImmobilisation' => PlanComptableSelecteur::groupesPourType('immobilisation'),
            'dureesUsuelles' => self::DUREES_USUELLES,
        ])->layout('layouts.app-sidebar', ['title' => 'Immobilisations']);
    }
}
