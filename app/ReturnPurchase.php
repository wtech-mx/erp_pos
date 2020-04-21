<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ReturnPurchase extends Model
{
    protected $table = 'return_purchases';
    protected $fillable =[
        "reference_no", "user_id", "supplier_id", "warehouse_id", "account_id", "item", "total_qty", "total_discount", "total_tax", "total_cost","order_tax_rate", "order_tax", "grand_total", "document", "return_note", "staff_note"
    ];
}
