<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeDetails extends Model
{
    use HasFactory;

    protected $table = 'employee_details';
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'user_id',
        'contact_number',
        'gender',
        'civil_status',
        'birthdate',
        'position',
        'department',
        'pagibig',
        'sss',
        'philhealth',
        'files',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
