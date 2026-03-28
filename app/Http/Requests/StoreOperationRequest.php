<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'code' => ['required', 'string', 'max:50', 'unique:operations,code'],
            'nom' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'nombre_seances' => ['nullable', 'integer', 'min:1'],
            'type_operation_id' => ['required', 'exists:type_operations,id'],
        ];
    }
}
