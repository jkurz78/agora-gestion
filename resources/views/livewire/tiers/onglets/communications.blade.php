<div>
    <x-tiers.section-card id="communications" titre="Communications" :compteur="$timeline->total">
        <x-slot:headerExtra>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm" wire:change="setFiltre($event.target.value)">
                    <option value="">Toutes catégories ({{ $timeline->total }})</option>
                    @foreach($timeline->compteursParCategorie as $cat => $nb)
                        <option value="{{ $cat }}" @selected($filtreCategorie === $cat)>
                            {{ $cat }} ({{ $nb }})
                        </option>
                    @endforeach
                </select>
            </div>
        </x-slot:headerExtra>

        @if($timeline->emails->isEmpty())
            <p class="text-muted mb-0 p-3">Aucun email dans cette catégorie.</p>
        @else
            @include('livewire.tiers.onglets.partials.communications-table', ['lignes' => $timeline->emails])
            <div class="px-3 pb-3">{{ $timeline->emails->links() }}</div>
        @endif
    </x-tiers.section-card>

    @if($selected)
        @include('livewire.tiers.onglets.partials.communications-modal-detail', ['email' => $selected])
    @endif
</div>
