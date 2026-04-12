<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Helpers\EmailLogo;
use App\Mail\MessageLibreMail;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class OperationCommunication extends Component
{
    use WithFileUploads;

    public Operation $operation;

    // Composition
    public string $objet = '';

    public string $corps = '';

    public ?int $selectedTemplateId = null;

    // Save as template
    public bool $showSaveTemplate = false;

    public string $templateNom = '';

    public ?int $templateTypeOperationId = null;

    // Participant selection
    /** @var array<int> */
    public array $selectedParticipants = [];

    public ?int $filtreSeanceId = null;

    // Preview
    public bool $showPreview = false;

    // File attachments
    /** @var array<int, TemporaryUploadedFile> */
    public array $emailAttachments = [];

    // Test email
    public bool $showTestModal = false;

    public string $testEmail = '';

    // Campaign history
    public ?int $expandedCampagneId = null;

    // Bulk send
    public bool $showConfirmSend = false;

    public bool $envoiEnCours = false;

    public int $envoiProgression = 0;

    public int $envoiTotal = 0;

    public string $envoiResultat = '';

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

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        $this->initParticipants();
        $this->testEmail = auth()->user()?->email ?? '';
    }

    private function initParticipants(): void
    {
        // Select all participants that have an email
        $this->selectedParticipants = $this->getParticipantsWithEmail()
            ->pluck('id')
            ->toArray();
    }

    public function getParticipantsWithEmail(): Collection
    {
        return $this->getAllParticipants()
            ->filter(fn (Participant $p) => ! empty($p->tiers?->email));
    }

    public function getAllParticipants(): Collection
    {
        $query = $this->operation->participants()->with('tiers');

        if ($this->filtreSeanceId) {
            $presentIds = Presence::where('seance_id', $this->filtreSeanceId)
                ->where('statut', 'present')
                ->pluck('participant_id');
            $query->whereIn('id', $presentIds);
        }

        return $query->get();
    }

    public function updatedFiltreSeanceId(): void
    {
        $this->initParticipants();
    }

    public function toggleSelectAll(): void
    {
        $withEmail = $this->getParticipantsWithEmail()->pluck('id')->toArray();
        if (count($this->selectedParticipants) === count($withEmail)) {
            $this->selectedParticipants = [];
        } else {
            $this->selectedParticipants = $withEmail;
        }
    }

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

    /**
     * @return array<int, string>
     */
    public function getUnresolvedVariables(): array
    {
        if (empty($this->corps)) {
            return [];
        }

        $operation = $this->operation->loadMissing('seances');
        $seances = $operation->seances->sortBy('date');
        $today = now()->startOfDay();

        $prochaine = $seances->first(fn (Seance $s) => $s->date && $s->date->gte($today));
        $precedente = $seances->last(fn (Seance $s) => $s->date && $s->date->lt($today));

        $values = [
            '{date_prochaine_seance}' => $prochaine?->date?->format('d/m/Y') ?? '',
            '{numero_prochaine_seance}' => $prochaine ? (string) $prochaine->numero : '',
            '{titre_prochaine_seance}' => $prochaine?->titre ?? '',
            '{jours_avant_prochaine_seance}' => $prochaine?->date ? (string) (int) $today->diffInDays($prochaine->date, false) : '',
            '{date_precedente_seance}' => $precedente?->date?->format('d/m/Y') ?? '',
            '{numero_precedente_seance}' => $precedente ? (string) $precedente->numero : '',
            '{titre_precedente_seance}' => $precedente?->titre ?? '',
        ];

        $unresolved = [];
        foreach ($values as $var => $value) {
            if ($value === '' && str_contains($this->corps, $var)) {
                $unresolved[] = $var;
            }
        }

        return $unresolved;
    }

    public function openSaveAsTemplate(): void
    {
        if ($this->selectedTemplateId) {
            $source = MessageTemplate::find($this->selectedTemplateId);
            $this->templateNom = $source ? 'Copie de '.$source->nom : '';
            $this->templateTypeOperationId = $source?->type_operation_id;
        } else {
            $this->templateNom = '';
            $this->templateTypeOperationId = null;
        }
        $this->showSaveTemplate = true;
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateNom' => 'required|string|max:100',
            'objet' => 'required|string|max:255',
            'corps' => 'required|string',
        ]);

        MessageTemplate::create([
            'categorie' => 'operation',
            'nom' => $this->templateNom,
            'objet' => $this->objet,
            'corps' => $this->corps,
            'type_operation_id' => $this->templateTypeOperationId,
        ]);

        $this->showSaveTemplate = false;
        $this->templateNom = '';
        $this->templateTypeOperationId = null;

        session()->flash('message', 'Modèle enregistré.');
    }

    public function updateTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        $template = MessageTemplate::find($this->selectedTemplateId);
        if ($template) {
            $template->update([
                'objet' => $this->objet,
                'corps' => $this->corps,
            ]);
            session()->flash('message', 'Modèle mis à jour.');
        }
    }

    public function deleteTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        MessageTemplate::destroy($this->selectedTemplateId);
        $this->selectedTemplateId = null;
        session()->flash('message', 'Modèle supprimé.');
    }

    public function getAvailableTemplates(): Collection
    {
        return MessageTemplate::with('typeOperation')
            ->where('categorie', 'operation')
            ->orderBy('nom')
            ->get()
            ->groupBy(fn (MessageTemplate $t) => $t->typeOperation?->nom ?? 'Modèles généraux');
    }

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

    public function envoyerTest(): void
    {
        $this->validate([
            'testEmail' => 'required|email',
            'objet' => 'required|string',
            'corps' => 'required|string',
        ]);

        if (empty($this->selectedParticipants)) {
            session()->flash('error', 'Aucun participant sélectionné.');

            return;
        }

        $operation = $this->operation->loadMissing(['typeOperation', 'seances']);
        $typeOp = $operation->typeOperation;

        if (! $typeOp?->email_from) {
            session()->flash('error', "Adresse d'expédition non configurée sur le type d'opération.");

            return;
        }

        $participant = Participant::with('tiers')->find($this->selectedParticipants[0]);
        if (! $participant) {
            return;
        }

        $mail = $this->buildMail($participant, $operation, test: true);

        try {
            Mail::mailer()
                ->to($this->testEmail)
                ->send($mail->from($typeOp->email_from, $typeOp->email_from_name ?? null));

            $this->showTestModal = false;
            session()->flash('message', "Email de test envoyé à {$this->testEmail}.");
        } catch (\Throwable $e) {
            session()->flash('error', "Erreur d'envoi : {$e->getMessage()}");
        }
    }

    public function envoyerMessages(): void
    {
        $this->validate([
            'objet' => 'required|string',
            'corps' => 'required|string',
        ]);

        if (empty($this->selectedParticipants)) {
            session()->flash('error', 'Aucun participant sélectionné.');

            return;
        }

        $operation = $this->operation->loadMissing(['typeOperation', 'seances']);
        $typeOp = $operation->typeOperation;

        if (! $typeOp?->email_from) {
            session()->flash('error', "Adresse d'expédition non configurée sur le type d'opération.");

            return;
        }

        $participants = Participant::with('tiers')
            ->whereIn('id', $this->selectedParticipants)
            ->get();

        $this->envoiEnCours = true;
        $this->envoiTotal = $participants->count();
        $this->envoiProgression = 0;
        $this->envoiResultat = '';
        $this->showConfirmSend = false;

        // Persist attachments to permanent storage (capture metadata before store moves the file)
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
            'operation_id' => $operation->id,
            'objet' => $this->objet,
            'corps' => $this->corps,
            'pieces_jointes' => $piecesJointes ?: null,
            'nb_destinataires' => $this->envoiTotal,
            'nb_erreurs' => 0,
            'envoye_par' => Auth::id(),
        ]);

        $sent = 0;
        $errors = 0;

        foreach ($participants as $participant) {
            $tiers = $participant->tiers;
            $email = $tiers?->email;

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
                $mail = $this->buildMail($participant, $operation, trackingToken: $trackingToken, storedAttachmentPaths: $permanentPaths);

                Mail::mailer()
                    ->to($email)
                    ->send($mail->from($typeOp->email_from, $typeOp->email_from_name ?? null));

                EmailLog::create([
                    'tiers_id' => $participant->tiers_id,
                    'participant_id' => $participant->id,
                    'operation_id' => $operation->id,
                    'categorie' => 'message',
                    'destinataire_email' => $email,
                    'destinataire_nom' => $tiers->displayName(),
                    'objet' => $mail->envelope()->subject,
                    'objet_rendu' => $mail->envelope()->subject,
                    'corps_html' => $mail->corpsHtml,
                    'statut' => 'envoye',
                    'tracking_token' => $trackingToken,
                    'envoye_par' => Auth::id(),
                    'campagne_id' => $campagne->id,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                EmailLog::create([
                    'tiers_id' => $participant->tiers_id,
                    'participant_id' => $participant->id,
                    'operation_id' => $operation->id,
                    'categorie' => 'message',
                    'destinataire_email' => $email ?? '',
                    'destinataire_nom' => $tiers?->displayName() ?? '',
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

    /**
     * @param  array<int, string>|null  $storedAttachmentPaths  Permanent paths (after store). If null, uses temp Livewire files.
     */
    private function buildMail(Participant $participant, Operation $operation, bool $test = false, ?string $trackingToken = null, ?array $storedAttachmentPaths = null): MessageLibreMail
    {
        $tiers = $participant->tiers;
        $seances = $operation->seances->sortBy('date');
        $today = now()->startOfDay();

        $prochaine = $seances->first(fn (Seance $s) => $s->date && $s->date->gte($today));
        $precedente = $seances->last(fn (Seance $s) => $s->date && $s->date->lt($today));

        if ($storedAttachmentPaths !== null) {
            $attachmentPaths = $storedAttachmentPaths;
        } else {
            $attachmentPaths = [];
            foreach ($this->emailAttachments as $file) {
                $attachmentPaths[] = ['path' => $file->getRealPath(), 'nom' => $file->getClientOriginalName()];
            }
        }

        // Count seances done (date in the past) and remaining
        $seancesEffectuees = $seances->filter(fn (Seance $s) => $s->date && $s->date->lt($today))->count();
        $seancesRestantes = $seances->filter(fn (Seance $s) => $s->date && $s->date->gte($today))->count();
        $joursAvant = $prochaine?->date ? (int) $today->diffInDays($prochaine->date, false) : null;

        return new MessageLibreMail(
            prenomParticipant: $tiers?->prenom ?? '',
            nomParticipant: $tiers?->nom ?? '',
            emailParticipant: $tiers?->email ?? '',
            operationNom: $operation->nom,
            typeOperationNom: $operation->typeOperation?->nom ?? '',
            libelleArticle: $operation->typeOperation?->libelle_article,
            dateDebut: $operation->date_debut?->format('d/m/Y') ?? '',
            dateFin: $operation->date_fin?->format('d/m/Y') ?? '',
            nbSeances: $operation->nombre_seances ?? 0,
            dateProchainSeance: $prochaine?->date?->format('d/m/Y'),
            datePrecedenteSeance: $precedente?->date?->format('d/m/Y'),
            numeroProchainSeance: $prochaine?->numero,
            numeroPrecedenteSeance: $precedente?->numero,
            titreProchainSeance: $prochaine?->titre,
            titrePrecedenteSeance: $precedente?->titre,
            joursAvantProchaineSeance: $joursAvant,
            nbSeancesEffectuees: $seancesEffectuees,
            nbSeancesRestantes: $seancesRestantes,
            objet: $test ? '[Test] '.$this->objet : $this->objet,
            corps: $this->corps,
            attachmentPaths: $attachmentPaths,
            typeOperationId: $operation->typeOperation?->id,
            trackingToken: $trackingToken,
            seances: $seances,
        );
    }

    /**
     * Returns pre-built HTML elements for insertion into the editor.
     *
     * @return array<string, string>
     */
    public function getInsertableElements(): array
    {
        $operation = $this->operation->loadMissing(['typeOperation', 'seances']);
        $seances = $operation->seances->sortBy('date');
        $today = now()->startOfDay();
        $typeOpId = $operation->typeOperation?->id;

        $logos = EmailLogo::variables($typeOpId);

        $buildTable = function (Collection $items): string {
            if ($items->isEmpty()) {
                return '<p><em>Aucune séance.</em></p>';
            }
            $rows = '';
            foreach ($items as $s) {
                $rows .= '<tr>'
                    .'<td style="padding:6px 10px;border:1px solid #ddd;text-align:center">'.$s->numero.'</td>'
                    .'<td style="padding:6px 10px;border:1px solid #ddd">'.($s->date?->format('d/m/Y') ?? '').'</td>'
                    .'<td style="padding:6px 10px;border:1px solid #ddd">'.e($s->titre_affiche).'</td>'
                    .'</tr>';
            }

            return '<table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:13px">'
                .'<tr style="background:#3d5473;color:#fff">'
                .'<th style="padding:6px 10px;text-align:center;width:50px">N°</th>'
                .'<th style="padding:6px 10px;width:100px">Date</th>'
                .'<th style="padding:6px 10px">Titre</th>'
                .'</tr>'.$rows.'</table>';
        };

        $aVenir = $seances->filter(fn (Seance $s) => $s->date && $s->date->gte($today));

        $infoBlock = '<table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:13px">'
            .'<tr><td style="padding:4px 8px;font-weight:bold;width:120px">Opération</td><td style="padding:4px 8px">'.e($operation->nom).'</td></tr>'
            .'<tr><td style="padding:4px 8px;font-weight:bold">Type</td><td style="padding:4px 8px">'.e($operation->typeOperation?->nom ?? '').'</td></tr>'
            .'<tr><td style="padding:4px 8px;font-weight:bold">Du</td><td style="padding:4px 8px">'.($operation->date_debut?->format('d/m/Y') ?? '').' au '.($operation->date_fin?->format('d/m/Y') ?? '').'</td></tr>'
            .'<tr><td style="padding:4px 8px;font-weight:bold">Séances</td><td style="padding:4px 8px">'.$operation->nombre_seances.'</td></tr>'
            .'</table>';

        return [
            'logo' => $logos['{logo}'],
            'logo_operation' => $logos['{logo_operation}'],
            'table_seances' => $buildTable($seances),
            'table_seances_a_venir' => $buildTable($aVenir),
            'bloc_infos' => $infoBlock,
        ];
    }

    public function openPreview(): void
    {
        $this->showPreview = true;
    }

    public function getPreviewHtml(): array
    {
        if (empty($this->selectedParticipants) || empty($this->corps)) {
            return ['objet' => $this->objet, 'corps' => '<p class="text-muted"><em>Saisissez un corps et sélectionnez au moins un participant.</em></p>'];
        }

        $operation = $this->operation->loadMissing(['typeOperation', 'seances']);
        $participant = Participant::with('tiers')->find($this->selectedParticipants[0]);
        if (! $participant) {
            return ['objet' => $this->objet, 'corps' => ''];
        }

        $mail = $this->buildMail($participant, $operation);

        return [
            'objet' => $mail->envelope()->subject,
            'corps' => $mail->corpsHtml,
            'participant' => $participant->tiers?->displayName() ?? '—',
        ];
    }

    public function render(): View
    {
        $allParticipants = $this->getAllParticipants();
        $withEmail = $allParticipants->filter(fn (Participant $p) => ! empty($p->tiers?->email));
        $sansEmail = $allParticipants->count() - $withEmail->count();

        return view('livewire.operation-communication', [
            'participants' => $allParticipants,
            'participantsWithEmailCount' => $withEmail->count(),
            'sansEmailCount' => $sansEmail,
            'seances' => $this->operation->seances()->orderBy('numero')->get(),
            'templates' => $this->getAvailableTemplates(),
            'messageVariables' => CategorieEmail::Message->variables(),
            'unresolvedVariables' => $this->getUnresolvedVariables(),
            'campagnes' => CampagneEmail::where('operation_id', $this->operation->id)
                ->with('envoyePar')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }
}
