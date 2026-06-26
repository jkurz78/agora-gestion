<div>
    @if (session('scan_ok'))
        <div class="alert alert-success py-2 mb-3">
            <i class="bi bi-check-circle me-1"></i>{{ session('scan_ok') }}
        </div>
    @endif

    {{-- Upload form --}}
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-upload me-1"></i> Importer un scan</h6>
        </div>
        <div class="card-body">
            <div class="row align-items-end">
                <div class="col-md-8">
                    <label class="form-label small fw-semibold">Fichier (PNG, JPG ou PDF)</label>
                    <input type="file" class="form-control form-control-sm" wire:model="fichier" accept=".png,.jpg,.jpeg,.pdf">
                    @error('fichier') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <button class="btn btn-primary btn-sm w-100" wire:click="importer" wire:loading.attr="disabled">
                        <span wire:loading wire:target="importer" class="spinner-border spinner-border-sm me-1"></span>
                        Importer
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Scan list --}}
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-list-ul me-1"></i> Scans ({{ $scans->count() }})</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                    <tr>
                        <th>Date</th>
                        <th>Source</th>
                        <th>QR</th>
                        <th>Participant</th>
                        <th class="text-center">Statut</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($scans as $scan)
                        <tr>
                            <td class="small">{{ $scan->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <span class="badge {{ $scan->source === 'email' ? 'bg-info' : 'bg-secondary' }}">
                                    {{ $scan->source }}
                                </span>
                            </td>
                            <td>
                                @if ($scan->qr_statut === 'detecte')
                                    <span class="text-success"><i class="bi bi-qr-code-scan"></i> Détecté</span>
                                @else
                                    <span class="text-muted"><i class="bi bi-question-circle"></i> Illisible</span>
                                @endif
                            </td>
                            <td>{{ $scan->invitation?->participant?->tiers?->displayName() ?? '—' }}</td>
                            <td class="text-center">
                                @php
                                    $badge = match ($scan->statut) {
                                        'en_attente' => 'bg-warning text-dark',
                                        'rattache'   => 'bg-primary',
                                        'traite'     => 'bg-success',
                                        'ignore'     => 'bg-dark',
                                        default      => 'bg-secondary',
                                    };
                                    $label = match ($scan->statut) {
                                        'en_attente' => 'En attente',
                                        'rattache'   => 'Rattaché',
                                        'traite'     => 'Traité',
                                        'ignore'     => 'Ignoré',
                                        default      => $scan->statut,
                                    };
                                @endphp
                                <span class="badge {{ $badge }}">{{ $label }}</span>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('questionnaires.campagnes.scans.image', $scan) }}"
                                   target="_blank"
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if ($scan->ocrDraft && $scan->ocrDraft->statut === 'brouillon')
                                    <a href="{{ route('questionnaires.campagnes.scans.valider', $scan) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        Valider
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3"><em>Aucun scan importé.</em></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
