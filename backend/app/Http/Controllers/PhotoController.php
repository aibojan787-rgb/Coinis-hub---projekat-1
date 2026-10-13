<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Http\Resources\PhotoUpdateResource;
use App\Jobs\ProcessPhotoJob;
use App\Models\Photo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
            'server_time' => now()->timestamp,
        ]);
    }

    /**
     * Return processed photos the client has not seen yet, for polling.
     *
     * Two workers can finish photos out of order, so a photo with a LOWER id
     * can become ready after one with a higher id. Asking only for
     * "id greater than after_id" would miss it forever. The optional `since`
     * (unix time) also returns every photo that finished processing at or
     * after that moment, whatever its id. The client sends the `server_time`
     * of its last poll (minus a small overlap) and skips photos it already has.
     *
     * `has_more` is true when the limit cut the list short: the client must
     * then keep its old `since` and ask again.
     */
    public function updates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_id' => ['required', 'integer', 'min:0'],
            'since' => ['sometimes', 'integer', 'min:0'],
        ]);

        $afterId = (int) $validated['after_id'];
        $since = isset($validated['since']) ? (int) $validated['since'] : null;
        $limit = (int) config('photos.updates_limit');

        $latestId = (int) Photo::processed()->max('id');
        $latestChange = (string) Photo::processed()->max('updated_at');

        $photos = Photo::processed()
            ->where(function (Builder $query) use ($afterId, $since): void {
                $query->where('id', '>', $afterId);

                if ($since !== null) {
                    $query->orWhere('updated_at', '>=', Carbon::createFromTimestamp($since));
                }
            })
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $photos->count() > $limit;

        $response = PhotoUpdateResource::collection($photos->take($limit))
            ->additional([
                'server_time' => now()->timestamp,
                'has_more' => $hasMore,
            ])
            ->response($request);

        $response->setEtag(md5("{$afterId}:{$since}:{$latestId}:{$latestChange}"));
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
     * Download the high quality copy of a processed photo.
     *
     * New photos have a JPEG download copy (see config photos.download). Photos
     * processed before that existed, or when originals are kept, fall back to
     * the raw original, which keeps its metadata (including GPS) and has no
     * file extension on disk, so its name is built from the mime type.
     */
    public function download(string $uuid): Response
    {
        $photo = Photo::processed()->where('uuid', $uuid)->firstOrFail();

        $disk = Storage::disk('local');
        $downloadPath = Photo::downloadPathFor($photo->original_path);

        if ($disk->exists($downloadPath) && ! config('photos.download.keep_originals')) {
            $path = $downloadPath;
            $mimeType = 'image/jpeg';
        } else {
            abort_unless($disk->exists($photo->original_path), 404);

            $path = $photo->original_path;
            $mimeType = $photo->mime_type;
        }

        $extension = self::DOWNLOAD_EXTENSIONS[$mimeType] ?? 'jpg';
        $fileName = sprintf(
            'foto-%s-%s.%s',
            $photo->created_at->format('Ymd-His'),
            Str::substr($photo->uuid, 0, 8),
            $extension,
        );

        return $disk->download($path, $fileName, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
