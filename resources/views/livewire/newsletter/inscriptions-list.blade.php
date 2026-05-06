<div class="container py-4">
    <h1 class="h3 mb-4">
        <i class="bi bi-envelope-heart"></i> Inscriptions newsletter
    </h1>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'inscriptions' ? 'active' : '' }}"
                    type="button"
                    wire:click="setTab('inscriptions')">
                Inscriptions à traiter
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $tab === 'desinscriptions' ? 'active' : '' }}"
                    type="button"
                    wire:click="setTab('desinscriptions')">
                Désinscriptions à traiter
            </button>
        </li>
    </ul>

    <div class="tab-content">
        @if ($tab === 'inscriptions')
            <div class="text-muted">Liste des inscriptions à traiter (à venir).</div>
        @else
            <div class="text-muted">Liste des désinscriptions à traiter (à venir).</div>
        @endif
    </div>
</div>
