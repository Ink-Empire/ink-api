<?php
namespace App\Http\Requests;


use Illuminate\Http\Request;

class ElasticQueryTranslateRequest extends Request
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
            'query' => ['required', 'array'],
            'query.size' => ['nullable', 'integer'],
            'query.select' => ['nullable', 'array'],
            'query.sort' => function ($attribute, $values, $fail) {
                $values = isset($values[0]) ? $values : [$values];
                foreach ($values as $value) {
                    foreach ($value as $field => $order) {
                        if (!in_array($order, [
                            'asc', 'desc'
                        ])) {
                            $fail($attribute . ' is invalid.');
                        }
                    }
                }
            },
            'query.whereNot' => ['nullable', 'array'],
            'query.where' => ['nullable', 'array'],
            'query.orWhere' => ['nullable', 'array'],
            'query.whereTextOrdered' => ['nullable', 'array'],
            'query.whereText' => ['nullable', 'array'],
            'query.wherePrefix' => ['nullable', 'array'],
            'query.whereRange' => ['nullable', 'array'],
            'query.whereRange.operator' => function ($attribute, $value, $fail) {
                if (isset($value) && !in_array($value, [
                        'lt', 'gt', 'lte', '=', 'gte'
                    ])) {
                    $fail($attribute . ' is invalid.');
                }
            },
            'query.whereBetween' => ['nullable', 'array'],
        ];
    }
}
