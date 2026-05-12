<div>
    {{-- Bandeau identité (pas de h1) --}}
    <div class="d-flex align-items-center gap-2 mb-3">
        <span style="font-size:1rem">@if($tiers->type === 'entreprise')🏢@else👤@endif</span>
        <span class="fs-5 fw-semibold">{{ $tiers->displayName() }}</span>

        @if($tiers->type === 'entreprise')
            <span class="badge text-bg-secondary">Entreprise</span>
        @else
            <span class="badge text-bg-secondary">Particulier</span>
        @endif

        @if($tiers->email_optout)
            <span class="badge text-bg-warning"><i class="bi bi-envelope-slash me-1"></i>Désinscrit</span>
        @endif

        @if($tiers->est_helloasso)
            <span class="badge text-bg-info" title="Tiers synchronisé depuis HelloAsso">HelloAsso</span>
        @endif
    </div>

    @if($tiers->email || $tiers->telephone || $tiers->ville)
        <div class="text-muted small mb-3">
            @if($tiers->email)<span>{{ $tiers->email }}</span>@endif
            @if($tiers->telephone)<span class="ms-2">• {{ $tiers->telephone }}</span>@endif
            @if($tiers->ville)<span class="ms-2">• {{ $tiers->ville }}</span>@endif
        </div>
    @endif

    {{-- Onglets --}}
    <ul class="nav nav-tabs mb-3">
        @foreach($onglets as $onglet)
            <li class="nav-item">
                <a class="nav-link {{ $onglet['key'] === $currentOnglet ? 'active' : '' }}"
                   href="?onglet={{ $onglet['key'] }}"
                   wire:click.prevent="$set('onglet', '{{ $onglet['key'] }}')">
                    {{ $onglet['label'] }}
                    @if($onglet['count'] !== null)
                        <span class="text-muted">({{ $onglet['count'] }})</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>

    {{-- Contenu onglet --}}
    @if($currentOnglet === 'dons')
        <livewire:tiers.onglets.dons :tiers="$tiers" :key="'dons-'.$tiers->id" wire:lazy />
    @elseif($currentOnglet === 'adhesion')
        <livewire:tiers.onglets.adhesion :tiers="$tiers" :key="'adhesion-'.$tiers->id" wire:lazy />
    @elseif($currentOnglet === 'operations')
        <livewire:tiers.onglets.operations :tiers="$tiers" :key="'operations-'.$tiers->id" wire:lazy />
    @elseif($currentOnglet === 'communications')
        <livewire:tiers.onglets.communications :tiers="$tiers" :key="'communications-'.$tiers->id" wire:lazy />
    @elseif($currentOnglet === 'documents')
        <livewire:tiers.onglets.documents :tiers="$tiers" :key="'documents-'.$tiers->id" wire:lazy />
    @else
        <livewire:tiers.onglets.coordonnees :tiers="$tiers" :key="'coord-'.$tiers->id" wire:lazy />
    @endif
</div>
