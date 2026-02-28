<?php

namespace App\Services;

use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\Image;
use App\Models\BlockedTerm;
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

            // Match AI-suggested tags to master list (do NOT auto-attach)
            $matchedTags = $this->matchTagsToMasterList($tattoo, $uniqueTags);

            Log::info("Generated tag suggestions for tattoo ID: {$tattoo->id}", [
                'count' => count($matchedTags),
                'tags' => $uniqueTags,
                'matched_tags' => array_map(fn($t) => $t->name, $matchedTags)
            ]);

            return $matchedTags;

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
                                'text' => $this->getTagAnalysisPrompt()
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
     * Match tag names against master list without attaching to tattoo.
     * Returns matched Tag models for the frontend to display as suggestions.
     */
    public function matchTagsToMasterList(Tattoo $tattoo, array $tagNames): array
    {
        $matchedTags = [];

        foreach ($tagNames as $tagName) {
            $tag = $this->findMatchingTag($tagName);

            if ($tag) {
                $matchedTags[] = $tag;
            } else {
                Log::info("Unmatched AI tag suggestion", [
                    'tattoo_id' => $tattoo->id,
                    'suggested_tag' => $tagName
                ]);
            }
        }

        return $matchedTags;
    }

    /**
     * Attach tags to a tattoo by matching against master list.
     * Uses syncWithoutDetaching to preserve user-selected tags.
     */
    public function attachTagsToTattoo(Tattoo $tattoo, array $tagNames): array
    {
        $attachedTags = [];
        $tagIds = [];

        foreach ($tagNames as $tagName) {
            $tag = $this->findMatchingTag($tagName);

            if ($tag) {
                $tagIds[] = $tag->id;
                $attachedTags[] = $tag;
            } else {
                Log::info("Unmatched AI tag suggestion", [
                    'tattoo_id' => $tattoo->id,
                    'suggested_tag' => $tagName
                ]);
            }
        }

        if (!empty($tagIds)) {
            $tattoo->tags()->syncWithoutDetaching($tagIds);
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

        // Try partial match (tag name contains the search term)
        $tag = Tag::where('name', 'like', '%' . $tagName . '%')->first();

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

    /**
     * Analyze images and return AI tag suggestions WITHOUT attaching to a tattoo.
     * Used for showing suggestions while user is selecting tags during upload flow.
     *
     * @param array $imageUrls Array of image URLs to analyze
     * @return array Array of suggested Tag objects that match the master list
     */
    public function suggestTagsForImages(array $imageUrls): array
    {
        $allTags = [];

        foreach ($imageUrls as $imageUrl) {
            if (empty($imageUrl)) continue;

            try {
                $tags = $this->analyzeImageUrl($imageUrl);
                $allTags = array_merge($allTags, $tags);
            } catch (Exception $e) {
                Log::error("Failed to analyze image for suggestions", [
                    'url' => $imageUrl,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $uniqueTags = array_unique($allTags);

        // Return suggestions as a mix of existing tags and new suggestions
        // New suggestions are returned with id=null so frontend knows they need to be created
        $resultTags = [];
        $seenNames = [];

        foreach ($uniqueTags as $tagName) {
            $tagName = strtolower(trim($tagName));

            // Skip if we've already processed this name
            if (in_array($tagName, $seenNames)) {
                continue;
            }
            $seenNames[] = $tagName;

            // Skip inappropriate or invalid tags
            if (!$this->isValidTagName($tagName)) {
                continue;
            }

            // Check if tag exists
            $existingTag = $this->findMatchingTag($tagName);

            if ($existingTag) {
                // Return existing tag
                $resultTags[] = $existingTag;
            } else {
                // Return as a "suggested" new tag (not created yet)
                // Frontend will call create endpoint when user selects it
                $resultTags[] = (object) [
                    'id' => null,
                    'name' => $tagName,
                    'slug' => Str::slug($tagName),
                    'is_pending' => false,
                    'is_new_suggestion' => true,
                ];
            }
        }

        Log::info("AI tag suggestions generated", [
            'image_count' => count($imageUrls),
            'raw_suggestions' => $uniqueTags,
            'result_tags' => array_map(fn($t) => [
                'name' => $t->name,
                'is_new' => $t->id === null
            ], $resultTags)
        ]);

        return $resultTags;
    }

    /**
     * Check if a tag name is valid (not inappropriate, correct length)
     */
    private function isValidTagName(string $tagName): bool
    {
        // Check length
        if (strlen($tagName) < 2 || strlen($tagName) > 30) {
            return false;
        }

        // Get blocked terms from database (cached for 1 hour)
        $blockedTerms = BlockedTerm::getActiveTerms();

        foreach ($blockedTerms as $blocked) {
            if (str_contains($tagName, $blocked)) {
                Log::warning("Blocked inappropriate AI tag suggestion", ['tag' => $tagName]);
                return false;
            }
        }

        return true;
    }

    /**
     * Analyze a single image URL using OpenAI Vision API
     * Similar to analyzeImage but takes a URL directly instead of an Image model
     */
    private function analyzeImageUrl(string $imageUrl): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $this->getTagAnalysisPrompt()
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $imageUrl,
                                    'detail' => 'low'
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 50,
                'temperature' => 0.3,
            ]);

            $content = $response->choices[0]->message->content ?? '';
            return $this->parseTagsFromResponse($content);

        } catch (Exception $e) {
            Log::error("Failed to analyze image URL", [
                'url' => $imageUrl,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get the prompt for AI tag analysis with content filtering
     */
    private function getTagAnalysisPrompt(): string
    {
        return 'Analyze this tattoo image and provide three to five single-word noun descriptions that best describe what you see. ' .
               'Focus on the main subjects, objects, and themes visible in the tattoo (e.g., animals, flowers, symbols, mythological creatures). ' .
               'Do not use words like "ink" or "tattoo" as we already know this is a tattoo. ' .
               'Avoid generic words like "art", "design", "style", or "piece". ' .
               'IMPORTANT: Do not suggest any inappropriate, vulgar, sexual, or offensive terms. Keep suggestions family-friendly and professional. ' .
               'If the image contains adult or inappropriate content, return only safe, neutral descriptors or nothing at all. ' .
               'It is okay to not return anything if the image is abstract or inappropriate. ' .
               'Return only the words separated by commas, no additional text or explanation.';
    }

    /**
     * Analyze a tattoo image and return both a description and tag suggestions.
     * Used by the fix/cleanup command to correct mismatched descriptions and tags.
     *
     * @param string $imageUrl The URL of the tattoo image
     * @param array $existingTags Array of existing approved tag names for matching
     * @return array ['description' => string, 'suggested_tags' => array, 'matched_tags' => array]
     */
    public function analyzeTattooForDescriptionAndTags(string $imageUrl, array $existingTags = []): array
    {
        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $this->getDescriptionAndTagsPrompt($existingTags)
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $imageUrl,
                                    'detail' => 'low'
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 200,
                'temperature' => 0.3,
            ]);

            $content = $response->choices[0]->message->content ?? '';
            return $this->parseDescriptionAndTagsResponse($content, $existingTags);

        } catch (Exception $e) {
            Log::error("Failed to analyze tattoo for description and tags", [
                'url' => $imageUrl,
                'error' => $e->getMessage()
            ]);
            return [
                'description' => null,
                'suggested_tags' => [],
                'matched_tags' => []
            ];
        }
    }

    /**
     * Get prompt for combined description and tag analysis
     */
    private function getDescriptionAndTagsPrompt(array $existingTags): string
    {
        $tagList = '';
        if (!empty($existingTags)) {
            // Include a sample of existing tags to guide the AI toward using them
            $sampleTags = array_slice($existingTags, 0, 100);
            $tagList = "\n\nHere are some existing tags you should prefer to use when they match: " . implode(', ', $sampleTags);
        }

        return 'Analyze this tattoo image and provide:

1. DESCRIPTION: Write a clear, concise description (1-2 sentences) of what the tattoo depicts. Focus on the main subject, style elements, and any notable artistic details. Do not mention that it is a tattoo.

2. TAGS: List 3-5 single-word or two-word tags that describe the main subjects and themes visible. Focus on concrete nouns (animals, objects, symbols) rather than abstract concepts.' . $tagList . '

IMPORTANT: Keep all content family-friendly and professional. If the image is unclear or inappropriate, provide neutral descriptors.

Format your response exactly like this:
DESCRIPTION: [your description here]
TAGS: [tag1, tag2, tag3, tag4, tag5]';
    }

    /**
     * Parse the combined description and tags response from OpenAI
     */
    private function parseDescriptionAndTagsResponse(string $response, array $existingTags): array
    {
        $description = null;
        $suggestedTags = [];
        $matchedTags = [];

        // Extract description
        if (preg_match('/DESCRIPTION:\s*(.+?)(?=TAGS:|$)/is', $response, $descMatch)) {
            $description = trim($descMatch[1]);
            // Clean up any trailing newlines
            $description = preg_replace('/\s+/', ' ', $description);
        }

        // Extract tags
        if (preg_match('/TAGS:\s*(.+?)$/is', $response, $tagMatch)) {
            $tagString = trim($tagMatch[1]);
            $suggestedTags = array_map('trim', explode(',', $tagString));
            $suggestedTags = array_filter($suggestedTags, fn($t) => strlen($t) >= 2 && strlen($t) <= 30);
            $suggestedTags = array_map('strtolower', $suggestedTags);
            $suggestedTags = array_slice($suggestedTags, 0, 5);
        }

        // Match suggested tags against existing tags
        foreach ($suggestedTags as $suggestedTag) {
            $match = $this->findMatchingTag($suggestedTag);
            if ($match) {
                $matchedTags[] = $match;
            }
        }

        Log::info("Parsed description and tags response", [
            'description' => $description,
            'suggested_tags' => $suggestedTags,
            'matched_count' => count($matchedTags)
        ]);

        return [
            'description' => $description,
            'suggested_tags' => $suggestedTags,
            'matched_tags' => $matchedTags
        ];
    }

    /**
     * Create a tag from an AI suggestion (user accepted it).
     * Creates as approved (is_pending = false) with is_ai_generated = true.
     */
    public function createFromAiSuggestion(string $tagName): ?Tag
    {
        $tagName = strtolower(trim($tagName));
        $slug = Str::slug($tagName);

        // Validate the tag name
        if (!$this->isValidTagName($tagName)) {
            return null;
        }

        // Check if tag already exists
        $existing = Tag::where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        // Create new tag as APPROVED (user accepted the AI suggestion)
        $tag = Tag::create([
            'name' => $tagName,
            'slug' => $slug,
            'is_pending' => false, // Approved because user explicitly accepted it
            'is_ai_generated' => true,
        ]);

        Log::info("Created AI-suggested tag (user accepted)", [
            'tag_id' => $tag->id,
            'name' => $tagName
        ]);

        return $tag;
    }
}
