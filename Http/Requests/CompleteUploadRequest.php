<?php

namespace App\Services\UploadService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string'],
            'parts' => ['required', 'array'],
            'parts.*.ETag' => ['required', 'string'],
            'parts.*.PartNumber' => ['required', 'integer'],
        ];
    }
}

