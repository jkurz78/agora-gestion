<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Mail\FormulaireInvitation;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Services\FormulaireTokenService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class ParticipantTable extends Component
{
    public Operation $operation;

    // ── Add modal ──────────────────────────────────────────────
    public bool $showAddModal = false;

    public ?int $addTiersId = null;

    public string $addDateInscription = '';

    public string $addNom = '';

    public string $addPrenom = '';

    public string $addAdresse = '';

    public string $addCodePostal = '';

    public string $addVille = '';

    public string $addTelephone = '';

    public string $addEmail = '';

    public ?int $addTypeOperationTarifId = null;

    // ── Notes modal ────────────────────────────────────────────
    public bool $showNotesModal = false;

    public ?int $notesParticipantId = null;

    public string $medNotes = '';

    // ── Token modal ────────────────────────────────────────────
    public bool $showTokenModal = false;

    public ?string $tokenCode = null;

    public ?string $tokenUrl = null;

    public ?string $tokenExpireAt = null;

    public ?int $tokenParticipantId = null;

    public string $tokenEmailMessage = '';

    public string $tokenEmailType = '';

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function render(): View
    {
        $canSeeSensible = (bool) Auth::user()?->peut_voir_donnees_sensibles;

        $this->operation->loadMissing('typeOperation.tarifs');

        $query = Participant::where('operation_id', $this->operation->id)
            ->with(['tiers', 'referePar', 'formulaireToken', 'typeOperationTarif']);

        if ($canSeeSensible) {
            $query->with('donneesMedicales');
        }

        $participants = $query->orderBy('id')->get();

        return view('livewire.participant-table', [
            'participants' => $participants,
            'canSeeSensible' => $canSeeSensible,
        ]);
    }

    // ── Add modal ──────────────────────────────────────────────

    public function openAddModal(): void
    {
        $this->resetAddFields();
        $this->showAddModal = true;
    }

    #[On('tiers-selected')]
    public function onTiersSelected(int $id): void
    {
        if (! $this->showAddModal) {
            return;
        }

        $this->addTiersId = $id;
    }

    public function addParticipant(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->addTiersId === null) {
            $this->addError('addTiersId', 'Veuillez sélectionner un tiers.');

            return;
        }

        $this->quickAddParticipant($this->addTiersId);
    }

    private function quickAddParticipant(int $tiersId): void
    {
        $exists = Participant::where('tiers_id', $tiersId)
            ->where('operation_id', $this->operation->id)
            ->exists();

        if ($exists) {
            $this->addError('addTiersId', 'Ce tiers est déjà inscrit à cette opération.');

            return;
        }

        Participant::create([
            'tiers_id' => $tiersId,
            'operation_id' => $this->operation->id,
            'date_inscription' => now()->toDateString(),
            'type_operation_tarif_id' => $this->addTypeOperationTarifId,
        ]);

        $this->showAddModal = false;
        $this->resetAddFields();
    }

    // ── Inline field updates ───────────────────────────────────

    public function updateTiersField(int $participantId, string $field, string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allowed = ['nom', 'prenom', 'telephone', 'email'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $participant->tiers->update([$field => $value]);
        $participant->touch();
    }

    public function updateParticipantField(int $participantId, string $field, string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allowed = ['date_inscription'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($field === 'date_inscription' && $value !== '') {
            try {
                $value = Carbon::parse($value)->toDateString();
            } catch (\Throwable) {
                return;
            }
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $participant->update([$field => $value]);
    }

    public function updateMedicalField(int $participantId, string $field, string $value): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $allowed = ['date_naissance', 'sexe', 'taille', 'poids'];
        if (! in_array($field, $allowed, true)) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $med = $participant->donneesMedicales ?? ParticipantDonneesMedicales::create([
            'participant_id' => $participant->id,
        ]);

        if ($field === 'date_naissance' && $value !== '') {
            try {
                Carbon::parse($value);
            } catch (\Throwable) {
                return;
            }
        }

        $med->update([$field => $value !== '' ? $value : null]);
        $participant->touch();
    }

    // ── Remove ─────────────────────────────────────────────────

    public function removeParticipant(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($id);

        $participant->delete();
    }

    // ── Notes modal ────────────────────────────────────────────

    public function openNotesModal(int $participantId): void
    {
        $participant = Participant::with('donneesMedicales')->findOrFail($participantId);
        $this->notesParticipantId = $participant->id;
        $this->medNotes = $participant->donneesMedicales?->notes ?? '';
        $this->showNotesModal = true;
    }

    public function saveNotes(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $participant = Participant::findOrFail($this->notesParticipantId);

        $med = $participant->donneesMedicales ?? ParticipantDonneesMedicales::create([
            'participant_id' => $participant->id,
        ]);

        $notes = $this->medNotes !== '' ? ParticipantDonneesMedicales::sanitizeNotes($this->medNotes) : null;
        $med->update(['notes' => $notes]);

        $participant->touch();
        $this->showNotesModal = false;
        $this->js('window._quillNotesInstance = null;');
    }

    // ── Token modal ────────────────────────────────────────────

    public function genererToken(int $participantId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $participant = Participant::where('operation_id', $this->operation->id)
            ->findOrFail($participantId);

        $token = app(FormulaireTokenService::class)->generate($participant, $this->tokenExpireAt);

        $this->tokenCode = $token->token;
        $this->tokenUrl = route('formulaire.index', ['token' => $token->token]);
        $this->tokenExpireAt = $token->expire_at->format('Y-m-d');
        $this->tokenParticipantId = $participantId;
        $this->tokenEmailMessage = '';
        $this->tokenEmailType = '';
        $this->showTokenModal = true;
    }

    public function genererTokenAvecDate(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->tokenParticipantId === null) {
            return;
        }
        $this->genererToken($this->tokenParticipantId);
    }

    public function ouvrirToken(int $participantId): void
    {
        $participant = Participant::where('operation_id', $this->operation->id)
            ->with('formulaireToken')
            ->findOrFail($participantId);

        $token = $participant->formulaireToken;
        if ($token === null) {
            return;
        }

        $this->tokenCode = $token->token;
        $this->tokenUrl = route('formulaire.index', ['token' => $token->token]);
        $this->tokenExpireAt = $token->expire_at->format('Y-m-d');
        $this->tokenParticipantId = $participantId;
        $this->tokenEmailMessage = '';
        $this->tokenEmailType = '';
        $this->showTokenModal = true;
    }

    public function envoyerTokenParEmail(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->tokenParticipantId === null || $this->tokenUrl === null) {
            return;
        }

        $participant = Participant::with('tiers', 'operation.typeOperation')
            ->findOrFail($this->tokenParticipantId);

        $email = $participant->tiers?->email;
        if (! $email) {
            $this->tokenEmailMessage = 'Ce participant n\'a pas d\'adresse email renseignée.';
            $this->tokenEmailType = 'danger';

            return;
        }

        $typeOp = $participant->operation?->typeOperation;
        if (! $typeOp?->email_from) {
            $this->tokenEmailMessage = 'L\'adresse d\'expédition n\'est pas configurée sur le type d\'opération.';
            $this->tokenEmailType = 'danger';

            return;
        }

        // Load email template (custom for this type, or default)
        $template = EmailTemplate::where('categorie', 'formulaire')
            ->where('type_operation_id', $typeOp->id)
            ->first()
            ?? EmailTemplate::where('categorie', 'formulaire')
                ->whereNull('type_operation_id')
                ->first();

        $destinataireNom = trim(($participant->tiers->nom ?? '').' '.($participant->tiers->prenom ?? ''));

        try {
            $op = $participant->operation;
            $mail = new FormulaireInvitation(
                prenomParticipant: $participant->tiers->prenom ?? 'Participant',
                nomParticipant: $participant->tiers->nom ?? '',
                nomOperation: $op->nom,
                nomTypeOperation: $typeOp->nom,
                formulaireUrl: $this->tokenUrl,
                tokenCode: $this->tokenCode ?? '',
                dateExpiration: Carbon::parse($this->tokenExpireAt)->format('d/m/Y'),
                dateDebut: $op->date_debut?->format('d/m/Y') ?? '',
                dateFin: $op->date_fin?->format('d/m/Y') ?? '',
                nombreSeances: $op->nombre_seances !== null ? (string) $op->nombre_seances : '',
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                libelleArticle: $typeOp->libelle_article,
                typeOperationId: $typeOp->id,
            );

            Mail::mailer()
                ->to($email)
                ->send($mail->from($typeOp->email_from, $typeOp->email_from_name ?? null));

            EmailLog::create([
                'tiers_id' => $participant->tiers_id,
                'participant_id' => $participant->id,
                'operation_id' => $participant->operation_id,
                'categorie' => 'formulaire',
                'email_template_id' => $template?->id,
                'destinataire_email' => $email,
                'destinataire_nom' => $destinataireNom,
                'objet' => $template?->objet ?? 'Formulaire à compléter — '.$op->nom,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
            ]);

            $this->tokenEmailMessage = "Email envoyé à {$email}.";
            $this->tokenEmailType = 'success';
        } catch (\Throwable $e) {
            EmailLog::create([
                'tiers_id' => $participant->tiers_id,
                'participant_id' => $participant->id,
                'operation_id' => $participant->operation_id,
                'categorie' => 'formulaire',
                'email_template_id' => $template?->id,
                'destinataire_email' => $email,
                'destinataire_nom' => $destinataireNom,
                'objet' => $template?->objet ?? 'Formulaire à compléter — '.($participant->operation?->nom ?? ''),
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            $this->tokenEmailMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->tokenEmailType = 'danger';
        }
    }

    // ── Adhérent check ────────────────────────────────────────

    /**
     * Check if the participant's tiers has an active cotisation for the current exercice.
     */
    public function isAdherent(Participant $participant): bool
    {
        if ($participant->tiers_id === null) {
            return false;
        }

        $exercice = app(ExerciceService::class)->current();

        return TransactionLigne::query()
            ->whereHas('sousCategorie', fn ($q) => $q->where('pour_cotisations', true))
            ->whereHas('transaction', fn ($q) => $q
                ->where('tiers_id', $participant->tiers_id)
                ->forExercice($exercice))
            ->exists();
    }

    // ── Helpers ────────────────────────────────────────────────

    private function resetAddFields(): void
    {
        $this->addTiersId = null;
        $this->addDateInscription = '';
        $this->addNom = '';
        $this->addPrenom = '';
        $this->addAdresse = '';
        $this->addCodePostal = '';
        $this->addVille = '';
        $this->addTelephone = '';
        $this->addEmail = '';
        $this->addTypeOperationTarifId = null;
    }
}
