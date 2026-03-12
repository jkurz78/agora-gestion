<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatutOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'nombre_seances' => ['nullable', 'integer', 'min:1'],
            'statut' => ['required', Rule::in(array_column(StatutOperation::cases(), 'value'))],
        ];
    }
}
