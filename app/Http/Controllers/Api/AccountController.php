<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AccountService;
use App\Http\Requests\Employee\StoreEmployeeRequest;
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
}
