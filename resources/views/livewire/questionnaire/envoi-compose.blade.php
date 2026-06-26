<div>
    @if (session('envoi_ok'))
        <div class="alert alert-success py-2 mb-3">
            <i class="bi bi-check-circle me-1"></i>{{ session('envoi_ok') }}
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex align-items-center">
            <h6 class="mb-0"><i class="bi bi-send me-1"></i> Envoyer les invitations</h6>
        </div>
        <div class="card-body">

            {{-- Objet --}}
            <div class="row mb-3">
                <div class="col-md-8">
                    <label class="form-label small fw-semibold">Objet</label>
                    <input type="text" class="form-control form-control-sm" wire:model="objet"
                           placeholder="Ex : Votre avis nous intéresse">
                    @error('objet') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Corps TinyMCE --}}
            <div class="mb-3">
                <label class="form-label small fw-semibold">Corps de l'email</label>
                @include('partials.tinymce-rich-editor', [
                    'id'          => 'q-envoi-corps',
                    'model'       => 'corps',
                    'content'     => $corps,
                    'height'      => 320,
                    'groups'      => [
                        'Participant' => ['{prenom}' => 'Prénom', '{nom}' => 'Nom', '{email_participant}' => 'Email'],
                        'Politesse'   => ['{civilite}' => 'Civilité', '{politesse}' => 'Politesse', '{civilite_nom}' => 'M. NOM', '{politesse_nom}' => 'Monsieur NOM', '{salutation}' => 'Salutation'],
                        'Opération'   => ['{operation}' => 'Opération', '{type_operation}' => 'Type', '{date_debut}' => 'Date début', '{date_fin}' => 'Date fin', '{nb_seances}' => 'Nb séances', '{association}' => 'Association'],
                        'Lien'        => ['{lien_questionnaire}' => 'Lien du questionnaire'],
                    ],
                    'insertItems' => [
                        "Logo de l'association" => '{logo}',
                        'Tableau des séances'   => '{table_seances}',
                    ],
                ])
                @error('corps') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            </div>

            {{-- Sélection des participants --}}
            <div class="mb-3">
                <div class="d-flex align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">
                        Destinataires
                        <span class="badge bg-secondary ms-1">{{ count($selectedParticipants) }} destinataire{{ count($selectedParticipants) > 1 ? 's' : '' }}</span>
                    </label>
                </div>
                <div class="border rounded" style="max-height: 250px; overflow-y: auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880" class="table-dark">
                                <th style="width: 40px;">
                                    <input type="checkbox" class="form-check-input"
                                           wire:click="toggleTousParticipants"
                                           @checked(count($selectedParticipants) === $participantsAvecEmailCount && $participantsAvecEmailCount > 0)>
                                </th>
                                <th>Nom</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($participants as $p)
                                @php $hasEmail = !empty($p->tiers?->email); @endphp
                                <tr class="{{ !$hasEmail ? 'text-muted' : '' }}">
                                    <td>
                                        @if ($hasEmail)
                                            <input type="checkbox" class="form-check-input"
                                                   value="{{ $p->id }}"
                                                   wire:model="selectedParticipants">
                                        @else
                                            <input type="checkbox" class="form-check-input" disabled>
                                        @endif
                                    </td>
                                    <td>{{ $p->tiers?->displayName() ?? '—' }}</td>
                                    <td>
                                        @if ($hasEmail)
                                            {{ $p->tiers->email }}
                                        @else
                                            <em class="small">sans email</em>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted small text-center py-2"><em>Aucun participant.</em></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Actions --}}
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button type="button" class="btn btn-outline-warning"
                        onclick="envoiSyncAndCall('relancer')">
                    <i class="bi bi-arrow-repeat me-1"></i>Relancer les non-répondants
                </button>
                <button type="button" class="btn btn-primary"
                        onclick="envoiSyncAndCall('envoyer')">
                    <i class="bi bi-envelope-paper me-1"></i>Envoyer les invitations
                </button>
            </div>

        </div>
    </div>
</div>

@script
<script>
    window.envoiSyncAndCall = function (action) {
        var syncCorps = window['__qgSync_q-envoi-corps'];
        if (typeof syncCorps === 'function') { syncCorps(); }
        setTimeout(function () { $wire.call(action); }, 150);
    };
</script>
@endscript
