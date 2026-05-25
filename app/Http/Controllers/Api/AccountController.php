<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AccountService;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;

class AccountController extends Controller
{
    protected $service;

    public function __construct(AccountService $service)
    {
        $this->service = $service;
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employee = $this->service->create($request->validated());

        return new EmployeeResource($employee);
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15); 
        $employees = $this->service->getAll((int)$perPage);

        return EmployeeResource::collection($employees);
    }

    public function show($id)
    {
        $employee = $this->service->getById((int) $id);

        if (!$employee) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee = $this->service->update((int) $id, $request->validated());

        if (!$employee) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new EmployeeResource($employee);
    }

    public function destroy($id)
    {
        $deleted = $this->service->delete((int) $id);

        if (!$deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Account deactivated successfully']);
    }

    public function restore($id)
    {
        $employee = $this->service->restore((int) $id);

        if (!$employee) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return new EmployeeResource($employee);
    }
}