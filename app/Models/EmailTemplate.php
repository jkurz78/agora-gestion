<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategorieEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailTemplate extends TenantModel
{
    protected $fillable = [
        'association_id',
        'categorie',
        'type_operation_id',
        'objet',
        'corps',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieEmail::class,
            'type_operation_id' => 'integer',
        ];
    }

    public function typeOperation(): BelongsTo
    {
        return $this->belongsTo(TypeOperation::class);
    }

    public static function sanitizeCorps(string $html): string
    {
        // Protéger les variables {var} dans les attributs href/src AVANT le purify :
        // HTMLPurifier URL-encode les caractères { } en %7B %7D quand ils apparaissent
        // dans une URI, ce qui casse le matching côté str_replace dans le mailer.
        // On remplace temporairement par une URL syntaxiquement valide qu'HTMLPurifier
        // accepte, puis on restaure après purify.
        // Hostname syntaxiquement valide (pas d'underscore — rejetés par HTMLPurifier).
        $hrefSentinel = 'https://var.placeholder.local/';
        $html = preg_replace_callback(
            '/(href|src)="(\{[a-z_]+\})"/i',
            fn ($m) => $m[1].'="'.$hrefSentinel.trim($m[2], '{}').'"',
            $html
        ) ?? $html;

        $cacheDir = storage_path('app/htmlpurifier');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $config = \HTMLPurifier_Config::createDefault();

        // Allowlist élargie pour le HTML newsletter : tous les éléments structurants
        // peuvent porter style/class + tables peuvent porter les attributs HTML legacy
        // (bgcolor, cellpadding, cellspacing, border, width, height, role) requis pour
        // la compatibilité Outlook / Apple Mail / clients mail anciens.
        // Allowlist HTMLPurifier alignée sur les attributs natifs supportés
        // (HTMLPurifier rejette les paires element/attribut hors specs HTML4/5).
        // Pour le HTML email : style et class sur tout, attributs legacy table
        // (bgcolor, cellpadding, cellspacing, border, width, align, valign).
        $allowedHtml = implode(',', [
            'p[style|class]',
            'br',
            'strong[style|class]',
            'em[style|class]',
            'u',
            'sup',
            'sub',
            'ul[style|class]',
            'ol[style|class]',
            'li[style|class]',
            'a[href|title|target|style|class|name]',
            'h1[style|class]',
            'h2[style|class]',
            'h3[style|class]',
            'h4[style|class]',
            'h5[style|class]',
            'h6[style|class]',
            'span[style|class]',
            'div[style|class]',
            'table[style|class|width|border|cellpadding|cellspacing|bgcolor|align]',
            'tbody[style|class]',
            'thead[style|class]',
            'tfoot[style|class]',
            'tr[style|class|bgcolor|align|valign]',
            'td[style|class|width|height|colspan|rowspan|bgcolor|align|valign]',
            'th[style|class|width|height|colspan|rowspan|bgcolor|align|valign]',
            'img[src|alt|width|height|style|class|border|align]',
            'hr[style|class]',
            'blockquote[style|class]',
        ]);
        $config->set('HTML.Allowed', $allowedHtml);

        // CSS.Trusted active des propriétés CSS supplémentaires (position, top,
        // bottom, etc.) que HTMLPurifier filtre par défaut pour clickjacking.
        // Pour du HTML email préparé par un admin authentifié, ces protections
        // sont surdimensionnées. Le contenu est rendu en email, pas dans l'UI.
        $config->set('CSS.Trusted', true);
        $config->set('CSS.AllowImportant', true);

        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);
        $config->set('Cache.SerializerPath', $cacheDir);

        // Étendre CSSDefinition avec les propriétés modernes que HTMLPurifier ne
        // supporte pas nativement (box-shadow, border-radius extended, transition,
        // transform, etc.). Pour un éditeur d'email, on utilise AttrDef_Text qui
        // accepte n'importe quelle valeur — combiné à URI.AllowedSchemes la
        // sécurité côté URL CSS reste assurée.
        $css = $config->getCSSDefinition();
        $extraCssProperties = [
            'box-shadow', 'text-shadow',
            'border-radius', 'border-top-left-radius', 'border-top-right-radius',
            'border-bottom-left-radius', 'border-bottom-right-radius',
            'border-collapse', 'border-spacing',
            'box-sizing', 'transition', 'transform', 'transform-origin',
            'word-wrap', 'overflow-wrap', 'word-break',
            'max-width', 'max-height', 'min-width', 'min-height',
            'background-size', 'background-clip', 'background-origin',
            'column-count', 'column-gap', 'column-rule',
            'flex', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-self', 'align-content',
            'grid', 'grid-template-columns', 'grid-template-rows', 'grid-area', 'grid-column', 'grid-row',
            'gap', 'row-gap', 'column-gap',
            'mso-table-lspace', 'mso-table-rspace', // Outlook
            '-webkit-text-size-adjust', '-ms-text-size-adjust', '-ms-interpolation-mode',
        ];
        foreach ($extraCssProperties as $prop) {
            $css->info[$prop] = new \HTMLPurifier_AttrDef_Text;
        }
        // Étendre 'display' avec les valeurs modernes (HTMLPurifier exclut inline-block, flex, grid)
        $css->info['display'] = new \HTMLPurifier_AttrDef_Enum([
            'inline', 'block', 'inline-block', 'list-item', 'run-in', 'compact',
            'marker', 'table', 'inline-table', 'table-row-group', 'table-header-group',
            'table-footer-group', 'table-row', 'table-column-group', 'table-column',
            'table-cell', 'table-caption', 'none', 'flex', 'inline-flex', 'grid', 'inline-grid',
        ]);

        $purified = (new \HTMLPurifier($config))->purify($html);

        // Restaurer les variables protégées dans href/src.
        // HTMLPurifier peut convertir l'URL en relative ou en chemin si elle paraît
        // étrange — on couvre les deux formes possibles.
        $sentinelRegex = '/(href|src)="(?:'.preg_quote($hrefSentinel, '/').'|\/)([a-z_]+)"/i';

        return preg_replace_callback(
            $sentinelRegex,
            fn ($m) => $m[1].'="{'.$m[2].'}"',
            $purified
        ) ?? $purified;
    }
}
