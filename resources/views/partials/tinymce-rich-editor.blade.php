{{--
    Shared rich-text editor using a faithful Alpine copy of the email composer's mechanism.
    Multi-instance-safe: Alpine creates one component instance per element.

    Parameters (pass via @include(..., [...])):
      $id          — unique textarea id (string)
      $model       — Livewire wire property name to sync into (string)
      $content     — initial HTML (string)
      $groups      — assoc array ['GroupLabel' => ['{var}' => 'Human label', ...], ...]
      $insertItems — assoc array ['Label' => '{var_or_html}', ...] (optional, default [])
      $height      — editor height in px (int, default 320)
--}}
@php
    $height      ??= 320;
    $insertItems ??= [];
    $editorConfig = [
        'id'          => $id,
        'model'       => $model,
        'groups'      => $groups,
        'insertItems' => $insertItems,
        'height'      => $height,
    ];
@endphp

{{-- One JSON config blob per editor instance, keyed by id --}}
<script>
    window.__qgEditorCfg = window.__qgEditorCfg || {};
    window.__qgEditorCfg[{{ Js::from($id) }}] = {!! json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
</script>

<div wire:ignore x-data="qgRichEditor()" x-init="init()" data-editor-id="{{ $id }}">
    <textarea x-ref="editor" id="{{ $id }}" rows="10" style="width:100%">{!! $content !!}</textarea>
</div>

@assets
<script>
    // Strip mce-variable spans back to plain {token} strings.
    // Uses callback form — avoids dollar-N corruption in Livewire SupportAutoInjectedAssets.
    function __qgStripVarSpans(html) {
        return html.replace(
            /<span\b[^>]*\bqg-mce-var\b[^>]*>(\{[^}]+\})<\/span>/g,
            function (_m, token) { return token; }
        );
    }

    // Wrap plain {token} strings in non-editable styled spans.
    function __qgWrapVars(html, variables) {
        variables.forEach(function (v) {
            var esc = v.replace(/[{}]/g, '\\$&');
            var rx = new RegExp('(?!<span[^>]*>)' + esc + '(?![^<]*<\\/span>)', 'g');
            html = html.replace(rx, '<span class="qg-mce-var mce-noneditable">' + v + '</span>');
        });
        return html;
    }

    // Register the Alpine component once (guard prevents double-registration on hot-reload).
    if (typeof Alpine !== 'undefined' && !Alpine._qgRichEditorRegistered) {
        Alpine._qgRichEditorRegistered = true;

        Alpine.data('qgRichEditor', function () {
            return {
                editor: null,
                cfg: null,

                init() {
                    // Read per-instance config from the data attribute set on this element.
                    var editorId = this.$el.getAttribute('data-editor-id');
                    this.cfg = (window.__qgEditorCfg || {})[editorId] || {};
                    this.$nextTick(function () { this.setup(); }.bind(this));
                    this.$cleanup(function () { this.destroy(); }.bind(this));
                },

                setup() {
                    if (typeof tinymce === 'undefined') {
                        setTimeout(function () { this.setup(); }.bind(this), 300);
                        return;
                    }

                    var cfg = this.cfg;
                    if (!cfg || !cfg.id) return;

                    // Skip if already initialised (e.g. Livewire morphdom re-ran init).
                    if (tinymce.get(cfg.id)) return;

                    var textarea = this.$refs.editor;
                    if (!textarea) return;

                    var self = this;

                    // Flat variable list for wrapping.
                    var allVars = [];
                    Object.values(cfg.groups || {}).forEach(function (grp) {
                        Object.keys(grp).forEach(function (v) { allVars.push(v); });
                    });

                    // Nested menu items from groups (one nestedmenuitem per group).
                    var menuItems = Object.entries(cfg.groups || {}).map(function (entry) {
                        var groupName = entry[0];
                        var vars = entry[1];
                        return {
                            type: 'nestedmenuitem',
                            text: groupName,
                            getSubmenuItems: function () {
                                return Object.entries(vars).map(function (ve) {
                                    var key   = ve[0];
                                    var label = ve[1];
                                    return {
                                        type: 'menuitem',
                                        text: key + ' — ' + label,
                                        onAction: function () {
                                            if (self.editor) {
                                                self.editor.insertContent(
                                                    '<span class="qg-mce-var mce-noneditable">' + key + '</span>&nbsp;'
                                                );
                                            }
                                        },
                                    };
                                });
                            },
                        };
                    });

                    // "Insérer" menu items from insertItems (flat key=label, value=token/html).
                    var insertItems = Object.entries(cfg.insertItems || {}).map(function (ie) {
                        var label = ie[0];
                        var token = ie[1];
                        return {
                            type: 'menuitem',
                            text: label,
                            onAction: function () {
                                if (self.editor) {
                                    self.editor.insertContent(
                                        '<span class="qg-mce-var mce-noneditable">' + token + '</span>&nbsp;'
                                    );
                                }
                            },
                        };
                    });

                    var hasInsert = insertItems.length > 0;

                    // Resolve the Livewire component from the closest wire:id ancestor.
                    function getWire() {
                        var el = self.$el;
                        var wireEl = el.closest('[wire\\:id]');
                        if (!wireEl) return null;
                        var wireId = wireEl.getAttribute('wire:id');
                        return wireId ? Livewire.find(wireId) : null;
                    }

                    tinymce.init({
                        target: textarea,
                        language: 'fr_FR',
                        language_url: '/vendor/tinymce/langs/fr_FR.js',
                        height: cfg.height || 320,
                        menubar: 'edit insert format table',
                        statusbar: true,
                        promotion: false,
                        plugins: 'lists link noneditable table image media code fullscreen',
                        toolbar: [
                            'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor',
                            'alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table image media link | variablesBtn'
                            + (hasInsert ? ' insertBtn' : '') + ' | code fullscreen',
                        ],
                        noneditable_class: 'qg-mce-var',
                        block_formats: 'Paragraphe=p; Titre 1=h1; Titre 2=h2; Titre 3=h3; Titre 4=h4',
                        font_family_formats: 'Arial=arial,helvetica,sans-serif; Georgia=georgia,serif; Courier New=courier new,courier,monospace; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
                        font_size_formats: '10px 12px 14px 16px 18px 20px 24px 28px 32px 36px',
                        table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
                        table_default_styles: { 'border-collapse': 'collapse', 'width': '100%' },
                        table_default_attributes: { border: '1', cellpadding: '6', cellspacing: '0' },
                        image_title: true,
                        image_caption: true,
                        image_advtab: true,
                        image_class_list: [
                            { title: 'En ligne (défaut)', value: '' },
                            { title: 'Flottante à gauche', value: 'img-float-left' },
                            { title: 'Flottante à droite', value: 'img-float-right' },
                            { title: 'Centrée (bloc)', value: 'img-center' },
                        ],
                        automatic_uploads: false,
                        images_upload_handler: function (blobInfo) {
                            return new Promise(function (resolve) {
                                var reader = new FileReader();
                                reader.onload = function () { resolve(reader.result); };
                                reader.readAsDataURL(blobInfo.blob());
                            });
                        },
                        file_picker_types: 'image',
                        file_picker_callback: function (callback, value, meta) {
                            if (meta.filetype === 'image') {
                                var input = document.createElement('input');
                                input.setAttribute('type', 'file');
                                input.setAttribute('accept', 'image/*');
                                input.addEventListener('change', function () {
                                    var file = this.files[0];
                                    var reader = new FileReader();
                                    reader.onload = function () {
                                        callback(reader.result, { alt: file.name, title: file.name });
                                    };
                                    reader.readAsDataURL(file);
                                });
                                input.click();
                            }
                        },
                        content_style: [
                            'body { font-family: Arial, sans-serif; font-size: 14px; }',
                            '.qg-mce-var { background: #f3edff; border: 1px solid #d4c5f9; border-radius: 3px; padding: 1px 3px; font-family: monospace; font-size: 12px; color: #7c3aed; display: inline-block; }',
                            'table { border-collapse: collapse; }',
                            'td, th { border: 1px solid #ccc; padding: 6px; }',
                            '.img-float-left { float: left; margin: 0 16px 12px 0; }',
                            '.img-float-right { float: right; margin: 0 0 12px 16px; }',
                            '.img-center { display: block; margin: 12px auto; }',
                            'img { max-width: 100%; height: auto; }',
                        ].join(' '),
                        setup: function (editor) {
                            self.editor = editor;

                            editor.ui.registry.addMenuButton('variablesBtn', {
                                text: 'Variables',
                                fetch: function (callback) { callback(menuItems); },
                            });

                            if (hasInsert) {
                                editor.ui.registry.addMenuButton('insertBtn', {
                                    text: 'Insérer',
                                    icon: 'table-insert-row-after',
                                    fetch: function (callback) { callback(insertItems); },
                                });
                            }

                            // Preserve aspect ratio on image resize.
                            editor.on('ObjectResized', function (e) {
                                if (e.target.nodeName === 'IMG') {
                                    e.target.style.height = 'auto';
                                    e.target.removeAttribute('height');
                                }
                            });

                            editor.on('init', function () {
                                editor.setContent(__qgWrapVars(editor.getContent(), allVars));
                            });

                            // Sync stripped content to Livewire on every change.
                            editor.on('change input', function () {
                                var wire = getWire();
                                if (wire) {
                                    wire.set(cfg.model, __qgStripVarSpans(editor.getContent()));
                                }
                            });
                        },
                    });

                    // Expose per-id sync helper for save-button pre-flush.
                    window['__qgSync_' + cfg.id] = function () {
                        var ed = tinymce.get(cfg.id);
                        if (ed) {
                            var wire = getWire();
                            if (wire) {
                                wire.set(cfg.model, __qgStripVarSpans(ed.getContent()));
                            }
                        }
                    };
                },

                destroy() {
                    if (this.editor) {
                        try { tinymce.remove(this.editor); } catch (e) {}
                        this.editor = null;
                    }
                },
            };
        });
    }
</script>
@endassets
