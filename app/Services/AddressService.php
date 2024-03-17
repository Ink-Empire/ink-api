<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;

/**
 *
 */
class AddressService
{
    /**
     * @param int $id
     * @return void|User
     */
    public function getById(int $id)
    {
        if ($id) {
            return Address::where('id', $id)->first();
        }

        return null;
    }

    /**
     *
     */
    public function create(array $data): ?Address
    {

        try {//TODO lots of validation to be added here
            $address = Address::factory($data)->create();
            return $address;
        } catch (\Exception $e) {
            return null;
        }
    }
}
