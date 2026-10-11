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

class PhotoController extends Controller
{
    /**
     * Store an uploaded photo and queue it for processing.
     */
    public function store(StorePhotoRequest $request): JsonResponse
    {
        $uuid = (string) Str::uuid();
        $file = $request->file('photo');

        $originalPath = Storage::disk('local')->putFileAs("photos/{$uuid}", $file, 'original');

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
}
