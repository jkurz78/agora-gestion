<div>
    {{-- Header with exercice selector and view toggle --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Analyse</h4>
        <div class="d-flex gap-3 align-items-center">
            <div class="btn-group btn-group-sm" role="group">
                <button type="button"
                        class="btn {{ $activeView === 'participants' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('participants')">
                    <i class="bi bi-people me-1"></i>Participants / Règlements
                </button>
                <button type="button"
                        class="btn {{ $activeView === 'financier' ? 'btn-primary' : 'btn-outline-primary' }}"
                        wire:click="switchView('financier')">
                    <i class="bi bi-cash-stack me-1"></i>Financière
                </button>
            </div>
            <select class="form-select form-select-sm" style="width:auto" wire:model.live="filterExercice">
                @foreach($exerciceYears as $year)
                    <option value="{{ $year }}">{{ $year }}/{{ $year + 1 }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Pivot table container --}}
    <div id="pivot-output" class="border rounded bg-white p-2" wire:ignore></div>

    {{-- Init script: runs after CDN scripts are loaded via @push in wrapper --}}
    <script>
        (function() {
            function initPivot() {
                if (typeof jQuery === 'undefined' || typeof jQuery.fn.pivotUI === 'undefined') {
                    setTimeout(initPivot, 100);
                    return;
                }

                var data = @json($pivotData);
                var view = @json($activeView);

                var defaults = view === 'participants'
                    ? { rows: ["Opération"], vals: ["Montant prévu"], aggregatorName: "Somme" }
                    : { rows: ["Opération"], vals: ["Montant"], aggregatorName: "Somme" };

                jQuery("#pivot-output").empty().pivotUI(data, Object.assign({
                    locale: "fr",
                    cols: [],
                    rendererName: "Table",
                }, defaults));
            }

            initPivot();
        })();
    </script>
</div>
