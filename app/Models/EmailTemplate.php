<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategorieEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailTemplate extends Model
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

        return (new \HTMLPurifier($config))->purify($html);
    }
}
