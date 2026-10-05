<x-app-layout>
    <x-slot name="title">Écritures de rattachement</x-slot>
    <livewire:provisions.provision-index />

    @push('scripts')
    {{--
        Tri des colonnes, côté client : un clic sur un en-tête `th[data-tri]` d'une `table[data-triable]` trie ses
        lignes selon la valeur `data-sort` de la cellule (dates en ISO Y-m-d, nombres avec un point), à défaut son
        texte. Deux nombres se comparent comme des nombres (« 1000 » après « 175 »), le reste comme du texte à la
        française, les chiffres dans leur ordre naturel.

        Un seul écouteur sur le document, par délégation : les tableaux que Livewire remplace, ou ne rend qu'après
        une recherche, n'ont aucun écouteur à reposer.
    --}}
    <script>
        (function () {
            'use strict';

            function valeur(cellule) {
                if (!cellule) return '';
                var brut = cellule.getAttribute('data-sort');
                return (brut !== null ? brut : cellule.textContent).trim();
            }

            function nombre(texte) {
                return texte !== '' && isFinite(Number(texte)) ? Number(texte) : null;
            }

            function comparer(a, b) {
                var na = nombre(a), nb = nombre(b);
                if (na !== null && nb !== null) return na - nb;
                return a.localeCompare(b, 'fr', { numeric: true, sensitivity: 'base' });
            }

            document.addEventListener('click', function (evenement) {
                var entete = evenement.target.closest ? evenement.target.closest('th[data-tri]') : null;
                if (!entete) return;

                var tableau = entete.closest('table[data-triable]');
                if (!tableau || !tableau.tBodies.length) return;

                var colonne = Array.prototype.indexOf.call(entete.parentElement.children, entete);
                var croissant = entete.getAttribute('aria-sort') !== 'ascending';
                var corps = tableau.tBodies[0];

                Array.prototype.slice.call(corps.rows)
                    .map(function (ligne, rang) { return { ligne: ligne, rang: rang, cle: valeur(ligne.cells[colonne]) }; })
                    // À égalité, l'ordre d'origine : le tri est stable.
                    .sort(function (x, y) { return (croissant ? 1 : -1) * comparer(x.cle, y.cle) || x.rang - y.rang; })
                    .forEach(function (entree) { corps.appendChild(entree.ligne); });

                Array.prototype.forEach.call(entete.parentElement.children, function (autre) {
                    autre.removeAttribute('aria-sort');
                });
                entete.setAttribute('aria-sort', croissant ? 'ascending' : 'descending');
            });
        })();
    </script>
    @endpush
</x-app-layout>
