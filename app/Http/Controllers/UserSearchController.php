<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\BriefUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('searchString');
        if (!$query || strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $users = User::where('type_id', UserTypes::CLIENT_TYPE_ID)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('username', 'like', "%{$query}%");
            })
            ->with('image')
            ->take(20)
            ->get();

        return response()->json([
            'users' => BriefUserResource::collection($users),
        ]);
    }
}
