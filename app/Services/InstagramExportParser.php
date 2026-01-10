<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class InstagramExportParser
{
    /**
     * Parse Instagram export data from a ZIP archive.
     * Returns array of posts with their media files and metadata.
     */
    public function parseFromZip(ZipArchive $zip): array
    {
        $posts = [];

        // Try different possible JSON file locations
        $jsonPaths = [
            'content/posts_1.json',
            'content/posts.json',
            'your_instagram_activity/content/posts_1.json',
            'your_activity_across_facebook/content/posts_1.json',
        ];

        $jsonContent = null;
        $basePath = '';

        foreach ($jsonPaths as $path) {
            // Try to find the file (case-insensitive)
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strtolower($filename) === strtolower($path) ||
                    str_ends_with(strtolower($filename), strtolower($path))) {
                    $jsonContent = $zip->getFromIndex($i);
                    // Extract base path for relative media URIs
                    $basePath = dirname($filename);
                    if ($basePath === 'content') {
                        $basePath = '';
                    } elseif (str_ends_with($basePath, '/content')) {
                        $basePath = substr($basePath, 0, -8);
                    }
                    break 2;
                }
            }
        }

        if (!$jsonContent) {
            Log::warning('No Instagram posts JSON found in ZIP');
            return [];
        }

        // Instagram exports can have encoding issues
        $jsonContent = $this->fixEncoding($jsonContent);

        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse Instagram JSON: ' . json_last_error_msg());
            return [];
        }

        // Handle both array format and object with posts key
        $postsData = $data;
        if (isset($data['posts'])) {
            $postsData = $data['posts'];
        }

        foreach ($postsData as $index => $post) {
            $mediaItems = $post['media'] ?? [];

            if (empty($mediaItems)) {
                continue;
            }

            // Get caption from first media item
            $caption = $mediaItems[0]['title'] ?? null;
            $timestamp = isset($mediaItems[0]['creation_timestamp'])
                ? Carbon::createFromTimestamp($mediaItems[0]['creation_timestamp'])
                : null;

            // Generate unique group ID for carousel posts
            $groupId = null;
            if (count($mediaItems) > 1) {
                $groupId = ($timestamp?->timestamp ?? $index) . '_' . $index;
            }

            $media = [];
            foreach ($mediaItems as $mediaItem) {
                $uri = $mediaItem['uri'] ?? null;
                if (!$uri) {
                    continue;
                }

                // Skip videos
                if ($this->isVideoFile($uri)) {
                    continue;
                }

                // Resolve relative path
                if ($basePath && !str_starts_with($uri, $basePath)) {
                    $uri = $basePath . '/' . $uri;
                }

                $media[] = [
                    'uri' => $uri,
                    'timestamp' => $timestamp,
                ];
            }

            // Skip posts with no images (video-only posts)
            if (empty($media)) {
                continue;
            }

            $posts[] = [
                'group_id' => count($media) > 1 ? $groupId : null,
                'caption' => $caption,
                'timestamp' => $timestamp,
                'media' => $media,
            ];
        }

        Log::info("Parsed Instagram export: " . count($posts) . " posts found");

        return $posts;
    }

    /**
     * Fix encoding issues common in Instagram exports.
     * Instagram sometimes uses escaped Unicode sequences.
     */
    private function fixEncoding(string $content): string
    {
        // Fix escaped Unicode sequences like \u00e9
        $content = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE');
        }, $content);

        return $content;
    }

    /**
     * Check if a file path is a video based on extension.
     */
    private function isVideoFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'mov', 'avi', 'webm', 'mkv', 'm4v']);
    }
}
