<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatutOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateOperationRequest extends FormRequest
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
        $rules = [
            'nom' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'nombre_seances' => ['nullable', 'integer', 'min:1'],
            'statut' => ['required', Rule::in(array_column(StatutOperation::cases(), 'value'))],
            'type_operation_id' => ['required', 'exists:type_operations,id'],
        ];

        if ($this->route('operation')->participants()->exists()) {
            $rules['type_operation_id'] = ['required', 'integer', Rule::in([$this->route('operation')->type_operation_id])];
        }

        return $rules;
    }
}
