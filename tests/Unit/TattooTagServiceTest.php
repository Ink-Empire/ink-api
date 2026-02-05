<?php

namespace Tests\Unit;

use App\Models\Image;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\TagService;
use Tests\Traits\RefreshTestDatabase;
use Tests\TestCase;

class TattooTagServiceTest extends TestCase
{
    use RefreshTestDatabase;

    private TagService $service;
    private User $artist;
    private Tattoo $tattoo;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new TagService();

        // Create test artist
        $this->artist = User::factory()->create([
            'type_id' => 2, // Artist type
        ]);

        // Create test tattoo
        $this->tattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
            'description' => 'Test tattoo',
        ]);
    }

    public function test_parse_tags_from_response()
    {
        $response = 'dragon, flower, skull, wings, rose';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(5, $tags);
        $this->assertEquals(['dragon', 'flower', 'skull', 'wings', 'rose'], $tags);
    }

    public function test_parse_tags_with_extra_spaces()
    {
        $response = ' dragon , flower,   skull  , wings, rose ';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(5, $tags);
        $this->assertEquals(['dragon', 'flower', 'skull', 'wings', 'rose'], $tags);
    }

    public function test_parse_tags_filters_invalid_tags()
    {
        $response = 'dragon, a, verylongtagnamethatshouldbefiltered, rose';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(2, $tags);
        $this->assertEquals(['dragon', 'rose'], $tags);
    }

    public function test_attach_tags_to_tattoo()
    {
        // Create some tags in the master list
        $dragonTag = Tag::create(['name' => 'dragon', 'slug' => 'dragon', 'is_pending' => false]);
        $flowerTag = Tag::create(['name' => 'flower', 'slug' => 'flower', 'is_pending' => false]);
        Tag::create(['name' => 'skull', 'slug' => 'skull', 'is_pending' => false]);

        $tagNames = ['dragon', 'flower', 'nonexistent'];

        $attachedTags = $this->service->attachTagsToTattoo($this->tattoo, $tagNames);

        // Should only attach existing tags
        $this->assertCount(2, $attachedTags);

        // Verify pivot table
        $this->assertDatabaseHas('tattoos_tags', [
            'tattoo_id' => $this->tattoo->id,
            'tag_id' => $dragonTag->id,
        ]);
        $this->assertDatabaseHas('tattoos_tags', [
            'tattoo_id' => $this->tattoo->id,
            'tag_id' => $flowerTag->id,
        ]);
    }

    public function test_get_tags_for_tattoo()
    {
        // Create tags and attach to tattoo
        $dragonTag = Tag::create(['name' => 'dragon', 'slug' => 'dragon', 'is_pending' => false]);
        $flowerTag = Tag::create(['name' => 'flower', 'slug' => 'flower', 'is_pending' => false]);

        $this->tattoo->tags()->attach([$dragonTag->id, $flowerTag->id]);

        $tags = $this->service->getTagsForTattoo($this->tattoo);

        $this->assertCount(2, $tags);
    }

    public function test_clear_tags_for_tattoo()
    {
        // Create tags and attach to tattoo
        $dragonTag = Tag::create(['name' => 'dragon', 'slug' => 'dragon', 'is_pending' => false]);
        $flowerTag = Tag::create(['name' => 'flower', 'slug' => 'flower', 'is_pending' => false]);

        $this->tattoo->tags()->attach([$dragonTag->id, $flowerTag->id]);

        $this->assertDatabaseCount('tattoos_tags', 2);

        $result = $this->service->clearTagsForTattoo($this->tattoo);

        $this->assertTrue($result);
        $this->assertDatabaseCount('tattoos_tags', 0);

        // Tags should still exist in master list
        $this->assertDatabaseCount('tags', 2);
    }

    public function test_find_matching_tag()
    {
        Tag::create(['name' => 'dragon', 'slug' => 'dragon', 'is_pending' => false]);
        Tag::create(['name' => 'cherry blossom', 'slug' => 'cherry-blossom', 'is_pending' => false]);

        // Exact match
        $tag = $this->service->findMatchingTag('dragon');
        $this->assertNotNull($tag);
        $this->assertEquals('dragon', $tag->name);

        // Match by slug
        $tag = $this->service->findMatchingTag('cherry blossom');
        $this->assertNotNull($tag);
        $this->assertEquals('cherry blossom', $tag->name);

        // No match
        $tag = $this->service->findMatchingTag('nonexistent');
        $this->assertNull($tag);
    }

    public function test_generate_tags_for_tattoo_with_no_images()
    {
        // Tattoo has no images
        $tags = $this->service->generateTagsForTattoo($this->tattoo);

        $this->assertEmpty($tags);
    }

    public function test_set_tags_for_tattoo()
    {
        $dragonTag = Tag::create(['name' => 'dragon', 'slug' => 'dragon', 'is_pending' => false]);
        $flowerTag = Tag::create(['name' => 'flower', 'slug' => 'flower', 'is_pending' => false]);
        $skullTag = Tag::create(['name' => 'skull', 'slug' => 'skull', 'is_pending' => false]);

        // Set initial tags
        $this->service->setTagsForTattoo($this->tattoo, [$dragonTag->id, $flowerTag->id]);
        $this->assertCount(2, $this->tattoo->fresh()->tags);

        // Replace with new tags
        $this->service->setTagsForTattoo($this->tattoo, [$skullTag->id]);
        $this->assertCount(1, $this->tattoo->fresh()->tags);
        $this->assertEquals('skull', $this->tattoo->fresh()->tags->first()->name);
    }
}
