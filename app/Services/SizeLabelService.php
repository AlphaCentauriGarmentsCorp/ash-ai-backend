<?php

namespace App\Services;

use App\Models\SizeLabel;
use Illuminate\Database\Eloquent\Collection;

class SizeLabelService
{

    public function getAll(): Collection
    {
        return SizeLabel::all();
    }

    public function find(int $id): ?SizeLabel
    {
        return SizeLabel::find($id);
    }

    public function create(array $data): SizeLabel
    {
        return SizeLabel::create($data);
    }

    public function update(array $data, int $id): ?SizeLabel
    {
        $sizeLabel = SizeLabel::find($id);

        if (!$sizeLabel) {
            return null;
        }

        $sizeLabel->update($data);
        return $sizeLabel;
    }

    public function delete(int $id): bool
    {
        $sizeLabel = SizeLabel::find($id);

        if (!$sizeLabel) {
            return false;
        }

        return $sizeLabel->delete();
    }
}
