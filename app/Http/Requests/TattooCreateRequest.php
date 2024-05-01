<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;

class TattooCreateRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'required|string',
            'description' => 'required|string',
            'image' => 'required|image',
            'styles' => 'required|array',
            'user_id' => 'required|integer',
        ];
    }
}
