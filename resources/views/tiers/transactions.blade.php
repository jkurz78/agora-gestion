<x-app-layout>
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('tiers.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Tiers
        </a>
        <h1 class="mb-0 h4">Transactions — {{ $tiers->displayName() }}</h1>
    </div>

    <livewire:transaction-universelle
        :tiers-id="$tiers->id"
        :locked-types="['depense', 'recette', 'don', 'cotisation']" />
</x-app-layout>
