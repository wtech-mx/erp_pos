<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\ReturnPurchase;
use App\Warehouse;
use App\Supplier;
use App\Tax;
use App\Product;
use App\Product_Warehouse;
use App\Unit;
use App\PurchaseProductReturn;
use App\Account;
use Auth;
use DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\UserNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ReturnPurchaseController extends Controller
{
    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchase-return-index')){
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if(empty($all_permission))
                $all_permission[] = 'dummy text';
            $general_setting = DB::table('general_settings')->latest()->first();
            if(Auth::user()->role_id > 2 && $general_setting->staff_access == 'own')
                $lims_return_all = ReturnPurchase::orderBy('id', 'desc')->where('user_id', Auth::id())->get();
            else
                $lims_return_all = ReturnPurchase::orderBy('id', 'desc')->get();
            return view('return_purchase.index', compact('lims_return_all', 'all_permission'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchase-return-add')){
            $lims_warehouse_list = Warehouse::where('is_active',true)->get();
            $lims_supplier_list = Supplier::where('is_active',true)->get();
            $lims_tax_list = Tax::where('is_active',true)->get();
            $lims_account_list = Account::where('is_active',true)->get();
            return view('return_purchase.create', compact('lims_warehouse_list', 'lims_supplier_list', 'lims_tax_list', 'lims_account_list'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function getProduct($id)
    {
        $lims_product_warehouse_data = DB::table('products')->join('product_warehouse', 'products.id', '=', 'product_warehouse.product_id')->where([
                ['product_warehouse.warehouse_id', $id],
                ['products.is_active', true] ])->get();
        
        $product_code = [];
        $product_name = [];
        $product_qty = [];
        $product_data = [];
        foreach ($lims_product_warehouse_data as $product_warehouse) 
        {
            $product_qty[] = $product_warehouse->qty;
            $lims_product_data = Product::find($product_warehouse->product_id);
            $product_code[] =  $lims_product_data->code;
            $product_name[] = $lims_product_data->name;
            $product_type[] = $lims_product_data->type;
        }

        $product_data[] = $product_code;
        $product_data[] = $product_name;
        $product_data[] = $product_qty;
        $product_data[] = $product_type;
        return $product_data;
    }

    public function limsProductSearch(Request $request)
    {
        $todayDate = date('Y-m-d');
        $product_code = explode(" ", $request['data']);
        $lims_product_data = Product::where('code', $product_code[0])->first();

        $product[] = $lims_product_data->name;
        $product[] = $lims_product_data->code;
        $product[] = $lims_product_data->cost;
        
        if ($lims_product_data->tax_id) {
            $lims_tax_data = Tax::find($lims_product_data->tax_id);
            $product[] = $lims_tax_data->rate;
            $product[] = $lims_tax_data->name;
        } else {
            $product[] = 0;
            $product[] = 'No Tax';
        }
        $product[] = $lims_product_data->tax_method;

        $units = Unit::where("base_unit", $lims_product_data->unit_id)
                    ->orWhere('id', $lims_product_data->unit_id)
                    ->get();

        $unit_name = array();
        $unit_operator = array();
        $unit_operation_value = array();
        foreach ($units as $unit) {
            if ($lims_product_data->purchase_unit_id == $unit->id) {
                array_unshift($unit_name, $unit->unit_name);
                array_unshift($unit_operator, $unit->operator);
                array_unshift($unit_operation_value, $unit->operation_value);
            } else {
                $unit_name[]  = $unit->unit_name;
                $unit_operator[] = $unit->operator;
                $unit_operation_value[] = $unit->operation_value;
            }
        }
        
        $product[] = implode(",", $unit_name) . ',';
        $product[] = implode(",", $unit_operator) . ',';
        $product[] = implode(",", $unit_operation_value) . ',';
        $product[] = $lims_product_data->id;
        return $product;
    }

    public function store(Request $request)
    {
        $data = $request->except('document');
        //return dd($data);
        $data['reference_no'] = 'prr-' . date("Ymd") . '-'. date("his");
        $data['user_id'] = Auth::id();
        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $documentName = $document->getClientOriginalName();
            $document->move('public/return/documents', $documentName);
            $data['document'] = $documentName;
        }

        ReturnPurchase::create($data);
        $lims_return_data = ReturnPurchase::latest()->first();
        if($data['supplier_id']){
            $lims_supplier_data = Supplier::find($data['supplier_id']);
        
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_price'] = $lims_return_data->total_cost;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;
        }

        $product_id = $data['product_id'];
        $qty = $data['qty'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];

        foreach ($product_id as $key => $pro_id) {
            $lims_product_data = Product::find($pro_id);
            if($purchase_unit[$key] != 'n/a'){
                $lims_purchase_unit_data  = Unit::where('unit_name', $purchase_unit[$key])->first();
                $purchase_unit_id = $lims_purchase_unit_data->id;
                if($lims_purchase_unit_data->operator == '*')
                    $quantity = $qty[$key] * $lims_purchase_unit_data->operation_value;
                elseif($lims_purchase_unit_data->operator == '/')
                    $quantity = $qty[$key] / $lims_purchase_unit_data->operation_value;

                $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $pro_id],
                        ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();

                $lims_product_data->qty -=  $quantity;
                $lims_product_warehouse_data->qty -= $quantity;

                $lims_product_data->save();
                $lims_product_warehouse_data->save();
            }

            $mail_data['products'][$key] = $lims_product_data->name;
            if($purchase_unit_id)
                $mail_data['unit'][$key] = $lims_purchase_unit_data->unit_code;
            else
                $mail_data['unit'][$key] = '';
            $mail_data['qty'][$key] = $qty[$key];
            $mail_data['total'][$key] = $total[$key];
            PurchaseProductReturn::insert(
                ['return_id' => $lims_return_data->id, 'product_id' => $pro_id, 'qty' => $qty[$key], 'purchase_unit_id' => $purchase_unit_id, 'net_unit_cost' => $net_unit_cost[$key], 'discount' => $discount[$key], 'tax_rate' => $tax_rate[$key], 'tax' => $tax[$key], 'total' => $total[$key] ]
            );
        }
        $message = 'Return created successfully';
        if($data['supplier_id']){
            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
            }
            catch(\Exception $e){
                $message = 'Return created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        return redirect('return-purchase')->with('message', $message);
    }

    public function productReturnData($id)
    {
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();
        foreach ($lims_product_return_data as $key => $product_return_data) {
            $product = Product::find($product_return_data->product_id);
            if($product_return_data->purchase_unit_id != 0){
                $unit_data = Unit::find($product_return_data->purchase_unit_id);
                $unit = $unit_data->unit_code;
            }
            else
                $unit = '';

            $product_return[0][$key] = $product->name . '-' . $product->code;
            $product_return[1][$key] = $product_return_data->qty;
            $product_return[2][$key] = $unit;
            $product_return[3][$key] = $product_return_data->tax;
            $product_return[4][$key] = $product_return_data->tax_rate;
            $product_return[5][$key] = $product_return_data->discount;
            $product_return[6][$key] = $product_return_data->total;
        }
        return $product_return;
    }

    public function sendMail(Request $request)
    {
        $data = $request->all();
        $lims_return_data = ReturnPurchase::find($data['return_id']);
        //return $lims_return_data;
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $data['return_id'])->get();
        if($lims_return_data->supplier_id){
            $lims_supplier_data = Supplier::find($lims_return_data->supplier_id);
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_cost'] = $lims_return_data->total_cost;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;

            foreach ($lims_product_return_data as $key => $product_return_data) {
                $lims_product_data = Product::find($product_return_data->product_id);
                $mail_data['products'][$key] = $lims_product_data->name;
                if($product_return_data->purchase_unit_id){
                    $lims_unit_data = Unit::find($product_return_data->purchase_unit_id);
                    $mail_data['unit'][$key] = $lims_unit_data->unit_code;
                }
                else
                    $mail_data['unit'][$key] = '';

                $mail_data['qty'][$key] = $product_return_data->qty;
                $mail_data['total'][$key] = $product_return_data->qty;
            }

            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
                $message = 'Mail sent successfully';
            }
            catch(\Exception $e){
                $message = 'Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        else
            $message = "This return doesn't belong to any supplier";
        
        return redirect()->back()->with('message', $message);
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if($role->hasPermissionTo('purchase-return-edit')){
            $lims_supplier_list = Supplier::where('is_active',true)->get();
            $lims_warehouse_list = Warehouse::where('is_active',true)->get();
            $lims_account_list = Account::where('is_active',true)->get();
            $lims_tax_list = Tax::where('is_active',true)->get();
            $lims_return_data = ReturnPurchase::find($id);
            $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();
            return view('return_purchase.edit',compact('lims_supplier_list', 'lims_warehouse_list', 'lims_tax_list', 'lims_account_list', 'lims_return_data','lims_product_return_data'));
        }
        else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');;
    }

    public function update(Request $request, $id)
    {
        $data = $request->except('document');
        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());
            
            $documentName = $document->getClientOriginalName();
            $document->move('public/return/documents', $documentName);
            $data['document'] = $documentName;
        }

        $lims_return_data = ReturnPurchase::find($id);
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

        $product_id = $data['product_id'];
        $qty = $data['qty'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];

        foreach ($lims_product_return_data as $key => $product_return_data) {
            $old_product_id[] = $product_return_data->product_id;
            $lims_product_data = Product::find($product_return_data->product_id);
            if($product_return_data->purchase_unit_id != 0){
                $lims_purchase_unit_data = Unit::find($product_return_data->purchase_unit_id);
                if ($lims_purchase_unit_data->operator == '*')
                    $quantity = $product_return_data->qty * $lims_purchase_unit_data->operation_value;
                elseif($lims_purchase_unit_data->operator == '/')
                    $quantity = $product_return_data->qty / $lims_purchase_unit_data->operation_value;

                $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $product_return_data->product_id],
                        ['warehouse_id', $lims_return_data->warehouse_id],
                        ])->first();

                $lims_product_data->qty += $quantity;
                $lims_product_warehouse_data->qty += $quantity;
                $lims_product_data->save();
                $lims_product_warehouse_data->save();
            }
            if( !(in_array($old_product_id[$key], $product_id)) )
                $product_return_data->delete();
        }
        foreach ($product_id as $key => $pro_id) {
            $lims_product_data = Product::find($pro_id);
            if($purchase_unit[$key] != 'n/a'){
                $lims_purchase_unit_data = Unit::where('unit_name', $purchase_unit[$key])->first();
                $purchase_unit_id = $lims_purchase_unit_data->id;
                if ($lims_purchase_unit_data->operator == '*')
                    $quantity = $qty[$key] * $lims_purchase_unit_data->operation_value;
                elseif($lims_purchase_unit_data->operator == '/')
                    $quantity = $qty[$key] / $lims_purchase_unit_data->operation_value;
                $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $pro_id],
                        ['warehouse_id', $data['warehouse_id'] ],
                        ])->first();

                $lims_product_data->qty -=  $quantity;
                $lims_product_warehouse_data->qty -= $quantity;

                $lims_product_data->save();
                $lims_product_warehouse_data->save();
            }

            $mail_data['products'][$key] = $lims_product_data->name;
            if($purchase_unit_id)
                $mail_data['unit'][$key] = $lims_purchase_unit_data->unit_code;
            else
                $mail_data['unit'][$key] = '';
            $mail_data['qty'][$key] = $qty[$key];
            $mail_data['total'][$key] = $total[$key];

            $product_return['return_id'] = $id ;
            $product_return['product_id'] = $pro_id;
            $product_return['qty'] = $qty[$key];
            $product_return['purchase_unit_id'] = $purchase_unit_id;
            $product_return['net_unit_cost'] = $net_unit_cost[$key];
            $product_return['discount'] = $discount[$key];
            $product_return['tax_rate'] = $tax_rate[$key];
            $product_return['tax'] = $tax[$key];
            $product_return['total'] = $total[$key];

            if((in_array($pro_id, $old_product_id))){
                PurchaseProductReturn::where([
                    ['return_id', $id],
                    ['product_id', $pro_id]
                    ])->update($product_return);
            }
            else
                PurchaseProductReturn::create($product_return);
        }
        $lims_return_data->update($data);
        $message = 'Return updated successfully';
        if($data['supplier_id']) {
            $lims_supplier_data = Supplier::find($data['supplier_id']);
            //collecting male data
            $mail_data['email'] = $lims_supplier_data->email;
            $mail_data['reference_no'] = $lims_return_data->reference_no;
            $mail_data['total_qty'] = $lims_return_data->total_qty;
            $mail_data['total_price'] = $lims_return_data->total_cost;
            $mail_data['order_tax'] = $lims_return_data->order_tax;
            $mail_data['order_tax_rate'] = $lims_return_data->order_tax_rate;
            $mail_data['grand_total'] = $lims_return_data->grand_total;
            
            try{
                Mail::send( 'mail.return_details', $mail_data, function( $message ) use ($mail_data)
                {
                    $message->to( $mail_data['email'] )->subject( 'Return Details' );
                });
            }
            catch(\Exception $e){
                $message = 'Return updated successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }
        return redirect('return-purchase')->with('message', $message);
    }

    public function deleteBySelection(Request $request)
    {
        $return_id = $request['returnIdArray'];
        foreach ($return_id as $id) {
            $lims_return_data = ReturnPurchase::find($id);
            $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

            foreach ($lims_product_return_data as $key => $product_return_data) {
                $lims_product_data = Product::find($product_return_data->product_id);

                if($product_return_data->purchase_unit_id != 0){
                    $lims_purchase_unit_data = Unit::find($product_return_data->purchase_unit_id);

                    if ($lims_purchase_unit_data->operator == '*')
                        $quantity = $product_return_data->qty * $lims_purchase_unit_data->operation_value;
                    elseif($lims_purchase_unit_data->operator == '/')
                        $quantity = $product_return_data->qty / $lims_purchase_unit_data->operation_value;

                    $lims_product_warehouse_data = Product_Warehouse::where([
                            ['product_id', $product_return_data->product_id],
                            ['warehouse_id', $lims_return_data->warehouse_id],
                            ])->first();

                    $lims_product_data->qty += $quantity;
                    $lims_product_warehouse_data->qty += $quantity;
                    $lims_product_data->save();
                    $lims_product_warehouse_data->save();
                    $product_return_data->delete();
                }
            }
            $lims_return_data->delete();
        }
        return 'Return deleted successfully!';
    }

    public function destroy($id)
    {
        $lims_return_data = ReturnPurchase::find($id);
        $lims_product_return_data = PurchaseProductReturn::where('return_id', $id)->get();

        foreach ($lims_product_return_data as $key => $product_return_data) {
            $lims_product_data = Product::find($product_return_data->product_id);

            if($product_return_data->purchase_unit_id != 0){
                $lims_purchase_unit_data = Unit::find($product_return_data->purchase_unit_id);

                if ($lims_purchase_unit_data->operator == '*')
                    $quantity = $product_return_data->qty * $lims_purchase_unit_data->operation_value;
                elseif($lims_purchase_unit_data->operator == '/')
                    $quantity = $product_return_data->qty / $lims_purchase_unit_data->operation_value;

                $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $product_return_data->product_id],
                        ['warehouse_id', $lims_return_data->warehouse_id],
                        ])->first();

                $lims_product_data->qty += $quantity;
                $lims_product_warehouse_data->qty += $quantity;
                $lims_product_data->save();
                $lims_product_warehouse_data->save();
                $product_return_data->delete();
            }
        }
        $lims_return_data->delete();
        return redirect('return-purchase')->with('not_permitted', 'Data deleted successfully');;
    }
}
