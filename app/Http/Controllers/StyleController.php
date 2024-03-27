<?php

namespace App\Http\Controllers;

use App\Http\Resources\StyleResource;
use App\Models\Style;
use App\Services\StyleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StyleController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;


    public function __construct(protected StyleService $styleService)
    {
    }

    public function get()
    {
        $studios = $this->styleService->get();

        return $this->returnResponse('styles', StyleResource::collection($studios));
    }

    public function create(Request $request)
    {
        try {
            $data = $request->all();
            Style::factory([
                'name' => $data['name'],
                'parent_id' => $data['parent_id'],
            ])->create();
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

    public function delete($id)
    {
        try {
            $style = $this->styleService->getById($id);

            if ($style) {
                $style->delete();
            }

            return response()->json(['message' => 'Style deleted successfully'], 200);

        } catch (\Exception $e) {
            Log::error("Unable to create style",
                [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
        }
    }
}
