<?php

declare(strict_types=1);

namespace App\Livewire\Portail;

use App\Livewire\Portail\Concerns\WithPortailTenant;
use App\Models\Association;
use App\Models\Tiers;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;

final class MonProfil extends Component
{
    use WithPortailTenant;

    public Association $association;

    // ── Champs éditables uniquement ──────────────────────────────────────────
    // SECURITY: nom, prenom, email, civilite ne sont JAMAIS exposés comme
    // propriétés publiques. Ils sont lus depuis le modèle Tiers en render()
    // et passés à la vue via $locked. Un wire:set sur ces champs jettera
    // une exception Livewire (propriété inconnue) ou restera sans effet.
    public string $adresse_ligne1 = '';

    public string $code_postal = '';

    public string $ville = '';

    public string $pays = '';

    public string $telephone = '';

    public bool $email_optout = false;

    public function mount(Association $association): void
    {
        $this->association = $association;

        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        $this->adresse_ligne1 = (string) ($tiers->adresse_ligne1 ?? '');
        $this->code_postal = (string) ($tiers->code_postal ?? '');
        $this->ville = (string) ($tiers->ville ?? '');
        $this->pays = (string) ($tiers->pays ?? '');
        $this->telephone = (string) ($tiers->telephone ?? '');
        $this->email_optout = (bool) ($tiers->email_optout ?? false);
    }

    /**
     * Sauvegarde les 6 champs éditables avec whitelist explicite.
     *
     * SECURITY: L'update() ne reçoit que les 6 clés listées explicitement —
     * jamais $this->all() qui prendrait aussi $association etc.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'adresse_ligne1' => ['nullable', 'string', 'max:255'],
            'code_postal' => ['nullable', 'string', 'max:20'],
            'ville' => ['nullable', 'string', 'max:120'],
            'pays' => ['nullable', 'string', 'max:80'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email_optout' => ['boolean'],
        ]);

        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        DB::transaction(function () use ($tiers, $validated): void {
            // Whitelist explicite — uniquement les 6 champs autorisés
            Tiers::find($tiers->id)->update([
                'adresse_ligne1' => $validated['adresse_ligne1'],
                'code_postal' => $validated['code_postal'],
                'ville' => $validated['ville'],
                'pays' => $validated['pays'],
                'telephone' => $validated['telephone'],
                'email_optout' => $validated['email_optout'],
            ]);
        });

        Log::info('portail.profil.updated', ['tiers_id' => $tiers->id]);

        session()->flash('success', 'Vos informations ont bien été enregistrées.');
    }

    public function render(): View
    {
        /** @var Tiers $tiers */
        $tiers = Auth::guard('tiers-portail')->user();

        $assocEmail = (string) ($this->association->email ?? '');

        // Champs en lecture seule — jamais exposés comme propriétés publiques
        $locked = [
            'civilite' => $tiers->civilite?->label() ?? '',
            'nom' => $tiers->nom ?? '',
            'prenom' => $tiers->prenom ?? '',
            'email' => $tiers->email ?? '',
        ];

        // Mailto — contactez-nous pour modification identité
        $subjectContact = rawurlencode('Modification de mes informations personnelles');
        $bodyContact = rawurlencode("Bonjour,\n\nJe souhaite modifier mes informations personnelles sur le portail.\n\nMerci.");
        $mailtoContact = $assocEmail !== ''
            ? "mailto:{$assocEmail}?subject={$subjectContact}&body={$bodyContact}"
            : '#';

        // Mailto — demande de suppression RGPD
        $prenom = $tiers->prenom ?? '';
        $nom = $tiers->nom ?? '';
        $subjectRgpd = rawurlencode("Demande de suppression de compte ({$prenom} {$nom})");
        $bodyRgpd = rawurlencode("Bonjour,\n\nJe souhaite exercer mon droit à l'effacement conformément au RGPD et demande la suppression de mon compte sur le portail.\n\nCordialement,\n{$prenom} {$nom}");
        $mailtoRgpd = $assocEmail !== ''
            ? "mailto:{$assocEmail}?subject={$subjectRgpd}&body={$bodyRgpd}"
            : '#';

        return view('livewire.portail.mon-profil', [
            'locked' => $locked,
            'assocEmail' => $assocEmail,
            'mailtoContact' => $mailtoContact,
            'mailtoRgpd' => $mailtoRgpd,
        ])->layout('portail.layouts.authenticated');
    }
}
