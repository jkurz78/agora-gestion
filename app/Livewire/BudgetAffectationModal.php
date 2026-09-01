<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Models\BudgetLine;
use App\Models\Compte;
use App\Models\Operation;
use App\Services\BudgetService;
use App\Services\Compta\PlanComptableSelecteur;
use App\Services\ExerciceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Affectation d'un budget à une opération, en une passe.
 *
 * Le geste réel n'est pas « ventiler un compte » mais « donner son budget à une
 * opération », qui en touche cinq ou six. On choisit l'opération une fois, on
 * saisit tous ses comptes, on enregistre une fois.
 *
 * Une seule propriété tableau et un seul enregistrement transactionnel : une
 * synchronisation live par cellule ferait une centaine d'allers-retours Livewire.
 * Le "restant à ventiler" par ligne et les totaux "prévu" par section (dérivés
 * de la saisie) sont recalculés pendant la frappe en JS vanilla, dans la vue —
 * voir le <script> de budget-affectation-modal.blade.php. Le "réalisé", lui,
 * est un fait : il ne bouge jamais côté client, seul le serveur le calcule.
 * Toutes ces valeurs restent des valeurs serveur figées au dernier rendu ; le
 * serveur recalcule tout à l'enregistrement et reste seul juge.
 */
final class BudgetAffectationModal extends Component
{
    use RespectsExerciceCloture;

    public bool $ouverte = false;

    public ?int $operationId = null;

    public string $filtre = '';

    /** @var array<int, string> compte_id => montant saisi */
    public array $montants = [];

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    #[On('ouvrir-affectation')]
    public function ouvrir(int $operationId): void
    {
        // Le bouton générique de l'écran Budget dispatche 0 (« aucune opération
        // choisie » : le sélecteur de la modale reste vide). Une badge du bandeau
        // « sans budget affecté » dispatche un vrai id.
        $this->operationId = $operationId > 0 ? $operationId : null;
        $this->filtre = '';
        $this->ouverte = true;

        $this->chargerMontants();
    }

    /**
     * Recharge les montants déjà ventilés pour l'opération courante.
     *
     * Appelé par {@see ouvrir()} pour l'ouverture initiale, et par
     * {@see updatedOperationId()} quand l'utilisateur change d'opération via le
     * sélecteur de la modale — sans quoi les cellules resteraient vides pour une
     * opération déjà partiellement budgétée, ce qui masquerait ses montants
     * existants au lieu de les proposer à la modification.
     */
    private function chargerMontants(): void
    {
        if ($this->operationId === null) {
            $this->montants = [];

            return;
        }

        $exercice = app(ExerciceService::class)->current();

        $this->montants = BudgetLine::forExercice($exercice)
            ->where('operation_id', $this->operationId)
            ->get()
            ->mapWithKeys(fn (BudgetLine $l): array => [(int) $l->compte_id => (string) $l->montant_prevu])
            ->all();
    }

    /**
     * Sélection d'une opération via le menu déroulant de la modale (par
     * opposition à {@see ouvrir()}, qui l'ouvre déjà positionnée sur une
     * opération précise). Livewire n'appelle ce hook que pour un changement
     * poussé par le front (wire:model.live), jamais pour l'affectation directe
     * faite dans ouvrir() — les deux chemins restent donc indépendants.
     */
    public function updatedOperationId(): void
    {
        $this->chargerMontants();
    }

    public function fermer(): void
    {
        $this->ouverte = false;
        $this->operationId = null;
        $this->montants = [];
    }

    public function enregistrer(): void
    {
        if (! $this->canEdit || $this->operationId === null || $this->exerciceCloture) {
            return;
        }

        // $this->montants et $this->operationId sont entièrement pilotés par le
        // navigateur : un appel forgé pourrait viser un compte ou une opération
        // d'une autre association. On confronte donc les deux au périmètre
        // réellement affiché — plutôt qu'aux seules règles d'autorisation, qui
        // ne disent rien du tenant.
        if (! Operation::query()->proposableALaSaisie()->whereKey($this->operationId)->exists()) {
            return;
        }

        $comptesAutorises = $this->comptesAutorises();

        $exercice = app(ExerciceService::class)->current();

        // Le gel ne verrouille QUE les enveloppes : aucune garde de validation ici.

        // Classement en deux passes : la première ne touche pas la base, elle se
        // contente de trier chaque cellule (suppression / écriture / erreur). Une
        // saisie négative ou non numérique (faute de frappe : "12OO" au lieu de
        // "1200", un "-500"...) ne doit JAMAIS emprunter la branche de
        // suppression — sans quoi une coquille détruirait silencieusement une
        // ventilation existante. On refuse alors l'ENSEMBLE de l'enregistrement,
        // y compris les comptes valides de la même saisie : un enregistrement
        // partiel serait plus difficile à comprendre pour l'utilisateur qu'un
        // refus net, à corriger et à soumettre à nouveau.
        $aSupprimer = [];
        $aEcrire = [];
        $comptesInvalides = [];

        foreach ($this->montants as $compteId => $valeur) {
            if (! in_array((int) $compteId, $comptesAutorises, true)) {
                continue;
            }

            $compteId = (int) $compteId;
            $valeur = trim((string) $valeur);

            // Cellule vide ou à zéro : suppression, c'est la spec — la ligne ne
            // doit pas subsister à zéro, ce qui polluerait le décompte des
            // opérations budgétées.
            if ($valeur === '' || (is_numeric($valeur) && (float) $valeur === 0.0)) {
                $aSupprimer[] = $compteId;

                continue;
            }

            if (! is_numeric($valeur) || (float) $valeur < 0) {
                $comptesInvalides[] = $compteId;

                continue;
            }

            $aEcrire[$compteId] = (float) $valeur;
        }

        if ($comptesInvalides !== []) {
            $noms = Compte::whereIn('id', $comptesInvalides)
                ->orderBy('numero_pcg')
                ->get()
                ->map(fn (Compte $c): string => $c->numero_pcg.' — '.$c->intitule)
                ->implode(', ');

            $this->addError('montants', "Montant invalide pour : {$noms}. Rien n'a été enregistré.");

            return;
        }

        DB::transaction(function () use ($exercice, $aSupprimer, $aEcrire): void {
            if ($aSupprimer !== []) {
                BudgetLine::forExercice($exercice)
                    ->where('operation_id', $this->operationId)
                    ->whereIn('compte_id', $aSupprimer)
                    ->delete();
            }

            foreach ($aEcrire as $compteId => $montant) {
                $existante = BudgetLine::forExercice($exercice)
                    ->where('compte_id', $compteId)
                    ->where('operation_id', $this->operationId)
                    ->first();

                if ($existante !== null) {
                    $existante->update(['montant_prevu' => $montant]);

                    continue;
                }

                BudgetLine::create([
                    'compte_id' => $compteId,
                    'operation_id' => $this->operationId,
                    'exercice' => $exercice,
                    'montant_prevu' => $montant,
                ]);
            }
        });

        $this->dispatch('budget-affecte');
        $this->fermer();
    }

    /**
     * Liste blanche des compte_id que la modale expose réellement — les mêmes
     * comptes que ceux énumérés par {@see lignes()}. Sert de garde tenant pour
     * {@see enregistrer()} : $this->montants est piloté par le navigateur, il
     * ne faut jamais faire confiance à ses clés.
     *
     * @return list<int>
     */
    private function comptesAutorises(): array
    {
        return PlanComptableSelecteur::comptesAutorisesPourTypes(['depense', 'recette']);
    }

    public function render(): View
    {
        $lignes = $this->ouverte ? $this->lignes() : [];

        return view('livewire.budget-affectation-modal', [
            'lignes' => $lignes,
            'totaux' => $this->totaux($lignes),
            'operations' => $this->ouverte ? Operation::query()->proposableALaSaisie()->orderBy('nom')->get() : collect(),
            'exerciceLabel' => app(ExerciceService::class)->label(app(ExerciceService::class)->current()),
        ]);
    }

    /**
     * Une entrée par compte de classe 6 et 7, dans l'ordre de l'écran Budget.
     *
     * @return list<array{compte_id: int, numero: string, intitule: string, type: string,
     *                    enveloppe: float|null, restant: float|null, montant: float, realise: float|null}>
     */
    private function lignes(): array
    {
        $exercice = app(ExerciceService::class)->current();

        $enveloppes = BudgetLine::forExercice($exercice)
            ->enveloppes()
            ->pluck('montant_prevu', 'compte_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        // Réalisé PAR OPÉRATION : un fait, jamais recalculé côté client (à la
        // différence du "restant", dérivé de la saisie en cours). Sans
        // opération choisie, aucune colonne "réalisé" n'a de sens — chaque
        // ligne portera null, affiché en tiret par la vue.
        $realiseParOperation = $this->operationId === null
            ? []
            : app(BudgetService::class)->realiseParCompteEtOperation($exercice);

        // Σ ventilations des AUTRES opérations. Inclure celle qu'on édite ferait
        // afficher un restant amputé du montant en cours de modification.
        //
        // Laravel réécrit where(col, '!=', null) en whereNotNull, donc écrire
        // ->where('operation_id', '!=', $this->operationId) sans opération
        // choisie serait correct (redondant avec whereNotNull() de
        // ventilations()) — mais un relecteur pourrait « corriger » ce qui
        // ressemble, en SQL pur, à une comparaison toujours fausse (<> NULL),
        // et casser le comportement. Le when() dit l'intention explicitement :
        // sans opération choisie, TOUTES les ventilations sont déduites.
        $autresVentilations = BudgetLine::forExercice($exercice)
            ->ventilations()
            ->when(
                $this->operationId !== null,
                fn ($q) => $q->where('operation_id', '!=', $this->operationId)
            )
            ->selectRaw('compte_id, SUM(montant_prevu) as total')
            ->groupBy('compte_id')
            ->pluck('total', 'compte_id')
            ->map(fn ($v): float => (float) $v)
            ->all();

        $lignes = [];

        foreach (['depense', 'recette'] as $type) {
            foreach (PlanComptableSelecteur::groupesPourType($type) as $groupe) {
                foreach ($groupe['comptes'] as $compte) {
                    $compteId = (int) $compte->id;

                    if ($this->filtre !== '' && ! str_contains(
                        mb_strtolower($compte->numero_pcg.' '.$compte->intitule),
                        mb_strtolower($this->filtre)
                    )) {
                        continue;
                    }

                    $enveloppe = $enveloppes[$compteId] ?? null;
                    $montant = (float) ($this->montants[$compteId] ?? 0);

                    // BASE, inchangée : enveloppe − ventilations des AUTRES opérations.
                    // C'est elle qui empêche le restant de fondre à chaque réouverture
                    // de la modale — ne pas la mêler au montant en cours de saisie ici.
                    // L'affichage (base − montant, en rouge si négatif) est calculé
                    // dans la vue, pas dans ce tableau : voir budget-affectation-modal.blade.php.
                    $restant = $enveloppe === null
                        ? null
                        : round($enveloppe - ($autresVentilations[$compteId] ?? 0.0), 2);

                    $realise = $this->operationId === null
                        ? null
                        : (float) ($realiseParOperation[$compteId][$this->operationId] ?? 0.0);

                    $lignes[] = [
                        'compte_id' => $compteId,
                        'numero' => $compte->numero_pcg,
                        'intitule' => $compte->intitule,
                        'type' => $type,
                        'enveloppe' => $enveloppe,
                        'restant' => $restant,
                        'montant' => $montant,
                        'realise' => $realise,
                    ];
                }
            }
        }

        return $lignes;
    }

    /**
     * Totaux par section (charges/produits) et résultat prévisionnel
     * (produits − charges), pour les deux colonnes "prévu" (Σ montant saisi)
     * et "réalisé" (Σ realise). Calculé côté serveur pour que l'affichage
     * soit juste au premier rendu et après enregistrement — le JS de la vue
     * ne fait que recalculer la colonne "prévu" en direct pendant la frappe ;
     * le "réalisé" est un fait, il ne bouge jamais côté client.
     *
     * Les totaux "réalisé" restent à null tant qu'aucune opération n'est
     * choisie, en écho au "—" affiché par chaque ligne : additionner des
     * valeurs sans opération donnerait un zéro trompeur, comme s'il y avait
     * un fait à montrer.
     *
     * @param  list<array{compte_id: int, numero: string, intitule: string, type: string,
     *                    enveloppe: float|null, restant: float|null, montant: float, realise: float|null}>  $lignes
     * @return array{charges_prevu: float, charges_realise: float|null,
     *               produits_prevu: float, produits_realise: float|null,
     *               resultat_prevu: float, resultat_realise: float|null}
     */
    private function totaux(array $lignes): array
    {
        $chargesPrevu = 0.0;
        $produitsPrevu = 0.0;
        $chargesRealise = $this->operationId === null ? null : 0.0;
        $produitsRealise = $this->operationId === null ? null : 0.0;

        foreach ($lignes as $l) {
            if ($l['type'] === 'depense') {
                $chargesPrevu += $l['montant'];
                if ($chargesRealise !== null) {
                    $chargesRealise += $l['realise'] ?? 0.0;
                }
            } else {
                $produitsPrevu += $l['montant'];
                if ($produitsRealise !== null) {
                    $produitsRealise += $l['realise'] ?? 0.0;
                }
            }
        }

        return [
            'charges_prevu' => round($chargesPrevu, 2),
            'charges_realise' => $chargesRealise === null ? null : round($chargesRealise, 2),
            'produits_prevu' => round($produitsPrevu, 2),
            'produits_realise' => $produitsRealise === null ? null : round($produitsRealise, 2),
            'resultat_prevu' => round($produitsPrevu - $chargesPrevu, 2),
            'resultat_realise' => ($chargesRealise === null || $produitsRealise === null)
                ? null
                : round($produitsRealise - $chargesRealise, 2),
        ];
    }
}
