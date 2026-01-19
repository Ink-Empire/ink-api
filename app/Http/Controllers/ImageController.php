<?php

namespace App\Http\Controllers;

use App\Exceptions\UserNotFoundException;
use App\Http\Resources\StudioResource;
use App\Http\Resources\UserResource;
use App\Models\Image;
use App\Services\ImageService;
use App\Services\StudioService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     * Generate a presigned URL for direct S3 upload from the client.
     * This bypasses the server for faster uploads.
     */
    public function getPresignedUrl(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Validate request
            $request->validate([
                'content_type' => 'required|string|in:image/jpeg,image/png,image/webp,image/gif',
                'purpose' => 'required|string|in:tattoo,profile,studio',
            ]);

            $contentType = $request->input('content_type');
            $purpose = $request->input('purpose');

            // Generate unique filename
            $extension = match ($contentType) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'jpg',
            };

            $timestamp = now()->format('YmdHis');
            $random = Str::random(8);
            $baseFilename = "{$purpose}_{$user->id}_{$timestamp}_{$random}.{$extension}";
            $filename = ImageService::prefixFilename($baseFilename);

            // Get S3 client and bucket
            $disk = Storage::disk('s3');
            $client = $disk->getClient();
            $bucket = config('filesystems.disks.s3.bucket');

            // Create presigned PUT request
            $command = $client->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => $filename,
                'ContentType' => $contentType,
                'ACL' => 'public-read',
                'CacheControl' => 'max-age=31536000',
            ]);

            // URL valid for 15 minutes
            $presignedRequest = $client->createPresignedRequest($command, '+15 minutes');
            $presignedUrl = (string) $presignedRequest->getUri();

            // Get the public URL where the image will be accessible after upload
            $publicUrl = $disk->url($filename);

            return response()->json([
                'success' => true,
                'data' => [
                    'upload_url' => $presignedUrl,
                    'filename' => $filename,
                    'public_url' => $publicUrl,
                    'expires_in' => 900, // 15 minutes in seconds
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
     * Generate multiple presigned URLs for batch upload.
     * More efficient than calling getPresignedUrl multiple times.
     */
    public function getPresignedUrls(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'files' => 'required|array|min:1|max:10',
                'files.*.content_type' => 'required|string|in:image/jpeg,image/png,image/webp,image/gif',
                'purpose' => 'required|string|in:tattoo,profile,studio',
            ]);

            $files = $request->input('files');
            $purpose = $request->input('purpose');

            $disk = Storage::disk('s3');
            $client = $disk->getClient();
            $bucket = config('filesystems.disks.s3.bucket');

            $results = [];
            $timestamp = now()->format('YmdHis');

            foreach ($files as $index => $file) {
                $contentType = $file['content_type'];

                $extension = match ($contentType) {
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    default => 'jpg',
                };

                $random = Str::random(8);
                $baseFilename = "{$purpose}_{$user->id}_{$timestamp}_{$index}_{$random}.{$extension}";
                $filename = ImageService::prefixFilename($baseFilename);

                $command = $client->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => $filename,
                    'ContentType' => $contentType,
                    'ACL' => 'public-read',
                    'CacheControl' => 'max-age=31536000',
                ]);

                $presignedRequest = $client->createPresignedRequest($command, '+15 minutes');

                $results[] = [
                    'upload_url' => (string) $presignedRequest->getUri(),
                    'filename' => $filename,
                    'public_url' => $disk->url($filename),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'uploads' => $results,
                    'expires_in' => 900,
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
     */
    public function confirmUploads(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $request->validate([
                'filenames' => 'required|array|min:1|max:10',
                'filenames.*' => 'required|string',
            ]);

            $filenames = $request->input('filenames');
            $disk = Storage::disk('s3');
            $images = [];

            foreach ($filenames as $filename) {
                // Verify the file exists in S3
                if (!$disk->exists($filename)) {
                    \Log::warning('Uploaded file not found in S3', [
                        'filename' => $filename,
                        'user_id' => $user->id,
                    ]);
                    continue;
                }

                // Create Image record
                $image = new Image([
                    'filename' => $filename,
                    'is_primary' => 0,
                ]);
                $image->setUriAttribute($filename);
                $image->save();

                $images[] = [
                    'id' => $image->id,
                    'filename' => $image->filename,
                    'uri' => $image->uri,
                ];
            }

            if (empty($images)) {
                return $this->returnErrorResponse('No valid images found', 'None of the uploaded files could be confirmed');
            }

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
}
