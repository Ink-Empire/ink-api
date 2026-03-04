<?php

namespace App\Http\Controllers;

use App\Http\Resources\StyleResource;
use App\Models\Style;
use App\Services\StyleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StyleController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;


    public function __construct(protected StyleService $styleService)
    {
    }

    public function index()
    {
        // Cache styles for 1 hour - they rarely change
        $styles = Cache::remember('styles:all', 3600, function () {
            return $this->styleService->get();
        });

        return $this->returnResponse('styles', StyleResource::collection($styles));
    }

    public function get()
    {
        return $this->index();
    }

    public function create(Request $request)
    {
        try {
            $data = $request->all();
            Style::factory([
                'name' => $data['name'],
                'parent_id' => $data['parent_id'],
            ])->create();

            // Invalidate styles cache
            Cache::forget('styles:all');
        } catch (\Exception $e) {
            Log::error("Unable to create style",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            return $this->returnErrorResponse($e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $data = $request->all();

            $style = $this->styleService->getById($id);

            if ($style) {
                $style->name = $data['name'];
                $style->parent_id = $data['parent_id'];
                $style->save();

                // Invalidate styles cache
                Cache::forget('styles:all');
            }

            return $this->returnResponse('style', new StyleResource($style));

        } catch (\Exception $e) {
            Log::error("Unable to create style",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);

            return $this->returnErrorResponse($e->getMessage());
        }
    }

    /**
     * Get AI style suggestions for images.
     * Used during the upload flow to show suggestions while user is selecting styles.
     */
    public function suggestFromImages(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $request->validate([
            'image_urls' => 'required|array|min:1|max:10',
            'image_urls.*' => 'required|string|url',
        ]);

        try {
            $styles = $this->styleService->suggestStylesForImages($request->input('image_urls'));

            return response()->json([
                'success' => true,
                'data' => array_map(fn($style) => [
                    'id' => $style->id,
                    'name' => $style->name,
                    'is_ai_suggested' => true,
                ], $styles),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze images: ' . $e->getMessage(),
                'data' => [],
            ], 500);
        }
    }

    public function delete($id)
    {
        try {
            $style = $this->styleService->getById($id);

            if ($style) {
                $style->delete();

                // Invalidate styles cache
                Cache::forget('styles:all');
            }

            return response()->json(['message' => 'Style deleted successfully'], 200);

        } catch (\Exception $e) {
            Log::error("Unable to delete style",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
        }
    }
}
