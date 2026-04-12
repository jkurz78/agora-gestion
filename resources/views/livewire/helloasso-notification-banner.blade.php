{{-- resources/views/livewire/helloasso-notification-banner.blade.php --}}
<div>
    @if ($count > 0)
    <div class="alert alert-warning mb-0 rounded-0 border-start-0 border-end-0 py-2 px-4"
         style="border-top: none;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Attention</strong>, les données HelloAsso ne sont pas à jour.
                <strong>{{ $count }} notification(s) reçue(s).</strong>
                <button type="button" class="btn btn-link btn-sm p-0 ms-1"
                        wire:click="toggleDetails">
                    {{ $showDetails ? 'Masquer les détails' : 'Voir les détails' }}
                </button>
            </div>
            <a href="{{ route('banques.helloasso-sync') }}" class="btn btn-warning btn-sm">
                <i class="bi bi-arrow-repeat"></i> Lancer la synchronisation
            </a>
        </div>

        @if ($showDetails)
        <ul class="list-unstyled mt-2 mb-0 ms-4">
            @foreach ($notifications as $notif)
            <li class="small text-muted">
                <i class="bi bi-dot"></i>
                {{ $notif->created_at->format('d/m H:i') }} — {{ $notif->libelle }}
            </li>
            @endforeach
        </ul>
        @endif
    </div>
    @endif
</div>
