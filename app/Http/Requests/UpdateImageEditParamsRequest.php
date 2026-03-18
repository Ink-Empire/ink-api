<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateImageEditParamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bri' => 'nullable|integer|min:-100|max:100',
            'con' => 'nullable|integer|min:-100|max:100',
            'sat' => 'nullable|integer|min:-100|max:100',
            'sharp' => 'nullable|integer|min:0|max:100',
            'sepia' => 'nullable|integer|min:0|max:100',
            'hue_shift' => 'nullable|integer|min:0|max:359',
            'rot' => 'nullable|integer|in:0,90,180,270',
            'mono' => 'nullable|boolean',
            'auto_enhance' => 'nullable|boolean',
            'crop' => 'nullable|array',
            'crop.x' => 'required_with:crop|numeric',
            'crop.y' => 'required_with:crop|numeric',
            'crop.w' => 'required_with:crop|numeric',
            'crop.h' => 'required_with:crop|numeric',
        ];
    }
}
