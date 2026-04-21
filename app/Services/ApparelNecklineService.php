<?php

namespace App\Services;

use App\Models\ApparelNeckline;
use Illuminate\Database\Eloquent\Collection;

class ApparelNecklineService
{
    public function getAll(): Collection
    {
        return ApparelNeckline::all();
    }

    public function find(int $id): ?ApparelNeckline
    {
        return ApparelNeckline::find($id);
    }

    public function create(array $data): ApparelNeckline
    {
        return ApparelNeckline::create($data);
    }

    public function update(array $data, int $id): ?ApparelNeckline
    {
        $apparelNeckline = ApparelNeckline::find($id);

        if (! $apparelNeckline) {
            return null;
        }

        $apparelNeckline->update($data);

        return $apparelNeckline;
    }

    public function delete(int $id): bool
    {
        $apparelNeckline = ApparelNeckline::find($id);

        if (! $apparelNeckline) {
            return false;
        }

        return $apparelNeckline->delete();
    }
}
