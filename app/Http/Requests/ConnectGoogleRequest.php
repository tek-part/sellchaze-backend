<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnectGoogleRequest extends FormRequest
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
            'access_token' => ['required_without:id_token', 'string'],
            'id_token' => ['required_without:access_token', 'string'],
        ];
    }
}
