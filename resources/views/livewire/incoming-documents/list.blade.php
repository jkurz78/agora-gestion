<div>
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('info'))
        <div class="alert alert-info">{{ session('info') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <label class="form-label mb-0 text-nowrap small">Ajouter un document :</label>
                <input type="file" class="form-control form-control-sm" wire:model="fichierAjoute" accept="application/pdf" style="max-width:400px">
                <button class="btn btn-sm btn-primary text-nowrap" wire:click="ajouter">
                    <i class="bi bi-plus-lg"></i> Ajouter
                </button>
            </div>
            @error('fichierAjoute')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            @if($documents->total() === 0)
                <p class="text-muted text-center my-4">Aucun document en attente.</p>
            @else
                <table class="table table-sm align-middle" style="font-size:0.875rem">
                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                        <tr>
                            <th>Reçu le</th>
                            <th style="width:120px">Expéditeur</th>
                            <th>Fichier</th>
                            <th>Sujet</th>
                            <th>Raison</th>
                            <th style="width:380px">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $espacePrefix = request()->routeIs('compta.*') ? 'compta' : 'gestion';
                        @endphp
                        @foreach($documents as $doc)
                            <tr>
                                <td>{{ $doc->received_at->format('d/m H:i') }}</td>
                                <td class="text-truncate" style="max-width:120px" title="{{ $doc->sender_email }}">{{ $senderLabels[$doc->sender_email] ?? Str::before($doc->sender_email, '@') }}</td>
                                <td>{{ $doc->original_filename }}</td>
                                <td>{{ Str::limit($doc->subject ?? '—', 30) }}</td>
                                <td>
                                    <span class="badge bg-warning text-dark"
                                          title="{{ $doc->reason_detail }}">
                                        {{ match($doc->reason) {
                                            'unclassified' => 'Non classifié',
                                            'not_a_pdf' => 'Non-PDF',
                                            'pdf_unreadable' => 'PDF corrompu',
                                            'qr_not_found' => 'Aucun QR',
                                            'qr_unreadable' => 'QR illisible',
                                            'qr_wrong_environment' => 'Mauvais env',
                                            'qr_no_matching_seance' => 'Séance inconnue',
                                            'decoder_error' => 'Erreur technique',
                                            default => $doc->reason,
                                        } }}
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <a href="{{ route($espacePrefix.'.documents-en-attente.download', $doc) }}"
                                       class="btn btn-sm btn-outline-secondary" target="_blank" title="Aperçu">
                                        👁
                                    </a>
                                    <button class="btn btn-sm btn-outline-primary"
                                            wire:click="ouvrirAssignation({{ $doc->id }})">
                                        Attacher à une séance
                                    </button>
                                    @if(\App\Services\InvoiceOcrService::isConfigured() && Auth::user()->role->canWrite(\App\Enums\Espace::Compta))
                                        <button class="btn btn-sm btn-outline-success"
                                                wire:click="creerDepense({{ $doc->id }})"
                                                title="Créer une dépense depuis ce PDF">
                                            <i class="bi bi-receipt"></i> Créer dépense
                                        </button>
                                    @endif
                                    <button class="btn btn-sm btn-outline-danger"
                                            wire:click="supprimer({{ $doc->id }})"
                                            wire:confirm="Supprimer ce document ?"
                                            title="Supprimer">
                                        🗑
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{ $documents->links() }}
            @endif
        </div>
    </div>

    @if($showAssignModal)
        <div class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,0.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Attacher à une séance</h5>
                        <button type="button" class="btn-close" wire:click="fermerAssignation"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Opération</label>
                        <select class="form-select" wire:model.live="selectedOperationId">
                            <option value="">Choisir…</option>
                            @foreach($operations as $op)
                                <option value="{{ $op->id }}">{{ $op->nom }}</option>
                            @endforeach
                        </select>

                        @if($selectedOperationId)
                            <label class="form-label mt-3">Séance</label>
                            <select class="form-select" wire:model.live="selectedSeanceId">
                                <option value="">Choisir…</option>
                                @foreach($seances as $s)
                                    <option value="{{ $s->id }}">
                                        Séance {{ $s->numero }}{{ $s->titre ? ' — '.$s->titre : '' }}
                                        {{ $s->date ? '('.$s->date->format('d/m/Y').')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            @error('selectedSeanceId')
                                <div class="text-danger small">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" wire:click="fermerAssignation">Annuler</button>
                        <button class="btn btn-primary" wire:click="assignerASeance"
                                @disabled(! $selectedSeanceId)>
                            Attacher
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
