<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable =[
        "reference_no", "user_id", "customer_id", "warehouse_id", "biller_id", "item", "total_qty", "total_discount", "total_tax", "total_price", "order_tax_rate", "order_tax", "order_discount","coupon_id", "coupon_discount", "shipping_cost", "grand_total", "sale_status", "payment_status", "paid_amount", "document", "sale_note", "staff_note"
    ];
}
