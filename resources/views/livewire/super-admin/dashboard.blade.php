<div class="row g-3">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted small">Associations</h6>
                <div class="h4 mb-1">{{ $kpiActifs }} <small class="text-muted">actives</small></div>
                <div class="small text-muted">{{ $kpiSuspendus }} suspendues · {{ $kpiArchives }} archivées</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted small">Utilisateurs par tenant</h6>
                <div class="h4 mb-1">{{ $kpiUsersParTenant->sum('total') }}</div>
                <div class="small text-muted">sur {{ $kpiUsersParTenant->count() }} tenants</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted small">Stockage tenants</h6>
                <div class="h4 mb-1">{{ $kpiStockageMo }} <small class="text-muted">Mo</small></div>
                <div class="small text-muted">storage/app/associations/</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted small">Queue</h6>
                <div class="h4 mb-1">{{ $kpiJobs }} <small class="text-muted">en attente</small></div>
                <div class="small text-muted">{{ $kpiFailedJobs }} en échec</div>
            </div>
        </div>
    </div>
</div>
