<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Validator;
use App\Models\OrganizationalLog;
use Illuminate\Foundation\Auth\User as Authenticatable;


class Account extends Authenticatable

    {
        use HasApiTokens, Notifiable, HasFactory;
    
        protected $fillable = [
            'first_name',
            'last_name',
            'middle_name',
            'email',
            'role',
            'password',
            'phone_number',
            'is_archived',
            
            
        ];
        public static function validateAccount($data)
    {
        $users = Account::pluck('first_name')->toArray();
       

        $validator = Validator::make($data, [
            
            'first_name' => ['required', 'string','min:3','max:225'],
            'last_name' => ['required', 'string','min:3','max:225'],
            'middle_name' => ['required', 'string','min:1','max:225'],
            'email' => [ 'email', 'unique:accounts,email'],
            'phone_number' => ['required', 'email', 'unique:accounts,email'],
            'role' => ['required'  ],
            'password',
          
           
        ]);

        return $validator;
    }
}

    
    
    

