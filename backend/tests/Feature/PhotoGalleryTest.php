<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_lists_only_processed_photos_newest_first(): void
    {
        $older = Photo::factory()->create();
        Photo::factory()->pending()->create();
        Photo::factory()->failed()->create();
        $newer = Photo::factory()->create();

        $response = $this->getJson('/api/photos');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.uuid', $newer->uuid);
        $response->assertJsonPath('data.1.uuid', $older->uuid);
    }

    public function test_gallery_returns_the_latest_processed_id_for_polling(): void
    {
        Photo::factory()->create();
        $newest = Photo::factory()->create();
        Photo::factory()->pending()->create();

        $this->getJson('/api/photos')->assertJsonPath('latest_id', $newest->id);
    }

    public function test_gallery_returns_24_photos_per_page_with_a_cursor(): void
    {
        Photo::factory()->count(30)->create();

        $response = $this->getJson('/api/photos');

        $response->assertOk()->assertJsonCount(24, 'data');
        $this->assertNotNull($response->json('links.next'));
    }

    public function test_gallery_resource_exposes_public_fields_only(): void
    {
        Photo::factory()->create();

        $photo = $this->getJson('/api/photos')->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['uuid', 'thumb_url', 'web_url', 'width', 'height', 'created_at'],
            array_keys($photo),
        );
    }

    public function test_single_processed_photo_can_be_shown_by_uuid(): void
    {
        $photo = Photo::factory()->create();

        $this->getJson("/api/photos/{$photo->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $photo->uuid);
    }

    public function test_single_photo_that_is_not_processed_returns_404(): void
    {
        $photo = Photo::factory()->pending()->create();

        $this->getJson("/api/photos/{$photo->uuid}")->assertNotFound();
    }

    public function test_unknown_or_malformed_uuid_returns_404(): void
    {
        $this->getJson('/api/photos/00000000-0000-0000-0000-000000000000')->assertNotFound();
        $this->getJson('/api/photos/not-a-uuid')->assertNotFound();
    }

    public function test_updates_return_only_processed_photos_after_the_given_id(): void
    {
        $first = Photo::factory()->create();
        $second = Photo::factory()->create();
        Photo::factory()->pending()->create();
        $third = Photo::factory()->create();

        $response = $this->getJson("/api/photos/updates?after_id={$second->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.uuid', $third->uuid);
        $response->assertJsonPath('data.0.cursor_id', $third->id);
        $this->assertNotSame($first->uuid, $response->json('data.0.uuid'));
    }

    public function test_updates_requires_after_id(): void
    {
        $this->getJson('/api/photos/updates')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('after_id');
    }

    public function test_updates_return_304_when_nothing_changed(): void
    {
        Photo::factory()->create();

        $first = $this->getJson('/api/photos/updates?after_id=0');
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/photos/updates?after_id=0')
            ->assertStatus(304);
    }

    public function test_responses_include_security_headers(): void
    {
        $this->getJson('/api/photos')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
