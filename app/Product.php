<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable =[

        "name", "code", "type", "barcode_symbology", "brand_id", "category_id", "unit_id", "purchase_unit_id", "sale_unit_id", "cost", "price", "qty", "alert_quantity", "promotion", "promotion_price","starting_date", "last_date", "tax_id", "tax_method", "image", "file", "featured", "product_list", "qty_list", "price_list", "product_details", "product_invoice_details", "is_active"
    ];

    public function category()
    {
    	return $this->belongsTo('App\Category');
    }

    public function brand()
    {
    	return $this->belongsTo('App\Brand');
    }
}
