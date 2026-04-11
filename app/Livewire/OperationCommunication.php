<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Mail\MessageLibreMail;
use App\Models\CampagneEmail;
use App\Models\EmailLog;
use App\Models\MessageTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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
        return $this->operation->participants()
            ->with('tiers')
            ->get()
            ->filter(fn (Participant $p) => ! empty($p->tiers?->email));
    }

    public function getAllParticipants(): Collection
    {
        return $this->operation->participants()
            ->with('tiers')
            ->get();
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
            '{date_precedente_seance}' => $precedente?->date?->format('d/m/Y') ?? '',
            '{numero_prochaine_seance}' => $prochaine ? (string) $prochaine->numero : '',
            '{numero_precedente_seance}' => $precedente ? (string) $precedente->numero : '',
        ];

        $unresolved = [];
        foreach ($values as $var => $value) {
            if ($value === '' && str_contains($this->corps, $var)) {
                $unresolved[] = $var;
            }
        }

        return $unresolved;
    }

    public function saveAsTemplate(): void
    {
        $this->validate([
            'templateNom' => 'required|string|max:100',
            'objet' => 'required|string|max:255',
            'corps' => 'required|string',
        ]);

        MessageTemplate::create([
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

    public function getAvailableTemplates(): Collection
    {
        return MessageTemplate::with('typeOperation')
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

        $mail = $this->buildMail($participant, $operation);

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

        $campagne = CampagneEmail::create([
            'operation_id' => $operation->id,
            'objet' => $this->objet,
            'corps' => $this->corps,
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
                $mail = $this->buildMail($participant, $operation);

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
                    'statut' => 'envoye',
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

    private function buildMail(Participant $participant, Operation $operation): MessageLibreMail
    {
        $tiers = $participant->tiers;
        $seances = $operation->seances->sortBy('date');
        $today = now()->startOfDay();

        $prochaine = $seances->first(fn (Seance $s) => $s->date && $s->date->gte($today));
        $precedente = $seances->last(fn (Seance $s) => $s->date && $s->date->lt($today));

        $attachmentPaths = [];
        foreach ($this->emailAttachments as $file) {
            $attachmentPaths[] = $file->getRealPath();
        }

        return new MessageLibreMail(
            prenomParticipant: $tiers?->prenom ?? '',
            nomParticipant: $tiers?->nom ?? '',
            operationNom: $operation->nom,
            typeOperationNom: $operation->typeOperation?->nom ?? '',
            dateDebut: $operation->date_debut?->format('d/m/Y') ?? '',
            dateFin: $operation->date_fin?->format('d/m/Y') ?? '',
            nbSeances: $operation->nombre_seances ?? 0,
            dateProchainSeance: $prochaine?->date?->format('d/m/Y'),
            datePrecedenteSeance: $precedente?->date?->format('d/m/Y'),
            numeroProchainSeance: $prochaine?->numero,
            numeroPrecedenteSeance: $precedente?->numero,
            objet: $this->objet,
            corps: $this->corps,
            attachmentPaths: $attachmentPaths,
            typeOperationId: $operation->typeOperation?->id,
        );
    }

    public function render(): View
    {
        return view('livewire.operation-communication', [
            'participants' => $this->getAllParticipants(),
            'participantsWithEmailCount' => $this->getParticipantsWithEmail()->count(),
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
