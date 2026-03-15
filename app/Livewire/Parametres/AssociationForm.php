<?php
declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Models\Association;
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

    public function mount(): void
    {
        $association = Association::find(1);
        if ($association) {
            $this->nom         = $association->nom ?? '';
            $this->adresse     = $association->adresse ?? '';
            $this->code_postal = $association->code_postal ?? '';
            $this->ville       = $association->ville ?? '';
            $this->email       = $association->email ?? '';
            $this->telephone   = $association->telephone ?? '';
            $this->logo_path   = $association->logo_path;
        }
    }

    public function save(): void
    {
        $this->validate([
            'nom'         => ['required', 'string', 'max:255'],
            'adresse'     => ['nullable', 'string', 'max:500'],
            'code_postal' => ['nullable', 'string', 'max:10'],
            'ville'       => ['nullable', 'string', 'max:255'],
            'email'       => ['nullable', 'email', 'max:255'],
            'telephone'   => ['nullable', 'string', 'max:30'],
            'logo'        => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        $data = [
            'nom'         => $this->nom,
            'adresse'     => $this->adresse,
            'code_postal' => $this->code_postal,
            'ville'       => $this->ville,
            'email'       => $this->email,
            'telephone'   => $this->telephone,
        ];

        if ($this->logo !== null) {
            $extension = $this->logo->extension();
            $path      = Storage::disk('public')->putFileAs('association', $this->logo, 'logo.'.$extension);

            if ($path === false) {
                $this->addError('logo', 'Impossible de sauvegarder le logo.');
                return;
            }

            // Delete old file only after new file is successfully written
            if ($this->logo_path !== null && $this->logo_path !== $path && Storage::disk('public')->exists($this->logo_path)) {
                Storage::disk('public')->delete($this->logo_path);
            }

            $data['logo_path'] = $path;
            $this->logo_path   = $path;
            $this->logo        = null;
        }

        // Direct assignment pattern (id not in fillable)
        $association = Association::find(1) ?? new Association();
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

        return view('livewire.parametres.association-form', ['logoUrl' => $logoUrl]);
    }
}
