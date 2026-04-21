<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Enums\UsageComptable;
use App\Helpers\EmailLogo;
use App\Mail\CommunicationTiersMail;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\MessageTemplate;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use App\Support\CurrentAssociation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class CommunicationTiers extends Component
{
    use WithFileUploads;

    // ── Filters ──────────────────────────────────────────────────────────────

    public string $search = '';

    /** @var 'et'|'ou' */
    public string $modeFiltres = 'et';

    /** @var null|'exercice'|'tous' */
    public ?string $filtreDonateurs = null;

    /** @var null|'exercice'|'tous' */
    public ?string $filtreAdherents = null;

    public bool $filtreFournisseurs = false;

    public bool $filtreClients = false;

    /** @var array<int> */
    public array $filtreTypeOperationIds = [];

    /** @var null|'exercice'|'tous' */
    public ?string $filtreParticipantsScope = null;

    // ── Selection ────────────────────────────────────────────────────────────

    public bool $selectAll = false;

    /** @var array<int> */
    public array $selectedTiersIds = [];

    // ── Composition ──────────────────────────────────────────────────────────

    public string $objet = '';

    public string $corps = '';

    // Templates
    public ?int $selectedTemplateId = null;

    public bool $showSaveTemplate = false;

    public string $templateNom = '';

    // Attachments
    /** @var array<int, TemporaryUploadedFile> */
    public array $emailAttachments = [];

    // Test email
    public bool $showTestModal = false;

    public string $testEmail = '';

    // Send
    public bool $showConfirmSend = false;

    public bool $envoiEnCours = false;

    public int $envoiProgression = 0;

    public int $envoiTotal = 0;

    public string $envoiResultat = '';

    // Preview
    public bool $showPreview = false;

    // Campaign history
    public ?int $expandedCampagneId = null;

    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || ! (RoleAssociation::tryFrom($user->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false)) {
            abort(403);
        }
    }

    /**
     * Build the filtered Tiers query.
     *
     * @return Builder<Tiers>
     */
    private function buildQuery(): Builder
    {
        $query = Tiers::query();

        // Text search: always AND, any of nom/prenom/entreprise/email
        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function (Builder $q) use ($s): void {
                $q->where('nom', 'like', "%{$s}%")
                    ->orWhere('prenom', 'like', "%{$s}%")
                    ->orWhere('entreprise', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        $activeFilters = $this->collectActiveFilters();

        if (empty($activeFilters)) {
            return $query;
        }

        if ($this->modeFiltres === 'ou') {
            // OR: tiers matching at least one filter
            $query->where(function (Builder $q) use ($activeFilters): void {
                foreach ($activeFilters as $filter) {
                    $q->orWhere(function (Builder $inner) use ($filter): void {
                        $filter($inner);
                    });
                }
            });
        } else {
            // AND (default): tiers matching all active filters
            foreach ($activeFilters as $filter) {
                $filter($query);
            }
        }

        return $query;
    }

    /**
     * Returns an array of closures, one per active role/type filter.
     * Each closure applies its constraint to a Builder<Tiers>.
     *
     * @return array<int, callable(Builder<Tiers>): void>
     */
    private function collectActiveFilters(): array
    {
        $filters = [];
        $exercice = app(ExerciceService::class)->current();

        if ($this->filtreFournisseurs) {
            $filters[] = fn (Builder $q) => $q->where('pour_depenses', true);
        }

        if ($this->filtreClients) {
            $filters[] = fn (Builder $q) => $q->where('pour_recettes', true);
        }

        if ($this->filtreDonateurs !== null && $this->filtreDonateurs !== '') {
            $donSousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');
            $ex = $this->filtreDonateurs === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($donSousCategorieIds, $ex): void {
                $q->whereHas('transactions', function (Builder $tq) use ($donSousCategorieIds, $ex): void {
                    $tq->where('type', 'recette');
                    if ($ex !== null) {
                        $tq->forExercice($ex);
                    }
                    $tq->whereHas('lignes', function (Builder $lq) use ($donSousCategorieIds): void {
                        $lq->whereIn('sous_categorie_id', $donSousCategorieIds);
                    });
                });
            };
        }

        if ($this->filtreAdherents !== null && $this->filtreAdherents !== '') {
            $cotSousCategorieIds = SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id');
            $ex = $this->filtreAdherents === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($cotSousCategorieIds, $ex): void {
                $q->whereHas('transactions', function (Builder $tq) use ($cotSousCategorieIds, $ex): void {
                    $tq->where('type', 'recette');
                    if ($ex !== null) {
                        $tq->forExercice($ex);
                    }
                    $tq->whereHas('lignes', function (Builder $lq) use ($cotSousCategorieIds): void {
                        $lq->whereIn('sous_categorie_id', $cotSousCategorieIds);
                    });
                });
            };
        }

        if ($this->filtreParticipantsScope !== null && $this->filtreParticipantsScope !== '' && ! empty($this->filtreTypeOperationIds)) {
            $typeOpIds = $this->filtreTypeOperationIds;
            $ex = $this->filtreParticipantsScope === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($typeOpIds, $ex): void {
                $q->whereHas('participants', function (Builder $pq) use ($typeOpIds, $ex): void {
                    $pq->whereHas('operation', function (Builder $oq) use ($typeOpIds, $ex): void {
                        $oq->whereIn('type_operation_id', $typeOpIds);
                        if ($ex !== null) {
                            $oq->forExercice($ex);
                        }
                    });
                });
            };
        } elseif ($this->filtreParticipantsScope !== null && $this->filtreParticipantsScope !== '') {
            // Scope set but no type filter: match any participant
            $ex = $this->filtreParticipantsScope === 'exercice' ? $exercice : null;

            $filters[] = function (Builder $q) use ($ex): void {
                $q->whereHas('participants', function (Builder $pq) use ($ex): void {
                    $pq->whereHas('operation', function (Builder $oq) use ($ex): void {
                        if ($ex !== null) {
                            $oq->forExercice($ex);
                        }
                    });
                });
            };
        }

        return $filters;
    }

    /**
     * Toggle select all: selects all filtered tiers that have an email and are not opted out.
     */
    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectAll = false;
            $this->selectedTiersIds = [];

            return;
        }

        $ids = $this->buildQuery()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email_optout', false)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $this->selectAll = true;
        $this->selectedTiersIds = $ids;
    }

    // ── Insertable elements ─────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    public function getInsertableElements(): array
    {
        $logos = EmailLogo::variables();
        $optoutUrl = '{lien_optout}';
        $optoutBlock = '<p style="font-size:12px;color:#666;margin-top:20px;text-align:center">'
            .'<a href="'.$optoutUrl.'" style="color:#666">Se désinscrire des communications</a>'
            .'</p>';

        return [
            'logo' => $logos['{logo}'] ?: '<em>[Logo non configuré]</em>',
            'lien_optout' => $optoutBlock,
        ];
    }

    // ── Templates ────────────────────────────────────────────────────────────

    public function loadTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        $template = MessageTemplate::find($this->selectedTemplateId);
        if ($template) {
            $this->objet = $template->objet;
            $this->corps = $template->corps;
            $this->dispatch('template-loaded', corps: $this->corps);
        }
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateNom' => 'required|string|max:100',
            'objet' => 'required|string|max:255',
            'corps' => 'required|string',
        ]);

        MessageTemplate::create([
            'categorie' => 'communication',
            'nom' => $this->templateNom,
            'objet' => $this->objet,
            'corps' => EmailTemplate::sanitizeCorps($this->corps),
            'type_operation_id' => null,
        ]);

        $this->showSaveTemplate = false;
        $this->templateNom = '';

        session()->flash('message', 'Modèle enregistré.');
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function updatedEmailAttachments(): void
    {
        $this->validate([
            'emailAttachments' => 'array|max:5',
            'emailAttachments.*' => 'file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
        ]);

        $totalBytes = array_sum(
            array_map(fn ($f) => $f->getSize(), $this->emailAttachments)
        );

        if ($totalBytes > 10 * 1024 * 1024) {
            $this->addError('emailAttachments', 'La taille totale des pièces jointes ne doit pas dépasser 10 Mo.');
            $this->emailAttachments = [];
        }
    }

    public function removeAttachment(int $index): void
    {
        unset($this->emailAttachments[$index]);
        $this->emailAttachments = array_values($this->emailAttachments);
    }

    // ── Test email ───────────────────────────────────────────────────────────

    public function envoyerTest(): void
    {
        $this->validate([
            'testEmail' => 'required|email',
            'objet' => 'required|string',
            'corps' => 'required|string',
        ]);

        if (empty($this->selectedTiersIds)) {
            session()->flash('error', 'Aucun tiers sélectionné.');

            return;
        }

        $assoc = CurrentAssociation::tryGet();
        if (! $assoc?->email_from) {
            session()->flash('error', "Adresse d'expédition non configurée.");

            return;
        }

        $tiers = Tiers::find($this->selectedTiersIds[0]);
        if (! $tiers) {
            return;
        }

        $trackingToken = null;
        $mail = new CommunicationTiersMail(
            prenom: $tiers->prenom ?? '',
            nom: $tiers->nom ?? '',
            email: $tiers->email ?? '',
            objet: '[Test] '.$this->objet,
            corps: $this->corps,
            trackingToken: $trackingToken,
            attachmentPaths: [],
        );

        try {
            Mail::mailer()
                ->to($this->testEmail)
                ->send($mail->from($assoc->email_from, $assoc->email_from_name ?? null));

            $this->showTestModal = false;
            session()->flash('message', "Email de test envoyé à {$this->testEmail}.");
        } catch (\Throwable $e) {
            session()->flash('error', "Erreur d'envoi : {$e->getMessage()}");
        }
    }

    // ── Send ─────────────────────────────────────────────────────────────────

    public function envoyerMessages(): void
    {
        $this->validate([
            'objet' => 'required|string',
            'corps' => 'required|string',
        ]);

        if (empty($this->selectedTiersIds)) {
            session()->flash('error', 'Aucun tiers sélectionné.');

            return;
        }

        $assoc = CurrentAssociation::tryGet();
        if (! $assoc?->email_from) {
            session()->flash('error', "Adresse d'expédition non configurée.");

            return;
        }

        $tiersList = Tiers::whereIn('id', $this->selectedTiersIds)->get();

        $this->envoiEnCours = true;
        $this->envoiTotal = $tiersList->count();
        $this->envoiProgression = 0;
        $this->envoiResultat = '';
        $this->showConfirmSend = false;

        // Persist attachments to permanent storage
        $piecesJointes = [];
        foreach ($this->emailAttachments as $file) {
            $nom = $file->getClientOriginalName();
            $taille = $file->getSize();
            $uniqueName = time().'_'.$nom;
            $path = $file->storeAs('campagnes-email', $uniqueName, 'local');
            $piecesJointes[] = [
                'nom' => $nom,
                'path' => $path,
                'taille' => $taille,
            ];
        }

        $campagne = CampagneEmail::create([
            'operation_id' => null,
            'objet' => $this->objet,
            'corps' => EmailTemplate::sanitizeCorps($this->corps),
            'pieces_jointes' => $piecesJointes ?: null,
            'nb_destinataires' => $this->envoiTotal,
            'nb_erreurs' => 0,
            'envoye_par' => Auth::id(),
        ]);

        $sent = 0;
        $errors = 0;

        foreach ($tiersList as $tiers) {
            $email = $tiers->getRawOriginal('email');

            if (! $email) {
                $this->envoiProgression++;

                continue;
            }

            try {
                $trackingToken = Str::random(32);
                $permanentPaths = array_map(
                    fn (array $pj) => ['path' => Storage::disk('local')->path($pj['path']), 'nom' => $pj['nom']],
                    $piecesJointes
                );

                $mail = new CommunicationTiersMail(
                    prenom: $tiers->prenom ?? '',
                    nom: $tiers->nom ?? '',
                    email: $email,
                    objet: $this->objet,
                    corps: $this->corps,
                    trackingToken: $trackingToken,
                    attachmentPaths: $permanentPaths,
                );

                Mail::mailer()
                    ->to($email)
                    ->send($mail->from($assoc->email_from, $assoc->email_from_name ?? null));

                EmailLog::create([
                    'tiers_id' => (int) $tiers->id,
                    'participant_id' => null,
                    'operation_id' => null,
                    'categorie' => 'communication',
                    'destinataire_email' => $email,
                    'destinataire_nom' => $tiers->displayName(),
                    'objet' => $this->objet,
                    'objet_rendu' => $mail->envelope()->subject,
                    'corps_html' => EmailTemplate::sanitizeCorps($mail->corpsHtml),
                    'statut' => 'envoye',
                    'tracking_token' => $trackingToken,
                    'envoye_par' => Auth::id(),
                    'campagne_id' => $campagne->id,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                EmailLog::create([
                    'tiers_id' => (int) $tiers->id,
                    'participant_id' => null,
                    'operation_id' => null,
                    'categorie' => 'communication',
                    'destinataire_email' => $email ?? '',
                    'destinataire_nom' => $tiers->displayName(),
                    'objet' => $this->objet,
                    'statut' => 'erreur',
                    'erreur_message' => $e->getMessage(),
                    'envoye_par' => Auth::id(),
                    'campagne_id' => $campagne->id,
                ]);
                $errors++;
            }

            $this->envoiProgression++;
            usleep(500_000);
        }

        $campagne->update([
            'nb_destinataires' => $sent + $errors,
            'nb_erreurs' => $errors,
        ]);

        $this->emailAttachments = [];
        $this->envoiEnCours = false;
        $this->envoiResultat = "{$sent} email(s) envoyé(s)".($errors > 0 ? ", {$errors} erreur(s)" : '');

        $this->objet = '';
        $this->corps = '';
        $this->selectedTemplateId = null;
        $this->dispatch('template-loaded', corps: '');
    }

    // ── Campaign history ──────────────────────────────────────────────────────

    public function toggleCampagne(int $id): void
    {
        $this->expandedCampagneId = $this->expandedCampagneId === $id ? null : $id;
    }

    public function reutiliserCampagne(int $id): void
    {
        $campagne = CampagneEmail::find($id);
        if (! $campagne) {
            return;
        }

        $this->objet = $campagne->objet;
        $this->corps = $campagne->corps;
        $this->selectedTemplateId = null;
        $this->dispatch('template-loaded', corps: $this->corps);
    }

    public function telechargerPieceJointe(int $campagneId, int $index): mixed
    {
        $campagne = CampagneEmail::find($campagneId);
        if (! $campagne || ! is_array($campagne->pieces_jointes)) {
            return null;
        }

        $pj = $campagne->pieces_jointes[$index] ?? null;
        if (! $pj || ! Storage::disk('local')->exists($pj['path'])) {
            session()->flash('error', 'Fichier introuvable.');

            return null;
        }

        return Storage::disk('local')->download($pj['path'], $pj['nom']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function render(): View
    {
        $tiersList = $this->buildQuery()
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        $emailCount = $tiersList
            ->filter(fn (Tiers $t) => ! empty($t->getRawOriginal('email')) && ! $t->email_optout)
            ->count();

        $emailFrom = CurrentAssociation::tryGet()?->email_from ?? '';

        $typesOperation = TypeOperation::actif()->orderBy('nom')->get();

        $campagnes = CampagneEmail::whereNull('operation_id')
            ->with('envoyePar')
            ->orderByDesc('created_at')
            ->get();

        $templates = MessageTemplate::where('categorie', 'communication')
            ->orderBy('nom')
            ->get();

        return view('livewire.communication-tiers', compact(
            'tiersList',
            'emailCount',
            'emailFrom',
            'typesOperation',
            'campagnes',
            'templates',
        ));
    }
}
