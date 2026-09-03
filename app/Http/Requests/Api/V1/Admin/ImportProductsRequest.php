<?php

namespace App\Http\Requests\Api\V1\Admin;

class ImportProductsRequest extends BaseAdminRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->has('dry_run')) {
            $this->merge(['dry_run' => filter_var($this->input('dry_run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'],
            'dry_run' => ['required', 'boolean'],
        ];
    }
}
