<?php
if ( ! defined('ABSPATH') ) exit;
class Stockland_REST_Wholesale extends Stockland_REST_Base {
    public function __construct(){ add_action('stockland_register_rest_routes',array($this,'routes')); }
    public function routes(){
        register_rest_route(self::NAMESPACE_V1,'/wholesale',array(
            'methods'=>'POST','callback'=>array($this,'create'),'permission_callback'=>function(){return current_user_can('stockland_create_wholesale');}
        ));
    }
    public function create(WP_REST_Request $request){
        $p=$request->get_json_params();$sku=sanitize_text_field($p['sku']??'');$q=(float)($p['quantity']??0);$wh=(int)Stockland_DB::get_location_id('Central Warehouse');$date=sanitize_text_field($p['sale_date']??current_time('Y-m-d'));
        if(!$wh||!$sku||$q<=0||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))return new WP_Error('invalid','Invalid wholesale sale.',array('status'=>400));
        $prod=Stockland_DB::get_product($sku);if(!$prod||($prod['product_type']??'SIMPLE')==='STYLE')return new WP_Error('invalid','Select a stockable variant/SKU.',array('status'=>400));
        try{$price=isset($p['price'])?Stockland_Money::decimal(Stockland_Money::minor($p['price'])):Stockland_Money::decimal(Stockland_Money::minor($prod['wholesale_price']));}catch(Throwable $e){return new WP_Error('invalid_price','Wholesale price must be a valid decimal amount.',array('status'=>400));}if(Stockland_Money::minor($price)<0)return new WP_Error('invalid_price','Wholesale price cannot be negative.',array('status'=>400));
        $lock=Stockland_DB::acquire_day_lock($wh,$date);if(!$lock['ok'])return new WP_Error('busy','Another warehouse operation is being processed for this date. Please retry.',array('status'=>409));
        global $wpdb;if(false===$wpdb->query('START TRANSACTION')){Stockland_DB::release_day_lock($lock);return new WP_Error('transaction_failed','Could not start the wholesale transaction.',array('status'=>500));}
        try{
            if(Stockland_DB::is_day_closed($wh,$date))throw new Exception('This Central Warehouse business day is already closed.');
            $wpdb->get_var($wpdb->prepare("SELECT sku FROM ".Stockland_DB::table('products')." WHERE sku=%s FOR UPDATE",$sku));
            if(Stockland_DB::get_available_stock($wh,$sku)<$q)throw new Exception('Insufficient Central Warehouse stock.');
            $rate=(float)Stockland_DB::get_vat_rate();$gross=Stockland_Money::multiply($q,$price);$basis=Stockland_DB::get_vat_basis();
            if($basis==='INCLUSIVE'){$vat=Stockland_Money::inclusive_tax($gross,$rate);$net=Stockland_Money::subtract($gross,$vat);}else{$vat=Stockland_Money::percentage($gross,$rate);$net=$gross;$gross=Stockland_Money::add($gross,$vat);} $cogs=Stockland_Money::multiply($q,$prod['purchase_cost']);$id=Stockland_Sequence::next('WHO-', $date);
            if(false===$wpdb->insert(Stockland_DB::table('wholesale_sales'),array('sale_id'=>$id,'sale_date'=>$date,'customer'=>sanitize_text_field($p['customer']??''),'sku'=>$sku,'variant_id'=>(int)$prod['variant_id'],'quantity'=>$q,'wholesale_price'=>$price,'sales_value'=>$gross,'payment_status'=>sanitize_text_field($p['payment_status']??'Unpaid'),'vat_amount'=>$vat,'net_sales_excl_vat'=>$net,'cogs'=>$cogs,'gross_profit'=>Stockland_Money::subtract($net,$cogs),'created_by'=>get_current_user_id())))throw new Exception('Could not save wholesale sale.');
            if(false===Stockland_Ledger::record($wh,$sku,'WHOLESALE_SALE',-$q,'wholesale_sale',$id,$date,get_current_user_id(),$id))throw new Exception('Ledger post failed.');
            // Wholesale is an authoritative business order. It must have the same
            // Order -> Inventory -> PLEDGER/GL -> Audit atomic contract as Daily POS.
            // Payment status controls whether the receivable is settled immediately;
            // Partial without a payment amount is retained as an order-state marker and
            // remains an AR invoice until an explicit Finance payment is posted.
            $payment_status=strtoupper(sanitize_text_field($p['payment_status']??'UNPAID'));
            if(!in_array($payment_status,array('PAID','UNPAID','PARTIAL'),true))throw new Exception('Invalid wholesale payment status.');
            $debit_account=$payment_status==='PAID'?'1000':'1100';
            $jid=Stockland_Accounting::post_journal(
                $date,
                'WHOLESALE_SALE',
                $id,
                'Wholesale sale '.$id,
                array(
                    array('account_code'=>$debit_account,'debit'=>$gross,'credit'=>0,'location_id'=>$wh,'memo'=>'Wholesale sale proceeds / receivable'),
                    array('account_code'=>'4100','debit'=>0,'credit'=>$net,'location_id'=>$wh,'memo'=>'Wholesale sales revenue'),
                    array('account_code'=>'2100','debit'=>0,'credit'=>$vat,'location_id'=>$wh,'memo'=>'VAT payable'),
                    array('account_code'=>'5000','debit'=>$cogs,'credit'=>0,'location_id'=>$wh,'memo'=>'Cost of goods sold'),
                    array('account_code'=>'1200','debit'=>0,'credit'=>$cogs,'location_id'=>$wh,'memo'=>'Inventory relief'),
                ),
                get_current_user_id()
            );
            if(!$jid)throw new Exception('Wholesale PLEDGER/GL journal failed. The order was rolled back and no partial stock post was allowed.');
            if(!Stockland_Audit::log(get_current_user_id(),$wh,'WHOLESALE_SALE',$sku,$q,null,$price,null,$id))throw new Exception('Audit event could not be recorded; wholesale sale was not committed.');
            if(false===$wpdb->query('COMMIT'))throw new Exception('Wholesale sale commit failed.');
            Stockland_DB::release_day_lock($lock);return rest_ensure_response(array('sale_id'=>$id,'total'=>$gross,'net'=>$net,'vat'=>$vat));
        }catch(Throwable $e){$wpdb->query('ROLLBACK');Stockland_DB::release_day_lock($lock);return new WP_Error('wholesale_failed',$e->getMessage(),array('status'=>409));}
    }
}
