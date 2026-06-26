<div>
    @if ($showRemplacer)
        <div class="alert alert-warning mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Ce participant a déjà répondu en ligne.</strong>
            La réponse existante sera remplacée par la saisie papier.
            <div class="mt-2">
                <button class="btn btn-warning btn-sm" wire:click="valider">Confirmer le remplacement</button>
                <button class="btn btn-outline-secondary btn-sm ms-1" wire:click="$set('showRemplacer', false)">Annuler</button>
            </div>
        </div>
    @endif

    <div class="row">
        {{-- Left: scan image --}}
        <div class="col-md-5">
            <div class="card sticky-top" style="top: 1rem;">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-image me-1"></i> Scan</h6>
                </div>
                <div class="card-body p-1">
                    <img src="{{ route('questionnaires.campagnes.scans.image', $scan) }}"
                         alt="Scan du questionnaire"
                         class="img-fluid border rounded">
                </div>
                @if ($participant)
                    <div class="card-footer small text-muted">
                        <i class="bi bi-person me-1"></i> {{ $participant->displayName() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Right: OCR values + correction form --}}
        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-robot me-1"></i> Réponses détectées</h6>
                    @if ($hasExisting)
                        <span class="badge bg-warning text-dark">Réponse existante</span>
                    @endif
                </div>
                <div class="card-body">
                    @foreach ($questions as $q)
                        @if (! $q->type->estReponse())
                            <h6 class="mt-3 mb-2 text-muted">{{ $q->libelle }}</h6>
                            @continue
                        @endif

                        @php
                            $qid = (string) $q->id;
                            $ocrEntry = $payload[$qid] ?? null;
                            $confidence = $ocrEntry['confidence'] ?? null;
                        @endphp

                        <div class="mb-3 p-2 rounded {{ $q->obligatoire ? 'border border-primary-subtle' : '' }}">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <label class="form-label small fw-semibold mb-0">
                                    {{ $q->libelle }}
                                    @if ($q->obligatoire)
                                        <span class="text-danger">*</span>
                                    @endif
                                </label>
                                @if ($confidence !== null)
                                    @php
                                        $confPct = round($confidence * 100);
                                        $confClass = $confPct >= 80 ? 'bg-success' : ($confPct >= 50 ? 'bg-warning text-dark' : 'bg-danger');
                                    @endphp
                                    <span class="badge {{ $confClass }} ms-2" title="Confiance OCR">
                                        {{ $confPct }}%
                                    </span>
                                @endif
                            </div>

                            @switch($q->type->value)
                                @case('texte_court')
                                    <input type="text" class="form-control form-control-sm"
                                           wire:model="valeurs.{{ $qid }}">
                                    @break

                                @case('texte_long')
                                    <textarea class="form-control form-control-sm" rows="3"
                                              wire:model="valeurs.{{ $qid }}"></textarea>
                                    @break

                                @case('satisfaction')
                                @case('satisfaction_texte_long')
                                    <div class="d-flex gap-2 mb-1">
                                        @for ($i = 1; $i <= 5; $i++)
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio"
                                                       name="q_{{ $qid }}"
                                                       id="q_{{ $qid }}_{{ $i }}"
                                                       value="{{ $i }}"
                                                       wire:model="valeurs.{{ $qid }}">
                                                <label class="form-check-label" for="q_{{ $qid }}_{{ $i }}">{{ $i }}</label>
                                            </div>
                                        @endfor
                                    </div>
                                    @break

                                @case('ressenti')
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="small text-muted">0</span>
                                        <input type="range" class="form-range flex-grow-1" min="0" max="100"
                                               wire:model="valeurs.{{ $qid }}">
                                        <span class="small text-muted">100</span>
                                        <span class="badge bg-secondary ms-1">{{ $valeurs[$qid] ?? '—' }}</span>
                                    </div>
                                    @break

                                @case('case_a_cocher')
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               wire:model="valeurs.{{ $qid }}"
                                               id="q_{{ $qid }}">
                                        <label class="form-check-label" for="q_{{ $qid }}">Oui</label>
                                    </div>
                                    @break

                                @case('choix_unique')
                                    <select class="form-select form-select-sm" wire:model="valeurs.{{ $qid }}">
                                        <option value="">— Choisir —</option>
                                        @foreach ($q->options() as $opt)
                                            <option value="{{ $opt['valeur'] }}">{{ $opt['libelle'] }}</option>
                                        @endforeach
                                    </select>
                                    @break
                            @endswitch
                        </div>
                    @endforeach

                    {{-- Contact consent --}}
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" wire:model="accepteContact" id="accepte-contact">
                        <label class="form-check-label" for="accepte-contact">
                            Le participant accepte d'être recontacté
                        </label>
                    </div>
                </div>

                <div class="card-footer d-flex justify-content-between">
                    <button class="btn btn-outline-secondary" wire:click="ignorer"
                            wire:confirm="Ignorer ce scan ? L'OCR sera rejeté.">
                        <i class="bi bi-x-circle me-1"></i> Ignorer
                    </button>
                    <button class="btn btn-primary" wire:click="valider">
                        <i class="bi bi-check-circle me-1"></i> Valider et enregistrer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
