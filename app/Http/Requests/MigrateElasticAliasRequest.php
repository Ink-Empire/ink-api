<?php
namespace App\Http\Requests;


use Illuminate\Http\Request;

class MigrateElasticAliasRequest extends Request
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

    /**
     * The validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'alias' => ['string']
        ];
    }
}
