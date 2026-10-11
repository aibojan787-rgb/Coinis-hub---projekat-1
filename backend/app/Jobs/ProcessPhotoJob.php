<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\ImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPhotoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public Photo $photo) {}

    /**
     * Execute the job.
     */
    public function handle(ImageProcessor $processor): void
    {
        $originalPath = Storage::disk('local')->path($this->photo->original_path);

        $result = $processor->process($originalPath);

        $directory = dirname($this->photo->original_path);
        $webPath = "{$directory}/web.webp";
        $thumbPath = "{$directory}/thumb.webp";

        Storage::disk('public')->put($webPath, $result['web']);
        Storage::disk('public')->put($thumbPath, $result['thumb']);

        $this->photo->update([
            'status' => Photo::STATUS_PROCESSED,
            'web_path' => $webPath,
            'thumb_path' => $thumbPath,
            'width' => $result['width'],
            'height' => $result['height'],
            'size_bytes' => strlen($result['web']),
        ]);
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $this->photo->update(['status' => Photo::STATUS_FAILED]);

        Log::error('Photo processing failed', [
            'photo_uuid' => $this->photo->uuid,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
