<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserProfileResource;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserProfileController extends Controller
{
    public function __construct(
        protected PaginationService $paginationService
    ) {
    }

    /**
     * Get a public user profile by slug.
     */
    public function getProfile(string $slug): JsonResponse
    {
        $user = User::with(['image', 'socialMediaLinks'])
            ->withCount('uploadedTattoos')
            ->where('slug', $slug)
            ->first();

        if (!$user) {
            return $this->returnErrorResponse('User not found', 404);
        }

        return $this->returnResponse('user', new UserProfileResource($user));
    }

    /**
     * Get a user's uploaded tattoos by slug via Elasticsearch.
     */
    public function getUploadedTattoos(Request $request, string $slug): JsonResponse
    {
        $user = User::where('slug', $slug)->first();

        if (!$user) {
            return $this->returnErrorResponse('User not found', 404);
        }

        $params = $request->all();
        $pagination = $this->paginationService->extractParams($params);

        $version = Cache::get("es:user:{$user->id}:tattoos:version", 0);
        $cacheKey = "es:user:{$user->id}:tattoos:v{$version}:p{$pagination['page']}:pp{$pagination['per_page']}";

        $response = Cache::remember($cacheKey, 300, function () use ($user, $pagination) {
            $search = Tattoo::search();
            $search->where('uploaded_by_user_id', $user->id);
            $search->sort('created_at', 'desc');
            $search->from($pagination['offset']);
            $search->take($pagination['per_page']);

            return $search->get();
        });

        $tattoos = $response['response'] ?? [];
        $total = $response['total']['value'] ?? $response['total'] ?? 0;

        return response()->json([
            'tattoos' => $tattoos,
            ...$this->paginationService->buildMeta($total, $pagination['page'], $pagination['per_page']),
        ]);
    }
}
