@php
    $asso = \App\Tenant\TenantContext::current();
    $smtp = \App\Models\SmtpParametres::where('association_id', $asso->id)->first();
    $helloasso = \App\Models\HelloAssoParametres::where('association_id', $asso->id)->first();
    $imap = \App\Models\IncomingMailParametres::where('association_id', $asso->id)->first();
    $compte = \App\Models\CompteBancaire::where('association_id', $asso->id)->first();
    $nbCategories = \App\Models\Categorie::where('association_id', $asso->id)->count();
    $nbTypeOp = \App\Models\TypeOperation::where('association_id', $asso->id)->count();
@endphp

<h3>9. Récapitulatif</h3>
<p class="text-muted">Vérifiez ci-dessous votre configuration avant de terminer l'onboarding.</p>

<dl class="row">
    <dt class="col-sm-4">Nom de l'association</dt>
    <dd class="col-sm-8">{{ $asso->nom }}</dd>

    <dt class="col-sm-4">Adresse</dt>
    <dd class="col-sm-8">{{ $asso->adresse }}<br>{{ $asso->code_postal }} {{ $asso->ville }}</dd>

    <dt class="col-sm-4">SIRET</dt>
    <dd class="col-sm-8">{{ $asso->siret ?: '—' }}</dd>

    <dt class="col-sm-4">Exercice</dt>
    <dd class="col-sm-8">Début au mois {{ $asso->exercice_mois_debut }}</dd>

    <dt class="col-sm-4">Compte bancaire</dt>
    <dd class="col-sm-8">
        @if ($compte) {{ $compte->nom }} — {{ $compte->iban }}
        @else <span class="text-danger">non configuré</span>
        @endif
    </dd>

    <dt class="col-sm-4">SMTP</dt>
    <dd class="col-sm-8">
        @if ($smtp?->enabled) {{ $smtp->smtp_host }}:{{ $smtp->smtp_port }}
        @else <span class="text-warning">désactivé</span>
        @endif
    </dd>

    <dt class="col-sm-4">HelloAsso</dt>
    <dd class="col-sm-8">
        @if ($helloasso)
            {{ $helloasso->organisation_slug }}
            ({{ $helloasso->environnement instanceof \BackedEnum ? $helloasso->environnement->value : $helloasso->environnement }})
        @else non configuré
        @endif
    </dd>

    <dt class="col-sm-4">Boîte IMAP</dt>
    <dd class="col-sm-8">{{ $imap?->imap_host ?: 'non configurée' }}</dd>

    <dt class="col-sm-4">Plan comptable</dt>
    <dd class="col-sm-8">{{ $nbCategories }} catégorie(s) créée(s)</dd>

    <dt class="col-sm-4">Types d'opérations</dt>
    <dd class="col-sm-8">{{ $nbTypeOp }} créé(s)</dd>
</dl>

<div class="alert alert-info small">
    Vous pourrez modifier ces paramètres à tout moment depuis le menu Paramètres.
</div>

<div class="d-flex gap-2 justify-content-between mt-4">
    <button type="button" wire:click="goToStep(8)" class="btn btn-link">← Retour</button>
    <button type="button" wire:click="finalize" class="btn btn-success btn-lg"
            wire:confirm="Terminer l'onboarding et accéder au tableau de bord ?">
        Terminer l'onboarding
    </button>
</div>
