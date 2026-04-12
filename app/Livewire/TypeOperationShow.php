<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Mail\TestEmail;
use App\Models\EmailTemplate;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Models\TypeOperationSeance;
use App\Models\TypeOperationTarif;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class TypeOperationShow extends Component
{
    use WithFileUploads;

    public ?int $typeOperationId = null;

    public string $activeTab = 'general';

    // ── Form fields ──────────────────────────────────────────────
    public string $nom = '';

    public string $libelle_article = '';

    public string $description = '';

    public string $sous_categorie_id = '';

    public string $nombre_seances = '';

    public bool $formulaireActif = false;

    public bool $formulairePrescripteur = false;

    public bool $formulaireParcoursTherapeutique = false;

    public bool $formulaireDroitImage = false;

    public string $formulairePrescripteurTitre = '';

    public string $formulaireQualificatifAtelier = '';

    public bool $reserve_adherents = false;

    public bool $actif = true;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public string $existingLogoPath = '';

    /** @var TemporaryUploadedFile|null */
    public $attestationMedicale = null;

    public string $existingAttestationPath = '';

    // ── Email fields ─────────────────────────────────────────────
    public string $email_from = '';

    public string $email_from_name = '';

    public string $testEmailTo = '';

    public bool $showTestEmailModal = false;

    // ── Email template state ──────────────────────────────────────
    public string $emailSubTab = 'formulaire';

    public bool $showPromoteConfirm = false;

    /** @var array<string, array{id: int|null, objet: string, corps: string, is_default: bool}> */
    public array $emailTemplates = [];

    // ── Tarifs management ────────────────────────────────────────
    /** @var array<int, array{id: int|null, libelle: string, montant: string}> */
    public array $tarifs = [];

    public string $newTarifLibelle = '';

    public string $newTarifMontant = '';

    /** @var array<int, int> */
    public array $tarifsToDelete = [];

    // ── Seance titles ───────────────────────────────────────────
    /** @var array<int, array{numero: int, titre: string}> */
    public array $seanceTitres = [];

    // ── Operations count (for delete protection) ──────────────
    public int $operationsCount = 0;

    // ── Flash message ───────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    public function mount(?TypeOperation $typeOperation = null): void
    {
        if ($typeOperation !== null && $typeOperation->exists) {
            $this->typeOperationId = $typeOperation->id;
            $this->loadFromModel($typeOperation);
        } else {
            $this->loadEmailTemplates(null);
        }
    }

    private function loadFromModel(TypeOperation $type): void
    {
        $type->loadMissing(['tarifs', 'seanceDefaults']);
        $this->operationsCount = $type->operations()->count();

        $this->nom = $type->nom;
        $this->libelle_article = $type->libelle_article ?? '';
        $this->description = $type->description ?? '';
        $this->sous_categorie_id = (string) $type->sous_categorie_id;
        $this->nombre_seances = $type->nombre_seances !== null ? (string) $type->nombre_seances : '';
        $this->formulaireActif = (bool) $type->formulaire_actif;
        $this->formulairePrescripteur = (bool) $type->formulaire_prescripteur;
        $this->formulaireParcoursTherapeutique = (bool) $type->formulaire_parcours_therapeutique;
        $this->formulaireDroitImage = (bool) $type->formulaire_droit_image;
        $this->formulairePrescripteurTitre = $type->formulaire_prescripteur_titre ?? '';
        $this->formulaireQualificatifAtelier = $type->formulaire_qualificatif_atelier ?? '';
        $this->reserve_adherents = (bool) $type->reserve_adherents;
        $this->actif = (bool) $type->actif;
        $this->logo = null;
        $this->existingLogoPath = $type->logo_path ?? '';
        $this->attestationMedicale = null;
        $this->existingAttestationPath = $type->attestation_medicale_path ?? '';
        $this->email_from = $type->email_from ?? '';
        $this->email_from_name = $type->email_from_name ?? '';
        $this->tarifs = $type->tarifs->map(fn (TypeOperationTarif $t) => [
            'id' => $t->id,
            'libelle' => $t->libelle,
            'montant' => (string) $t->montant,
        ])->toArray();
        $this->tarifsToDelete = [];
        $this->loadEmailTemplates($type->id);
        $this->loadSeanceTitres($type);
    }

    private function loadSeanceTitres(TypeOperation $type): void
    {
        $nombreSeances = $type->nombre_seances ?? 0;
        $defaults = $type->seanceDefaults->keyBy('numero');
        $this->seanceTitres = [];

        for ($i = 1; $i <= $nombreSeances; $i++) {
            $this->seanceTitres[] = [
                'numero' => $i,
                'titre' => $defaults[$i]->titre ?? '',
            ];
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function updatedNombreSeances(): void
    {
        $this->syncSeanceTitresCount();
    }

    public function incrementSeances(): void
    {
        $current = $this->nombre_seances !== '' ? (int) $this->nombre_seances : 0;
        $this->nombre_seances = (string) ($current + 1);
        $this->syncSeanceTitresCount();
    }

    public function decrementSeances(): void
    {
        $current = $this->nombre_seances !== '' ? (int) $this->nombre_seances : 0;
        if ($current <= 0) {
            return;
        }
        $this->nombre_seances = $current === 1 ? '' : (string) ($current - 1);
        $this->syncSeanceTitresCount();
    }

    private function syncSeanceTitresCount(): void
    {
        $count = $this->nombre_seances !== '' ? (int) $this->nombre_seances : 0;
        $current = count($this->seanceTitres);

        if ($count > $current) {
            for ($i = $current + 1; $i <= $count; $i++) {
                $this->seanceTitres[] = ['numero' => $i, 'titre' => ''];
            }
        } elseif ($count < $current) {
            $this->seanceTitres = array_slice($this->seanceTitres, 0, $count);
        }
    }

    // ── Save ─────────────────────────────────────────────────────

    /**
     * Called from JS — receives TinyMCE content before saving.
     *
     * @param  array<string, string>  $editorContent
     */
    public function saveWithEditorContent(array $editorContent = []): void
    {
        foreach ($editorContent as $categorie => $corps) {
            if (isset($this->emailTemplates[$categorie])) {
                $this->emailTemplates[$categorie]['corps'] = $corps;
            }
        }
        $this->save();
    }

    public function save(): void
    {
        $rules = [
            'nom' => 'required|string|max:150|unique:type_operations,nom'.($this->typeOperationId ? ','.$this->typeOperationId : ''),
            'description' => 'nullable|string|max:1000',
            'sous_categorie_id' => 'required|exists:sous_categories,id',
            'nombre_seances' => 'nullable|integer|min:1',
            'logo' => 'nullable|image|max:512',
            'attestationMedicale' => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'email_from' => 'nullable|email|max:255',
            'email_from_name' => 'nullable|string|max:255',
        ];

        $this->validate($rules);

        $type = DB::transaction(function (): TypeOperation {
            $logoPath = null;

            if ($this->logo) {
                $logoPath = $this->logo->store('type-operations', 'public');
            }

            $attestationPath = null;

            if ($this->attestationMedicale) {
                $attestationPath = $this->attestationMedicale->store('type-operations/attestations', 'public');
            }

            $data = [
                'nom' => $this->nom,
                'libelle_article' => $this->libelle_article !== '' ? $this->libelle_article : null,
                'description' => $this->description !== '' ? $this->description : null,
                'sous_categorie_id' => (int) $this->sous_categorie_id,
                'nombre_seances' => $this->nombre_seances !== '' ? (int) $this->nombre_seances : null,
                'formulaire_actif' => $this->formulaireActif,
                'formulaire_prescripteur' => $this->formulairePrescripteur,
                'formulaire_parcours_therapeutique' => $this->formulaireParcoursTherapeutique,
                'formulaire_droit_image' => $this->formulaireDroitImage,
                'formulaire_prescripteur_titre' => $this->formulairePrescripteurTitre !== '' ? $this->formulairePrescripteurTitre : null,
                'formulaire_qualificatif_atelier' => $this->formulaireQualificatifAtelier !== '' ? $this->formulaireQualificatifAtelier : null,
                'reserve_adherents' => $this->reserve_adherents,
                'actif' => $this->actif,
                'email_from' => $this->email_from !== '' ? $this->email_from : null,
                'email_from_name' => $this->email_from_name !== '' ? $this->email_from_name : null,
            ];

            if ($logoPath !== null) {
                $data['logo_path'] = $logoPath;
            }

            if ($attestationPath !== null) {
                $data['attestation_medicale_path'] = $attestationPath;
            }

            if ($this->typeOperationId) {
                $type = TypeOperation::findOrFail($this->typeOperationId);

                if ($logoPath !== null && $type->logo_path) {
                    Storage::disk('public')->delete($type->logo_path);
                }

                if ($attestationPath !== null && $type->attestation_medicale_path) {
                    Storage::disk('public')->delete($type->attestation_medicale_path);
                }

                $type->update($data);
            } else {
                $type = TypeOperation::create($data);
                $this->typeOperationId = $type->id;
            }

            $this->syncTarifs($type);
            $this->syncSeanceTitres($type);
            $this->syncEmailTemplates($type);

            return $type;
        });

        $this->flashMessage = 'Type d\'opération enregistré.';
        $this->flashType = 'success';

        // After creation, redirect to show page (only for new types)
        if ($type->wasRecentlyCreated) {
            $this->redirect(route('operations.types-operation.show', $type), navigate: true);
        }
    }

    public function delete(): void
    {
        if ($this->typeOperationId === null) {
            return;
        }

        $type = TypeOperation::withCount('operations')->findOrFail($this->typeOperationId);

        if ($type->operations_count > 0) {
            $this->flashMessage = 'Impossible de supprimer : des opérations utilisent ce type.';
            $this->flashType = 'danger';

            return;
        }

        if ($type->logo_path) {
            Storage::disk('public')->delete($type->logo_path);
        }

        $type->delete();

        $this->redirect(route('operations.types-operation.index'), navigate: true);
    }

    // ── Tarifs ───────────────────────────────────────────────────

    public function addTarif(): void
    {
        if ($this->newTarifLibelle === '' || $this->newTarifMontant === '') {
            return;
        }

        $normalized = str_replace(',', '.', $this->newTarifMontant);
        if (! is_numeric($normalized)) {
            $this->addError('newTarifMontant', 'Le montant doit être un nombre valide.');

            return;
        }

        $this->tarifs[] = [
            'id' => null,
            'libelle' => $this->newTarifLibelle,
            'montant' => $this->newTarifMontant,
        ];

        $this->newTarifLibelle = '';
        $this->newTarifMontant = '';
    }

    public function removeTarif(int $index): void
    {
        if (! isset($this->tarifs[$index])) {
            return;
        }

        $tarif = $this->tarifs[$index];

        if ($tarif['id'] !== null) {
            $this->tarifsToDelete[] = $tarif['id'];
        }

        unset($this->tarifs[$index]);
        $this->tarifs = array_values($this->tarifs);
    }

    // ── Test email ────────────────────────────────────────────────

    public function openTestEmailModal(): void
    {
        $this->flashMessage = '';
        $this->flashType = '';
        $this->testEmailTo = '';
        $this->showTestEmailModal = true;
    }

    public function sendTestEmail(): void
    {
        $this->validate([
            'email_from' => 'required|email',
            'testEmailTo' => 'required|email',
        ], [
            'email_from.required' => 'L\'adresse d\'expédition est requise pour envoyer un test.',
            'testEmailTo.required' => 'Veuillez saisir une adresse destinataire.',
            'testEmailTo.email' => 'L\'adresse destinataire n\'est pas valide.',
        ]);

        try {
            $mail = new TestEmail($this->nom ?: 'Sans nom');

            Mail::mailer()
                ->to($this->testEmailTo)
                ->send($mail->from($this->email_from, $this->email_from_name ?: null));

            $this->flashMessage = "Email de test envoyé à {$this->testEmailTo}.";
            $this->flashType = 'success';
        } catch (\Throwable $e) {
            $this->flashMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->flashType = 'danger';
        }
    }

    // ── Email templates ──────────────────────────────────────────

    public function loadEmailTemplates(?int $typeOperationId): void
    {
        foreach (CategorieEmail::cases() as $cat) {
            if ($cat === CategorieEmail::Message) {
                continue;
            }
            $custom = $typeOperationId !== null
                ? EmailTemplate::where('categorie', $cat->value)
                    ->where('type_operation_id', $typeOperationId)
                    ->first()
                : null;

            if ($custom) {
                $this->emailTemplates[$cat->value] = [
                    'id' => $custom->id,
                    'objet' => $custom->objet,
                    'corps' => $custom->corps,
                    'is_default' => false,
                ];
            } else {
                $default = EmailTemplate::where('categorie', $cat->value)
                    ->whereNull('type_operation_id')
                    ->first();

                $this->emailTemplates[$cat->value] = [
                    'id' => $default?->id,
                    'objet' => $default?->objet ?? '',
                    'corps' => $default?->corps ?? '',
                    'is_default' => true,
                ];
            }
        }
    }

    public function personnaliserTemplate(string $categorie): void
    {
        if (! isset($this->emailTemplates[$categorie]) || ! $this->emailTemplates[$categorie]['is_default']) {
            return;
        }

        $this->emailTemplates[$categorie]['is_default'] = false;
        $this->emailTemplates[$categorie]['id'] = null;
    }

    public function revenirAuDefaut(string $categorie): void
    {
        if (! isset($this->emailTemplates[$categorie]) || $this->emailTemplates[$categorie]['is_default']) {
            return;
        }

        if ($this->emailTemplates[$categorie]['id'] !== null) {
            EmailTemplate::where('id', $this->emailTemplates[$categorie]['id'])->delete();
        }

        $default = EmailTemplate::where('categorie', $categorie)
            ->whereNull('type_operation_id')
            ->first();

        $this->emailTemplates[$categorie] = [
            'id' => $default?->id,
            'objet' => $default?->objet ?? '',
            'corps' => $default?->corps ?? '',
            'is_default' => true,
        ];
    }

    public function promouvoirEnDefaut(string $categorie): void
    {
        if (! isset($this->emailTemplates[$categorie]) || $this->emailTemplates[$categorie]['is_default']) {
            return;
        }

        $default = EmailTemplate::where('categorie', $categorie)
            ->whereNull('type_operation_id')
            ->first();

        $objet = $this->emailTemplates[$categorie]['objet'];
        $corps = $this->emailTemplates[$categorie]['corps'];

        if ($default) {
            $default->update(['objet' => $objet, 'corps' => $corps]);
        } else {
            $default = EmailTemplate::create([
                'categorie' => $categorie,
                'type_operation_id' => null,
                'objet' => $objet,
                'corps' => $corps,
            ]);
        }

        if ($this->emailTemplates[$categorie]['id'] !== null) {
            EmailTemplate::where('id', $this->emailTemplates[$categorie]['id'])->delete();
        }

        $this->emailTemplates[$categorie] = [
            'id' => $default->id,
            'objet' => $default->objet,
            'corps' => $default->corps,
            'is_default' => true,
        ];

        session()->flash('message', 'Modèle par défaut mis à jour. La personnalisation a été supprimée.');
    }

    // ── Private helpers ──────────────────────────────────────────

    private function syncTarifs(TypeOperation $type): void
    {
        foreach ($this->tarifsToDelete as $tarifId) {
            $tarif = TypeOperationTarif::find($tarifId);
            if ($tarif === null) {
                continue;
            }

            if ($tarif->participants()->exists()) {
                $this->tarifs[] = [
                    'id' => $tarif->id,
                    'libelle' => $tarif->libelle,
                    'montant' => (string) $tarif->montant,
                ];
                $this->flashMessage = "Le tarif \"{$tarif->libelle}\" ne peut pas être supprimé car des participants l'utilisent.";
                $this->flashType = 'warning';

                continue;
            }

            $tarif->delete();
        }

        foreach ($this->tarifs as $tarifData) {
            if ($tarifData['id'] !== null) {
                TypeOperationTarif::where('id', $tarifData['id'])->update([
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            } else {
                TypeOperationTarif::create([
                    'type_operation_id' => $type->id,
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            }
        }
    }

    private function syncSeanceTitres(TypeOperation $type): void
    {
        $existingIds = TypeOperationSeance::where('type_operation_id', $type->id)
            ->pluck('id', 'numero');

        $seenNumeros = [];

        foreach ($this->seanceTitres as $item) {
            $numero = $item['numero'];
            $titre = $item['titre'] !== '' ? $item['titre'] : null;
            $seenNumeros[] = $numero;

            if ($existingIds->has($numero)) {
                TypeOperationSeance::where('id', $existingIds[$numero])->update([
                    'titre' => $titre,
                ]);
            } else {
                TypeOperationSeance::create([
                    'type_operation_id' => $type->id,
                    'numero' => $numero,
                    'titre' => $titre,
                ]);
            }
        }

        // Delete rows no longer needed
        TypeOperationSeance::where('type_operation_id', $type->id)
            ->whereNotIn('numero', $seenNumeros)
            ->delete();
    }

    private function syncEmailTemplates(TypeOperation $type): void
    {
        foreach ($this->emailTemplates as $categorie => $data) {
            if ($data['is_default']) {
                continue;
            }

            EmailTemplate::updateOrCreate(
                ['categorie' => $categorie, 'type_operation_id' => $type->id],
                [
                    'objet' => $data['objet'],
                    'corps' => EmailTemplate::sanitizeCorps($data['corps']),
                ],
            );
        }
    }

    public function render(): View
    {
        $sousCategories = SousCategorie::where('pour_inscriptions', true)
            ->with('categorie')
            ->orderBy('nom')
            ->get();

        return view('livewire.type-operation-show', [
            'sousCategories' => $sousCategories,
        ]);
    }
}
