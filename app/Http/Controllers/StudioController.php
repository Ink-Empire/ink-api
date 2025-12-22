<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\StudioResource;
use App\Http\Resources\UserResource;
use App\Models\Studio;
use App\Models\StudioAnnouncement;
use App\Models\StudioSpotlight;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudioController extends Controller
{
    public function __construct(
        protected AddressService $addressService,
        protected ImageService   $imageService,
        protected StudioService  $studioService,
        protected UserService    $userService,
    )
    {
    }

    /**
     * Check if a studio username or email is available
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $username = $request->input('username');

        if ($email) {
            $emailExists = Studio::where('email', $email)->exists();
            return response()->json([
                'available' => !$emailExists,
                'field' => 'email'
            ]);
        }

        if ($username) {
            // Check both username and slug since they're often the same
            $usernameExists = Studio::where('slug', $username)
                ->orWhere('slug', strtolower($username))
                ->exists();
            return response()->json([
                'available' => !$usernameExists,
                'field' => 'username'
            ]);
        }

        return response()->json([
            'available' => true,
            'field' => null
        ]);
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function get()
    {
        $studios = $this->studioService->get();

        return $this->returnResponse('studios', StudioResource::collection($studios));
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id)
    {
        $studio = $this->studioService->getById($id);
        return $this->returnResponse('studio', new StudioResource($studio));
    }

    //TODO create custom request
    public function create(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $data = $request->all();

            $address = null;

//            if ($data['address']) {
//                $address = $this->addressService->create(
//                    [
//                        'address1' => $data['address']['address1'],
//                        'address2' => $data['address']['address2'] ?? null,
//                        'city' => $data['address']['city'],
//                        'state' => $data['address']['state'],
//                        'postal_code' => $data['address']['postal_code'],
//                        'country_code' => $data['address']['country_code'] ?? "US"
//                    ]
//                );
//            }

            $studio = new Studio([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? null,
                'email' => $data['email'] ?? null,
                'about' => $data['about'] ?? null,
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'location_lat_long' => $data['location_lat_long'] ?? null,
                'address_id' => $address->id ?? null,
                'owner_id' => $data['owner_id'] ?? null,
            ]);

            $studio->save();

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error("Unable to create studio", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();
        $studio = $this->studioService->getById($id);

        foreach ($data as $fieldName => $fieldVal) {
            if (in_array($fieldName, $studio->getFillable())) {
                $studio->{$fieldName} = $fieldVal;
            }

            switch ($fieldName) {
                case 'days':
                    $this->studioService->setBusinessDays($data, $studio);
                    $studio->load('business_hours');
                    break;
                case 'styles':
                    $this->studioService->updateStyles($studio, $fieldVal);
                    break;
                case 'tattoos':
                    $this->userService->updateTattoos($studio, $fieldVal);
                    break;
                case 'artists':
                    $this->userService->updateArtists($studio, $fieldVal);
                    break;
            }
        }
        $studio->save();

        return $this->returnResponse('studio', new StudioResource($studio));

    }

    public function updateBusinessHours(Request $request, $id)
    {
        $data = $request->all();

        $studio = $this->studioService->getById($id);

        if (isset($data['days'])) {
            $this->studioService->setBusinessDays($data, $studio);
            $studio->load('business_hours');
        }

        return $this->returnResponse('studio', new StudioResource($studio));
    }

    public function uploadImage(Request $request, $id): JsonResponse
    {
        try {
            $studio = $this->studioService->getById($id);

            if (!$studio) {
                return $this->returnErrorResponse('Studio not found');
            }

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $date = date('Ymdi');
                $extension = $file->getClientOriginalExtension() ?: 'jpeg';
                $filename = "studio_" . $id . "_" . $date . "." . $extension;

                $fileData = base64_encode(file_get_contents($file->getRealPath()));
                $image = $this->imageService->processImage($fileData, $filename);
            } elseif ($request->has('image')) {
                $file = $request->image;
                $date = date('Ymdi');
                $filename = "studio_" . $id . "_" . $date . ".jpeg";

                $image = $this->imageService->processImage($file, $filename);
            } else {
                return $this->returnErrorResponse('No image provided');
            }

            $studio = $this->studioService->setStudioImage($id, $image);

            return $this->returnResponse('studio', new StudioResource($studio));

        } catch (\Exception $e) {
            \Log::error('Unable to upload studio image', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    // Artist Management
    public function getArtists($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $artists = $this->studioService->getStudioArtists($studio);
        return $this->returnResponse('artists', UserResource::collection($artists));
    }

    public function addArtist(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $username = $request->input('username');
        if (!$username) {
            return $this->returnErrorResponse('Username is required', 422);
        }

        $artist = $this->studioService->addArtistByUsername($studio, $username);
        if (!$artist) {
            return $this->returnErrorResponse('Artist not found with that username', 404);
        }

        return $this->returnResponse('artist', new UserResource($artist));
    }

    public function removeArtist($id, $userId): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $removed = $this->studioService->removeArtist($studio, $userId);
        if (!$removed) {
            return $this->returnErrorResponse('Artist was not associated with this studio', 404);
        }

        return response()->json(['success' => true]);
    }

    // Announcements
    public function getAnnouncements($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        return $this->returnResponse('announcements', $studio->announcements);
    }

    public function createAnnouncement(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $announcement = $this->studioService->createAnnouncement($studio, $request->all());
        return $this->returnResponse('announcement', $announcement);
    }

    public function updateAnnouncement(Request $request, $id, $announcementId): JsonResponse
    {
        $announcement = StudioAnnouncement::where('studio_id', $id)
            ->where('id', $announcementId)
            ->first();

        if (!$announcement) {
            return $this->returnErrorResponse('Announcement not found', 404);
        }

        $updated = $this->studioService->updateAnnouncement($announcement, $request->all());
        return $this->returnResponse('announcement', $updated);
    }

    public function deleteAnnouncement($id, $announcementId): JsonResponse
    {
        $announcement = StudioAnnouncement::where('studio_id', $id)
            ->where('id', $announcementId)
            ->first();

        if (!$announcement) {
            return $this->returnErrorResponse('Announcement not found', 404);
        }

        $this->studioService->deleteAnnouncement($announcement);
        return response()->json(['success' => true]);
    }

    // Spotlights
    public function getSpotlights($id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $spotlights = $this->studioService->getSpotlightsWithData($studio);
        return $this->returnResponse('spotlights', $spotlights);
    }

    public function addSpotlight(Request $request, $id): JsonResponse
    {
        $studio = $this->studioService->getById($id);
        if (!$studio) {
            return $this->returnErrorResponse('Studio not found', 404);
        }

        $request->validate([
            'type' => 'required|in:artist,tattoo',
            'item_id' => 'required|integer',
        ]);

        $spotlight = $this->studioService->addSpotlight(
            $studio,
            $request->input('type'),
            $request->input('item_id'),
            $request->input('display_order', 0)
        );

        return $this->returnResponse('spotlight', $spotlight);
    }

    public function removeSpotlight($id, $spotlightId): JsonResponse
    {
        $spotlight = StudioSpotlight::where('studio_id', $id)
            ->where('id', $spotlightId)
            ->first();

        if (!$spotlight) {
            return $this->returnErrorResponse('Spotlight not found', 404);
        }

        $this->studioService->removeSpotlight($spotlight);
        return response()->json(['success' => true]);
    }
}
