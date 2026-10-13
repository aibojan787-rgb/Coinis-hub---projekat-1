<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Http\Resources\PhotoUpdateResource;
use App\Jobs\ProcessPhotoJob;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PhotoController extends Controller
{
    /**
     * File extension for each accepted mime type (originals have none on disk).
     *
     * @var array<string, string>
     */
    private const DOWNLOAD_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Store an uploaded photo and queue it for processing.
     */
    public function store(StorePhotoRequest $request): JsonResponse
    {
        $uuid = (string) Str::uuid();
        $file = $request->file('photo');

        try {
            $originalPath = Storage::disk('local')->putFileAs("photos/{$uuid}", $file, 'original');
        } catch (Throwable $exception) {
            // Most likely a full disk or a permissions problem on the storage volume.
            Log::error('Could not store uploaded photo', ['exception' => $exception->getMessage()]);

            return response()->json([
                'message' => 'The server could not store the photo.',
            ], 503);
        }

        if ($originalPath === false) {
            Log::error('Could not store uploaded photo', ['exception' => 'putFileAs returned false']);

            return response()->json([
                'message' => 'The server could not store the photo.',
            ], 503);
        }

        $photo = Photo::create([
            'uuid' => $uuid,
            'status' => Photo::STATUS_PENDING,
            'original_path' => $originalPath,
            'mime_type' => $file->getMimeType(),
        ]);

        ProcessPhotoJob::dispatch($photo);

        return response()->json([
            'uuid' => $photo->uuid,
            'status' => $photo->status,
        ], 202);
    }

    /**
     * List processed photos, newest first, with cursor pagination.
     */
    public function index(): AnonymousResourceCollection
    {
        $photos = Photo::processed()
            ->orderByDesc('id')
            ->cursorPaginate(config('photos.per_page'));

        return PhotoResource::collection($photos)->additional([
            'latest_id' => (int) Photo::processed()->max('id'),
        ]);
    }

    /**
     * Return processed photos added after the given internal id, for polling.
     */
    public function updates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['required', 'integer', 'min:0'],
        ]);

        $afterId = (int) $validated['after_id'];
        $latestId = (int) Photo::processed()->max('id');

        $photos = Photo::processed()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(config('photos.updates_limit'))
            ->get();

        $response = PhotoUpdateResource::collection($photos)->response($request);
        $response->setEtag(md5("{$afterId}:{$latestId}"));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Show a single processed photo by its public uuid.
     */
    public function show(string $uuid): PhotoResource
    {
        $photo = Photo::processed()->where('uuid', $uuid)->firstOrFail();

        return new PhotoResource($photo);
    }

    /**
     * Report the processing status of a photo ("pending", "processed" or "failed").
     *
     * The uploader polls this to learn about a failed photo right away. The
     * uuid is unguessable and nothing else about the photo is returned.
     */
    public function status(string $uuid): JsonResponse
    {
        $photo = Photo::where('uuid', $uuid)->firstOrFail();

        return response()->json(['status' => $photo->status]);
    }

    /**
     * Download the original, full-quality file of a processed photo.
     *
     * The original is stored without an extension, so the file name is built
     * from the detected mime type. The original is streamed from private
     * storage and keeps its metadata (including GPS, if the phone recorded it).
     */
    public function download(string $uuid): Response
    {
        $photo = Photo::processed()->where('uuid', $uuid)->firstOrFail();

        $disk = Storage::disk('local');

        abort_unless($disk->exists($photo->original_path), 404);

        $extension = self::DOWNLOAD_EXTENSIONS[$photo->mime_type] ?? 'jpg';
        $fileName = sprintf(
            'foto-%s-%s.%s',
            $photo->created_at->format('Ymd-His'),
            Str::substr($photo->uuid, 0, 8),
            $extension,
        );

        return $disk->download($photo->original_path, $fileName, [
            'Content-Type' => $photo->mime_type,
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
