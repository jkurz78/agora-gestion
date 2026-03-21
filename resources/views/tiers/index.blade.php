<x-app-layout>
    <x-slot name="header">
        <h2 class="fw-semibold fs-5 text-dark">Tiers</h2>
    </x-slot>

    <div class="container py-4">
        <button class="btn text-white mb-3" style="background:#722281"
                onclick="Livewire.dispatch('open-tiers-form', {prefill: {}})">
            + Nouveau tiers
        </button>
        @livewire('tiers-list')
    </div>
</x-app-layout>
