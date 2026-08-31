<x-app-layout>
    <x-slot:title>Informations techniques</x-slot:title>

    <div class="container-fluid px-0">
        <p class="text-muted small mb-3">
            Ces informations décrivent la version d’AgoraGestion installée et les composants qui la font tourner.
            Elles sont utiles au support en cas d’anomalie : n’hésitez pas à les joindre à un signalement.
        </p>

        @php
            $blocs = [
                ['titre' => 'AgoraGestion', 'icone' => 'bi-box-seam', 'lignes' => $infos['agoragestion']],
                ['titre' => 'Socle applicatif', 'icone' => 'bi-layers', 'lignes' => $infos['socle']],
                ['titre' => 'Configuration du serveur', 'icone' => 'bi-hdd-rack', 'lignes' => $infos['serveur']],
            ];
        @endphp

        <div class="row g-3">
            @foreach ($blocs as $bloc)
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <h2 class="h6 mb-3" style="color:#1e3a5f;">
                                <i class="bi {{ $bloc['icone'] }} me-1"></i> {{ $bloc['titre'] }}
                            </h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
                                        <tr>
                                            <th scope="col">Élément</th>
                                            <th scope="col">Valeur</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($bloc['lignes'] as $libelle => $valeur)
                                            <tr>
                                                <td>{{ $libelle }}</td>
                                                <td class="font-monospace">{{ $valeur !== '' ? $valeur : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h6 mb-3" style="color:#1e3a5f;">
                            <i class="bi bi-puzzle me-1"></i> Extensions PHP
                        </h2>
                        <p class="text-muted small mb-3">
                            Une extension absente désactive silencieusement une fonctionnalité :
                            la lecture des PDF, la génération d’images ou les calculs comptables.
                        </p>
                        <ul class="list-unstyled mb-0">
                            @foreach ($infos['extensions'] as $extension => $presente)
                                <li class="mb-1">
                                    @if ($presente)
                                        <i class="bi bi-check-circle-fill text-success me-1"></i>
                                    @else
                                        <i class="bi bi-x-circle-fill text-danger me-1"></i>
                                    @endif
                                    <span class="font-monospace">{{ $extension }}</span>
                                    <span class="text-muted small">— {{ $presente ? 'présente' : 'absente' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
