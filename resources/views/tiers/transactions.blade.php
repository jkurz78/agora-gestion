<x-app-layout>
    <div class="container-fluid py-3">
        <div class="mb-2">
            <a href="{{ route('tiers.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Tiers
            </a>
        </div>
        <livewire:transaction-universelle
            :tiers-id="$tiers->id"
            :locked-types="['depense', 'recette', 'don', 'cotisation']"
            :page-title="'Transactions — '.$tiers->displayName()"
            page-title-icon="person-lines-fill" />
    </div>
</x-app-layout>
