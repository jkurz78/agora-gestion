{{--
    Garde de fermeture des modales de saisie.

    Problème corrigé : un clic n'importe où en dehors d'une modale — ou la touche
    Échap — la refermait silencieusement, faisant perdre tout ce qui venait d'être
    saisi (signalé sur la création d'opération, qui comporte une dizaine de champs).

    Deux mécaniques produisaient ce comportement :
      · les modales rendues par Livewire, dont le fond noir porte `wire:click.self`
        avec un appel de fermeture ;
      · les modales pilotées par Bootstrap, dont les options `backdrop` et
        `keyboard` valent `true` par défaut.

    Règle appliquée : une modale qui contient un champ de saisie ne se ferme plus
    ni au clic extérieur ni à Échap — on la ferme par son bouton Annuler / Fermer,
    présent sur toutes. Une modale sans champ (aperçu d'e-mail, pièce jointe, fiche
    médicale, détail de communication) garde le clic extérieur, qui y est commode
    et sans risque.

    Le critère est volontairement « contient un champ » plutôt que « l'utilisateur
    a saisi quelque chose » : ce dernier reposerait sur l'événement `input`, qui ne
    remonte pas depuis TinyMCE (iframe) et n'est pas émis quand une valeur est
    posée par JS (tiers-autocomplete, compte-autocomplete). Ces angles morts se
    traduiraient par des pertes de saisie, c'est-à-dire le bug qu'on corrige.

    Échappatoire : `data-modal-guard="off"` sur le fond rétablit l'ancien
    comportement pour une modale donnée.
--}}
<script>
    (function () {
        'use strict';

        // Ce qui fait d'une modale une modale « de saisie ».
        var CHAMPS = 'input:not([type=hidden]), select, textarea, [contenteditable="true"], .tox-tinymce';

        // Les deux porteurs de fermeture : le fond Livewire et l'élément .modal de
        // Bootstrap (dans les deux cas, c'est un clic SUR cet élément — donc à côté
        // du contenu — qui déclenche la fermeture).
        function estPorteurDeFermeture(el) {
            if (!el || el.nodeType !== 1) {
                return false;
            }
            if (el.dataset && el.dataset.modalGuard === 'off') {
                return false;
            }

            return el.hasAttribute('wire:click.self') || el.classList.contains('modal');
        }

        function contientUneSaisie(el) {
            return el.querySelector(CHAMPS) !== null;
        }

        // Phase de capture : on passe avant le gestionnaire de Livewire comme avant
        // celui de Bootstrap, tous deux posés sur l'élément lui-même.
        document.addEventListener('click', function (event) {
            var cible = event.target;

            if (cible !== event.currentTarget && estPorteurDeFermeture(cible) && contientUneSaisie(cible)) {
                event.stopImmediatePropagation();
                event.preventDefault();
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            var modales = document.querySelectorAll('.modal.show, [wire\\:click\\.self]');

            for (var i = 0; i < modales.length; i++) {
                var modale = modales[i];
                // getClientRects() est le seul test de visibilité fiable ici :
                // ces modales sont en position fixed (classe CSS, pas style inline),
                // ce qui met offsetParent à null même quand elles sont affichées.
                if (modale.getClientRects().length === 0) {
                    continue;
                }
                if (estPorteurDeFermeture(modale) && contientUneSaisie(modale)) {
                    event.stopImmediatePropagation();
                    event.preventDefault();

                    return;
                }
            }
        }, true);
    })();
</script>
