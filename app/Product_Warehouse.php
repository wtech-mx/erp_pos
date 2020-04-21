<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product_Warehouse extends Model
{
	protected $table = 'product_warehouse';
    protected $fillable =[

        "product_code", "warehouse_id", "qty"
    ];
}
