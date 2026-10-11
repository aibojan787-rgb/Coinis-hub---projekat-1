<?php

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use SplFileInfo;

class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver);
    }

    /**
     * Build the public web and thumbnail WebP files from an original upload.
     *
     * Re-encoding through GD drops all metadata (EXIF, GPS, device info), and
     * the orientation is applied before encoding so the output is upright.
     *
     * @return array{width: int, height: int, web: string, thumb: string}
     */
    public function process(string $sourcePath): array
    {
        $image = $this->manager->read(new SplFileInfo($sourcePath))->orient();

        $webEdge = (int) config('photos.web.max_edge');
        $thumbSize = (int) config('photos.thumb.size');

        $web = (clone $image)
            ->scaleDown(width: $webEdge, height: $webEdge)
            ->encode(new WebpEncoder(quality: (int) config('photos.web.quality')));

        $thumb = (clone $image)
            ->cover($thumbSize, $thumbSize)
            ->encode(new WebpEncoder(quality: (int) config('photos.thumb.quality')));

        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'web' => (string) $web,
            'thumb' => (string) $thumb,
        ];
    }
}
