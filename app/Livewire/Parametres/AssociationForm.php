<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\Association;
use App\Models\CompteBancaire;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AssociationForm extends Component
{
    use WithFileUploads;

    public string $nom = '';

    public string $adresse = '';

    public string $code_postal = '';

    public string $ville = '';

    public string $email = '';

    public string $telephone = '';

    public $logo = null;

    public ?string $logo_path = null;

    public $cachet = null;

    public ?string $cachet_signature_path = null;

    public ?string $siret = null;

    public ?string $forme_juridique = null;

    public ?string $facture_conditions_reglement = null;

    public ?string $facture_mentions_legales = null;

    public ?string $facture_mentions_penalites = null;

    public ?int $facture_compte_bancaire_id = null;

    public function mount(): void
    {
        $association = Association::find(1);
        if ($association) {
            $this->nom = $association->nom ?? '';
            $this->adresse = $association->adresse ?? '';
            $this->code_postal = $association->code_postal ?? '';
            $this->ville = $association->ville ?? '';
            $this->email = $association->email ?? '';
            $this->telephone = $association->telephone ?? '';
            $this->logo_path = $association->logo_path;
            $this->cachet_signature_path = $association->cachet_signature_path;
            $this->siret = $association->siret;
            $this->forme_juridique = $association->forme_juridique;
            $this->facture_conditions_reglement = $association->facture_conditions_reglement;
            $this->facture_mentions_legales = $association->facture_mentions_legales;
            $this->facture_mentions_penalites = $association->facture_mentions_penalites;
            $this->facture_compte_bancaire_id = $association->facture_compte_bancaire_id;
        }
    }

    public function save(): void
    {
        $this->validate([
            'nom' => ['required', 'string', 'max:255'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'cachet' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'siret' => ['nullable', 'string', 'max:14'],
            'forme_juridique' => ['nullable', 'string', 'max:255'],
            'facture_conditions_reglement' => ['nullable', 'string', 'max:1000'],
            'facture_mentions_legales' => ['nullable', 'string', 'max:2000'],
            'facture_mentions_penalites' => ['nullable', 'string', 'max:2000'],
            'facture_compte_bancaire_id' => ['nullable', 'integer', 'exists:comptes_bancaires,id'],
        ]);

        $data = [
            'nom' => $this->nom,
            'adresse' => $this->adresse,
            'code_postal' => $this->code_postal,
            'ville' => $this->ville,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'siret' => $this->siret,
            'forme_juridique' => $this->forme_juridique,
            'facture_conditions_reglement' => $this->facture_conditions_reglement,
            'facture_mentions_legales' => $this->facture_mentions_legales,
            'facture_mentions_penalites' => $this->facture_mentions_penalites,
            'facture_compte_bancaire_id' => $this->facture_compte_bancaire_id,
        ];

        if ($this->logo !== null) {
            $extension = $this->logo->extension();
            $path = Storage::disk('public')->putFileAs('association', $this->logo, 'logo.'.$extension);

            if ($path === false) {
                $this->addError('logo', 'Impossible de sauvegarder le logo.');

                return;
            }

            // Delete old file only after new file is successfully written
            if ($this->logo_path !== null && $this->logo_path !== $path && Storage::disk('public')->exists($this->logo_path)) {
                Storage::disk('public')->delete($this->logo_path);
            }

            $data['logo_path'] = $path;
            $this->logo_path = $path;
            $this->logo = null;
        }

        if ($this->cachet !== null) {
            $extension = $this->cachet->extension();
            $path = Storage::disk('public')->putFileAs('association', $this->cachet, 'cachet.'.$extension);

            if ($path === false) {
                $this->addError('cachet', 'Impossible de sauvegarder le cachet.');

                return;
            }

            if ($this->cachet_signature_path !== null && $this->cachet_signature_path !== $path && Storage::disk('public')->exists($this->cachet_signature_path)) {
                Storage::disk('public')->delete($this->cachet_signature_path);
            }

            $data['cachet_signature_path'] = $path;
            $this->cachet_signature_path = $path;
            $this->cachet = null;
        }

        // Direct assignment pattern (id not in fillable)
        $association = Association::find(1) ?? new Association;
        $association->id = 1;
        $association->fill($data)->save();

        session()->flash('success', 'Informations de l\'association mises à jour.');
    }

    public function render(): View
    {
        $logoUrl = null;
        if ($this->logo_path !== null && Storage::disk('public')->exists($this->logo_path)) {
            $logoUrl = Storage::disk('public')->url($this->logo_path);
        }

        $cachetUrl = null;
        if ($this->cachet_signature_path !== null && Storage::disk('public')->exists($this->cachet_signature_path)) {
            $cachetUrl = Storage::disk('public')->url($this->cachet_signature_path);
        }

        $comptesBancaires = CompteBancaire::where('est_systeme', false)->orderBy('nom')->get();

        return view('livewire.parametres.association-form', [
            'logoUrl' => $logoUrl,
            'cachetUrl' => $cachetUrl,
            'comptesBancaires' => $comptesBancaires,
        ]);
    }
}
