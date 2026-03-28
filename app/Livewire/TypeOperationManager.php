<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CategorieEmail;
use App\Mail\TestEmail;
use App\Models\EmailTemplate;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class TypeOperationManager extends Component
{
    use WithFileUploads;

    // ── Display mode ──────────────────────────────────────────────
    public bool $modalOnly = false;

    // ── Modal state ──────────────────────────────────────────────
    public bool $showModal = false;

    public ?int $editingId = null;

    // ── Tab state ──────────────────────────────────────────────
    public int $activeTab = 1;

    public int $maxVisitedTab = 1;

    // ── Form fields ──────────────────────────────────────────────
    public string $code = '';

    public string $nom = '';

    public string $description = '';

    public string $sous_categorie_id = '';

    public string $nombre_seances = '';

    public bool $confidentiel = false;

    public bool $reserve_adherents = false;

    public bool $actif = true;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public string $existingLogoPath = '';

    // ── Email fields ──────────────────────────────────────────────
    public string $email_from = '';

    public string $email_from_name = '';

    public string $testEmailTo = '';

    public bool $showTestEmailModal = false;

    // ── Email template state ──────────────────────────────────────
    public string $emailSubTab = 'formulaire';

    /** @var array<string, array{id: int|null, objet: string, corps: string, is_default: bool}> */
    public array $emailTemplates = [];

    // ── Tarifs management ────────────────────────────────────────
    /** @var array<int, array{id: int|null, libelle: string, montant: string}> */
    public array $tarifs = [];

    public string $newTarifLibelle = '';

    public string $newTarifMontant = '';

    // ── Filter ───────────────────────────────────────────────────
    public string $filter = 'tous';

    // ── Flash message ───────────────────────────────────────────
    public string $flashMessage = '';

    public string $flashType = '';

    // ── Tarifs flagged for deletion ──────────────────────────────
    /** @var array<int, int> */
    public array $tarifsToDelete = [];

    public function render(): View
    {
        $query = TypeOperation::with(['sousCategorie', 'tarifs'])
            ->withCount('operations');

        if ($this->filter === 'actif') {
            $query->where('actif', true);
        } elseif ($this->filter === 'inactif') {
            $query->where('actif', false);
        }

        $types = $query->orderBy('code')->get();

        $sousCategories = SousCategorie::where('pour_inscriptions', true)
            ->with('categorie')
            ->orderBy('nom')
            ->get();

        return view('livewire.type-operation-manager', [
            'types' => $types,
            'sousCategories' => $sousCategories,
        ]);
    }

    // ── Modal actions ────────────────────────────────────────────

    #[On('openTypeOperationModal')]
    public function openCreate(): void
    {
        $this->resetForm();
        $this->showModal = true;
        $this->loadEmailTemplates(null);
    }

    public function openEdit(int $id): void
    {
        $type = TypeOperation::with('tarifs')->findOrFail($id);

        $this->editingId = $type->id;
        $this->code = $type->code;
        $this->nom = $type->nom;
        $this->description = $type->description ?? '';
        $this->sous_categorie_id = (string) $type->sous_categorie_id;
        $this->nombre_seances = $type->nombre_seances !== null ? (string) $type->nombre_seances : '';
        $this->confidentiel = $type->confidentiel;
        $this->reserve_adherents = $type->reserve_adherents;
        $this->actif = $type->actif;
        $this->logo = null;
        $this->existingLogoPath = $type->logo_path ?? '';
        $this->email_from = $type->email_from ?? '';
        $this->email_from_name = $type->email_from_name ?? '';
        $this->loadEmailTemplates($type->id);
        $this->testEmailTo = '';
        $this->tarifs = $type->tarifs->map(fn (TypeOperationTarif $t) => [
            'id' => $t->id,
            'libelle' => $t->libelle,
            'montant' => (string) $t->montant,
        ])->toArray();
        $this->tarifsToDelete = [];

        $this->showModal = true;
        $this->maxVisitedTab = 3;
    }

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
            'code' => 'required|string|max:20|unique:type_operations,code'.($this->editingId ? ','.$this->editingId : ''),
            'nom' => 'required|string|max:150|unique:type_operations,nom'.($this->editingId ? ','.$this->editingId : ''),
            'description' => 'nullable|string|max:1000',
            'sous_categorie_id' => 'required|exists:sous_categories,id',
            'nombre_seances' => 'nullable|integer|min:1',
            'logo' => 'nullable|image|max:512',
            'email_from' => 'nullable|email|max:255',
            'email_from_name' => 'nullable|string|max:255',
        ];

        $this->validate($rules);

        $type = DB::transaction(function (): TypeOperation {
            $logoPath = null;

            if ($this->logo) {
                $logoPath = $this->logo->store('type-operations', 'public');
            }

            $data = [
                'code' => $this->code,
                'nom' => $this->nom,
                'description' => $this->description !== '' ? $this->description : null,
                'sous_categorie_id' => (int) $this->sous_categorie_id,
                'nombre_seances' => $this->nombre_seances !== '' ? (int) $this->nombre_seances : null,
                'confidentiel' => $this->confidentiel,
                'reserve_adherents' => $this->reserve_adherents,
                'actif' => $this->actif,
                'email_from' => $this->email_from !== '' ? $this->email_from : null,
                'email_from_name' => $this->email_from_name !== '' ? $this->email_from_name : null,
            ];

            if ($logoPath !== null) {
                $data['logo_path'] = $logoPath;
            }

            if ($this->editingId) {
                $type = TypeOperation::findOrFail($this->editingId);

                // Delete old logo if a new one is uploaded
                if ($logoPath !== null && $type->logo_path) {
                    Storage::disk('public')->delete($type->logo_path);
                }

                $type->update($data);
            } else {
                $type = TypeOperation::create($data);
            }

            // ── Sync tarifs ──────────────────────────────────────
            $this->syncTarifs($type);

            // ── Sync email templates ────────────────────────────
            $this->syncEmailTemplates($type);

            return $type;
        });

        $this->showModal = false;
        $this->resetForm();

        $this->dispatch('typeOperationCreated', id: $type->id);
    }

    public function delete(int $id): void
    {
        $type = TypeOperation::withCount('operations')->findOrFail($id);

        if ($type->operations_count > 0) {
            $this->flashMessage = 'Impossible de supprimer : des opérations utilisent ce type.';
            $this->flashType = 'danger';

            return;
        }

        // Delete logo file if exists
        if ($type->logo_path) {
            Storage::disk('public')->delete($type->logo_path);
        }

        $type->delete();
    }

    // ── Tab navigation ───────────────────────────────────────────

    public function goToTab(int $tab): void
    {
        if ($tab > $this->maxVisitedTab && $this->editingId === null) {
            return;
        }
        $this->activeTab = $tab;
    }

    public function nextTab(): void
    {
        // Validate current tab before advancing (creation mode only)
        if ($this->editingId === null && $this->activeTab === 1) {
            $this->validate([
                'code' => 'required|string|max:20|unique:type_operations,code',
                'nom' => 'required|string|max:150|unique:type_operations,nom',
                'sous_categorie_id' => 'required|exists:sous_categories,id',
            ]);
        }

        if ($this->activeTab < 3) {
            $this->activeTab++;
            if ($this->activeTab > $this->maxVisitedTab) {
                $this->maxVisitedTab = $this->activeTab;
            }
        }
    }

    public function previousTab(): void
    {
        if ($this->activeTab > 1) {
            $this->activeTab--;
        }
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

        // Track existing tarifs for deletion on save
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

    // ── Private helpers ──────────────────────────────────────────

    private function syncTarifs(TypeOperation $type): void
    {
        // Delete tarifs flagged for removal (only if no participants use them)
        foreach ($this->tarifsToDelete as $tarifId) {
            $tarif = TypeOperationTarif::find($tarifId);
            if ($tarif === null) {
                continue;
            }

            if ($tarif->participants()->exists()) {
                // Re-add to the visible tarifs array so the UI stays consistent
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

        // Update existing tarifs and create new ones
        foreach ($this->tarifs as $tarifData) {
            if ($tarifData['id'] !== null) {
                // Update existing
                TypeOperationTarif::where('id', $tarifData['id'])->update([
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            } else {
                // Create new
                TypeOperationTarif::create([
                    'type_operation_id' => $type->id,
                    'libelle' => $tarifData['libelle'],
                    'montant' => (float) str_replace(',', '.', $tarifData['montant']),
                ]);
            }
        }
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

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->activeTab = 1;
        $this->maxVisitedTab = 1;
        $this->code = '';
        $this->nom = '';
        $this->description = '';
        $this->sous_categorie_id = '';
        $this->nombre_seances = '';
        $this->confidentiel = false;
        $this->reserve_adherents = false;
        $this->actif = true;
        $this->logo = null;
        $this->existingLogoPath = '';
        $this->email_from = '';
        $this->email_from_name = '';
        $this->testEmailTo = '';
        $this->showTestEmailModal = false;
        $this->emailSubTab = 'formulaire';
        $this->emailTemplates = [];
        $this->tarifs = [];
        $this->newTarifLibelle = '';
        $this->newTarifMontant = '';
        $this->tarifsToDelete = [];
        $this->resetValidation();
    }
}
