<div>
    @if($show && $seance)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            Feuille signée — Séance {{ $seance->numero }}
                            @if($seance->titre_affiche)
                                — {{ $seance->titre_affiche }}
                            @endif
                        </h5>
                        <button type="button" class="btn-close" wire:click="fermer"></button>
                    </div>
                    <div class="modal-body">
                        @if($seance->feuille_signee_path)
                            <div class="alert alert-success">
                                ✓ Feuille signée attachée le
                                {{ $seance->feuille_signee_at?->format('d/m/Y H:i') }}
                                ({{ $seance->feuille_signee_source === 'email' ? 'par email' : 'upload manuel' }}).
                            </div>

                            <a href="{{ route('operations.seances.feuille-signee.view', [$seance->operation_id, $seance]) }}"
                               class="btn btn-outline-primary btn-sm" target="_blank">
                                👁 Ouvrir le PDF
                            </a>
                            <a href="{{ route('operations.seances.feuille-signee.download', [$seance->operation_id, $seance]) }}"
                               class="btn btn-outline-secondary btn-sm">
                                📥 Télécharger
                            </a>
                            @if($this->canEdit)
                                <button class="btn btn-outline-danger btn-sm"
                                        wire:click="retirer"
                                        wire:confirm="Retirer la feuille signée de cette séance ? Les présences redeviendront éditables.">
                                    🗑 Retirer la feuille
                                </button>
                            @endif
                            <hr>
                            <p class="small text-muted">
                                Tu peux remplacer la feuille en uploadant un nouveau scan ci-dessous.
                            </p>
                        @endif

                        @if($this->canEdit)
                            <label class="form-label">Scan PDF de la feuille signée</label>
                            <input type="file" class="form-control" wire:model="feuilleScan" accept="application/pdf">
                            @error('feuilleScan')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                            <div class="mt-3">
                                <button class="btn btn-primary" wire:click="envoyer">
                                    Attacher
                                </button>
                                <button class="btn btn-secondary" wire:click="fermer">
                                    Annuler
                                </button>
                            </div>
                        @else
                            <div class="alert alert-info">
                                Vous n'avez pas les droits pour attacher ou retirer une feuille.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
