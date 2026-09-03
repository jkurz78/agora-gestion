{{--
    Zone de glisser-déposer autour d'un champ fichier existant.

    Le composant n'REMPLACE rien : il ENVELOPPE le `<label>` et l'`<input
    type="file">` déjà en place, qui restent la voie normale au clic. Le
    glisser-déposer s'ajoute par-dessus, si bien qu'un navigateur sans
    glisser-déposer, ou un utilisateur au clavier, garde exactement le
    comportement d'avant.

    Pourquoi passer par un DataTransfer : la propriété `input.files` n'accepte
    qu'un FileList, objet qu'on ne peut pas construire directement. On recompose
    donc un DataTransfer, on lui ajoute le fichier déposé, et on affecte son
    FileList. Puis on émet un événement `change` — c'est lui, et lui seul, que
    Livewire écoute pour démarrer le téléversement de `wire:model`.

    Le nombre de fichiers retenus suit le champ enveloppé : tous s'il porte
    l'attribut `multiple`, le premier sinon. Prendre le premier en silence sur
    un champ multiple ferait disparaître les autres sans que l'utilisateur les
    voie partir.

    L'aide n'apparaît qu'au survol d'un fichier ; `aidePermanente` la fige pour
    un écran entièrement dédié au dépôt.

    Usage :
        <x-zone-depot>
            <label class="btn btn-primary">
                Choisir un fichier
                <input type="file" wire:model="pieceJointe" class="d-none">
            </label>
        </x-zone-depot>
--}}
{{-- Pour une zone sans texte d'aide, passer une chaîne VIDE (`aide=""`) et
     non `:aide="null"` : @props traite un null explicite comme un attribut
     absent et réapplique la valeur par défaut. --}}
@props(['aide' => 'ou glissez-déposez le fichier ici', 'aidePermanente' => false])

<div
    x-data="{
        survol: false,
        deposer(evenement) {
            this.survol = false;

            const fichiers = evenement.dataTransfer?.files;
            if (! fichiers || fichiers.length === 0) {
                return;
            }

            const champ = this.$refs.contenu?.querySelector('input[type=file]');
            if (! champ || champ.disabled) {
                return;
            }

            // Un champ `multiple` reçoit tous les fichiers déposés ; un champ
            // simple n'en retient qu'un. Prendre le premier en silence sur un
            // champ multiple ferait disparaître les autres sans que personne
            // ne le voie partir.
            const paquet = new DataTransfer();
            const retenus = champ.multiple ? Array.from(fichiers) : [fichiers[0]];
            retenus.forEach((fichier) => paquet.items.add(fichier));
            champ.files = paquet.files;
            champ.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    @dragover.prevent="survol = true"
    @dragenter.prevent="survol = true"
    @dragleave.prevent="survol = false"
    @drop.prevent="deposer($event)"
    :class="survol ? 'zone-depot zone-depot-survol' : 'zone-depot'"
    {{ $attributes }}
>
    <div x-ref="contenu">{{ $slot }}</div>

    @if ($aide)
        {{-- L'aide ne s'affiche que pendant le survol d'un fichier. Trente-trois
             champs portent cette zone : une ligne de texte permanente sous
             chacun d'eux encombrerait tous les formulaires de l'application
             pour une commodité dont on ne se sert qu'au moment de déposer.
             `aidePermanente` la fige pour les rares écrans qui sont, eux,
             entièrement dédiés au dépôt. --}}
        <div class="zone-depot-aide small text-muted mt-2"
             @unless ($aidePermanente) x-show="survol" x-cloak @endunless>
            <i class="bi bi-arrow-down-circle me-1"></i>{{ $aide }}
        </div>
    @endif
</div>

{{-- Style en ligne, et non via @push('styles') : le layout ne porte qu'une
     pile `scripts`, un @push('styles') serait silencieusement perdu. Le @once
     garantit qu'il n'est rendu qu'une fois par page, même si la zone est
     utilisée plusieurs fois. --}}
@once
        <style>
            /* [x-cloak] n'est pas defini globalement dans le layout : sans
               cette regle, l'aide clignoterait avant l'initialisation d'Alpine.
               Le composant porte donc la sienne, ce qui le rend autonome. */
            .zone-depot [x-cloak] { display: none !important; }
            .zone-depot {
                /* outline et non border : l'outline ne prend AUCUNE place dans
                   le flux. C'est ce qui permet d'envelopper trente champs
                   fichier existants sans deplacer d'un pixel la mise en page
                   des formulaires qui les portent. Une bordure, elle, aurait
                   decale chacun d'eux. */
                outline: 2px dashed transparent;
                outline-offset: 4px;
                border-radius: .5rem;
                transition: outline-color .12s ease, background-color .12s ease;
            }
            /* Le pointillé n'apparaît qu'au survol d'un fichier : au repos la
               zone doit rester invisible, sans quoi elle encombre un formulaire
               où le dépôt n'est qu'une commodité parmi d'autres. */
            .zone-depot-survol {
                outline-color: #3d5473;
                background-color: rgba(61, 84, 115, .06);
            }
            .zone-depot-survol .zone-depot-aide {
                color: #3d5473 !important;
                font-weight: 600;
            }
        </style>
@endonce
