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
        $config->set(
            'HTML.Allowed',
            'p,br,strong,em,u,ul,ol,li,a[href|title|target],h1,h2,h3,h4,span[style],div[style],table,tr,td[style],th[style],img[src|alt|width|height|style]'
        );
        $config->set(
            'CSS.AllowedProperties',
            'color,background-color,font-size,font-weight,font-style,text-decoration,text-align,margin,padding,border,width,height'
        );
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank', '_self']);
        $config->set('Cache.SerializerPath', $cacheDir);

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
