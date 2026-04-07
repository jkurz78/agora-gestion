{{-- Couleurs app — surcharge Bootstrap (source unique pour toute l'app) --}}
<style>
    :root,
    [data-bs-theme="light"] {
        --bs-danger:         #B5453A;
        --bs-danger-rgb:     181, 69, 58;
        --bs-success:        #2E7D32;
        --bs-success-rgb:    46, 125, 50;
    }
    .btn-danger  { --bs-btn-bg: #B5453A; --bs-btn-border-color: #B5453A; --bs-btn-hover-bg: #9c3a31; --bs-btn-hover-border-color: #93362d; --bs-btn-active-bg: #93362d; --bs-btn-active-border-color: #8a3229; }
    .btn-success { --bs-btn-bg: #2E7D32; --bs-btn-border-color: #2E7D32; --bs-btn-hover-bg: #276a2b; --bs-btn-hover-border-color: #236326; --bs-btn-active-bg: #236326; --bs-btn-active-border-color: #1f5c22; }
    .btn-outline-danger  { --bs-btn-color: #B5453A; --bs-btn-border-color: #B5453A; --bs-btn-hover-bg: #B5453A; --bs-btn-hover-border-color: #B5453A; --bs-btn-active-bg: #B5453A; --bs-btn-active-border-color: #B5453A; }
    .btn-outline-success { --bs-btn-color: #2E7D32; --bs-btn-border-color: #2E7D32; --bs-btn-hover-bg: #2E7D32; --bs-btn-hover-border-color: #2E7D32; --bs-btn-active-bg: #2E7D32; --bs-btn-active-border-color: #2E7D32; }
    .text-danger { --bs-danger-rgb: 181, 69, 58; color: rgba(var(--bs-danger-rgb), var(--bs-text-opacity, 1)) !important; }
    .text-success { --bs-success-rgb: 46, 125, 50; color: rgba(var(--bs-success-rgb), var(--bs-text-opacity, 1)) !important; }
    .bg-danger { --bs-danger-rgb: 181, 69, 58; }
    .bg-success { --bs-success-rgb: 46, 125, 50; }
    .alert-danger  { --bs-alert-color: #6d2a23; --bs-alert-bg: #f2d8d5; --bs-alert-border-color: #ecc5c1; }
    .alert-success { --bs-alert-color: #1c4b1f; --bs-alert-bg: #d4edda; --bs-alert-border-color: #c3e6cb; }
    .badge.bg-danger  { background-color: #B5453A !important; }
    .badge.bg-success { background-color: #2E7D32 !important; }
</style>
