<?php

namespace App\Service;

class FacilityImageResolver
{
    public function __construct(
        private readonly string $projectDir,
    ) {}

    /**
     * Scans public/images/facilities/<slug>/ for level_<N>.png files and returns
     * a [level => url] map. Only levels with an actual file on disk are included.
     *
     * @return array<string, string>
     */
    public function resolve(string $slug): array
    {
        $dir = $this->projectDir . '/public/images/facilities/' . $slug;
        if (!is_dir($dir)) {
            return [];
        }

        $images = [];
        foreach (glob($dir . '/level_*.png') ?: [] as $path) {
            if (preg_match('/level_(\d+)\.png$/', $path, $m)) {
                $images[$m[1]] = '/images/facilities/' . $slug . '/level_' . $m[1] . '.png';
            }
        }
        ksort($images, SORT_NUMERIC);

        return $images;
    }
}
