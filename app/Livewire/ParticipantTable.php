<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Mail\FormulaireInvitation;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Services\FormulaireTokenService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

    // ── Edit modal ─────────────────────────────────────────────
    public bool $showEditModal = false;

    public ?int $editParticipantId = null;

    public string $editNom = '';

    public string $editPrenom = '';

    public string $editAdresse = '';

    public string $editCodePostal = '';

    public string $editVille = '';

    public string $editTelephone = '';

    public string $editEmail = '';

    public string $editDateInscription = '';

    public ?int $editReferePar = null;

    public ?int $editTypeOperationTarifId = null;

    // Medical fields (edit modal — Parcours tab)
    public string $editDateNaissance = '';

    public string $editSexe = '';

    public string $editTaille = '';

    public string $editPoids = '';

    public string $editNomJeuneFille = '';

    public string $editNationalite = '';

    // Medical: médecin & thérapeute (Parcours tab)
    public string $editMedecinNom = '';

    public string $editMedecinPrenom = '';

    public string $editMedecinTelephone = '';

    public string $editMedecinEmail = '';

    public string $editMedecinAdresse = '';

    public string $editMedecinCodePostal = '';

    public string $editMedecinVille = '';

    public string $editTherapeuteNom = '';

    public string $editTherapeutePrenom = '';

    public string $editTherapeuteTelephone = '';

    public string $editTherapeuteEmail = '';

    public string $editTherapeuteAdresse = '';

    public string $editTherapeuteCodePostal = '';

    public string $editTherapeuteVille = '';

    // Prescripteur / Adressé par (Adressé par tab)
    public string $editAdresseParEtablissement = '';

    public string $editAdresseParNom = '';

    public string $editAdresseParPrenom = '';

    public string $editAdresseParTelephone = '';

    public string $editAdresseParEmail = '';

    public string $editAdresseParAdresse = '';

    public string $editAdresseParCodePostal = '';

    public string $editAdresseParVille = '';

    // Engagements (read-only display data)
    public ?string $editDroitImageLabel = null;

    public ?string $editModePaiement = null;

    public ?string $editMoyenPaiement = null;

    public ?bool $editAutorisationContactMedecin = null;

    public ?string $editRgpdAccepteAt = null;

    public ?string $editFormulaireRempliAt = null;

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

    // ── Edit modal documents ──────────────────────────────────
    /** @var array<int, array{name: string, size: int, url: string}> */
    public array $editDocuments = [];

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
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

        $this->quickAddParticipant($id);
    }

    public function addParticipant(): void
    {
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
            'type_operation_tarif_id' => $this->editTypeOperationTarifId,
        ]);

        $this->showAddModal = false;
        $this->resetAddFields();
    }

    // ── Edit modal ─────────────────────────────────────────────

    public function openEditModal(int $participantId): void
    {
        $participant = Participant::with(['tiers', 'donneesMedicales', 'referePar', 'medecinTiers', 'therapeuteTiers', 'formulaireToken'])
            ->findOrFail($participantId);

        $this->editParticipantId = $participant->id;
        $this->editNom = $participant->tiers->nom ?? '';
        $this->editPrenom = $participant->tiers->prenom ?? '';
        $this->editAdresse = $participant->tiers->adresse_ligne1 ?? '';
        $this->editCodePostal = $participant->tiers->code_postal ?? '';
        $this->editVille = $participant->tiers->ville ?? '';
        $this->editTelephone = $participant->tiers->telephone ?? '';
        $this->editEmail = $participant->tiers->email ?? '';
        $this->editDateInscription = $participant->date_inscription->format('Y-m-d');
        $this->editReferePar = $participant->refere_par_id;
        $this->editTypeOperationTarifId = $participant->type_operation_tarif_id;

        // Medical data (Parcours tab)
        $med = $participant->donneesMedicales;
        $this->editDateNaissance = $med?->date_naissance ?? '';
        $this->editSexe = $med?->sexe ?? '';
        $this->editTaille = $med?->taille ?? '';
        $this->editPoids = $med?->poids ?? '';
        $this->editNomJeuneFille = $participant->nom_jeune_fille ?? '';
        $this->editNationalite = $participant->nationalite ?? '';

        // Médecin & Thérapeute (Parcours tab)
        $this->editMedecinNom = $med?->medecin_nom ?? '';
        $this->editMedecinPrenom = $med?->medecin_prenom ?? '';
        $this->editMedecinTelephone = $med?->medecin_telephone ?? '';
        $this->editMedecinEmail = $med?->medecin_email ?? '';
        $this->editMedecinAdresse = $med?->medecin_adresse ?? '';
        $this->editMedecinCodePostal = $med?->medecin_code_postal ?? '';
        $this->editMedecinVille = $med?->medecin_ville ?? '';

        $this->editTherapeuteNom = $med?->therapeute_nom ?? '';
        $this->editTherapeutePrenom = $med?->therapeute_prenom ?? '';
        $this->editTherapeuteTelephone = $med?->therapeute_telephone ?? '';
        $this->editTherapeuteEmail = $med?->therapeute_email ?? '';
        $this->editTherapeuteAdresse = $med?->therapeute_adresse ?? '';
        $this->editTherapeuteCodePostal = $med?->therapeute_code_postal ?? '';
        $this->editTherapeuteVille = $med?->therapeute_ville ?? '';

        // Adressé par (Prescripteur tab)
        $this->editAdresseParEtablissement = $participant->adresse_par_etablissement ?? '';
        $this->editAdresseParNom = $participant->adresse_par_nom ?? '';
        $this->editAdresseParPrenom = $participant->adresse_par_prenom ?? '';
        $this->editAdresseParTelephone = $participant->adresse_par_telephone ?? '';
        $this->editAdresseParEmail = $participant->adresse_par_email ?? '';
        $this->editAdresseParAdresse = $participant->adresse_par_adresse ?? '';
        $this->editAdresseParCodePostal = $participant->adresse_par_code_postal ?? '';
        $this->editAdresseParVille = $participant->adresse_par_ville ?? '';

        // Notes (loaded into medNotes for reuse with saveNotes)
        $this->medNotes = $med?->notes ?? '';
        $this->notesParticipantId = $participant->id;

        // Engagements (read-only)
        $this->editDroitImageLabel = $participant->droit_image?->label();
        $this->editModePaiement = $participant->mode_paiement_choisi;
        $this->editMoyenPaiement = $participant->moyen_paiement_choisi;
        $this->editAutorisationContactMedecin = $participant->autorisation_contact_medecin;
        $this->editRgpdAccepteAt = $participant->rgpd_accepte_at?->format('d/m/Y à H:i');
        $this->editFormulaireRempliAt = $participant->formulaireToken?->rempli_at?->format('d/m/Y à H:i');

        // Documents (only if user can see sensitive data)
        $this->editDocuments = Auth::user()?->peut_voir_donnees_sensibles
            ? $this->getParticipantDocuments($participant->id)
            : [];

        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $participant = Participant::with('tiers')->findOrFail($this->editParticipantId);

        // Update tiers
        $participant->tiers->update([
            'nom' => $this->editNom,
            'prenom' => $this->editPrenom,
            'adresse_ligne1' => $this->editAdresse,
            'code_postal' => $this->editCodePostal,
            'ville' => $this->editVille,
            'telephone' => $this->editTelephone,
            'email' => $this->editEmail,
        ]);

        // Update participant (including prescripteur fields)
        $participant->update([
            'date_inscription' => $this->editDateInscription,
            'refere_par_id' => $this->editReferePar,
            'type_operation_tarif_id' => $this->editTypeOperationTarifId,
            'nom_jeune_fille' => $this->editNomJeuneFille !== '' ? $this->editNomJeuneFille : null,
            'nationalite' => $this->editNationalite !== '' ? $this->editNationalite : null,
            'adresse_par_etablissement' => $this->editAdresseParEtablissement !== '' ? $this->editAdresseParEtablissement : null,
            'adresse_par_nom' => $this->editAdresseParNom !== '' ? $this->editAdresseParNom : null,
            'adresse_par_prenom' => $this->editAdresseParPrenom !== '' ? $this->editAdresseParPrenom : null,
            'adresse_par_telephone' => $this->editAdresseParTelephone !== '' ? $this->editAdresseParTelephone : null,
            'adresse_par_email' => $this->editAdresseParEmail !== '' ? $this->editAdresseParEmail : null,
            'adresse_par_adresse' => $this->editAdresseParAdresse !== '' ? $this->editAdresseParAdresse : null,
            'adresse_par_code_postal' => $this->editAdresseParCodePostal !== '' ? $this->editAdresseParCodePostal : null,
            'adresse_par_ville' => $this->editAdresseParVille !== '' ? $this->editAdresseParVille : null,
        ]);

        // Update medical data if user has permission
        if (Auth::user()?->peut_voir_donnees_sensibles) {
            ParticipantDonneesMedicales::updateOrCreate(
                ['participant_id' => $participant->id],
                [
                    'date_naissance' => $this->editDateNaissance !== '' ? $this->editDateNaissance : null,
                    'sexe' => $this->editSexe !== '' ? $this->editSexe : null,
                    'taille' => $this->editTaille !== '' ? $this->editTaille : null,
                    'poids' => $this->editPoids !== '' ? $this->editPoids : null,
                    'medecin_nom' => $this->editMedecinNom !== '' ? $this->editMedecinNom : null,
                    'medecin_prenom' => $this->editMedecinPrenom !== '' ? $this->editMedecinPrenom : null,
                    'medecin_telephone' => $this->editMedecinTelephone !== '' ? $this->editMedecinTelephone : null,
                    'medecin_email' => $this->editMedecinEmail !== '' ? $this->editMedecinEmail : null,
                    'medecin_adresse' => $this->editMedecinAdresse !== '' ? $this->editMedecinAdresse : null,
                    'medecin_code_postal' => $this->editMedecinCodePostal !== '' ? $this->editMedecinCodePostal : null,
                    'medecin_ville' => $this->editMedecinVille !== '' ? $this->editMedecinVille : null,
                    'therapeute_nom' => $this->editTherapeuteNom !== '' ? $this->editTherapeuteNom : null,
                    'therapeute_prenom' => $this->editTherapeutePrenom !== '' ? $this->editTherapeutePrenom : null,
                    'therapeute_telephone' => $this->editTherapeuteTelephone !== '' ? $this->editTherapeuteTelephone : null,
                    'therapeute_email' => $this->editTherapeuteEmail !== '' ? $this->editTherapeuteEmail : null,
                    'therapeute_adresse' => $this->editTherapeuteAdresse !== '' ? $this->editTherapeuteAdresse : null,
                    'therapeute_code_postal' => $this->editTherapeuteCodePostal !== '' ? $this->editTherapeuteCodePostal : null,
                    'therapeute_ville' => $this->editTherapeuteVille !== '' ? $this->editTherapeuteVille : null,
                    'notes' => $this->medNotes !== '' ? $this->medNotes : null,
                ]
            );
        }

        // Touch participant to bust wire:key cache
        $participant->touch();

        $this->showEditModal = false;
    }

    // ── Inline field updates ───────────────────────────────────

    public function updateTiersField(int $participantId, string $field, string $value): void
    {
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
        if (! Auth::user()?->peut_voir_donnees_sensibles) {
            return;
        }

        $participant = Participant::findOrFail($this->notesParticipantId);

        $med = $participant->donneesMedicales ?? ParticipantDonneesMedicales::create([
            'participant_id' => $participant->id,
        ]);

        $med->update(['notes' => $this->medNotes !== '' ? $this->medNotes : null]);

        $participant->touch();
        $this->showNotesModal = false;
        $this->js('window._quillNotesInstance = null;');
    }

    // ── Token modal ────────────────────────────────────────────

    public function genererToken(int $participantId): void
    {
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
            );

            Mail::mailer()
                ->to($email)
                ->send($mail->from($typeOp->email_from, $typeOp->email_from_name ?? null));

            $this->tokenEmailMessage = "Email envoyé à {$email}.";
            $this->tokenEmailType = 'success';
        } catch (\Throwable $e) {
            $this->tokenEmailMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->tokenEmailType = 'danger';
        }
    }

    // ── Document helper ─────────────────────────────────────────

    /**
     * @return array<int, array{name: string, size: int, url: string}>
     */
    private function getParticipantDocuments(int $participantId): array
    {
        $dir = "participants/{$participantId}";
        if (! Storage::disk('local')->exists($dir)) {
            return [];
        }

        return collect(Storage::disk('local')->files($dir))
            ->map(fn (string $path) => [
                'name' => basename($path),
                'size' => Storage::disk('local')->size($path),
                'url' => route('gestion.participants.documents.download', [
                    'participant' => $participantId,
                    'filename' => basename($path),
                ]),
            ])
            ->toArray();
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
        $this->editTypeOperationTarifId = null;
    }
}
