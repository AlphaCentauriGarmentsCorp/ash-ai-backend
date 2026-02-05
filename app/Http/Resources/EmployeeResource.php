<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'avatar' => $this->avatar ? asset($this->avatar) : null,
            'domain_role' => $this->domain_role,
            'domain_access' => $this->domain_access,


            'employee_details' => [
                'first_name'      => $this->employeeDetail?->first_name,
                'middle_name'     => $this->employeeDetail?->middle_name,
                'last_name'       => $this->employeeDetail?->last_name,
                'contact_number'  => $this->employeeDetail?->contact_number,
                'gender'          => $this->employeeDetail?->gender,
                'civil_status'    => $this->employeeDetail?->civil_status,
                'birthdate'       => $this->employeeDetail?->birthdate,
                'position'        => $this->employeeDetail?->position,
                'department'      => $this->employeeDetail?->department,
                'pagibig'         => $this->employeeDetail?->pagibig,
                'sss'             => $this->employeeDetail?->sss,
                'philhealth'      => $this->employeeDetail?->philhealth,
                'files'           => $this->employeeDetail?->files ?? [],
            ],


            // Addresses
            'addresses' => $this->addresses ? $this->addresses->map(function ($address) {
                return [
                    'type' => $address->type,
                    'street' => $address->street,
                    'brangay' => $address->brangay,
                    'city' => $address->city,
                    'province' => $address->province,
                    'postal' => $address->postal,
                    'country' => $address->country,
                ];
            }) : [],
            'created_at'         => $this->created_at,
        ];
    }
}
