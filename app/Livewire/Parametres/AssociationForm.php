<?php

declare(strict_types=1);

namespace App\Livewire\Parametres;

use App\Mail\TestEmail;
use App\Models\CompteBancaire;
use App\Support\CurrentAssociation;
use App\Support\TenantAsset;
use Illuminate\Support\Facades\Mail;
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

    public ?string $anthropic_api_key = null;

    public ?string $email_from = null;

    public ?string $email_from_name = null;

    public string $testEmailTo = '';

    public bool $showTestEmailModal = false;

    public string $testFlashMessage = '';

    public string $testFlashType = '';

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
            $this->facture_conditions_reglement = $association->facture_conditions_reglement;
            $this->facture_mentions_legales = $association->facture_mentions_legales;
            $this->facture_mentions_penalites = $association->facture_mentions_penalites;
            $this->facture_compte_bancaire_id = $association->facture_compte_bancaire_id;
            $this->anthropic_api_key = $association->anthropic_api_key;
            $this->email_from = $association->email_from;
            $this->email_from_name = $association->email_from_name;
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
            'anthropic_api_key' => ['nullable', 'string', 'max:255'],
            'email_from' => ['nullable', 'email', 'max:255'],
            'email_from_name' => ['nullable', 'string', 'max:255'],
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
            'anthropic_api_key' => $this->anthropic_api_key ?: null,
            'email_from' => $this->email_from ?: null,
            'email_from_name' => $this->email_from_name ?: null,
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

    public function openTestEmailModal(): void
    {
        $this->testEmailTo = '';
        $this->testFlashMessage = '';
        $this->testFlashType = '';
        $this->showTestEmailModal = true;
    }

    public function sendTestEmail(): void
    {
        $this->validate([
            'email_from' => 'required|email',
            'testEmailTo' => 'required|email',
        ], [
            'email_from.required' => "L'adresse d'expédition est requise.",
            'testEmailTo.required' => 'Veuillez saisir une adresse destinataire.',
            'testEmailTo.email' => "L'adresse destinataire n'est pas valide.",
        ]);

        try {
            Mail::mailer()
                ->to($this->testEmailTo)
                ->send((new TestEmail($this->nom ?: 'Association'))->from($this->email_from, $this->email_from_name ?: null));

            $this->testFlashMessage = "Email de test envoyé à {$this->testEmailTo}.";
            $this->testFlashType = 'success';
        } catch (\Throwable $e) {
            $this->testFlashMessage = 'Erreur lors de l\'envoi : '.$e->getMessage();
            $this->testFlashType = 'danger';
        }
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

        $comptesBancaires = CompteBancaire::saisieManuelle()->orderBy('nom')->get();

        return view('livewire.parametres.association-form', [
            'logoUrl' => $logoUrl,
            'cachetUrl' => $cachetUrl,
            'comptesBancaires' => $comptesBancaires,
        ]);
    }
}
