<div>
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($exercice)
        <div class="alert alert-danger mb-4">
            <h5 class="alert-heading">
                <i class="bi bi-unlock me-1"></i>
                Réouverture de l'exercice {{ $exercice->label() }}
            </h5>
            <hr>
            <p class="mb-1">
                <strong>Clôturé le</strong>
                {{ $exercice->date_cloture?->format('d/m/Y à H:i') }}
                <strong>par</strong>
                {{ $exercice->cloturePar?->name ?? '—' }}
            </p>
            <p class="mt-3 mb-1"><strong>Conséquences de la réouverture :</strong></p>
            <ul class="mb-0">
                <li>Les modifications sur les transactions et virements redeviennent possibles</li>
                <li>La pièce d’à-nouveaux sera invalidée immédiatement et conservée dans la piste d’audit</li>
                <li>Les documents de clôture éventuellement édités peuvent ne plus être valides</li>
                <li>Cette action sera enregistrée dans la piste d'audit de l'exercice</li>
            </ul>
        </div>

        <div class="mb-4">
            <label for="commentaire" class="form-label fw-semibold">Motif de réouverture <span class="text-danger">*</span></label>
            <textarea
                id="commentaire"
                class="form-control @error('commentaire') is-invalid @enderror"
                rows="3"
                wire:model="commentaire"
                placeholder="Expliquez la raison de la réouverture…"
            ></textarea>
            @error('commentaire')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-4">
            <button
                class="btn btn-danger"
                type="button"
                data-bs-toggle="modal"
                data-bs-target="#confirmerReouvertureModal"
            >
                <i class="bi bi-unlock me-1"></i>
                Réouvrir l'exercice {{ $exercice->label() }}
            </button>
        </div>

        <div wire:ignore.self class="modal fade" id="confirmerReouvertureModal" tabindex="-1" aria-labelledby="confirmerReouvertureLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmerReouvertureLabel">Confirmer la réouverture</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        Réouvrir l'exercice {{ $exercice->label() }} et invalider sa pièce d'à-nouveaux ?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="button" class="btn btn-danger" wire:click="reouvrir" data-bs-dismiss="modal">
                            <i class="bi bi-unlock me-1"></i> Confirmer la réouverture
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($actions->isNotEmpty())
        <h5 class="mt-4 mb-3">Historique de cet exercice</h5>
        <ul class="list-group">
            @foreach ($actions as $action)
                <li class="list-group-item d-flex align-items-start gap-2">
                    <span class="badge {{ $action->action->badge() }} mt-1">{{ $action->action->label() }}</span>
                    <div>
                        <span class="text-muted small">{{ $action->created_at->format('d/m/Y à H:i') }}</span>
                        — {{ $action->user?->name ?? '—' }}
                        @if ($action->commentaire)
                            <br><span class="text-secondary small fst-italic">{{ $action->commentaire }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
