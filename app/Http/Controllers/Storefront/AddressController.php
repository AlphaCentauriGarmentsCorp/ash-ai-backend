<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\StoreAddressRequest;
use App\Http\Requests\Storefront\UpdateAddressRequest;
use App\Http\Resources\Storefront\AddressResource;
use App\Models\Storefront\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AddressResource::collection($this->ownedAddresses()->get());
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request): Address {
            $data = $request->validated();

            $this->releaseDefaultsClaimedBy($data);

            return $request->user()->addresses()->create($data);
        });

        return response()->json([
            'message' => 'Address saved.',
            'data' => new AddressResource($address),
        ], 201);
    }

    public function update(UpdateAddressRequest $request, Address $address): JsonResponse
    {
        $this->authorizeOwnership($address);

        DB::transaction(function () use ($request, $address): void {
            $data = $request->validated();

            $this->releaseDefaultsClaimedBy($data, exceptId: $address->id);

            $address->update($data);
        });

        return response()->json([
            'message' => 'Address updated.',
            'data' => new AddressResource($address->fresh()),
        ]);
    }

    public function destroy(Address $address): JsonResponse
    {
        $this->authorizeOwnership($address);

        $address->delete();

        return response()->json(['message' => 'Address deleted.']);
    }

    /**
     * Route-model binding resolves by id alone, so without this any signed-in user
     * could read or edit someone else's address by guessing the number. 404 rather
     * than 403: a stranger's address id should not be confirmable.
     */
    private function authorizeOwnership(Address $address): void
    {
        abort_unless($address->user_id === auth()->id(), 404);
    }

    private function ownedAddresses()
    {
        return auth()->user()->addresses()
            ->orderByDesc('is_default_shipping')
            ->orderBy('id');
    }

    /**
     * "Default" must mean exactly one address, so claiming a default flag takes it
     * away from whichever of this user's addresses held it before.
     */
    private function releaseDefaultsClaimedBy(array $data, ?int $exceptId = null): void
    {
        foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
            if (empty($data[$flag])) {
                continue;
            }

            auth()->user()->addresses()
                ->where($flag, true)
                ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
                ->update([$flag => false]);
        }
    }
}
