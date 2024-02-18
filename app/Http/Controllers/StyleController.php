<?php

namespace App\Http\Controllers;

use App\Models\Style;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class StyleController extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function get()
    {
        return Style::all();
    }

    public function create()
    {

    }

    public function update()
    {

    }

    public function delete()
    {

    }

}
