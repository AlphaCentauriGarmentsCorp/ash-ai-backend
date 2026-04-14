<?php

namespace App\Http\Requests\PatternType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class Update extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:50',
            'description' => 'required|string|max:150',
            'images'      => 'nullable|array|max:10',
            'images.*'    => 'file|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ];
    }
 
    public function messages(): array
    {
        return [
            'name.required'        => 'The name field is required.',
            'name.string'          => 'The name must be a string.',
            'name.max'             => 'The name may not be greater than 50 characters.',
 
            'description.required' => 'The description field is required.',
            'description.string'   => 'The description must be a string.',
            'description.max'      => 'The description may not be greater than 150 characters.',
 
            'images.array'         => 'Images must be provided as a list.',
            'images.max'           => 'You may upload a maximum of 10 images.',
            'images.*.file'        => 'Each image must be a valid file.',
            'images.*.mimes'       => 'Each image must be a JPG, PNG, WEBP, or GIF.',
            'images.*.max'         => 'Each image must not exceed 5MB.',
        ];
    }
 
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422)
        );
    }
}
