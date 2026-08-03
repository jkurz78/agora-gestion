<x-app-layout>
    <x-slot:title>Cotisations</x-slot:title>
    <div class="container-fluid py-3">
        <livewire:transaction-universelle
            usage-filter="pour_cotisations"
            page-title="Cotisations"
            page-title-icon="people" />
    </div>
</x-app-layout>
