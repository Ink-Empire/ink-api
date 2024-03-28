<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Http\Resources\UserResource;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 *
 */
class UserService
{
    const USER_RELATIONSHIPS = [
        'styles' => Style::class,
        'tattoos' => Tattoo::class,
        'artists' => User::class
    ];

    public function __construct(
        protected ImageService   $imageService,
        protected AddressService $addressService
    )
    {
    }

    public function create(array $data): ?User
    {
        try {
            if (isset($data['address'])) {
                $address = $this->addressService->create(
                    $this->addressService->mapFields($data['address'])
                );
            }

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'type_id' => $data['type'] == 'client' ? 1 : 2,
                'address_id' => $address->id ?? null
            ]);
            $user->save();

            return $user;

        } catch (\Exception $e) {
            \Log::error("Unable to create user", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return null;
//            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * @param $id
     * @return void|User
     */
    public function getById($id)
    {
        if ($id) {
            return User::where('id', $id)->first();
        }

        return null;
    }

    /**
     * @throws UserNotFoundException
     */
    public function setProfileImage(string $user_id, Image $image): User
    {
        $user = $this->getById($user_id);

        if ($user) {
            $user->image_id = $image->id;
            $user->save();
        } else {
            throw new UserNotFoundException();
        }

        return $user;
    }
}
