<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoLiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_with_a_lower_id_that_finished_later_is_returned_through_since(): void
    {
        // Photo 1 was uploaded first but finished processing AFTER photo 2.
        $late = Photo::factory()->create(['updated_at' => now()]);
        $seen = Photo::factory()->create(['updated_at' => now()->subSeconds(20)]);

        $response = $this->getJson('/api/photos/updates?after_id='.$seen->id.'&since='.now()->subSeconds(60)->timestamp);

        $response->assertOk();
        $this->assertContains($late->uuid, collect($response->json('data'))->pluck('uuid')->all());
    }

    public function test_without_since_a_lower_id_photo_is_still_not_returned(): void
    {
        Photo::factory()->create(['updated_at' => now()]);
        $seen = Photo::factory()->create();

        $this->getJson('/api/photos/updates?after_id='.$seen->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_photos_that_finished_before_since_and_have_a_low_id_are_not_returned(): void
    {
        $old = Photo::factory()->create(['updated_at' => now()->subHour()]);

        $this->getJson('/api/photos/updates?after_id='.$old->id.'&since='.now()->subMinute()->timestamp)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pending_and_failed_photos_are_never_returned_through_since(): void
    {
        Photo::factory()->pending()->create(['updated_at' => now()]);
        Photo::factory()->failed()->create(['updated_at' => now()]);

        $this->getJson('/api/photos/updates?after_id=999&since=0')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_response_contains_server_time_and_has_more_flag(): void
    {
        Photo::factory()->create();

        $this->getJson('/api/photos/updates?after_id=0')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonStructure(['data', 'server_time', 'has_more']);
    }

    public function test_has_more_is_true_when_the_limit_cuts_the_list_short(): void
    {
        config(['photos.updates_limit' => 2]);
        Photo::factory()->count(3)->create();

        $this->getJson('/api/photos/updates?after_id=0')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', true);
    }

    public function test_since_must_be_a_non_negative_integer(): void
    {
        $this->getJson('/api/photos/updates?after_id=0&since=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('since');

        $this->getJson('/api/photos/updates?after_id=0&since=-5')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('since');
    }

    public function test_etag_changes_when_a_photo_finishes_without_raising_the_latest_id(): void
    {
        $photo = Photo::factory()->create(['updated_at' => now()->subMinute()]);

        $before = $this->getJson('/api/photos/updates?after_id=999')->headers->get('ETag');

        $photo->forceFill(['updated_at' => now()])->save();

        $after = $this->getJson('/api/photos/updates?after_id=999')->headers->get('ETag');

        $this->assertNotSame($before, $after);
    }

    public function test_gallery_page_contains_server_time_for_the_first_poll(): void
    {
        Photo::factory()->create();

        $this->getJson('/api/photos')
            ->assertOk()
            ->assertJsonStructure(['data', 'latest_id', 'server_time']);
    }
}
