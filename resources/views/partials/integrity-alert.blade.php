@auth
@php
    $tenantId = \App\Tenant\TenantContext::currentId();
    $integrityData = $tenantId ? \Illuminate\Support\Facades\Cache::get('compta:integrity:' . $tenantId) : null;
    $integrityIssues = $integrityData['issues'] ?? [];
    $integrityCheckedAt = $integrityData['checked_at'] ?? null;
@endphp
@if (! empty($integrityIssues) && (auth()->user()->currentRole() === 'admin' || auth()->user()->isSuperAdmin()))
    <div class="alert alert-danger alert-dismissible mb-0 rounded-0 border-start-0 border-end-0 py-2" role="alert">
        <div class="container-fluid px-4">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>Anomalie comptable détectée</strong> —
            {{ count($integrityIssues) }} problème{{ count($integrityIssues) > 1 ? 's' : '' }}
            d'intégrité{{ $integrityCheckedAt ? ' (vérifié le ' . \Carbon\Carbon::parse($integrityCheckedAt)->format('d/m/Y à H:i') . ')' : '' }}.
            <a href="#integrityDetails" data-bs-toggle="collapse" class="alert-link ms-1">Détails</a>
            <div class="collapse mt-2" id="integrityDetails">
                <ul class="mb-0 small">
                    @foreach ($integrityIssues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
@endif
@endauth
