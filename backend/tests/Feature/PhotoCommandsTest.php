<?php

namespace Tests\Feature;

use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_pending_photos_are_marked_as_failed(): void
    {
        $stale = Photo::factory()->pending()->create(['created_at' => now()->subHours(2)]);
        $fresh = Photo::factory()->pending()->create(['created_at' => now()->subMinutes(5)]);

        $this->artisan('app:fail-stale-photos')->assertSuccessful();

        $this->assertSame(Photo::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(Photo::STATUS_PENDING, $fresh->fresh()->status);
    }

    public function test_environment_check_passes_on_a_gd_build_with_webp(): void
    {
        $this->artisan('app:check-photo-environment')->assertSuccessful();
    }
}
