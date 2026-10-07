<?php

declare(strict_types=1);

namespace App\Livewire\Provisions;

use App\Enums\CasProvision;
use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Provision;
use App\Models\TransactionLigne;
use App\Services\Compta\RattachementExerciceService;
use App\Services\Compta\SchemaRattachement;
use App\Services\ExerciceService;
use App\Services\Rapports\CandidatsRattachementQuery;
use App\Tenant\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Exercices > Écritures de rattachement (spec § 6).
 *
 * Un produit encaissé ou une charge payée dans l'exercice peut concerner le suivant : l'écran le retire du
 * résultat de l'exercice et le reconnaît au début du suivant, par deux écritures qui se compensent.
 *
 * ── Ce que l'écran est, et n'est pas ────────────────────────────────────────
 *
 * Il n'a AUCUNE règle comptable à lui. Ce qui est rattachable se lit sur RattachementExerciceService::motifDeRefus()
 * (et sur CandidatsRattachementQuery, qui en reproduit les gardes pour la détection) ; le sens, les dates et le
 * libellé des deux écritures se lisent sur SchemaRattachement, que le générateur écrit et que l'aperçu de la
 * modale affiche ; la période fermée se lit sur motifPeriodeFermee(). L'écran PRÉVIENT (bandeau, boutons
 * désactivés, motif, aperçu) ; le service décide, sous verrou, et son refus s'affiche dans la modale.
 *
 * ── Ce que le composant garde, et ce qu'il ne garde pas ─────────────────────
 *
 * La détection, la recherche, les rattachements et la modale sont calculés dans render(), jamais stockés dans une
 * propriété publique : une propriété publique est sérialisée à chaque échange et se périme. Ne restent publics que
 * l'état de l'interface (la ligne ouverte, la recherche, les notes saisies, la pièce jointe). La ligne ouverte est
 * #[Locked] : le client ne la change pas, il demande à l'ouvrir, et elle est alors résolue dans l'association.
 *
 * Le montant n'est pas une propriété : il n'est pas saisissable (D12), le rattachement porte la ligne entière.
 *
 * Les refus du service (RuntimeException : motif, exercice clôturé…) s'affichent dans la modale ou en message.
 * ModelNotFoundException, elle, n'est PAS avalée : c'est un défaut de configuration (un compte 486/487 absent),
 * pas un refus à expliquer, et le masquer cacherait un rattachement qui ne peut jamais aboutir.
 */
final class ProvisionIndex extends Component
{
    use WithFileUploads;

    /** Nombre de lignes rendues par la recherche libre ; une de plus est lue pour savoir s'il y en a d'autres. */
    private const RECHERCHE_LIMITE = 20;

    /** Longueur maximale du terme cherché : au-delà, rien de plus n'est utile. */
    private const RECHERCHE_LONGUEUR_MAX = 100;

    /** La ligne d'origine ouverte dans la modale, ou null. #[Locked] : résolue dans l'association à l'ouverture. */
    #[Locked]
    public ?int $ligneId = null;

    public string $recherche = '';

    public string $notes = '';

    /** @var TemporaryUploadedFile|null */
    public $pieceJointe = null;

    /** Le refus du service, affiché dans la modale. */
    public string $erreurModale = '';

    public string $flashMessage = '';

    public string $flashType = '';

    public function getPeutEcrireProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()?->currentRole() ?? '')?->canWrite(Espace::Compta) ?? false;
    }

    // ── Modale ───────────────────────────────────────────────────────────────

    /** « Constater d'avance » : ouvre la modale sur la ligne choisie, résolue dans l'association courante. */
    public function ouvrir(int $ligneId): void
    {
        if (! $this->peutEcrire) {
            $this->avertir('Vous n’avez pas les droits pour constater un rattachement.', 'danger');

            return;
        }

        if ($this->ligneDeLAssociation($ligneId) === null) {
            $this->avertir('Cette ligne n’existe pas.', 'danger');

            return;
        }

        $this->ligneId = $ligneId;
        $this->notes = '';
        $this->pieceJointe = null;
        $this->erreurModale = '';
        $this->flashMessage = '';
        $this->resetValidation();
    }

    public function fermer(): void
    {
        $this->ligneId = null;
        $this->notes = '';
        $this->pieceJointe = null;
        $this->erreurModale = '';
        $this->resetValidation();
    }

    /** Valide la modale : crée le rattachement et ses deux écritures par le service, qui décide sous verrou. */
    public function confirmer(): void
    {
        $this->erreurModale = '';

        if ($this->ligneId === null) {
            return;
        }

        if (! $this->peutEcrire) {
            $this->erreurModale = 'Vous n’avez pas les droits pour constater un rattachement.';

            return;
        }

        $this->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'pieceJointe' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'notes.max' => 'Les notes ne doivent pas dépasser 2 000 caractères.',
            'pieceJointe.mimes' => 'Le justificatif doit être un fichier PDF, JPG ou PNG.',
            'pieceJointe.max' => 'Le justificatif ne doit pas dépasser 10 Mo.',
        ]);

        try {
            $provision = app(RattachementExerciceService::class)->rattacherParId(
                $this->ligneId,
                $this->notes,
                Auth::user(),
            );
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Le motif de refus, l'exercice clôturé, une ligne disparue : ce que le service explique.
            $this->erreurModale = $e->getMessage();

            return;
        }

        // Le rattachement est validé : le justificatif est une pièce annexe, son échec ne le défait pas.
        $justificatifEnregistre = $this->pieceJointe === null || $this->enregistrerPieceJointe($provision);

        $this->fermer();

        $this->flashMessage = $justificatifEnregistre
            ? 'Écriture de rattachement enregistrée.'
            : 'Écriture de rattachement enregistrée, mais le justificatif n’a pas pu être joint.';
        $this->flashType = $justificatifEnregistre ? 'success' : 'warning';
    }

    // ── Rattachements de l'exercice ──────────────────────────────────────────

    /** « Retirer » : supprime les deux écritures et libère la ligne d'origine (spec § 6.4), par le service. */
    public function retirer(int $provisionId): void
    {
        if (! $this->peutEcrire) {
            $this->avertir('Vous n’avez pas les droits pour retirer un rattachement.', 'danger');

            return;
        }

        try {
            app(RattachementExerciceService::class)->retirerParId($provisionId);
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->avertir($e->getMessage(), 'danger');

            return;
        }

        $this->avertir('Rattachement retiré : ses deux écritures sont supprimées et la ligne d’origine redevient modifiable.', 'success');
    }

    /** Le justificatif d'un rattachement, résolu dans l'association courante. */
    public function telechargerPieceJointe(int $provisionId): ?StreamedResponse
    {
        $provision = Provision::find($provisionId);
        $chemin = $provision?->pieceJointeFullPath();

        if ($provision === null || $chemin === null || ! Storage::disk('local')->exists($chemin)) {
            $this->avertir('Ce justificatif est introuvable.', 'danger');

            return null;
        }

        return Storage::disk('local')->download(
            $chemin,
            str_replace(['/', '\\'], '-', (string) $provision->piece_jointe_nom),
            ['Content-Type' => (string) $provision->piece_jointe_mime],
        );
    }

    // ── Rendu ────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        $detection = app(CandidatsRattachementQuery::class)->pourExercice($exercice);
        $rattachements = $this->rattachementsDeLExercice($exercice);
        $recherche = $this->rechercherLignes($exercice);

        return view('livewire.provisions.provision-index', [
            'exerciceLabel' => $exerciceService->label($exercice),
            'motifPeriodeFermee' => app(RattachementExerciceService::class)->motifPeriodeFermee($exercice),
            'peutEcrire' => $this->peutEcrire,
            'candidats' => $detection['eligibles'],
            'ecartees' => $detection['ecartees'],
            'ventilationsDesEcartees' => $this->ventilationsDesEcartees($detection['ecartees']),
            'resultatsRecherche' => $recherche['lignes'],
            'rechercheTronquee' => $recherche['tronquee'],
            'rattachements' => $rattachements,
            'operationsDesRattachements' => $this->operationsDesRattachements($rattachements),
            'modale' => $this->modale(),
        ]);
    }

    // ── Calculs de rendu (jamais stockés) ────────────────────────────────────

    /**
     * Les rattachements de l'exercice, avec ce que la liste lit. La ligne d'origine et sa pièce sont lues
     * supprimées logiquement comprises : une origine disparue n'empêche ni d'afficher le rattachement ni de le
     * retirer, qui est précisément la sortie de ce cas (RattachementExerciceService::retirer()).
     *
     * @return Collection<int, Provision>
     */
    private function rattachementsDeLExercice(int $exercice): Collection
    {
        return Provision::forExercice($exercice)
            ->avecCompteResultat()
            ->with([
                'ligne' => fn ($ligne) => $ligne->withTrashed()->with(['transaction' => fn ($piece) => $piece->withTrashed()]),
                'saisiPar',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Les noms des opérations des rattachements, en une seule requête.
     *
     * @param  Collection<int, Provision>  $rattachements
     * @return Collection<int, Operation>
     */
    private function operationsDesRattachements(Collection $rattachements): Collection
    {
        $ids = $rattachements
            ->map(fn (Provision $p): ?int => $p->operationEffectiveId())
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty()
            ? collect()
            : Operation::withTrashed()->whereIn('id', $ids->all())->get()->keyBy(fn (Operation $o): int => (int) $o->id);
    }

    /**
     * Les opérations de la ventilation des lignes écartées, en UNE requête pour toutes : une ligne ventilée par
     * affectations n'a généralement pas d'opération propre, et c'est la ventilation qui explique pourquoi elle
     * est listée. Rendues par ligne, de la plus ancienne à la plus récente.
     *
     * @param  Collection<int, object>  $ecartees
     * @return Collection<int, Collection<int, object>>
     */
    private function ventilationsDesEcartees(Collection $ecartees): Collection
    {
        if ($ecartees->isEmpty()) {
            return collect();
        }

        $associationId = (int) TenantContext::currentId();

        return DB::table('transaction_ligne_affectations as a')
            ->join('operations as o', 'o.id', '=', 'a.operation_id')
            ->where('o.association_id', $associationId)
            ->whereNull('o.deleted_at')
            ->whereIn('a.transaction_ligne_id', $ecartees->pluck('transaction_ligne_id')->all())
            ->orderBy('o.date_debut')
            ->orderBy('o.id')
            ->get(['a.transaction_ligne_id', 'o.nom', 'o.date_debut', 'o.date_fin'])
            ->groupBy(fn (object $affectation): int => (int) $affectation->transaction_ligne_id);
    }

    /**
     * La recherche libre : les lignes de charge ou de produit de l'exercice dont la pièce, le libellé ou le
     * montant correspondent, hors écritures de rattachement. Elle ne juge pas leur éligibilité — un jugement par résultat coûterait autant de
     * requêtes qu'il y en a —, la modale le fait pour la ligne choisie.
     *
     * Les bornes sont un intervalle semi-ouvert (>= début de N, < début de N+1), jamais un BETWEEN : sous SQLite
     * une colonne `date` écrite par Eloquent est « Y-m-d 00:00:00 », que `<= fin` ou BETWEEN laisserait tomber le
     * dernier jour de l'exercice. L'isolation tient à un filtre par table jointe (la pièce, le compte) ; la
     * ligne est de plus sous TransactionLigneTenantScope.
     *
     * @return array{lignes: Collection<int, TransactionLigne>, tronquee: bool}
     */
    private function rechercherLignes(int $exercice): array
    {
        $terme = trim(mb_substr($this->recherche, 0, self::RECHERCHE_LONGUEUR_MAX));

        if ($terme === '') {
            return ['lignes' => collect(), 'tronquee' => false];
        }

        $associationId = (int) TenantContext::currentId();
        $exerciceService = app(ExerciceService::class);
        $debut = $exerciceService->dateRange($exercice)['start']->toDateString();
        $debutSuivant = $exerciceService->dateRange($exercice + 1)['start']->toDateString();

        // Les jokers du terme (%, _) sont cherchés tels quels. « ! » sert de caractère d'échappement (ESCAPE '!') :
        // SQLite n'en a pas par défaut, et « \ » n'est pas celui de tous les moteurs.
        $motif = '%'.preg_replace('/([!%_])/', '!$1', $terme).'%';
        $montant = $this->montantSaisi($terme);

        $lignes = TransactionLigne::query()
            ->join('transactions as t', 't.id', '=', 'transaction_lignes.transaction_id')
            ->join('comptes as c', 'c.id', '=', 'transaction_lignes.compte_id')
            ->where('t.association_id', $associationId)
            ->where('c.association_id', $associationId)
            ->whereNull('t.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereIn('c.classe', [6, 7])
            // Les écritures d'un rattachement ne sont jamais proposées (§ 6.1, AC-11) : leur dotation est une ligne
            // de classe 7 de l'exercice, et l'on y retrouverait le rattachement qu'on vient de passer.
            ->whereNull('t.provision_id')
            ->where('t.date', '>=', $debut)
            ->where('t.date', '<', $debutSuivant)
            ->where(function (Builder $correspond) use ($motif, $montant): void {
                $correspond->whereRaw("t.numero_piece LIKE ? ESCAPE '!'", [$motif])
                    ->orWhereRaw("t.libelle LIKE ? ESCAPE '!'", [$motif])
                    ->orWhereRaw("transaction_lignes.libelle LIKE ? ESCAPE '!'", [$motif]);

                if ($montant !== null) {
                    $correspond->orWhere('transaction_lignes.debit', $montant)
                        ->orWhere('transaction_lignes.credit', $montant);
                }
            })
            ->select('transaction_lignes.*')
            ->orderBy('t.date')
            ->orderBy('t.id')
            ->orderBy('transaction_lignes.id')
            ->limit(self::RECHERCHE_LIMITE + 1)
            ->with(['transaction', 'compte'])
            ->get();

        return [
            'lignes' => $lignes->take(self::RECHERCHE_LIMITE)->values(),
            'tronquee' => $lignes->count() > self::RECHERCHE_LIMITE,
        ];
    }

    /** Un montant saisi à la française (« 175 », « 175,50 », « 1 200,00 ») ou à l'anglaise, ou null si ce n'en est pas un. */
    private function montantSaisi(string $terme): ?float
    {
        $nombre = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $terme);

        return is_numeric($nombre) ? round((float) $nombre, 2) : null;
    }

    /**
     * Ce que la modale montre de la ligne ouverte, ou null si aucune ne l'est. La ligne est jugée UNE fois, par
     * motifDeRefus() — la même autorité que le service. Refusée : le motif, aucun aperçu, aucune validation.
     * Acceptée : le cas déduit, la phrase qui dit ce qui va se passer, et les deux écritures telles que
     * SchemaRattachement les rend, c'est-à-dire telles que le générateur les écrira.
     *
     * @return array<string, mixed>|null
     */
    private function modale(): ?array
    {
        if ($this->ligneId === null) {
            return null;
        }

        $ligne = $this->ligneDeLAssociation($this->ligneId);

        if ($ligne === null) {
            // Disparue depuis l'ouverture : rien à montrer que le refus.
            return [
                'ligne' => null, 'refus' => 'Cette ligne n’existe pas.', 'compte' => null, 'operation' => null,
                'seance' => null, 'tiers' => null, 'montant' => 0.0, 'datePiece' => null, 'numeroPiece' => null,
                'cas' => null, 'phrase' => null, 'apercu' => null,
            ];
        }

        $piece = $ligne->transaction;
        $compte = $ligne->compte;
        $montant = round(max((float) $ligne->debit, (float) $ligne->credit), 2);

        $modale = [
            'ligne' => $ligne,
            'refus' => app(RattachementExerciceService::class)->motifDeRefus($ligne),
            'compte' => $compte,
            'operation' => $ligne->operation,
            'seance' => $ligne->seance,
            'tiers' => $piece?->tiers?->displayName(),
            'montant' => $montant,
            'datePiece' => $piece?->date,
            'numeroPiece' => $piece?->numero_piece,
            'cas' => null,
            'phrase' => null,
            'apercu' => null,
        ];

        if ($modale['refus'] !== null) {
            return $modale;
        }

        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->anneeForDate($piece->date);

        // Une origine non enregistrée : elle ne sert qu'à lire ce que le générateur lit — le cas, le libellé
        // effectif, l'opération et la séance héritées — par les mêmes méthodes du modèle.
        $provision = new Provision(['exercice' => $exercice, 'transaction_ligne_id' => $ligne->id, 'montant' => $montant]);
        $provision->setRelation('ligne', $ligne);
        $cas = $provision->cas();

        $modale['cas'] = $cas;
        $modale['phrase'] = $this->phrase($cas, $montant, $piece->date, $exerciceService->label($exercice), $exerciceService->label($exercice + 1));
        $modale['apercu'] = $this->apercu($cas, $provision, $compte, $montant, $exercice);

        return $modale;
    }

    /**
     * « les 175,00 € comptabilisés en produit le 08/04/2026 seront retirés du résultat 2025‑2026 et reconnus sur
     * 2026‑2027. » (spec § 6.3). « Comptabilisés », pas « encaissés » ni « dépensés » : la date est celle de la
     * pièce, qui peut n'être pas réglée.
     */
    private function phrase(CasProvision $cas, float $montant, CarbonInterface $date, string $exercice, string $suivant): string
    {
        // Le trait d'union insécable : une année ne se coupe pas en fin de ligne.
        $insecable = fn (string $label): string => str_replace('-', "\u{2011}", $label);

        return 'les '.number_format($montant, 2, ',', "\u{202F}").' € '
            .'comptabilisés en '.($cas->classe() === 7 ? 'produit' : 'charge').' le '.$date->format('d/m/Y')
            .' seront retirés du résultat '.$insecable($exercice).' et reconnus sur '.$insecable($suivant).'.';
    }

    /**
     * Les deux écritures, quatre lignes. Tout vient de SchemaRattachement ; le débit passe avant le crédit, comme
     * on lit une écriture. Les intitulés des comptes sont lus ici, le schéma ne rendant que des numéros : ce sont
     * les numéros qu'il rend, jamais ceux que l'écran supposerait.
     *
     * @return list<array{cle: string, titre: string, date: CarbonInterface, libelle: string, lignes: list<array{numero_pcg: string, intitule: ?string, debit: float, credit: float}>}>
     */
    private function apercu(CasProvision $cas, Provision $provision, ?Compte $compteResultat, float $montant, int $exercice): array
    {
        $schema = app(SchemaRattachement::class);

        $ecritures = [];

        foreach ([false, true] as $extourne) {
            $ecritures[$extourne ? 'extourne' : 'dotation'] = $schema->ecriture(
                $cas,
                (string) $compteResultat?->numero_pcg,
                $montant,
                $exercice,
                $provision->libelleEffectif(),
                $extourne,
            );
        }

        // Le scope tenant de Compte borne la lecture à l'association courante.
        $intitules = Compte::query()
            ->whereIn('numero_pcg', collect($ecritures)->flatMap(fn (array $ecriture): array => array_column($ecriture['lignes'], 'numero_pcg'))->unique()->values()->all())
            ->pluck('intitule', 'numero_pcg');

        $apercu = [];

        foreach ($ecritures as $cle => $ecriture) {
            $lignes = collect($ecriture['lignes'])
                ->map(fn (array $jambe): array => $jambe + ['intitule' => $intitules[$jambe['numero_pcg']] ?? null])
                ->sortByDesc('debit')
                ->values()
                ->all();

            $apercu[] = [
                'cle' => $cle,
                'titre' => $cle === 'extourne' ? 'Extourne, au début de l’exercice suivant' : 'Écriture de rattachement, à la fin de l’exercice',
                'date' => $ecriture['date'],
                'libelle' => $ecriture['libelle'],
                'lignes' => $lignes,
            ];
        }

        return $apercu;
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    /** La ligne résolue dans l'association courante, avec ce que la modale lit ; null si elle n'y existe pas. */
    private function ligneDeLAssociation(int $ligneId): ?TransactionLigne
    {
        // Résolution explicite dans le tenant (§ 7), comme rattacherParId() : un identifiant forgé d'une autre
        // association est introuvable. TransactionLigneTenantScope le filtre aussi : deux filets.
        return TransactionLigne::query()
            ->whereHas('transaction', fn (Builder $piece) => $piece->where('association_id', (int) TenantContext::currentId()))
            ->with(['transaction.tiers', 'compte', 'operation'])
            ->find($ligneId);
    }

    /** Le justificatif, écrit sous le dossier du rattachement ; faux (et journalisé) si le disque échoue. */
    private function enregistrerPieceJointe(Provision $provision): bool
    {
        try {
            $court = 'piece-jointe.'.($this->pieceJointe->guessExtension() ?? 'bin');

            Storage::disk('local')->put(
                $provision->storagePath('provisions/'.$provision->id.'/'.$court),
                $this->pieceJointe->get(),
            );

            $provision->update([
                'piece_jointe_path' => $court,
                'piece_jointe_nom' => $this->pieceJointe->getClientOriginalName(),
                'piece_jointe_mime' => $this->pieceJointe->getMimeType(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('[Rattachement] justificatif non enregistré', [
                'provision_id' => (int) $provision->id,
                'erreur' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function avertir(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
