<?php

namespace App\Http\Controllers;

use App\Enums\UserRelationships;
use App\Enums\UserTypes;
use App\Http\Resources\SelfUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AddressService;
use App\Services\ImageService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 *
 */
class UserController extends Controller
{

    public function __construct(
        protected UserService    $userService,
        protected ImageService   $imageService,
        protected AddressService $addressService
    )
    {
    }

    /**
     * Get the authenticated user
     */
    public function me(Request $request): SelfUserResource
    {
        return new SelfUserResource($request->user());
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getById($id)
    {
        $user = $this->userService->getById($id);
        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    /**
     * Upload or set profile photo.
     * Accepts either:
     * - image_id: ID of an already-uploaded image (from presigned URL flow - faster)
     * - profile_photo: File upload or base64 string (legacy flow - slower)
     */
    public function upload(Request $request): JsonResponse|Response
    {
        try {
            $user = $request->user();

            if (!$user) {
                \Log::error('Profile photo upload failed - no authenticated user');
                return $this->returnErrorResponse("Not authenticated", "Please log in to upload a photo");
            }

            // Check for presigned URL flow (faster) - image already uploaded to S3
            if ($request->has('image_id')) {
                $image = \App\Models\Image::find($request->input('image_id'));

                if (!$image) {
                    return $this->returnErrorResponse("Image not found", "The specified image does not exist");
                }

                $user = $this->userService->setProfileImage($user->id, $image);
                return $this->returnResponse('user', new UserResource($user));
            }

            // Legacy flow: process file through server
            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $date = Date('Ymdi');
                $extension = $file->getClientOriginalExtension() ?: 'jpeg';
                $filename = "profile_" . $user->id . "_" . $date . "." . $extension;

                // Read file and encode to base64
                $fileData = base64_encode(file_get_contents($file->getRealPath()));
                $image = $this->imageService->processImage($fileData, $filename);
            } else if ($request->has('profile_photo')) {
                // Handle direct base64 string if that's what's being sent
                $file = $request->profile_photo;
                $date = Date('Ymdi');
                $filename = "profile_" . $user->id . "_" . $date . ".jpeg";

                $image = $this->imageService->processImage($file, $filename);
            } else {
                return $this->returnErrorResponse("No profile photo provided", "No file uploaded");
            }

            $user = $this->userService->setProfileImage($user->id, $image);

            return $this->returnResponse('user', new UserResource($user));

        } catch (\Exception $e) {
            \Log::error("Error uploading profile photo", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => $request->user()->id ?? 'unknown'
            ]);

            return $this->returnErrorResponse($e->getMessage(), "Error uploading profile photo");
        }
    }

    /**
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();
            $user = $this->userService->getById($id);
            foreach ($data as $fieldName => $fieldVal) {
                if (in_array($fieldName, $user->getFillable())) {
                    $user->{$fieldName} = $fieldVal;
                }

                switch ($fieldName) {
                    case 'styles':
                        $this->userService->updateStyles($user, $fieldVal);
                        break;
                    case 'tattoos':
                        $this->userService->updateTattoos($user, $fieldVal);
                        break;
                    case 'artists':
                        $this->userService->updateArtists($user, $fieldVal);
                        break;
                }
            }
            $user->save();
        } catch (\Exception $e) {
            return $this->returnErrorResponse($e->getMessage());
        }

        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    /**
     * @return JsonResponse
     */
    public function addFavorite(Request $request, $type)
    {
        $ids = collect($request->get('ids'))->toArray();
        $user = $request->user();

        //is this a tattoo or artist?
        $relationship = UserRelationships::getRelationship($type);

        //sync the new ones
        $user->{$relationship}()->syncWithoutDetaching($ids);

        $user->save();

        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    public function removeFavorite(Request $request, $type)
    {
        $id = $request->route('id');
        $user = $request->user();

        $relationship = UserRelationships::getRelationship($type);

        //detach the item
        $user->{$relationship}()->detach($id);

        $user->save();

        return $this->returnResponse(UserTypes::USER, new UserResource($user));
    }

    /**
     * Toggle a favorite (add or remove based on action param)
     * Used by frontend to save/unsave artists, tattoos, studios
     */
    public function toggleFavorite(Request $request, $type)
    {
        $user = $request->user();
        $ids = $request->get('ids');
        $action = $request->get('action', 'add');

        // Handle both single ID and array of IDs
        $idArray = is_array($ids) ? $ids : [$ids];

        $relationship = UserRelationships::getRelationship($type);

        if (!$relationship) {
            return response()->json(['error' => 'Invalid favorite type'], 400);
        }

        if ($action === 'remove') {
            $user->{$relationship}()->detach($idArray);
        } else {
            $user->{$relationship}()->syncWithoutDetaching($idArray);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'action' => $action,
            'type' => $type,
            'ids' => $idArray
        ]);
    }

    /**
     * Get the user's saved/wishlisted artists with full details
     */
    public function getSavedArtists(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['wishlist' => [], 'debug' => 'No authenticated user']);
        }

        // Get the IDs of saved artists directly from the pivot table
        $savedArtistIds = \Illuminate\Support\Facades\DB::table('users_artists')
            ->where('user_id', $user->id)
            ->pluck('artist_id')
            ->toArray();

        if (empty($savedArtistIds)) {
            return response()->json([
                'wishlist' => [],
            ]);
        }

        // Fetch users directly (bypassing Artist scope) and join settings
        $savedArtists = User::whereIn('id', $savedArtistIds)
            ->with(['image', 'studio'])
            ->get()
            ->map(function ($artist) {
                // Get artist settings separately
                $settings = \App\Models\ArtistSettings::where('artist_id', $artist->id)->first();
                return [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'username' => $artist->username,
                    'image' => $artist->image ? ['id' => $artist->image->id, 'uri' => $artist->image->uri] : null,
                    'studio' => $artist->studio ? ['id' => $artist->studio->id, 'name' => $artist->studio->name] : null,
                    'books_open' => $settings?->books_open ?? false,
                ];
            });

        return response()->json([
            'wishlist' => $savedArtists
        ]);
    }

    /**
     * @return void
     */
    public function delete()
    {

    }

    // ==================== Admin Methods ====================

    /**
     * List all users with pagination for admin
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $perPage = min($request->input('per_page', 25), 100);
        $page = $request->input('page', 1);
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'desc');
        $filter = $request->input('filter', []);

        $query = User::query();

        // Apply filters
        if (is_string($filter)) {
            $filter = json_decode($filter, true) ?? [];
        }

        if (!empty($filter['q'])) {
            $search = $filter['q'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if (isset($filter['type_id'])) {
            $query->where('type_id', $filter['type_id']);
        }

        if (isset($filter['is_admin'])) {
            $query->where('is_admin', $filter['is_admin']);
        }

        // Apply sorting
        $query->orderBy($sort, $order);

        $total = $query->count();
        $users = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'data' => $users,
            'total' => $total,
        ]);
    }

    /**
     * Create a new user (admin only)
     */
    public function adminStore(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'type_id' => 'required|integer|in:1,2',
        ]);

        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => bcrypt($request->input('password')),
            'type_id' => $request->input('type_id'),
            'is_admin' => $request->input('is_admin', false),
            'username' => $request->input('username', strtolower(str_replace(' ', '', $request->input('name')))),
            'slug' => $request->input('slug', strtolower(str_replace(' ', '-', $request->input('name')))),
            'location' => $request->input('location', ''),
            'phone' => $request->input('phone'),
            'about' => $request->input('about'),
        ]);

        return response()->json([
            'data' => $user,
        ], 201);
    }

    /**
     * Get a single user for admin
     */
    public function adminShow(int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Update any user (admin only)
     */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $data = $request->all();

        foreach ($data as $fieldName => $fieldVal) {
            if (in_array($fieldName, $user->getFillable())) {
                $user->{$fieldName} = $fieldVal;
            }
        }

        $user->save();

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Delete a user (admin only)
     */
    public function adminDestroy(int $id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'data' => ['id' => $id],
        ]);
    }
}
