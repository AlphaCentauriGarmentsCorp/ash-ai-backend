<?php

namespace App\Services;

use App\Models\PatternType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class PatternTypeService
{
    private const DISK      = 'public';
    private const IMAGE_DIR = 'pattern_types';

    public function getAll(): Collection
    {
        return PatternType::all();
    }

    public function find(int $id): ?PatternType
    {
        return PatternType::find($id);
    }

    public function create(array $data): PatternType
    {
        $data['images'] = $this->uploadImages($data['images'] ?? []);

        return PatternType::create($data);
    }

    public function update(array $data, int $id): ?PatternType
    {
        $patternType = PatternType::find($id);

        if (!$patternType) {
            return null;
        }

        // Keep existing paths and append newly uploaded ones
        $existing = $patternType->images ?? [];
        $new      = $this->uploadImages($data['images'] ?? []);

        $data['images'] = array_merge($existing, $new);

        $patternType->update($data);
        return $patternType;
    }

    public function delete(int $id): bool
    {
        $patternType = PatternType::find($id);

        if (!$patternType) {
            return false;
        }

        foreach ($patternType->images ?? [] as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return $patternType->delete();
    }

    /**
     * Upload an array of UploadedFile instances and return their stored paths.
     *
     * @param  \Illuminate\Http\UploadedFile[]  $files
     * @return string[]
     */
    private function uploadImages(array $files): array
    {
        return array_map(
            fn($file) => $file->store(self::IMAGE_DIR, self::DISK),
            $files
        );
    }
}