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
            return response()->json(['files' => [], 'folders' => []]);
        }

        // Get root level files
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

        // Get subdirectories and their files
        $folders = [];
        $directories = File::directories($docsPath);

        foreach ($directories as $directory) {
            $folderName = basename($directory);
            $folderFiles = File::files($directory);
            $folderDocs = [];

            foreach ($folderFiles as $file) {
                if ($file->getExtension() === 'md') {
                    $filename = $file->getFilename();
                    $name = str_replace('.md', '', $filename);
                    $id = $folderName . '/' . $name;

                    $folderDocs[] = [
                        'id' => $id,
                        'filename' => $filename,
                        'title' => $this->formatTitle($name),
                        'path' => $file->getRealPath(),
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                    ];
                }
            }

            if (!empty($folderDocs)) {
                usort($folderDocs, fn($a, $b) => strcmp($a['title'], $b['title']));
                $folders[] = [
                    'name' => $folderName,
                    'title' => $this->formatTitle($folderName),
                    'files' => $folderDocs,
                ];
            }
        }

        // Sort files and folders by title
        usort($docs, fn($a, $b) => strcmp($a['title'], $b['title']));
        usort($folders, fn($a, $b) => strcmp($a['title'], $b['title']));

        return response()->json(['files' => $docs, 'folders' => $folders]);
    }

    /**
     * Get content of a specific documentation file.
     * Supports subdirectories via path like "flows/artist-signup-onboarding"
     */
    public function show(string $name): JsonResponse
    {
        $docsPath = base_path('docs');
        $filePath = $docsPath . '/' . $name . '.md';

        if (!File::exists($filePath)) {
            return response()->json(['error' => 'Documentation file not found'], 404);
        }

        $content = File::get($filePath);
        $filename = basename($name) . '.md';
        $displayTitle = $this->formatTitle(basename($name));

        return response()->json([
            'id' => $name,
            'filename' => $filename,
            'title' => $displayTitle,
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
