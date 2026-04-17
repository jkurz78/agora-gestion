<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\TypeOperation;
use App\Support\CurrentAssociation;
use Illuminate\Support\Facades\Storage;

final class EmailLogo
{
    /** Tags HTML autorisés dans les corps d'email (inclut img pour les logos). */
    public const ALLOWED_TAGS = '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div><table><tr><td><th><img>';

    /**
     * @return array<string, string> Variables {logo} et {logo_operation}
     */
    public static function variables(?int $typeOperationId = null): array
    {
        $association = CurrentAssociation::tryGet();
        $logoAsso = self::buildImgTagLocal(
            $association?->brandingLogoFullPath(),
            'local',
            'Logo',
        );

        $logoOp = '';
        if ($typeOperationId) {
            $typeOp = TypeOperation::find($typeOperationId);
            $logoOp = self::buildImgTagLocal(
                $typeOp?->typeOpLogoFullPath(),
                'local',
                'Logo '.($typeOp?->nom ?? ''),
            );
        }

        return [
            '{logo}' => $logoAsso,
            '{logo_operation}' => $logoOp ?: $logoAsso, // fallback sur logo association
        ];
    }

    private static function buildImgTagLocal(?string $path, string $disk, string $alt): string
    {
        if (! $path) {
            return '';
        }

        $content = Storage::disk($disk)->get($path);
        if (! $content) {
            return '';
        }

        $mime = Storage::disk($disk)->mimeType($path) ?: 'image/png';
        $base64 = base64_encode($content);

        return '<img src="data:'.$mime.';base64,'.$base64.'" alt="'.htmlspecialchars($alt).'" style="height:80px;width:auto;">';
    }
}
