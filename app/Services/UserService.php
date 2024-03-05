<?php

namespace App\Services;

use App\Models\User;

class UserService
{
    public function getById($id)
    {
        if ($id) {
            return User::where('id', $id)->first();
        }
    }
}
