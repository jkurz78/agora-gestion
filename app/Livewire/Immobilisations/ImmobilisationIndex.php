<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Enums\Espace;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\Sens;
use App\Exceptions\Immobilisation\MiseEnServiceAnterieureException;
use App\Livewire\Concerns\MontantValidation;
use App\Livewire\Concerns\WithPerPage;
use App\Livewire\Immobilisations\Concerns\WithDureeSelector;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Immobilisation;
use App\Models\ImmobilisationDotation;
use App\Models\Tiers;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use App\Services\Immobilisation\ImmobilisationComptesSeeder;
use App\Services\Immobilisation\ImmobilisationService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class ImmobilisationIndex extends Component
{
    use WithDureeSelector;
    use WithFileUploads;
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

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

    /** Réglé immédiatement (true) ou à crédit, dette fournisseur ouverte (false, comportement par défaut). */
    public bool $regleImmediatement = false;

    public string $mode_paiement = '';

    /** FK `comptes_bancaires.id` — le compte bancaire portant le règlement, résolu en compte 512X. */
    public ?int $compte_reglement_id = null;

    /**
     * Date du règlement. Suit la date d'achat tant qu'on n'y touche pas — payer
     * le jour de l'achat est le cas courant — mais reste saisissable : une
     * facture réglée quelques jours plus tard produit sinon un décaissement
     * daté du mauvais jour, invisible au rapprochement bancaire.
     */
    public string $date_reglement = '';

    /** @var TemporaryUploadedFile|null */
    public $pieceJointeAcquisition = null;

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
            'regleImmediatement', 'mode_paiement', 'compte_reglement_id', 'pieceJointeAcquisition',
            'date_reglement',
        ]);
        $this->quantite = 1;
        $this->initDureeChoix(60);
        $this->date_achat = app(ExerciceService::class)->defaultDate();
        $this->date_mise_en_service = $this->date_achat;
        $this->date_reglement = $this->date_achat;
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

    /**
     * La date d'achat pilote la mise en service et le règlement tant que
     * ceux-ci n'ont pas été touchés — payer et mettre en service le jour de
     * l'achat est le cas courant, et retaper trois fois la même date est une
     * corvée. Dès que l'utilisateur pose une date postérieure, elle est
     * respectée.
     */
    public function updatedDateAchat(string $value): void
    {
        if ($this->date_mise_en_service === '' || $this->date_mise_en_service < $value) {
            $this->date_mise_en_service = $value;
        }

        if ($this->date_reglement === '' || $this->date_reglement < $value) {
            $this->date_reglement = $value;
        }
    }

    public function enregistrer(): void
    {
        $this->authorize('create', Immobilisation::class);

        // Saisie française : « 3 000,50 » ou « 3000,50 » — même normalisation
        // que PosteTiersReglementModal::montantCentimes() avant validation,
        // plutôt que de réinventer la règle. Les trois espaces retirés sont
        // ceux que produisent réellement les sources courantes : l'espace
        // ASCII, l'insécable (U+00A0) et l'insécable fine (U+202F), celle que
        // pose number_format() en locale fr — sans quoi la règle `numeric`
        // rejette une saisie pourtant valide.
        $this->montant = str_replace(
            [' ', "\u{00A0}", "\u{202F}", ','],
            ['', '', '', '.'],
            $this->montant
        );

        $this->validate([
            // Bornes alignées sur les colonnes SQL réelles (migration
            // 2026_08_04_100001_create_immobilisations_tables) : un
            // dépassement doit produire un message de validation en
            // français, pas une erreur SQL brutale.
            //   - libelle : varchar(255)
            //   - quantite : unsignedInteger (max MySQL 4294967295)
            //   - montant : decimal(10,2) (max 99999999.99)
            //   - duree_mois : unsignedSmallInteger (max 65535) — 600 déjà
            //     appliqué est une borne métier plus stricte, donc déjà sûre.
            'libelle' => ['required', 'string', 'max:255'],
            'quantite' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'compte_id' => ['required', 'exists:comptes,id'],
            'compte_amortissement_id' => ['required', 'exists:comptes,id'],
            'tiers_id' => ['required', 'exists:tiers,id'],
            'montant' => ['required', 'numeric', MontantValidation::RULE, 'max:99999999.99'],
            'date_achat' => ['required', 'date'],
            'date_mise_en_service' => ['required', 'date'],
            'duree_mois' => ['required', 'integer', 'min:1', 'max:600'],
            'mode_paiement' => [
                Rule::requiredIf(fn (): bool => $this->regleImmediatement),
                'nullable',
                'in:virement,cheque,especes,cb,prelevement',
            ],
            'compte_reglement_id' => ['nullable', 'exists:comptes_bancaires,id'],
            // Un règlement antérieur à l'achat n'a pas de sens comptable ; il
            // peut en revanche lui être postérieur (facture réglée à 30 jours).
            'date_reglement' => [
                Rule::requiredIf(fn (): bool => $this->regleImmediatement),
                'nullable',
                'date',
                'after_or_equal:date_achat',
            ],
            'pieceJointeAcquisition' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'notes' => ['nullable', 'string'],
        ], MontantValidation::messages(['montant']), [
            'libelle' => 'libellé',
            'quantite' => 'quantité',
            'compte_id' => 'compte d’immobilisation',
            'compte_amortissement_id' => 'compte d’amortissement',
            'tiers_id' => 'fournisseur',
            'date_achat' => 'date d’achat',
            'date_mise_en_service' => 'date de mise en service',
            'duree_mois' => 'durée',
            'mode_paiement' => 'mode de paiement',
            'compte_reglement_id' => 'compte bancaire',
            'date_reglement' => 'date de règlement',
            'pieceJointeAcquisition' => 'justificatif',
        ]);

        $modePaiement = null;
        $compteTresorerie = null;

        if ($this->regleImmediatement) {
            $modePaiement = ModePaiement::from($this->mode_paiement);

            // Certains modes (virement, chèque, CB, prélèvement) exigent un
            // compte bancaire physique 512X ; les espèces n'en ont pas besoin
            // (portage automatique sur la caisse 530). La règle vit dans
            // CompteTresorerieResolver — on ne la réinvente pas ici.
            $compteTresorerie = CompteTresorerieResolver::resoudre(
                compteBancaireId: $this->compte_reglement_id,
                mode: $modePaiement,
                contextLog: 'ImmobilisationIndex',
                sens: Sens::Depense,
            );

            if ($compteTresorerie === null) {
                $this->addError('compte_reglement_id', 'Ce mode de paiement nécessite un compte bancaire.');

                return;
            }
        }

        try {
            $immobilisation = app(ImmobilisationService::class)->acquerir(
                tiers: Tiers::findOrFail((int) $this->tiers_id),
                libelle: $this->libelle,
                quantite: $this->quantite,
                compte: Compte::findOrFail((int) $this->compte_id),
                compteAmortissement: Compte::findOrFail((int) $this->compte_amortissement_id),
                montant: number_format((float) $this->montant, 2, '.', ''),
                dateAchat: CarbonImmutable::parse($this->date_achat),
                dateMiseEnService: CarbonImmutable::parse($this->date_mise_en_service),
                dureeMois: $this->duree_mois,
                modePaiement: $modePaiement,
                compteTresorerie: $compteTresorerie,
                notes: $this->notes === '' ? null : $this->notes,
                dateReglement: $this->regleImmediatement && $this->date_reglement !== ''
                    ? CarbonImmutable::parse($this->date_reglement)
                    : null,
            );
        } catch (MiseEnServiceAnterieureException $e) {
            $this->addError('date_mise_en_service', $e->getMessage());

            return;
        }

        // Le justificatif n'est écrit sur le disque qu'une fois la fiche et son
        // écriture comptable actées : si acquerir() avait échoué, on ne serait
        // jamais arrivé ici, donc aucun fichier orphelin ne peut subsister.
        //
        // Contre-audit P2-02 — le contrat est explicite : le justificatif est
        // une pièce annexe, pas une condition de l'acquisition. Un échec de
        // stockage (disque plein, permissions) ne doit donc pas remonter en
        // erreur alors que la fiche et ses écritures sont déjà commitées :
        // l'utilisateur croirait sa saisie perdue et la referait, créant un
        // doublon comptable bien plus coûteux qu'un justificatif manquant.
        $echecJustificatif = false;

        if ($this->pieceJointeAcquisition !== null) {
            try {
                app(TransactionService::class)->storePieceJointe(
                    $immobilisation->transaction,
                    $this->pieceJointeAcquisition
                );
            } catch (\Throwable $e) {
                $echecJustificatif = true;

                Log::error('immobilisation.justificatif_non_stocke', [
                    'immobilisation_id' => (int) $immobilisation->id,
                    'numero' => $immobilisation->numero,
                    'transaction_id' => (int) $immobilisation->transaction_id,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }

        $this->showModal = false;

        // Message sans jargon, qui dit ce qui s'est passé et quoi faire.
        $this->flashMessage = $echecJustificatif
            ? 'Immobilisation enregistrée, mais le justificatif n’a pas pu être joint. '
                .'Vous pouvez le rattacher depuis la transaction d’acquisition.'
            : 'Immobilisation enregistrée.';
        $this->flashType = $echecJustificatif ? 'warning' : 'success';
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    public function render(): View
    {
        /** @var LengthAwarePaginator<int, Immobilisation> $immobilisations */
        $immobilisations = Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->paginate($this->effectivePerPage());

        // Totaux de l'ensemble du registre, jamais seulement de la page
        // affichée : une agrégation SQL séparée, indépendante de la
        // pagination — sommer la page courante donnerait un total faux.
        $totalBrutCentimes = (int) round(
            ((float) Immobilisation::query()->sum('montant_acquisition')) * 100
        );
        $totalCumulCentimes = (int) round(
            ((float) ImmobilisationDotation::query()->sum('montant')) * 100
        );

        return view('livewire.immobilisations.immobilisation-index', [
            'immobilisations' => $immobilisations,
            'totalBrutCentimes' => $totalBrutCentimes,
            'totalCumulCentimes' => $totalCumulCentimes,
            'totalNetCentimes' => $totalBrutCentimes - $totalCumulCentimes,
            'comptesImmobilisation' => PlanComptableSelecteur::groupesPourType('immobilisation'),
            'dureesUsuelles' => self::DUREES_USUELLES,
            'modesPaiement' => ModePaiement::cases(),
            'comptesBancaires' => CompteBancaire::saisieManuelle()->orderBy('nom')->get(),
        ])->layout('layouts.app-sidebar', ['title' => 'Immobilisations']);
    }
}
