<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Livewire\Parametres\Concerns\AutoriseEcranParametre;
use App\Support\CurrentAssociation;
use App\Support\TenantAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;

final class AssociationForm extends Component
{
    use AutoriseEcranParametre;
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

    protected function cleEcranParametre(): string
    {
        return 'informations';
    }

    public function mount(): void
    {
        $association = CurrentAssociation::tryGet();
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
        ];

        if ($this->logo !== null) {
            $extension = $this->logo->extension();
            $shortName = 'logo.'.$extension;
            $association = CurrentAssociation::get();
            $fullPath = $association->storagePath('branding/'.$shortName);
            $dir = dirname($fullPath);

            $stored = Storage::disk('local')->putFileAs($dir, $this->logo, $shortName);

            if ($stored === false) {
                $this->addError('logo', 'Impossible de sauvegarder le logo.');

                return;
            }

            // Delete old file only after new file is successfully written
            if ($this->logo_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($this->logo_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['logo_path'] = $shortName;
            $this->logo_path = $shortName;
            $this->logo = null;
        }

        if ($this->cachet !== null) {
            $extension = $this->cachet->extension();
            $shortName = 'cachet.'.$extension;
            $association = CurrentAssociation::get();
            $fullPath = $association->storagePath('branding/'.$shortName);
            $dir = dirname($fullPath);

            $stored = Storage::disk('local')->putFileAs($dir, $this->cachet, $shortName);

            if ($stored === false) {
                $this->addError('cachet', 'Impossible de sauvegarder le cachet.');

                return;
            }

            if ($this->cachet_signature_path !== null) {
                $oldFull = $association->storagePath('branding/'.basename($this->cachet_signature_path));
                if ($oldFull !== $fullPath && Storage::disk('local')->exists($oldFull)) {
                    Storage::disk('local')->delete($oldFull);
                }
            }

            $data['cachet_signature_path'] = $shortName;
            $this->cachet_signature_path = $shortName;
            $this->cachet = null;
        }

        // Update the current tenant association
        $association = CurrentAssociation::get();
        $association->fill($data)->save();

        $this->dispatch('form-saved');
        session()->flash('success', 'Informations de l\'association mises à jour.');
    }

    public function render(): View
    {
        $logoUrl = null;
        $association = CurrentAssociation::tryGet();
        if ($this->logo_path !== null && $association !== null) {
            $fullPath = $association->storagePath('branding/'.basename($this->logo_path));
            if (Storage::disk('local')->exists($fullPath)) {
                $logoUrl = TenantAsset::url($fullPath);
            }
        }

        $cachetUrl = null;
        if ($this->cachet_signature_path !== null && $association !== null) {
            $fullPath = $association->storagePath('branding/'.basename($this->cachet_signature_path));
            if (Storage::disk('local')->exists($fullPath)) {
                $cachetUrl = TenantAsset::url($fullPath);
            }
        }

        return view('livewire.parametres.association-form', [
            'logoUrl' => $logoUrl,
            'cachetUrl' => $cachetUrl,
        ]);
    }
}
