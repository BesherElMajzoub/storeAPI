<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class BaseAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge($this->transformData($this->all()));
    }

    private function transformData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->transformData($value);
            } elseif ($value === 'true') {
                $data[$key] = true;
            } elseif ($value === 'false') {
                $data[$key] = false;
            } elseif ($value === 'null' || $value === 'undefined') {
                $data[$key] = null;
            }
        }
        return $data;
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => null,
            'errors' => $validator->errors(),
        ], 422);

        throw new HttpResponseException($response);
    }
}
