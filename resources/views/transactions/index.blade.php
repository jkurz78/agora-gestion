<x-app-layout>
    <h1 class="mb-4">Transactions</h1>
    <div class="d-flex align-items-center mb-3 gap-2">
        <livewire:transaction-form />
        <div class="ms-auto">
            <livewire:import-csv type="transaction" />
        </div>
    </div>
    <livewire:transaction-list />
</x-app-layout>
