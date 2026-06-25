<div>
    <p class="text-muted small mb-3">
        Une page par participant, avec un QR pour répondre en ligne ou un formulaire à remplir à la main.
    </p>

    {{-- Sélection des participants --}}
    @if ($participants->isNotEmpty())
        <div class="mb-3">
            <label class="form-label fw-semibold">Participants</label>
            <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                @foreach ($participants as $p)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               id="impr-part-{{ $p->id }}"
                               wire:model="selectedParticipants"
                               value="{{ $p->id }}">
                        <label class="form-check-label" for="impr-part-{{ $p->id }}">
                            {{ $p->tiers?->displayName() ?? '—' }}
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Action --}}
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-primary"
                wire:click="imprimer"
                wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="imprimer">Générer le PDF</span>
            <span wire:loading wire:target="imprimer">Génération…</span>
        </button>
    </div>
</div>
