<?php

namespace App\Http\Controllers;

use App\Http\Resources\ClientNoteResource;
use App\Http\Resources\ClientProfileResource;
use App\Http\Resources\UserTagCategoryResource;
use App\Http\Resources\UserTagResource;
use App\Models\User;
use App\Models\UserTag;
use App\Services\ClientInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientProfileController extends Controller
{
    public function __construct(
        protected ClientInsightsService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $clients = $this->service->getClients($request->user(), $request->query('search'));

        return response()->json(['clients' => $clients]);
    }

    public function show(Request $request, User $client): JsonResponse
    {
        $profileData = $this->service->getClientProfile($client, $request->user());

        return $this->returnResponse('profile', new ClientProfileResource($client, $profileData));
    }

    public function tagCategories(Request $request): JsonResponse
    {
        $categories = $this->service->getCategories($request->user());

        return $this->returnResponse('categories', UserTagCategoryResource::collection($categories));
    }

    public function createTagCategory(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|in:teal,coral,purple,amber',
        ]);

        $category = $this->service->createCategory($request->user(), $request->name, $request->color);

        return response()->json(['category' => new UserTagCategoryResource($category)], 201);
    }

    public function addTag(Request $request, User $client): JsonResponse
    {
        $request->validate([
            'tag_category_id' => 'required|integer|exists:user_tag_categories,id',
            'label' => 'required|string|max:100',
        ]);

        $tag = $this->service->addTag($client, $request->user(), $request->tag_category_id, $request->label);

        return response()->json(['tag' => new UserTagResource($tag)], 201);
    }

    public function removeTag(Request $request, User $client, UserTag $tag): JsonResponse
    {
        $this->service->removeTag($client, $request->user(), $tag);

        return response()->json(null, 204);
    }

    public function suggestions(Request $request, User $client): JsonResponse
    {
        $request->validate([
            'category_id' => 'required|integer|exists:user_tag_categories,id',
            'q' => 'nullable|string|max:100',
        ]);

        $labels = $this->service->getSuggestions($request->user(), $request->category_id, $request->q);

        return response()->json($labels);
    }

    public function addNote(Request $request, User $client): JsonResponse
    {
        $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $note = $this->service->addNote($client, $request->user(), $request->body);

        return response()->json(['note' => new ClientNoteResource($note)], 201);
    }
}
