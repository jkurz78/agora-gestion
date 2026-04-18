@php
    $recap = $this->recap;
    $asso = $recap['association'];
    $compte = $recap['compte'];
    $smtp = $recap['smtp'];
    $helloasso = $recap['helloasso'];
    $imap = $recap['imap'];
@endphp

<h3>8. Récapitulatif</h3>
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
    <dd class="col-sm-8">{{ $recap['nb_categories'] }} catégorie(s) créée(s)</dd>
</dl>

<div class="alert alert-info small mb-3">
    Vous pourrez modifier ces paramètres à tout moment depuis le menu Paramètres.
</div>

<div class="card border-primary mb-4">
    <div class="card-header bg-primary text-white">
        <strong>Prochaines étapes</strong>
    </div>
    <div class="card-body">
        <p class="mb-2">Une fois l'onboarding terminé, voici les chantiers naturels pour démarrer avec AgoraGestion&nbsp;:</p>
        <ol class="mb-0">
            <li class="mb-2">
                <strong>Importer vos contacts</strong> — rendez-vous sur l'écran
                <a href="{{ url('/tiers') }}" target="_blank" rel="noopener">Tiers</a>
                pour importer vos adhérents, donateurs et fournisseurs (CSV/XLSX) ou les créer un par un.
            </li>
            <li class="mb-2">
                <strong>Reprise comptable</strong> — si vous démarrez en cours d'exercice, saisissez
                ou importez vos transactions passées sur l'écran universel
                <a href="{{ url('/comptabilite/transactions') }}" target="_blank" rel="noopener">Recettes &amp; Dépenses</a>.
            </li>
            <li class="mb-0">
                <strong>Créer vos opérations</strong> — définissez d'abord un ou plusieurs
                <a href="{{ url('/operations/types-operation') }}" target="_blank" rel="noopener">types d'opération</a>
                (formations, adhésions, événements…), puis déclinez-les en
                <a href="{{ url('/operations') }}" target="_blank" rel="noopener">opérations</a>
                concrètes avec leurs séances et participants.
            </li>
        </ol>
    </div>
</div>

<div class="d-flex gap-2 justify-content-between mt-4">
    <button type="button" wire:click="goToStep(7)" class="btn btn-link">← Retour</button>
    <button type="button" wire:click="finalize" class="btn btn-success btn-lg"
            wire:confirm="Terminer l'onboarding et accéder au tableau de bord ?">
        Terminer l'onboarding
    </button>
</div>
