<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roles = $this->normalizedRoles();
        $permissions = $this->normalizedPermissions();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'roles' => $roles,
            'role_names' => collect($roles)->pluck('name')->values()->all(),
            'permissions' => $permissions,
            'all_permissions' => $permissions,
            'permission_names' => collect($permissions)->mapWithKeys(fn (string $permission) => [$permission => true])->all(),
            'domain_role' => $this->normalizedList($this->domain_role),
            'domain_access' => $this->normalizedList($this->domain_access),
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),
        ];
    }

    protected function normalizedRoles(): array
    {
        return $this->roles()
            ->with('permissions:id,name')
            ->get()
            ->map(function ($role): array {
                return [
                    'id' => $role->id,
                    'name' => $this->normalizeValue($role->name),
                    'guard_name' => $this->normalizeValue($role->guard_name),
                    'permissions' => $this->normalizedList($role->permissions->pluck('name')->all()),
                ];
            })
            ->values()
            ->all();
    }

    protected function normalizedPermissions(): array
    {
        return $this->normalizedList($this->getAllPermissions()->pluck('name')->all());
    }

    protected function normalizedList(mixed $values): array
    {
        return collect($this->toArrayOfValues($values))
            ->map(fn ($value) => $this->normalizeValue($value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizeValue(mixed $value): string
    {
        return Str::of((string) $value)->trim()->lower()->toString();
    }

    protected function toArrayOfValues(mixed $values): array
    {
        if ($values instanceof Collection) {
            return $values->all();
        }

        if (is_array($values)) {
            return $values;
        }

        if ($values === null) {
            return [];
        }

        return [$values];
    }
}
