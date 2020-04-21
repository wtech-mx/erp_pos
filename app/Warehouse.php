<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable =[

        "name", "phone", "email", "address", "is_active"
    ];
}
