<?php

namespace App\Http\Controllers;

use App\Services\UserService;

class UserController
{

    public function __construct(protected UserService $userService)
    {
    }

    public function get($id)
    {
        $user = $this->userService->getById($id);

        return response()->json(['user' => $user]);
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
