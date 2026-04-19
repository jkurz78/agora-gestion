(function () {
    'use strict';

    const API_URL = 'https://geo.api.gouv.fr/communes';

    function setVille(value) {
        const ville = document.getElementById('identiteVille');
        if (!ville) return;
        ville.value = value;
        ville.dispatchEvent(new Event('input', { bubbles: true }));
        ville.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function renderSuggestions(communes) {
        const container = document.getElementById('identiteVilleSuggestions');
        const list = container?.querySelector('[data-role="suggestions"]');
        if (!container || !list) return;

        list.innerHTML = '';
        communes.forEach(function (c) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.textContent = c.nom;
            btn.addEventListener('click', function () {
                setVille(c.nom);
                container.classList.add('d-none');
            });
            list.appendChild(btn);
        });
        container.classList.remove('d-none');
    }

    function hideSuggestions() {
        const container = document.getElementById('identiteVilleSuggestions');
        if (container) container.classList.add('d-none');
    }

    async function lookup(cp) {
        try {
            const response = await fetch(`${API_URL}?codePostal=${encodeURIComponent(cp)}&fields=nom&format=json`);
            if (!response.ok) return;
            const communes = await response.json();
            if (!Array.isArray(communes) || communes.length === 0) {
                hideSuggestions();
                return;
            }
            if (communes.length === 1) {
                setVille(communes[0].nom);
                hideSuggestions();
                return;
            }
            renderSuggestions(communes);
        } catch (e) {
            // fallback silencieux
        }
    }

    function init() {
        const cp = document.getElementById('identiteCodePostal');
        if (!cp || cp.dataset.cpvilleBound === '1') return;
        cp.dataset.cpvilleBound = '1';

        cp.addEventListener('input', function () {
            const value = cp.value.replace(/\D/g, '');
            if (value.length === 5) {
                lookup(value);
            } else {
                hideSuggestions();
            }
        });
    }

    function bindLivewire() {
        if (typeof window.Livewire !== 'undefined' && typeof window.Livewire.hook === 'function') {
            window.Livewire.hook('morph.updated', init);
        }
    }

    function boot() {
        init();
        bindLivewire();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('livewire:init', bindLivewire);
    document.addEventListener('livewire:navigated', init);
})();
