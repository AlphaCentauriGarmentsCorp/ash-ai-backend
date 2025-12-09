<?php

namespace App\Services;

use App\Models\Client;

class ClientService
{
    public function list()
    {
        return Client::all();
    }

    public function create(array $data)
    {
        $client = Client::create([
            'user_id'           => $data['user_id'],
            'company_name'      => $data['company_name'],
            'client_name'       => $data['client_name'],
            'email'             => $data['email'],
            'contact'           => $data['contact'],
            'street_address'    => $data['street_address'],
            'city'              => $data['city'],
            'province'          => $data['province'],
            'postal'            => $data['postal'],
            'country'           => $data['country'],
            'status'            => $data['status'],
       ]);

        return $client;

    }

    public function update(Client $Client, array $data)
    {
        $Client->update($data);
        return $Client;
    }

    public function delete(Client $method)
    {
        return $method->delete();
    }
}