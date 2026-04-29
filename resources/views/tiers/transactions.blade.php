<x-app-layout>
    <x-slot:title>Transactions — {{ $tiers->displayName() }}</x-slot:title>
    <x-slot:breadcrumbParent url="{{ route('tiers.index') }}">Liste des tiers</x-slot:breadcrumbParent>
    <div class="container-fluid py-3">
        @if(session()->has('message'))
            <div class="alert alert-success py-2 mb-2 small">{{ session('message') }}</div>
        @endif

        <div class="d-flex justify-content-end mb-2">
            <livewire:tiers-fusion :tiers="$tiers" />
        </div>

        <livewire:transaction-universelle
            :tiers-id="$tiers->id"
            :locked-types="['depense', 'recette', 'don', 'cotisation']"
            :page-title="'Transactions — '.$tiers->displayName()"
            page-title-icon="person-lines-fill" />

        @if($tiers->email_optout)
            <div class="alert alert-warning mt-3 mb-0">
                <i class="bi bi-envelope-slash me-1"></i>
                Ce tiers s'est <strong>désinscrit des communications</strong> (RGPD).
            </div>
        @endif

        {{-- Historique des emails envoyés à ce tiers --}}
        @php
            $emailLogs = \App\Models\EmailLog::where('tiers_id', $tiers->id)
                ->with('opens')
                ->orderByDesc('created_at')
                ->get();
        @endphp

        <div class="card mt-4">
            <div class="card-header">
                <i class="bi bi-envelope me-1"></i> Emails envoyés
            </div>
            <div class="card-body p-0">
                @if ($emailLogs->isEmpty())
                    <p class="text-muted p-3 mb-0">Aucun email envoyé à ce tiers.</p>
                @else
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                            <tr>
                                <th>Date</th>
                                <th>Objet</th>
                                <th>Catégorie</th>
                                <th>Statut</th>
                                <th>Ouverture</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($emailLogs as $log)
                                @php
                                    $opens = $log->opens;
                                    $firstOpen = $opens->sortBy('opened_at')->first();
                                    $openCount = $opens->count();
                                @endphp
                                <tr>
                                    <td>{{ $log->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $log->objet }}</td>
                                    <td>{{ $log->categorie }}</td>
                                    <td>
                                        @if ($log->statut === 'envoye')
                                            <span class="badge bg-success">Envoyé</span>
                                        @else
                                            <span class="badge bg-danger" title="{{ $log->erreur_message ?? '' }}">Erreur</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($firstOpen)
                                            <span class="text-success">
                                                <i class="bi bi-eye-fill"></i>
                                                {{ \Carbon\Carbon::parse($firstOpen->opened_at)->format('d/m/Y H:i') }}
                                                @if ($openCount > 1)
                                                    <span class="badge bg-secondary ms-1">{{ $openCount }}</span>
                                                @endif
                                            </span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    <livewire:tiers-merge-modal />
</x-app-layout>
