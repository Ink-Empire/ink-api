<?php

namespace App\Http\Controllers;

use App\Http\Resources\BulkUploadResource;
use App\Http\Resources\BulkUploadItemResource;
use App\Jobs\DeleteBulkUpload;
use App\Jobs\ScanBulkUploadZip;
use App\Jobs\ProcessBulkUploadBatch;
use App\Jobs\PublishBulkUploadItems;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BulkUploadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $uploads = BulkUpload::where('artist_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => BulkUploadResource::collection($uploads),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'data' => new BulkUploadResource($upload),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'file' => 'required|file|mimes:zip|max:512000', // 500MB max
            'source' => 'nullable|string|in:instagram,manual',
        ]);

        $file = $request->file('file');
        $source = $request->input('source', 'manual');

        // Create bulk upload record
        $bulkUpload = BulkUpload::create([
            'artist_id' => $user->id,
            'source' => $source,
            'status' => 'scanning',
            'original_filename' => $file->getClientOriginalName(),
            'zip_size_bytes' => $file->getSize(),
            'zip_expires_at' => now()->addDays(config('bulk_upload.zip_expiry_days', 30)),
        ]);

        // Generate unique filename and upload to S3
        $zipFilename = $bulkUpload->id . '_' . Str::random(8) . '.zip';
        $zipPath = "bulk-uploads/{$user->id}/{$zipFilename}";

        // Use streaming to avoid loading entire file into memory
        Storage::disk('s3')->put($zipPath, fopen($file->getRealPath(), 'r'));

        $bulkUpload->update(['zip_filename' => $zipFilename]);

        // Dispatch job to scan ZIP contents
        ScanBulkUploadZip::dispatch($bulkUpload->id);

        return response()->json([
            'data' => new BulkUploadResource($bulkUpload),
            'message' => 'Upload received. Scanning contents...',
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)
            ->findOrFail($id);

        // Mark as deleting so it doesn't show in lists
        $upload->update(['status' => 'deleting']);

        // Dispatch job to handle cleanup asynchronously
        \Log::info("dispatching job to delete bulk upload from S3");
        DeleteBulkUpload::dispatch($upload->id);

        return response()->json([
            'message' => 'Bulk upload deletion started',
        ]);
    }

    public function items(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)
            ->findOrFail($id);

        $request->validate([
            'filter' => 'nullable|string|in:all,unprocessed,processed,ready,published,skipped',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'primary_only' => 'nullable|boolean',
        ]);

        $filter = $request->input('filter', 'all');
        $perPage = $request->input('per_page', 24);
        $primaryOnly = $request->boolean('primary_only', true);

        $query = $upload->items()->with(['image', 'placement', 'primaryStyle']);

        // Apply filter
        switch ($filter) {
            case 'unprocessed':
                $query->unprocessed();
                break;
            case 'processed':
                $query->processed();
                break;
            case 'ready':
                $query->readyForPublish();
                break;
            case 'published':
                $query->where('is_published', true);
                break;
            case 'skipped':
                $query->where('is_skipped', true);
                break;
        }

        // Only show primary items from groups (for grid view)
        if ($primaryOnly) {
            $query->primaryInGroup();
        }

        $items = $query->orderBy('sort_order')->paginate($perPage);

        return response()->json([
            'data' => BulkUploadItemResource::collection($items),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);
        $item = $upload->items()->findOrFail($itemId);

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'placement_id' => 'nullable|exists:placements,id',
            'primary_style_id' => 'nullable|exists:styles,id',
            'additional_style_ids' => 'nullable|array',
            'additional_style_ids.*' => 'exists:styles,id',
            'approved_tag_ids' => 'nullable|array',
            'approved_tag_ids.*' => 'exists:tags,id',
            'is_skipped' => 'nullable|boolean',
        ]);

        $item->update(array_merge(
            $request->only([
                'title',
                'description',
                'placement_id',
                'primary_style_id',
                'additional_style_ids',
                'approved_tag_ids',
                'is_skipped',
            ]),
            ['is_edited' => true]
        ));

        // If this is a group, optionally apply to all items in group
        if ($request->boolean('apply_to_group') && $item->isPartOfGroup()) {
            $groupData = array_merge(
                $request->only([
                    'title',
                    'description',
                    'placement_id',
                    'primary_style_id',
                    'additional_style_ids',
                    'approved_tag_ids',
                ]),
                ['is_edited' => true]
            );

            BulkUploadItem::where('bulk_upload_id', $upload->id)
                ->where('post_group_id', $item->post_group_id)
                ->where('id', '!=', $item->id)
                ->update($groupData);
        }

        return response()->json([
            'data' => new BulkUploadItemResource($item->fresh(['image', 'placement', 'primaryStyle'])),
        ]);
    }

    public function batchUpdateItems(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);

        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'exists:bulk_upload_items,id',
            'updates' => 'required|array',
            'updates.placement_id' => 'nullable|exists:placements,id',
            'updates.primary_style_id' => 'nullable|exists:styles,id',
            'updates.is_skipped' => 'nullable|boolean',
        ]);

        $itemIds = $request->input('item_ids');
        $updates = $request->input('updates');

        // Verify all items belong to this upload
        $count = $upload->items()->whereIn('id', $itemIds)->count();
        if ($count !== count($itemIds)) {
            return response()->json(['error' => 'Some items do not belong to this upload'], 400);
        }

        BulkUploadItem::whereIn('id', $itemIds)->update($updates);

        return response()->json([
            'message' => "Updated {$count} items",
            'count' => $count,
        ]);
    }

    public function processBatch(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);

        if (!$upload->canProcess()) {
            return response()->json([
                'error' => 'Cannot process this upload. Status: ' . $upload->status,
            ], 400);
        }

        $request->validate([
            'batch_size' => 'nullable|integer|min:1|max:200',
        ]);

        $batchSize = $request->input('batch_size', config('bulk_upload.max_batch_size', 200));

        // Check if there are items to process
        $unprocessedCount = $upload->unprocessedItems()->count();
        if ($unprocessedCount === 0) {
            return response()->json([
                'error' => 'No unprocessed items remaining',
            ], 400);
        }

        $upload->update(['status' => 'processing']);

        ProcessBulkUploadBatch::dispatch($upload->id, $batchSize);

        return response()->json([
            'message' => "Processing next {$batchSize} items",
            'remaining' => $unprocessedCount,
        ]);
    }

    public function processRange(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);

        if (!$upload->canProcess()) {
            return response()->json([
                'error' => 'Cannot process this upload. Status: ' . $upload->status,
            ], 400);
        }

        $request->validate([
            'from' => 'required|integer|min:1',
            'to' => 'required|integer|min:1|gte:from',
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        // Limit range to 200
        if (($to - $from + 1) > 200) {
            return response()->json([
                'error' => 'Range cannot exceed 200 items',
            ], 400);
        }

        $upload->update(['status' => 'processing']);

        ProcessBulkUploadBatch::dispatch($upload->id, $to - $from + 1, $from - 1);

        return response()->json([
            'message' => "Processing items {$from} to {$to}",
        ]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);

        $readyCount = $upload->readyItems()->primaryInGroup()->count();

        if ($readyCount === 0) {
            return response()->json([
                'error' => 'No items ready to publish',
            ], 400);
        }

        PublishBulkUploadItems::dispatch($upload->id);

        return response()->json([
            'message' => "Publishing {$readyCount} tattoos",
            'count' => $readyCount,
        ]);
    }

    public function publishStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $upload = BulkUpload::where('artist_id', $user->id)->findOrFail($id);
        $upload->updateCounts();

        return response()->json([
            'status' => $upload->status,
            'total_images' => $upload->total_images,
            'cataloged_images' => $upload->cataloged_images,
            'processed_images' => $upload->processed_images,
            'published_images' => $upload->published_images,
            'ready_to_publish' => $upload->readyItems()->primaryInGroup()->count(),
        ]);
    }
}
