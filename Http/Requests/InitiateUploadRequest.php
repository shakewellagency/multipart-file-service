<?php

namespace App\Services\UploadService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitiateUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string'],
            'size' => ['required', 'integer', 'min:0'],
            'directory' => ['nullable', 'string', 'max:255'],
            'visibility' => ['nullable', 'in:public,private'],
        ];
    }
}

