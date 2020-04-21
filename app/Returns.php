<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Returns extends Model
{
	protected $table = 'returns';
    protected $fillable =[
        "reference_no", "user_id", "customer_id", "warehouse_id", "biller_id", "account_id", "item", "total_qty", "total_discount", "total_tax", "total_price","order_tax_rate", "order_tax", "grand_total", "document", "return_note", "staff_note"
    ];
}
