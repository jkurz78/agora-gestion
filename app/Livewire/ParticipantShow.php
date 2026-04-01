<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\DocumentPrevisionnel;
use App\Models\EmailLog;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;

final class ParticipantShow extends Component
{
    public Operation $operation;

    public Participant $participant;

    // ── State ────────────────────────────────────────────────────
    public string $successMessage = '';

    public string $activeTab = 'coordonnees';

    // ── Coordonnées (Tiers) ─────────────────────────────────────
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

    // ── Données personnelles ────────────────────────────────────
    public string $editNomJeuneFille = '';

    public string $editNationalite = '';

    public string $editDateNaissance = '';

    public string $editSexe = '';

    public string $editTaille = '';

    public string $editPoids = '';

    // ── Contacts médicaux ───────────────────────────────────────
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

    public ?int $mapMedecinTiersId = null;

    public ?int $mapTherapeuteTiersId = null;

    // ── Adressé par ─────────────────────────────────────────────
    public string $editAdresseParEtablissement = '';

    public string $editAdresseParNom = '';

    public string $editAdresseParPrenom = '';

    public string $editAdresseParTelephone = '';

    public string $editAdresseParEmail = '';

    public string $editAdresseParAdresse = '';

    public string $editAdresseParCodePostal = '';

    public string $editAdresseParVille = '';

    public ?int $mapAdresseParTiersId = null;

    // ── Notes ───────────────────────────────────────────────────
    public string $medNotes = '';

    // ── Engagements (read-only) ─────────────────────────────────
    public ?string $editDroitImageLabel = null;

    public ?string $editModePaiement = null;

    public ?string $editMoyenPaiement = null;

    public ?bool $editAutorisationContactMedecin = null;

    public ?string $editRgpdAccepteAt = null;

    public ?string $editFormulaireRempliAt = null;

    // ── Documents ───────────────────────────────────────────────
    /** @var array<int, array{name: string, size: int, url: string}> */
    public array $editDocuments = [];

    public function mount(Operation $operation, Participant $participant): void
    {
        $this->operation = $operation;
        $this->participant = $participant->loadMissing([
            'tiers', 'donneesMedicales', 'referePar',
            'medecinTiers', 'therapeuteTiers', 'formulaireToken',
        ]);

        $this->loadParticipantData();
    }

    public function save(): void
    {
        $participant = $this->participant->loadMissing('tiers');

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

        $this->dispatch('close-participant');
    }

    // ── Mapping Tiers methods ──────────────────────────────────

    public function mapAdresseParTiers(): void
    {
        if ($this->mapAdresseParTiersId === null) {
            return;
        }
        $this->participant->update(['refere_par_id' => $this->mapAdresseParTiersId]);
        $this->dispatch('notify', message: 'Tiers associé au prescripteur.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function createAdresseParTiers(): void
    {
        $tiers = Tiers::create([
            'nom' => $this->participant->adresse_par_nom,
            'prenom' => $this->participant->adresse_par_prenom,
            'entreprise' => $this->participant->adresse_par_etablissement,
            'telephone' => $this->participant->adresse_par_telephone,
            'email' => $this->participant->adresse_par_email,
            'adresse_ligne1' => $this->participant->adresse_par_adresse,
            'code_postal' => $this->participant->adresse_par_code_postal,
            'ville' => $this->participant->adresse_par_ville,
            'type' => 'particulier',
        ]);
        $this->participant->update(['refere_par_id' => $tiers->id]);
        $this->dispatch('notify', message: 'Tiers créé et associé.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function unlinkAdresseParTiers(): void
    {
        $this->participant->update(['refere_par_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function mapMedecinTiers(): void
    {
        if ($this->mapMedecinTiersId === null) {
            return;
        }
        $this->participant->update(['medecin_tiers_id' => $this->mapMedecinTiersId]);
        $this->dispatch('notify', message: 'Tiers associé au médecin traitant.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function createMedecinTiers(): void
    {
        $med = $this->participant->donneesMedicales;
        if ($med === null) {
            return;
        }
        $tiers = Tiers::create([
            'nom' => $med->medecin_nom,
            'prenom' => $med->medecin_prenom,
            'telephone' => $med->medecin_telephone,
            'email' => $med->medecin_email,
            'adresse_ligne1' => $med->medecin_adresse,
            'code_postal' => $med->medecin_code_postal,
            'ville' => $med->medecin_ville,
            'type' => 'particulier',
        ]);
        $this->participant->update(['medecin_tiers_id' => $tiers->id]);
        $this->dispatch('notify', message: 'Tiers créé et associé au médecin.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function unlinkMedecinTiers(): void
    {
        $this->participant->update(['medecin_tiers_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function mapTherapeuteTiers(): void
    {
        if ($this->mapTherapeuteTiersId === null) {
            return;
        }
        $this->participant->update(['therapeute_tiers_id' => $this->mapTherapeuteTiersId]);
        $this->dispatch('notify', message: 'Tiers associé au thérapeute.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function createTherapeuteTiers(): void
    {
        $med = $this->participant->donneesMedicales;
        if ($med === null) {
            return;
        }
        $tiers = Tiers::create([
            'nom' => $med->therapeute_nom,
            'prenom' => $med->therapeute_prenom,
            'telephone' => $med->therapeute_telephone,
            'email' => $med->therapeute_email,
            'adresse_ligne1' => $med->therapeute_adresse,
            'code_postal' => $med->therapeute_code_postal,
            'ville' => $med->therapeute_ville,
            'type' => 'particulier',
        ]);
        $this->participant->update(['therapeute_tiers_id' => $tiers->id]);
        $this->dispatch('notify', message: 'Tiers créé et associé au thérapeute.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function unlinkTherapeuteTiers(): void
    {
        $this->participant->update(['therapeute_tiers_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function render(): View
    {
        $typeOp = $this->operation->typeOperation;
        $canSeeSensible = (bool) Auth::user()?->peut_voir_donnees_sensibles;
        $hasParcours = $typeOp?->formulaire_parcours_therapeutique && $canSeeSensible;
        $hasPrescripteur = (bool) $typeOp?->formulaire_prescripteur;
        $hasEngagements = $typeOp?->formulaire_parcours_therapeutique || $typeOp?->formulaire_droit_image;
        $hasDocuments = $canSeeSensible && $typeOp?->formulaire_parcours_therapeutique;

        $this->operation->loadMissing('typeOperation.tarifs');

        $emailLogs = EmailLog::where('participant_id', $this->participant->id)
            ->orderByDesc('created_at')
            ->get();

        $formulaireToken = $this->participant->formulaireToken;

        // Build combined timeline
        $timeline = collect();

        foreach ($emailLogs as $log) {
            $timeline->push([
                'date' => $log->created_at,
                'type' => 'email',
                'categorie' => $log->categorie,
                'icon' => 'bi-envelope',
                'color' => $log->statut === 'envoye' ? 'success' : 'danger',
                'description' => $log->statut === 'envoye'
                    ? "Email {$log->categorie} envoyé à {$log->destinataire_email}"
                    : "Erreur envoi {$log->categorie} à {$log->destinataire_email}",
                'detail' => $log->objet,
                'copyable' => null,
            ]);
        }

        if ($formulaireToken) {
            $timeline->push([
                'date' => $formulaireToken->created_at,
                'type' => 'token_genere',
                'categorie' => 'formulaire',
                'icon' => 'bi-key',
                'color' => 'secondary',
                'description' => 'Token généré',
                'detail' => $formulaireToken->token,
                'copyable' => $formulaireToken->token,
            ]);
        }

        if ($formulaireToken?->rempli_at) {
            $timeline->push([
                'date' => $formulaireToken->rempli_at,
                'type' => 'formulaire_rempli',
                'categorie' => 'formulaire',
                'icon' => 'bi-check-circle-fill',
                'color' => 'primary',
                'description' => 'Formulaire rempli depuis '.$formulaireToken->rempli_ip,
                'detail' => null,
                'copyable' => null,
            ]);
        }

        // Documents prévisionnels (devis / pro forma)
        $documents = DocumentPrevisionnel::where('participant_id', $this->participant->id)
            ->with('operation')
            ->orderByDesc('created_at')
            ->get();

        foreach ($documents as $doc) {
            $timeline->push([
                'date' => $doc->created_at,
                'type' => 'document_previsionnel',
                'categorie' => $doc->type->value,
                'icon' => $doc->type === TypeDocumentPrevisionnel::Devis
                    ? 'bi-file-earmark-text'
                    : 'bi-file-earmark-ruled',
                'color' => 'info',
                'description' => sprintf(
                    '%s %s (v%d) — %s — %s',
                    $doc->type->label(),
                    $doc->numero,
                    $doc->version,
                    $doc->operation->nom,
                    number_format((float) $doc->montant_total, 2, ',', "\u{00A0}").' €',
                ),
                'detail' => null,
                'copyable' => null,
                'pdf_url' => route('gestion.documents-previsionnels.pdf', $doc),
            ]);
        }

        $timeline = $timeline->sortByDesc('date')->values();

        return view('livewire.participant-show', [
            'typeOp' => $typeOp,
            'canSeeSensible' => $canSeeSensible,
            'hasParcours' => $hasParcours,
            'hasPrescripteur' => $hasPrescripteur,
            'hasEngagements' => $hasEngagements,
            'hasDocuments' => $hasDocuments,
            'timeline' => $timeline,
        ]);
    }

    private function loadParticipantData(): void
    {
        $participant = $this->participant;
        $tiers = $participant->tiers;

        // Coordonnées
        $this->editNom = $tiers->nom ?? '';
        $this->editPrenom = $tiers->prenom ?? '';
        $this->editAdresse = $tiers->adresse_ligne1 ?? '';
        $this->editCodePostal = $tiers->code_postal ?? '';
        $this->editVille = $tiers->ville ?? '';
        $this->editTelephone = $tiers->telephone ?? '';
        $this->editEmail = $tiers->email ?? '';
        $this->editDateInscription = $participant->date_inscription->format('Y-m-d');
        $this->editReferePar = $participant->refere_par_id;
        $this->editTypeOperationTarifId = $participant->type_operation_tarif_id;

        // Données personnelles
        $med = $participant->donneesMedicales;
        $this->editDateNaissance = $med?->date_naissance ?? '';
        $this->editSexe = $med?->sexe ?? '';
        $this->editTaille = $med?->taille ?? '';
        $this->editPoids = $med?->poids ?? '';
        $this->editNomJeuneFille = $participant->nom_jeune_fille ?? '';
        $this->editNationalite = $participant->nationalite ?? '';

        // Contacts médicaux
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

        // Adressé par
        $this->editAdresseParEtablissement = $participant->adresse_par_etablissement ?? '';
        $this->editAdresseParNom = $participant->adresse_par_nom ?? '';
        $this->editAdresseParPrenom = $participant->adresse_par_prenom ?? '';
        $this->editAdresseParTelephone = $participant->adresse_par_telephone ?? '';
        $this->editAdresseParEmail = $participant->adresse_par_email ?? '';
        $this->editAdresseParAdresse = $participant->adresse_par_adresse ?? '';
        $this->editAdresseParCodePostal = $participant->adresse_par_code_postal ?? '';
        $this->editAdresseParVille = $participant->adresse_par_ville ?? '';

        // Notes
        $this->medNotes = $med?->notes ?? '';

        // Engagements (read-only)
        $this->editDroitImageLabel = $participant->droit_image?->label();
        $this->editModePaiement = $participant->mode_paiement_choisi;
        $this->editMoyenPaiement = $participant->moyen_paiement_choisi;
        $this->editAutorisationContactMedecin = $participant->autorisation_contact_medecin;
        $this->editRgpdAccepteAt = $participant->rgpd_accepte_at?->format('d/m/Y à H:i');
        $this->editFormulaireRempliAt = $participant->formulaireToken?->rempli_at?->format('d/m/Y à H:i');

        // Documents
        $this->editDocuments = Auth::user()?->peut_voir_donnees_sensibles
            ? $this->getParticipantDocuments($participant->id)
            : [];

        // Reset mapping selectors
        $this->mapAdresseParTiersId = null;
        $this->mapMedecinTiersId = null;
        $this->mapTherapeuteTiersId = null;
    }

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
}
