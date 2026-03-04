<?php

namespace App\Services;

use App\Models\Style;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Exception;

class StyleService
{
    /**
     *
     */
    public function get()
    {
        return Style::paginate(100);
    }

    /**
     * @param int $id
     */
    public function getById(int $id) : ?Style
    {
        if ($id) {
            return Style::where('id', $id)->first();
        }

        return null;
    }

    /**
     * Analyze images and return AI style suggestions from our curated list.
     *
     * @param array $imageUrls Array of image URLs to analyze
     * @return array Array of matching Style models
     */
    public function suggestStylesForImages(array $imageUrls): array
    {
        $allStyles = Style::pluck('name', 'id');

        if ($allStyles->isEmpty()) {
            return [];
        }

        $styleNameList = $allStyles->values()->implode(', ');
        $matchedStyleIds = [];

        foreach ($imageUrls as $imageUrl) {
            if (empty($imageUrl)) continue;

            try {
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => "You are a tattoo style expert. Here are the available tattoo styles: [{$styleNameList}]. "
                                        . "Look at this tattoo image and determine which styles best describe it. "
                                        . "Return 1-3 style names from the list above, comma-separated. "
                                        . "Most tattoos match at least one style. Only return \"none\" if the image is not a tattoo.",
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => [
                                        'url' => $imageUrl,
                                        'detail' => 'auto',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'max_tokens' => 100,
                    'temperature' => 0.3,
                ]);

                $content = trim($response->choices[0]->message->content ?? '');

                Log::info("AI style suggestion raw response", [
                    'url' => $imageUrl,
                    'content' => $content,
                ]);

                if (strtolower($content) === 'none') {
                    continue;
                }

                $suggestedNames = array_map('trim', explode(',', $content));

                foreach ($suggestedNames as $suggestedName) {
                    $normalizedSuggestion = strtolower(preg_replace('/[\s\-]+/', '', $suggestedName));
                    $match = $allStyles->filter(
                        fn($name) => strtolower(preg_replace('/[\s\-]+/', '', $name)) === $normalizedSuggestion
                    );
                    foreach ($match as $id => $name) {
                        $matchedStyleIds[$id] = true;
                    }
                }

            } catch (\Throwable $e) {
                Log::error("Failed to analyze image for style suggestions", [
                    'url' => $imageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $styles = Style::whereIn('id', array_keys($matchedStyleIds))->get();

        Log::info("AI style suggestions generated", [
            'image_count' => count($imageUrls),
            'matched_styles' => $styles->pluck('name')->toArray(),
        ]);

        return $styles->all();
    }
}
