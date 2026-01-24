<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class DocsController extends Controller
{
    /**
     * Get list of all documentation files as a tree structure.
     */
    public function index(): JsonResponse
    {
        $docsPath = base_path('docs');

        if (!File::isDirectory($docsPath)) {
            return response()->json(['files' => []]);
        }

        $files = File::files($docsPath);
        $docs = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $filename = $file->getFilename();
                $name = str_replace('.md', '', $filename);
                $title = $this->formatTitle($name);

                $docs[] = [
                    'id' => $name,
                    'filename' => $filename,
                    'title' => $title,
                    'path' => $file->getRealPath(),
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
        }

        // Sort by title
        usort($docs, fn($a, $b) => strcmp($a['title'], $b['title']));

        return response()->json(['files' => $docs]);
    }

    /**
     * Get content of a specific documentation file.
     */
    public function show(string $name): JsonResponse
    {
        $docsPath = base_path('docs');
        $filePath = $docsPath . '/' . $name . '.md';

        if (!File::exists($filePath)) {
            return response()->json(['error' => 'Documentation file not found'], 404);
        }

        $content = File::get($filePath);
        $filename = $name . '.md';

        return response()->json([
            'id' => $name,
            'filename' => $filename,
            'title' => $this->formatTitle($name),
            'content' => $content,
            'size' => File::size($filePath),
            'modified' => File::lastModified($filePath),
        ]);
    }

    /**
     * Format filename to readable title.
     */
    private function formatTitle(string $name): string
    {
        // Replace hyphens and underscores with spaces, then title case
        $title = str_replace(['-', '_'], ' ', $name);
        return ucwords($title);
    }
}
