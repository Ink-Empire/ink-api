<?php

namespace App\Http\Controllers;

use App\Exceptions\UserNotFoundException;
use App\Http\Resources\BriefImageResource;
use App\Http\Resources\StudioResource;
use App\Http\Resources\UserResource;
use App\Enums\UploadPurpose;
use App\Enums\UserTypes;
use App\Models\Artist;
use App\Scopes\ArtistScope;
use App\Models\Image;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use App\Http\Requests\UpdateImageEditParamsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class ImageController extends Controller
{
    public function __construct(
        protected ImageService  $imageService,
        protected UserService   $userService,
        protected StudioService $studioService
    )
    {
    }

    /**
     * @param Request $request
     */
    public function upload(Request $request): JsonResponse|Response
    {
        try {
            $data = $request->all();

            $file = $request->get('profile_photo');

            $id = $request->get('id');
            $type = $request->get('type'); //user, studio

            $date = Date('Ymdi');

            $filename = "profile_" . $id . "_" . $date . ".jpeg";

            $image = $this->imageService->processImage($file, $filename);

            if ($image) {
                return $this->setPrimaryImage($type, $id, $image);
            }

        } catch (\Exception $e) {
            $error = "Error: Unable to set profile image for type $type";
            \Log::error($error, [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return $this->returnErrorResponse($e->getMessage(), $error);
        }
    }

    /**
     * @throws UserNotFoundException|\App\Exceptions\StudioNotFoundException
     */
    private function setPrimaryImage($type, $id, $image)
    {
        switch ($type) {
            case 'user': //artists can also use user service during registration for upload
                $user = $this->userService->setProfileImage($id, $image);
                return $this->returnResponse('user', new UserResource($user));
            case 'studio':
                $studio = $this->studioService->setStudioImage($id, $image);
                return $this->returnResponse('studio', new StudioResource($studio));
        }
    }

    /**
     * Generate a signed upload form for a direct S3 upload from the client.
     * This bypasses the server for faster uploads.
     *
     * The client POSTs the returned fields plus the file to upload_url. Size,
     * content type, key and ACL are all signed into the policy, so S3 refuses
     * anything that does not match what was asked for here.
     */
    public function getPresignedUrl(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Validate request
            $request->validate([
                'content_type' => 'required|string|in:image/jpeg,image/png,image/webp,image/gif',
                'purpose' => ['required', 'string', Rule::in(UploadPurpose::values())],
            ]);

            $upload = $this->imageService->uploadForm(
                UploadPurpose::from($request->input('purpose')),
                $user->id,
                $request->input('content_type')
            );

            return response()->json([
                'success' => true,
                'data' => $upload + [
                    'max_bytes' => ImageService::maxUploadBytes(),
                    'expires_in' => config('uploads.window_minutes') * 60,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate presigned URL', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return $this->returnErrorResponse('Failed to generate upload URL', $e->getMessage());
        }
    }

    /**
     * Generate multiple signed upload forms for batch upload.
     * More efficient than calling getPresignedUrl multiple times.
     */
    public function getPresignedUrls(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'files' => 'required|array|min:1|max:10',
                'files.*.content_type' => 'required|string|in:image/jpeg,image/png,image/webp,image/gif',
                'purpose' => ['required', 'string', Rule::in(UploadPurpose::values())],
            ]);

            $purpose = UploadPurpose::from($request->input('purpose'));

            $uploads = [];

            foreach ($request->input('files') as $index => $file) {
                $uploads[] = $this->imageService->uploadForm(
                    $purpose,
                    $user->id,
                    $file['content_type'],
                    $index
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'uploads' => $uploads,
                    'max_bytes' => ImageService::maxUploadBytes(),
                    'expires_in' => config('uploads.window_minutes') * 60,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate presigned URLs', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return $this->returnErrorResponse('Failed to generate upload URLs', $e->getMessage());
        }
    }

    /**
     * Confirm that images were uploaded successfully and create Image records.
     * Called after direct S3 upload completes.
     *
     * The filenames arrive from the client, and an image URL exposes the
     * filename of every image on the site, so being able to name a file is not
     * evidence of having uploaded it. The service checks each one against the
     * caller before it creates a row.
     */
    public function confirmUploads(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'filenames' => 'required|array|min:1|max:10',
                'filenames.*' => 'required|string',
            ]);

            $confirmed = $this->imageService->confirmUploads(
                $user->id,
                $request->input('filenames')
            );

            if (empty($confirmed)) {
                return $this->returnErrorResponse('No valid images found', 'None of the uploaded files could be confirmed');
            }

            $images = array_map(fn (Image $image) => [
                'id' => $image->id,
                'filename' => $image->filename,
                'uri' => $image->uri,
            ], $confirmed);

            return response()->json([
                'success' => true,
                'data' => [
                    'images' => $images,
                    'count' => count($images),
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to confirm uploads', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return $this->returnErrorResponse('Failed to confirm uploads', $e->getMessage());
        }
    }

    public function updateEditParams(UpdateImageEditParamsRequest $request, Image $image): JsonResponse
    {
        $image->update(['edit_params' => $request->validated()]);

        // reindex any tattoos that use this image so ES reflects the updated edit_params
        $tattooIds = $image->tattoos()->pluck('tattoo_id')
            ->merge($image->tattoosAsPrimary()->pluck('id'))
            ->unique();

        if ($tattooIds->isNotEmpty()) {
            $tattoos = \App\Models\Tattoo::whereIn('id', $tattooIds)->get();
            $tattoos->searchable();

            foreach ($tattoos as $tattoo) {
                // Tattoo detail cache
                Cache::forget("es:tattoo:{$tattoo->id}");

                // User profile tattoo list (version bump forces new cache key)
                if ($tattoo->uploaded_by_user_id) {
                    Cache::increment("es:user:{$tattoo->uploaded_by_user_id}:tattoos:version");
                }
            }
        }

        // Re-index any artists that use this as their profile image
        $artistIds = $image->artists()
            ->whereIn('type_id', [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID])
            ->pluck('id');

        if ($artistIds->isNotEmpty()) {
            Artist::withoutGlobalScope(ArtistScope::class)
                ->whereIn('id', $artistIds)
                ->get()
                ->searchable();
        }

        return $this->returnResponse('image', new BriefImageResource($image->fresh()));
    }
}
