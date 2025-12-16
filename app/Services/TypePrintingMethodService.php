<?php

namespace App\Services;

use App\Models\TypePrintingMethod;
use Illuminate\Database\Eloquent\Collection;


class TypePrintingMethodService
{
    public function getAll(): Collection
    {
        return TypePrintingMethod::all();
    }

    public function find(int $id): ?TypePrintingMethod
    {
        return TypePrintingMethod::find($id);
    }

	public function create(array $data): TypePrintingMethod
    {
        return TypePrintingMethod::create($data);
    }

    public function update(
        TypePrintingMethod $typePrintingMethod,
        array $data
    ): TypePrintingMethod {
        $typePrintingMethod->update($data);
        return $typePrintingMethod;
    }

    public function delete(TypePrintingMethod $typePrintingMethod): bool
    {
        return $typePrintingMethod->delete();
    }
}