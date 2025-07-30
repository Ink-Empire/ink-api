<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\Image;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Exception;

class TagService
{
    /**
     * Analyze tattoo images using OpenAI and generate tags
     */
    public function generateTagsForTattoo(Tattoo $tattoo): array
    {
        try {
            // Get all images associated with this tattoo (from pivot table)
            $images = $tattoo->images;

            // Also include the primary image if it exists and isn't already in the collection
            if ($tattoo->primary_image_id) {
                $primaryImageInCollection = $images->where('id', $tattoo->primary_image_id)->first();

                if (!$primaryImageInCollection) {
                    // Primary image is not in the pivot table, load it separately
                    $primaryImage = $tattoo->primary_image;
                    if ($primaryImage) {
                        $images = $images->push($primaryImage);
                        Log::info("Added primary image to collection for tattoo ID: {$tattoo->id}", [
                            'primary_image_id' => $tattoo->primary_image_id,
                            'total_images' => $images->count()
                        ]);
                    }
                }
            }

            if ($images->count() === 0) {
                Log::warning("No images found for tattoo ID: {$tattoo->id}");
                return [];
            }

            $allTags = [];

            foreach ($images as $image) {
                $tags = $this->analyzeImage($image);
                $allTags = array_merge($allTags, $tags);
            }

            $uniqueTags = array_unique($allTags);

            $storedTags = $this->storeTags($tattoo, $uniqueTags);

            Log::info("Generated {count} tags for tattoo ID: {$tattoo->id}", [
                'count' => count($storedTags),
                'tags' => $uniqueTags
            ]);

            return $storedTags;

        } catch (Exception $e) {
            Log::error("Failed to generate tags for tattoo ID: {$tattoo->id}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }

    /**
     * Analyze a single image using OpenAI Vision API
     */
    private function analyzeImage(Image $image): array
    {
        try {
            // Get the image URL - assuming it's stored in S3 or has a public URL
            $imageUrl = $image['uri'];

            if (!$imageUrl) {
                Log::warning("Could not get URL for image ID: {$image->id}");
                return [];
            }

            // Call OpenAI Vision API
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini', // Use the cost-effective model for this task
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Analyze this tattoo image and provide three to five single-word noun descriptions that best describe what you see. Do not use words like "ink" or "tattoo" as we already know this is supposed to be a tattoo. Also avoid generic words like "art" or "design" as we want descriptive nouns for the image itself. Focus on the main subjects and objects visible in the tattoo. It is possible that the image is abstract and it is ok to not return a noun in this case. Return only the words separated by commas, no additional text or explanation.'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $imageUrl,
                                    'detail' => 'low' // Use low detail to reduce costs
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 50, // Keep it short since we only want 5 words
                'temperature' => 0.3, // Lower temperature for more consistent results
            ]);

            $content = $response->choices[0]->message->content ?? '';

            // Parse the response to extract individual tags
            $tags = $this->parseTagsFromResponse($content);

            Log::info("Analyzed image ID: {$image->id}", [
                'raw_response' => $content,
                'parsed_tags' => $tags
            ]);

            return $tags;

        } catch (Exception $e) {
            Log::error("Failed to analyze image ID: {$image->id}", [
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Parse tags from OpenAI response
     */
    private function parseTagsFromResponse(string $response): array
    {
        // Clean up the response
        $cleaned = trim($response);

        // Split by commas and clean each tag
        $tags = array_map('trim', explode(',', $cleaned));

        // Filter out empty tags and ensure they're single words
        $validTags = [];
        foreach ($tags as $tag) {
            // Remove any non-alphabetic characters and convert to lowercase
            $cleanTag = strtolower(preg_replace('/[^a-zA-Z]/', '', $tag));

            // Only keep tags that are 3-20 characters long
            if (strlen($cleanTag) >= 3 && strlen($cleanTag) <= 20) {
                $validTags[] = $cleanTag;
            }
        }

        // Limit to 5 tags maximum
        return array_slice($validTags, 0, 5);
    }

    /**
     * Store tags in the database
     */
    private function storeTags(Tattoo $tattoo, array $tags): array
    {
        $storedTags = [];

        foreach ($tags as $tagName) {
            try {
                $tag = Tag::create([
                    'tattoo_id' => $tattoo->id,
                    'tag' => $tagName
                ]);

                $storedTags[] = $tag;

            } catch (Exception $e) {
                Log::error("Failed to store tag '{$tagName}' for tattoo ID: {$tattoo->id}", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $storedTags;
    }

    /**
     * Get existing tags for a tattoo
     */
    public function getTagsForTattoo(Tattoo $tattoo): array
    {
        return $tattoo->tags->pluck('tag')->toArray();
    }

    /**
     * Delete all tags for a tattoo
     */
    public function deleteTagsForTattoo(Tattoo $tattoo): bool
    {
        try {
            $tattoo->tags()->delete();
            return true;
        } catch (Exception $e) {
            Log::error("Failed to delete tags for tattoo ID: {$tattoo->id}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Regenerate tags for a tattoo (delete existing and create new ones)
     */
    public function regenerateTagsForTattoo(Tattoo $tattoo): array
    {
        // Delete existing tags
        $this->deleteTagsForTattoo($tattoo);

        // Generate new tags
        return $this->generateTagsForTattoo($tattoo);
    }
}
