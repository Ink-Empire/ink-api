<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\Image;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

            // Match AI-suggested tags to master list and attach to tattoo
            $attachedTags = $this->attachTagsToTattoo($tattoo, $uniqueTags);

            Log::info("Generated tags for tattoo ID: {$tattoo->id}", [
                'count' => count($attachedTags),
                'tags' => $uniqueTags,
                'matched_tags' => array_map(fn($t) => $t->name, $attachedTags)
            ]);

            return $attachedTags;

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

        // Filter out empty tags and clean them
        $validTags = [];
        foreach ($tags as $tag) {
            // Convert to lowercase and trim
            $cleanTag = strtolower(trim($tag));

            // Remove extra whitespace but allow multi-word tags (e.g., "cherry blossom")
            $cleanTag = preg_replace('/\s+/', ' ', $cleanTag);

            // Only keep tags that are 2-30 characters long
            if (strlen($cleanTag) >= 2 && strlen($cleanTag) <= 30) {
                $validTags[] = $cleanTag;
            }
        }

        // Limit to 5 tags maximum
        return array_slice($validTags, 0, 5);
    }

    /**
     * Attach tags to a tattoo by matching against master list
     */
    public function attachTagsToTattoo(Tattoo $tattoo, array $tagNames): array
    {
        $attachedTags = [];
        $tagIds = [];

        foreach ($tagNames as $tagName) {
            // Try to find an exact match in the master list
            $tag = $this->findMatchingTag($tagName);

            if ($tag) {
                $tagIds[] = $tag->id;
                $attachedTags[] = $tag;
            } else {
                // Log unmatched tags for potential admin review
                Log::info("Unmatched AI tag suggestion", [
                    'tattoo_id' => $tattoo->id,
                    'suggested_tag' => $tagName
                ]);
            }
        }

        // Sync tags to tattoo (replaces existing)
        if (!empty($tagIds)) {
            $tattoo->tags()->sync($tagIds);
        }

        return $attachedTags;
    }

    /**
     * Find a matching tag in the master list
     * Uses fuzzy matching to find similar tags
     */
    public function findMatchingTag(string $tagName): ?Tag
    {
        $tagName = strtolower(trim($tagName));
        $slug = Str::slug($tagName);

        // First try exact match by slug
        $tag = Tag::where('slug', $slug)->first();
        if ($tag) {
            return $tag;
        }

        // Try exact match by name
        $tag = Tag::where('name', $tagName)->first();
        if ($tag) {
            return $tag;
        }

        // Try partial match (tag name contains the search term or vice versa)
        $tag = Tag::where('name', 'like', '%' . $tagName . '%')
                  ->orWhere(DB::raw('LOWER(?)'), 'like', DB::raw("CONCAT('%', name, '%')"))
                  ->first();

        return $tag;
    }

    /**
     * Set tags for a tattoo by tag IDs
     */
    public function setTagsForTattoo(Tattoo $tattoo, array $tagIds): array
    {
        // Filter to only valid tag IDs
        $validTags = Tag::whereIn('id', $tagIds)->get();
        $validIds = $validTags->pluck('id')->toArray();

        // Sync tags to tattoo
        $tattoo->tags()->sync($validIds);

        return $validTags->toArray();
    }

    /**
     * Add a single tag to a tattoo
     */
    public function addTagToTattoo(Tattoo $tattoo, int $tagId): ?Tag
    {
        $tag = Tag::find($tagId);
        if (!$tag) {
            return null;
        }

        // Attach without detaching existing
        $tattoo->tags()->syncWithoutDetaching([$tagId]);

        return $tag;
    }

    /**
     * Remove a tag from a tattoo
     */
    public function removeTagFromTattoo(Tattoo $tattoo, int $tagId): bool
    {
        $tattoo->tags()->detach($tagId);
        return true;
    }

    /**
     * Get existing tags for a tattoo
     */
    public function getTagsForTattoo(Tattoo $tattoo): array
    {
        return $tattoo->tags->toArray();
    }

    /**
     * Clear all tags for a tattoo (detach from pivot, don't delete from master)
     */
    public function clearTagsForTattoo(Tattoo $tattoo): bool
    {
        try {
            $tattoo->tags()->detach();
            return true;
        } catch (Exception $e) {
            Log::error("Failed to clear tags for tattoo ID: {$tattoo->id}", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Regenerate tags for a tattoo (clear existing and generate new ones)
     */
    public function regenerateTagsForTattoo(Tattoo $tattoo): array
    {
        // Clear existing tags
        $this->clearTagsForTattoo($tattoo);

        // Generate new tags
        return $this->generateTagsForTattoo($tattoo);
    }

    /**
     * Get all available tags (for autocomplete/listing)
     */
    public function getAllTags(): array
    {
        return Tag::orderBy('name')->get()->toArray();
    }

    /**
     * Search tags by name (for autocomplete)
     */
    public function searchTags(string $query, int $limit = 10): array
    {
        return Tag::search($query)
                  ->limit($limit)
                  ->get()
                  ->toArray();
    }

    /**
     * Get featured/popular tags (for homepage)
     */
    public function getFeaturedTags(int $limit = 15): array
    {
        return Tag::withCount('tattoos')
                  ->orderBy('tattoos_count', 'desc')
                  ->limit($limit)
                  ->get()
                  ->toArray();
    }

    /**
     * Get AI tag suggestions for review (tags that were suggested but not in master list)
     * This could be used for admin interface to approve new tags
     */
    public function getUnmatchedSuggestions(): array
    {
        // This would require logging unmatched tags to a separate table
        // For now, these are just logged to the application log
        return [];
    }
}
