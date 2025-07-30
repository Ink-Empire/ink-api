<?php

namespace Tests\Unit;

use App\Models\Image;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;
use App\Services\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

class TattooTagServiceTest extends TestCase
{
    use RefreshDatabase;

    private TagService $service;
    private User $artist;
    private Tattoo $tattoo;
    private Image $image;

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

        // Create test image
        $this->image = Image::factory()->create([
            'url' => 'https://example.com/test-image.jpg',
        ]);

        // Attach image to tattoo
        $this->tattoo->images()->attach($this->image->id);
    }

    public function testParseTagsFromResponse()
    {
        $response = 'dragon, flower, skull, wings, rose';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(5, $tags);
        $this->assertEquals(['dragon', 'flower', 'skull', 'wings', 'rose'], $tags);
    }

    public function testParseTagsWithExtraSpaces()
    {
        $response = ' dragon , flower,   skull  , wings, rose ';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(5, $tags);
        $this->assertEquals(['dragon', 'flower', 'skull', 'wings', 'rose'], $tags);
    }

    public function testParseTagsFiltersInvalidTags()
    {
        $response = 'dragon, a, verylongtagnamethatshouldbefiltered, 123invalid, rose';

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseTagsFromResponse');
        $method->setAccessible(true);

        $tags = $method->invoke($this->service, $response);

        $this->assertCount(2, $tags);
        $this->assertEquals(['dragon', 'rose'], $tags);
    }

    public function testGetImageUrlWithDirectUrl()
    {
        $image = new Image(['url' => 'https://example.com/image.jpg']);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getImageUrl');
        $method->setAccessible(true);

        $url = $method->invoke($this->service, $image);

        $this->assertEquals('https://example.com/image.jpg', $url);
    }

    public function testGetImageUrlWithS3Key()
    {
        Config::set('filesystems.disks.s3.bucket', 'test-bucket');
        Config::set('filesystems.disks.s3.region', 'us-east-1');

        $image = new Image(['s3_key' => 'images/tattoo.jpg']);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getImageUrl');
        $method->setAccessible(true);

        $url = $method->invoke($this->service, $image);

        $this->assertEquals('https://test-bucket.s3.us-east-1.amazonaws.com/images/tattoo.jpg', $url);
    }

    public function testStoreTags()
    {
        $tags = ['dragon', 'flower', 'skull'];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('storeTags');
        $method->setAccessible(true);

        $storedTags = $method->invoke($this->service, $this->tattoo, $tags);

        $this->assertCount(3, $storedTags);
        $this->assertDatabaseCount('tags', 3);

        foreach ($tags as $tagName) {
            $this->assertDatabaseHas('tags', [
                'tattoo_id' => $this->tattoo->id,
                'tag' => $tagName
            ]);
        }
    }

    public function testGetTagsForTattoo()
    {
        // Create some tags
        Tag::create(['tattoo_id' => $this->tattoo->id, 'tag' => 'dragon']);
        Tag::create(['tattoo_id' => $this->tattoo->id, 'tag' => 'flower']);

        $tags = $this->service->getTagsForTattoo($this->tattoo);

        $this->assertCount(2, $tags);
        $this->assertContains('dragon', $tags);
        $this->assertContains('flower', $tags);
    }

    public function testDeleteTagsForTattoo()
    {
        // Create some tags
        Tag::create(['tattoo_id' => $this->tattoo->id, 'tag' => 'dragon']);
        Tag::create(['tattoo_id' => $this->tattoo->id, 'tag' => 'flower']);

        $this->assertDatabaseCount('tags', 2);

        $result = $this->service->deleteTagsForTattoo($this->tattoo);

        $this->assertTrue($result);
        $this->assertDatabaseCount('tags', 0);
    }

    public function testGenerateTagsForTattooWithNoImages()
    {
        // Create tattoo without images
        $emptyTattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
        ]);

        $tags = $this->service->generateTagsForTattoo($emptyTattoo);

        $this->assertEmpty($tags);
    }
}
