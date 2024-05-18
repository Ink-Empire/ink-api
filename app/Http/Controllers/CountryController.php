<?php

namespace App\Http\Controllers;

use App\Models\Country;

class CountryController
{
    public function get()
    {
        $countries = Country::where('is_active', 1)->get();
        return response()->json(['countries' => $countries]);
    }
}
