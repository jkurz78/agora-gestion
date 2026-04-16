<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Enums\TypeDocumentPrevisionnel;
use App\Mail\DocumentMail;
use App\Models\DocumentPrevisionnel;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Models\ParticipantDonneesMedicales;
use App\Models\Tiers;
use App\Services\DocumentPrevisionnelService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class ParticipantShow extends Component
{
    public Operation $operation;

    public Participant $participant;

    // ── State ────────────────────────────────────────────────────
    public string $successMessage = '';

    public string $activeTab = 'coordonnees';

    public ?int $previewEmailLogId = null;

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

    // ── Engagements ─────────────────────────────────────────────
    public ?string $editDroitImage = null;

    public ?string $editModePaiement = null;

    public ?string $editMoyenPaiement = null;

    public ?bool $editAutorisationContactMedecin = null;

    public ?string $editRgpdAccepteAt = null;

    public ?string $editFormulaireRempliAt = null;

    public bool $engagementEditable = false;

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
        if (! $this->canEdit) {
            return;
        }

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

        // Engagement fields (only if formulaire not already filled online)
        if ($this->engagementEditable) {
            $engagementData = array_filter([
                'droit_image' => $this->editDroitImage !== '' && $this->editDroitImage !== null
                    ? $this->editDroitImage : null,
                'mode_paiement_choisi' => $this->editModePaiement !== '' ? $this->editModePaiement : null,
                'moyen_paiement_choisi' => $this->editMoyenPaiement !== '' ? $this->editMoyenPaiement : null,
            ], fn ($v) => $v !== null);

            if ($this->editAutorisationContactMedecin !== null) {
                $engagementData['autorisation_contact_medecin'] = $this->editAutorisationContactMedecin;
            }

            if ($engagementData !== []) {
                $participant->update($engagementData);
            }
        }

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

        $this->successMessage = 'Modifications enregistrées.';
    }

    // ── Mapping Tiers methods ──────────────────────────────────

    public function mapAdresseParTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->mapAdresseParTiersId === null) {
            return;
        }

        $sourceData = [
            'nom' => $this->participant->adresse_par_nom,
            'prenom' => $this->participant->adresse_par_prenom,
            'entreprise' => $this->participant->adresse_par_etablissement,
            'telephone' => $this->participant->adresse_par_telephone,
            'email' => $this->participant->adresse_par_email,
            'adresse_ligne1' => $this->participant->adresse_par_adresse,
            'code_postal' => $this->participant->adresse_par_code_postal,
            'ville' => $this->participant->adresse_par_ville,
        ];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapAdresseParTiersId,
            sourceLabel: 'Données prescripteur du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme prescripteur',
            context: 'adresse_par',
        );
    }

    public function createAdresseParTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        if (! $this->canEdit) {
            return;
        }

        $this->participant->update(['refere_par_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function mapMedecinTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->mapMedecinTiersId === null) {
            return;
        }

        $med = $this->participant->donneesMedicales;
        $sourceData = $med ? [
            'nom' => $med->medecin_nom,
            'prenom' => $med->medecin_prenom,
            'telephone' => $med->medecin_telephone,
            'email' => $med->medecin_email,
            'adresse_ligne1' => $med->medecin_adresse,
            'code_postal' => $med->medecin_code_postal,
            'ville' => $med->medecin_ville,
        ] : [];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapMedecinTiersId,
            sourceLabel: 'Données médecin du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme médecin traitant',
            context: 'medecin',
        );
    }

    public function createMedecinTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        if (! $this->canEdit) {
            return;
        }

        $this->participant->update(['medecin_tiers_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    public function mapTherapeuteTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if ($this->mapTherapeuteTiersId === null) {
            return;
        }

        $med = $this->participant->donneesMedicales;
        $sourceData = $med ? [
            'nom' => $med->therapeute_nom,
            'prenom' => $med->therapeute_prenom,
            'telephone' => $med->therapeute_telephone,
            'email' => $med->therapeute_email,
            'adresse_ligne1' => $med->therapeute_adresse,
            'code_postal' => $med->therapeute_code_postal,
            'ville' => $med->therapeute_ville,
        ] : [];

        $this->dispatch('open-tiers-merge',
            sourceData: $sourceData,
            tiersId: $this->mapTherapeuteTiersId,
            sourceLabel: 'Données thérapeute du formulaire',
            targetLabel: 'Tiers existant',
            confirmLabel: 'Associer comme thérapeute référent',
            context: 'therapeute',
        );
    }

    public function createTherapeuteTiers(): void
    {
        if (! $this->canEdit) {
            return;
        }

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
        if (! $this->canEdit) {
            return;
        }

        $this->participant->update(['therapeute_tiers_id' => null]);
        $this->dispatch('notify', message: 'Association supprimée.');
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    #[On('tiers-merge-confirmed')]
    public function onTiersMergeConfirmed(int $tiersId, string $context, array $contextData = []): void
    {
        if (! $this->canEdit) {
            return;
        }

        match ($context) {
            'medecin' => $this->participant->update(['medecin_tiers_id' => $tiersId]),
            'therapeute' => $this->participant->update(['therapeute_tiers_id' => $tiersId]),
            'adresse_par' => $this->participant->update(['refere_par_id' => $tiersId]),
            default => null,
        };

        $message = match ($context) {
            'medecin' => 'Tiers associé au médecin traitant.',
            'therapeute' => 'Tiers associé au thérapeute.',
            'adresse_par' => 'Tiers associé au prescripteur.',
            default => 'Tiers associé.',
        };

        $this->dispatch('notify', message: $message);
        $this->participant->refresh();
        $this->loadParticipantData();
    }

    #[On('document-uploaded')]
    public function onDocumentUploaded(): void
    {
        $this->editDocuments = Auth::user()?->peut_voir_donnees_sensibles
            ? $this->getParticipantDocuments($this->participant->id)
            : [];
    }

    public function getCanEditProperty(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    public function render(): View
    {
        $typeOp = $this->operation->typeOperation;
        $canSeeSensible = (bool) Auth::user()?->peut_voir_donnees_sensibles;
        $hasParcours = $typeOp?->formulaire_parcours_therapeutique && $canSeeSensible;
        $hasPrescripteur = (bool) $typeOp?->formulaire_prescripteur;
        $hasEngagements = $typeOp?->formulaire_parcours_therapeutique || $typeOp?->formulaire_droit_image;
        $hasDocuments = $canSeeSensible && (
            $typeOp?->formulaire_parcours_therapeutique
            || ParticipantDocument::where('participant_id', $this->participant->id)->exists()
        );

        $this->operation->loadMissing('typeOperation.tarifs');

        $emailLogs = EmailLog::where('participant_id', $this->participant->id)
            ->with('opens')
            ->orderByDesc('created_at')
            ->get();

        $formulaireToken = $this->participant->formulaireToken;

        // Build combined timeline
        $timeline = collect();

        // Inscription du participant
        $source = $this->participant->est_helloasso ? 'HelloAsso' : 'saisie manuelle';
        $timeline->push([
            'date' => $this->participant->created_at,
            'type' => 'inscription',
            'categorie' => 'inscription',
            'icon' => $this->participant->est_helloasso ? 'bi-cloud-download' : 'bi-person-plus',
            'color' => 'primary',
            'description' => "Inscription ({$source})",
            'detail' => null,
            'copyable' => null,
        ]);

        foreach ($emailLogs as $log) {
            $firstOpen = $log->opens->sortBy('opened_at')->first();
            $openInfo = $firstOpen
                ? ' — ouvert le '.$firstOpen->opened_at->format('d/m/Y à H:i').($log->opens->count() > 1 ? ' ('.$log->opens->count().'x)' : '')
                : '';

            $timeline->push([
                'date' => $log->created_at,
                'type' => 'email',
                'categorie' => $log->categorie,
                'icon' => 'bi-envelope',
                'color' => $log->statut === 'envoye' ? 'success' : 'danger',
                'description' => $log->statut === 'envoye'
                    ? "Email {$log->categorie} envoyé à {$log->destinataire_email}{$openInfo}"
                    : "Erreur envoi {$log->categorie} à {$log->destinataire_email}",
                'detail' => $log->objet_rendu ?? $log->objet,
                'copyable' => null,
                'email_log_id' => $log->corps_html ? $log->id : null,
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
                'pdf_url' => route('operations.documents-previsionnels.pdf', $doc),
                'document_id' => $doc->id,
            ]);
        }

        // Documents participant (scans formulaires papier, etc.)
        $participantDocs = ParticipantDocument::where('participant_id', $this->participant->id)
            ->orderByDesc('created_at')
            ->get();

        foreach ($participantDocs as $doc) {
            $timeline->push([
                'date' => $doc->created_at,
                'type' => 'document_attache',
                'categorie' => 'document',
                'icon' => 'bi-paperclip',
                'color' => 'secondary',
                'description' => "Document : {$doc->label}",
                'detail' => "Source : {$doc->source}",
                'copyable' => null,
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

        // Engagements
        $this->editDroitImage = $participant->droit_image?->value;
        $this->editModePaiement = $participant->mode_paiement_choisi;
        $this->editMoyenPaiement = $participant->moyen_paiement_choisi;
        $this->editAutorisationContactMedecin = $participant->autorisation_contact_medecin;
        $this->editRgpdAccepteAt = $participant->rgpd_accepte_at?->format('d/m/Y à H:i');
        $this->editFormulaireRempliAt = $participant->formulaireToken?->rempli_at?->format('d/m/Y à H:i');
        $this->engagementEditable = $participant->formulaireToken?->rempli_at === null;

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
     * @return array<int, array{id: int, name: string, label: string, size: int, url: string}>
     */
    private function getParticipantDocuments(int $participantId): array
    {
        return ParticipantDocument::where('participant_id', $participantId)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($doc) => Storage::disk('local')->exists($doc->storage_path))
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'name' => $doc->original_filename,
                'label' => $doc->label,
                'size' => Storage::disk('local')->size($doc->storage_path),
                'url' => route('operations.participants.documents.download', [
                    'participant' => $participantId,
                    'filename' => basename($doc->storage_path),
                ]),
            ])
            ->values()
            ->toArray();
    }

    public function envoyerDocumentEmail(int $documentId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $doc = DocumentPrevisionnel::with(['participant.tiers', 'operation.typeOperation'])
            ->findOrFail($documentId);

        $tiers = $doc->participant->tiers;

        if (! $tiers?->email) {
            session()->flash('error', 'Aucune adresse email pour ce tiers.');

            return;
        }

        $typeOp = $doc->operation->typeOperation;
        if (! $typeOp?->effectiveEmailFrom()) {
            session()->flash('error', "Aucune adresse d'expédition configurée (ni sur le type d'opération, ni dans Paramètres > Association > Communication).");

            return;
        }

        // Grammaire française pour le type de document
        [$typeLabel, $article, $articleDe] = match ($doc->type) {
            TypeDocumentPrevisionnel::Devis => ['devis', 'le devis', 'du devis'],
            TypeDocumentPrevisionnel::Proforma => ['pro forma', 'la pro forma', 'de la pro forma'],
        };

        // Récupérer ou générer le PDF
        $pdfContent = $doc->pdf_path && Storage::disk('local')->exists($doc->pdf_path)
            ? Storage::disk('local')->get($doc->pdf_path)
            : app(DocumentPrevisionnelService::class)->genererPdf($doc);

        $pdfFilename = ucfirst($typeLabel)." {$doc->numero} - {$tiers->displayName()}.pdf";

        $template = EmailTemplate::where('categorie', CategorieEmail::Document->value)
            ->whereNull('type_operation_id')
            ->first();

        try {
            $mail = new DocumentMail(
                prenomDestinataire: $tiers->prenom ?? '',
                nomDestinataire: $tiers->nom,
                typeDocument: $typeLabel,
                typeDocumentArticle: $article,
                typeDocumentArticleDe: $articleDe,
                numeroDocument: $doc->numero,
                dateDocument: $doc->date->format('d/m/Y'),
                montantTotal: number_format((float) $doc->montant_total, 2, ',', "\u{00A0}").' €',
                customObjet: $template?->objet,
                customCorps: $template?->corps,
                pdfContent: $pdfContent,
                pdfFilename: $pdfFilename,
                typeOperationId: $doc->operation->type_operation_id,
            );

            Mail::mailer()
                ->to($tiers->email)
                ->send($mail->from($typeOp->effectiveEmailFrom(), $typeOp->effectiveEmailFromName()));

            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $doc->participant_id,
                'operation_id' => $doc->operation_id,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => $mail->envelope()->subject,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
            ]);

            session()->flash('success', ucfirst($typeLabel)." envoyé à {$tiers->email}.");
        } catch (\Throwable $e) {
            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $doc->participant_id,
                'operation_id' => $doc->operation_id,
                'categorie' => CategorieEmail::Document->value,
                'email_template_id' => $template?->id,
                'destinataire_email' => $tiers->email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => ucfirst($typeLabel).' '.$doc->numero,
                'statut' => 'erreur',
                'erreur_message' => $e->getMessage(),
                'envoye_par' => Auth::id(),
            ]);

            session()->flash('error', "Erreur lors de l'envoi : ".$e->getMessage());
        }
    }
}
