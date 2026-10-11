<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Daily inventory control report.
 *
 * Reads the authoritative ledger for dated movement/opening/closing figures,
 * and uses the fast balance/reservation read models for current availability.
 * The report never writes inventory.
 * 16.1.39 reconciliation: hardened report boundary + batched ledger reads + ledger-derived current warehouse availability.
 */
class Stockland_REST_Inventory_Report_V16 {
    const NS = 'stockland/v16';

    public function __construct(){
        add_action('stockland_register_rest_routes',array($this,'routes'));
    }

    public function permission(){
        return Stockland_Authority::assert_profile();
    }

    public function routes(){
        register_rest_route(self::NS,'/reports/daily-inventory',array(
            'methods'=>'GET',
            'callback'=>array($this,'daily_inventory'),
            'permission_callback'=>array($this,'permission')
        ));
    }

    private function scope_location($requested){
        $p=Stockland_Authority::profile();
        if(!$p){
            return new WP_Error('no_stockland_profile','This account has no active Stockland profile.',array('status'=>403));
        }
        if(Stockland_Authority::company_scope()){
            $id=(int)$requested;
            return $id>0?$id:0;
        }
        $allowed=array_values(array_unique(array_map('intval',Stockland_Authority::location_ids())));
        if(empty($allowed))return new WP_Error('no_branch_scope','This user has no authorized location scope.',array('status'=>403));
        $id=(int)$requested;
        if($id>0){if(!in_array($id,$allowed,true))return new WP_Error('branch_forbidden','Requested location is outside your authorized operational scope.',array('status'=>403));return $id;}
        return (int)$allowed[0];
    }

    private function grouped_reservations_for_locations($location_ids){
        global $wpdb;
        if(empty($location_ids))return array();
        $t=Stockland_DB::table('reservations');
        $ph=implode(',',array_fill(0,count($location_ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT location_id,COALESCE(variant_id,0) variant_id,sku,COALESCE(SUM(quantity_reserved),0) reserved_qty
             FROM {$t}
             WHERE location_id IN({$ph}) AND status='Active'
             GROUP BY location_id,COALESCE(variant_id,0),sku",
            $location_ids
        ),ARRAY_A);
        $map=array();
        foreach($rows as $row){
            $loc=(int)$row['location_id'];$qty=(float)$row['reserved_qty'];$vid=(int)$row['variant_id'];
            $map[$loc.'|v:'.$vid]=$qty;
            if((string)$row['sku']!=='')$map[$loc.'|s:'.(string)$row['sku']]=$qty;
        }
        return $map;
    }

    private function current_warehouse_available($warehouse_id){
        global $wpdb;
        $bal=Stockland_DB::table('balances');
        $res=Stockland_DB::table('reservations');
        $prod=Stockland_DB::table('products');

        // IMPORTANT shared-host correction: never rebuild the warehouse balance by
        // aggregating the entire immutable ledger inside a daily report request.
        // The balances table is the authoritative derived read model and exists
        // specifically to keep operational reads bounded on InfinityFree/MariaDB.
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT b.variant_id,b.sku,
                    COALESCE(b.physical_qty,0)-COALESCE(r.reserved_qty,0) available_qty
             FROM {$bal} b
             INNER JOIN {$prod} p ON p.variant_id=b.variant_id
             LEFT JOIN (
                 SELECT COALESCE(variant_id,0) variant_id,sku,SUM(quantity_reserved) reserved_qty
                 FROM {$res}
                 WHERE location_id=%d AND status='Active'
                 GROUP BY COALESCE(variant_id,0),sku
             ) r ON (r.variant_id=b.variant_id OR (r.variant_id=0 AND r.sku=b.sku))
             WHERE b.location_id=%d AND p.is_active=1 AND p.product_type<>'STYLE'",
            $warehouse_id,$warehouse_id
        ),ARRAY_A);
        if($wpdb->last_error){
            throw new RuntimeException('Warehouse availability read failed: '.$wpdb->last_error);
        }
        $map=array();
        foreach((array)$rows as $row){
            $available=(float)$row['available_qty'];
            $map['variant:'.(int)$row['variant_id']]=$available;
            $map['sku:'.(string)$row['sku']]=$available;
        }
        return $map;
    }

    private function product_rows(){
        global $wpdb;
        $p=Stockland_DB::table('products');
        $rows=$wpdb->get_results(
            "SELECT variant_id,sku,product_name,unit,purchase_cost,retail_price,branch_reorder_level,central_reorder_level
             FROM {$p} WHERE is_active=1 AND product_type<>'STYLE' ORDER BY product_name,sku",
            ARRAY_A
        );
        if($wpdb->last_error){
            error_log('Stockland Daily Inventory product query: '.$wpdb->last_error);
            return array();
        }
        return $rows;
    }

    public function daily_inventory(WP_REST_Request $r){
        Stockland_Runtime_Diagnostics::stage('daily_inventory_entry');
        /*
         * Log an exact fatal boundary if PHP terminates during this report.
         * This does not alter business data and is intentionally read-only.
         */
        register_shutdown_function(function(){
            $last=error_get_last();
            if(is_array($last) && in_array((int)($last['type']??0),array(E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR,E_PARSE),true)){
                error_log('Stockland Daily Inventory FATAL: '.(string)($last['message']??'unknown').' @ '.(string)($last['file']??'unknown').':'.(string)($last['line']??'0'));
            }
        });
        try { return $this->daily_inventory_impl($r); }
        catch (Throwable $e) {
            $ref='INV-'.gmdate('YmdHis').'-'.substr(hash('sha256',wp_json_encode(array(get_current_user_id(),$r->get_params(),$e->getMessage()))),0,8);
            error_log('Stockland Daily Inventory ['.$ref.'] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            return new WP_Error('daily_inventory_runtime_failed','Daily Inventory could not be loaded safely. Reference '.$ref.'. Please open System Health / server error logs before retrying.',array('status'=>500,'reference'=>$ref));
        }
    }

    private function daily_inventory_impl(WP_REST_Request $r){
        global $wpdb;
        $date=sanitize_text_field($r->get_param('business_date')?:current_time('Y-m-d'));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
            return new WP_Error('invalid_date','business_date must be YYYY-MM-DD.',array('status'=>400));
        }
        $location=$this->scope_location($r->get_param('location_id'));
        if(is_wp_error($location))return $location;

        $locations_t=Stockland_DB::table('locations');
        $tx_t=Stockland_DB::table('transactions');
        $sales_t=Stockland_DB::table('sales');
        $bal_t=Stockland_DB::table('balances');
        $requested_limit=(int)$r->get_param('detail_limit');
        $detail_limit=$requested_limit>0?min(1000,max(100,$requested_limit)):500;

        /*
         * InfinityFree/MariaDB safety gate.
         * Daily Inventory is a read-only report, so a schema mismatch must
         * become a controlled REST error instead of a PHP/SQL fatal-looking
         * response.  The ledger/balance model is intentionally checked here
         * before any report query is assembled.
         */
        $required_columns=array(
            'locations'=>array('id','location_name','location_type','is_active'),
            'transactions'=>array('location_id','sku','variant_id','movement_type','quantity_signed','transaction_date'),
            'sales'=>array('branch_location_id','sku','variant_id','sale_date','sales_total','net_sales_excl_vat','vat_amount','cogs','gross_profit'),
            'balances'=>array('location_id','sku','variant_id','physical_qty'),
            'products'=>array('variant_id','sku','product_name','unit','purchase_cost','retail_price','branch_reorder_level','central_reorder_level','is_active','product_type'),
            'reservations'=>array('location_id','variant_id','sku','quantity_reserved','status')
        );
        foreach($required_columns as $logical=>$columns){
            $table=Stockland_DB::table($logical);
            if(!Stockland_DB::table_exists($table)){
                error_log('Stockland Daily Inventory schema missing table: '.$table);
                return new WP_Error('daily_inventory_schema_missing','Daily Inventory is temporarily unavailable because the ERP database schema is incomplete. Open System Health and repair the missing schema before retrying.',array('status'=>500,'table'=>$table));
            }
            $present=array_map('strval',(array)$wpdb->get_col('SHOW COLUMNS FROM '.$table,0));
            if($wpdb->last_error){
                error_log('Stockland Daily Inventory schema inspection failed for '.$table.': '.$wpdb->last_error);
                return new WP_Error('daily_inventory_schema_check_failed','Daily Inventory could not verify its database schema safely. Open System Health before retrying.',array('status'=>500));
            }
            $missing=array_values(array_diff($columns,$present));
            if(!empty($missing)){
                error_log('Stockland Daily Inventory missing columns in '.$table.': '.implode(',',$missing));
                return new WP_Error('daily_inventory_schema_mismatch','Daily Inventory cannot run against the current database structure. Open System Health and repair the reported schema mismatch.',array('status'=>500,'table'=>$table,'missing_columns'=>$missing));
            }
        }

        Stockland_Runtime_Diagnostics::stage('daily_inventory_locations');
        if(!$location){
            $locs=$wpdb->get_results("SELECT id,location_name,location_type FROM {$locations_t} WHERE is_active=1 AND location_type IN('Warehouse','Branch') ORDER BY (location_type='Warehouse') DESC,location_name",ARRAY_A);
        }else{
            $locs=$wpdb->get_results($wpdb->prepare("SELECT id,location_name,location_type FROM {$locations_t} WHERE id=%d AND is_active=1 LIMIT 1",$location),ARRAY_A);
        }
        if(empty($locs)){
            return rest_ensure_response(array('business_date'=>$date,'scope_location_id'=>$location,'warehouse_availability_basis'=>'ledger_transactions_plus_active_reservations','finance_data_quality'=>array('cost_missing_sale_lines'=>0,'master_price_missing_sale_lines'=>0),'rows'=>array(),'summary'=>array('opening_units'=>0,'receipts'=>0,'transfer_in'=>0,'transfer_out'=>0,'sales'=>0,'returns_in'=>0,'losses'=>0,'adjustments'=>0,'closing_units'=>0,'reserved_units'=>0,'available_units'=>0,'reorder_alerts'=>0,'warehouse_fulfillable'=>0,'warehouse_shortfall'=>0,'sales_gross'=>0,'sales_net'=>0,'sales_vat'=>0,'sales_cogs'=>0,'sales_gross_profit'=>0,'sales_lines'=>0,'sales_cost_missing_lines'=>0,'sales_master_price_missing_lines'=>0)));
        }

        $loc_ids=array_values(array_unique(array_map('intval',array_column($locs,'id'))));
        $loc_ph=implode(',',array_fill(0,count($loc_ids),'%d'));
        Stockland_Runtime_Diagnostics::stage('daily_inventory_products');
        $products=$this->product_rows();
        if(count($products)>2000) return new WP_Error('daily_inventory_catalog_too_large','Daily Inventory stopped safely because the active Product Master contains more than 2,000 sellable variants. Narrow the report scope or archive inactive catalog rows before retrying.',array('status'=>503,'product_count'=>count($products)));

        /*
         * Robust batched ledger reads.
         *
         * Keep variant-linked and legacy SKU-only movements in two simple queries.
         * The previous UNION/GROUP wrapper made the live Daily Inventory request
         * harder for constrained MariaDB hosts to optimize and harder to diagnose.
         * Both queries use the existing location/variant/date and SKU/date indexes.
         */
        $products_t=Stockland_DB::table('products');
        $movement=array();

        $movement_select="
            SELECT t.location_id,t.variant_id,p.sku,
                SUM(CASE WHEN t.transaction_date<%s THEN t.quantity_signed ELSE 0 END) opening_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='RECEIPT' THEN t.quantity_signed ELSE 0 END) receipts_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='TRANSFER_IN' THEN t.quantity_signed ELSE 0 END) transfer_in_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='TRANSFER_OUT' THEN -t.quantity_signed ELSE 0 END) transfer_out_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type IN('SALE','WHOLESALE_SALE') THEN -t.quantity_signed ELSE 0 END) sales_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='RETURN_IN' THEN t.quantity_signed ELSE 0 END) returns_in_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type IN('DAMAGE_LOSS','LOSS') THEN -t.quantity_signed ELSE 0 END) loss_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='ADJUSTMENT' THEN t.quantity_signed ELSE 0 END) adjustment_qty
            FROM {$tx_t} t
            INNER JOIN {$products_t} p ON p.variant_id=t.variant_id
            WHERE t.location_id IN({$loc_ph})
              AND t.variant_id IS NOT NULL
              AND t.transaction_date<=%s
              AND p.is_active=1
              AND p.product_type<>'STYLE'
            GROUP BY t.location_id,t.variant_id,p.sku";
        Stockland_Runtime_Diagnostics::stage('daily_inventory_ledger');
        $movement_rows=$wpdb->get_results(
            $wpdb->prepare($movement_select,array_merge(array($date,$date,$date,$date,$date,$date,$date,$date),$loc_ids,array($date))),
            ARRAY_A
        );
        if($wpdb->last_error){
            error_log('Stockland Daily Inventory ledger query: '.$wpdb->last_error);
            return new WP_Error('daily_inventory_ledger_query_failed','Daily Inventory ledger data could not be loaded safely.',array('status'=>500));
        }
        foreach($movement_rows as $row){
            $key=(int)$row['location_id'].'|v:'.(int)$row['variant_id'];
            $movement[$key]=array(
                'opening_qty'=>(float)$row['opening_qty'],
                'receipts_qty'=>(float)$row['receipts_qty'],
                'transfer_in_qty'=>(float)$row['transfer_in_qty'],
                'transfer_out_qty'=>(float)$row['transfer_out_qty'],
                'sales_qty'=>(float)$row['sales_qty'],
                'returns_in_qty'=>(float)$row['returns_in_qty'],
                'loss_qty'=>(float)$row['loss_qty'],
                'adjustment_qty'=>(float)$row['adjustment_qty'],
            );
        }

        $legacy_select="
            SELECT t.location_id,p.variant_id,p.sku,
                SUM(CASE WHEN t.transaction_date<%s THEN t.quantity_signed ELSE 0 END) opening_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='RECEIPT' THEN t.quantity_signed ELSE 0 END) receipts_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='TRANSFER_IN' THEN t.quantity_signed ELSE 0 END) transfer_in_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='TRANSFER_OUT' THEN -t.quantity_signed ELSE 0 END) transfer_out_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type IN('SALE','WHOLESALE_SALE') THEN -t.quantity_signed ELSE 0 END) sales_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='RETURN_IN' THEN t.quantity_signed ELSE 0 END) returns_in_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type IN('DAMAGE_LOSS','LOSS') THEN -t.quantity_signed ELSE 0 END) loss_qty,
                SUM(CASE WHEN t.transaction_date=%s AND t.movement_type='ADJUSTMENT' THEN t.quantity_signed ELSE 0 END) adjustment_qty
            FROM {$tx_t} t
            INNER JOIN {$products_t} p ON p.sku=t.sku
            WHERE t.location_id IN({$loc_ph})
              AND t.variant_id IS NULL
              AND t.transaction_date<=%s
              AND p.is_active=1
              AND p.product_type<>'STYLE'
            GROUP BY t.location_id,p.variant_id,p.sku";
        Stockland_Runtime_Diagnostics::stage('daily_inventory_legacy_ledger');
        $legacy_rows=$wpdb->get_results(
            $wpdb->prepare($legacy_select,array_merge(array($date,$date,$date,$date,$date,$date,$date,$date),$loc_ids,array($date))),
            ARRAY_A
        );
        if($wpdb->last_error){
            error_log('Stockland Daily Inventory legacy query: '.$wpdb->last_error);
            return new WP_Error('daily_inventory_legacy_query_failed','Legacy Daily Inventory movement data could not be loaded safely.',array('status'=>500));
        }
        foreach($legacy_rows as $row){
            $key=(int)$row['location_id'].'|v:'.(int)$row['variant_id'];
            if(!isset($movement[$key])){
                $movement[$key]=array(
                    'opening_qty'=>0.0,'receipts_qty'=>0.0,'transfer_in_qty'=>0.0,'transfer_out_qty'=>0.0,
                    'sales_qty'=>0.0,'returns_in_qty'=>0.0,'loss_qty'=>0.0,'adjustment_qty'=>0.0,
                );
            }
            foreach(array('opening_qty','receipts_qty','transfer_in_qty','transfer_out_qty','sales_qty','returns_in_qty','loss_qty','adjustment_qty') as $field){
                $movement[$key][$field]+=(float)$row[$field];
            }
        }

        Stockland_Runtime_Diagnostics::stage('daily_inventory_reservations');
        $reserved_map=$this->grouped_reservations_for_locations($loc_ids);

        Stockland_Runtime_Diagnostics::stage('daily_inventory_balances');
        $balance_rows=$wpdb->get_results($wpdb->prepare("SELECT location_id,variant_id,sku,physical_qty FROM {$bal_t} WHERE location_id IN({$loc_ph})",$loc_ids),ARRAY_A);
        if($wpdb->last_error){error_log('Stockland Daily Inventory balance query: '.$wpdb->last_error);return new WP_Error('daily_inventory_balance_query_failed','Current inventory balance could not be loaded safely.',array('status'=>500));}
        $balance_map=array();
        foreach($balance_rows as $row){$balance_map[(int)$row['location_id'].'|v:'.(int)$row['variant_id']]=(float)$row['physical_qty'];}

        Stockland_Runtime_Diagnostics::stage('daily_inventory_sales');
        $sales_rows=$wpdb->get_results($wpdb->prepare("SELECT branch_location_id,variant_id,sku,COALESCE(SUM(sales_total),0) gross_sales,COALESCE(SUM(net_sales_excl_vat),0) net_sales,COALESCE(SUM(vat_amount),0) vat_total,COALESCE(SUM(cogs),0) cogs,COALESCE(SUM(gross_profit),0) gross_profit,COUNT(*) sale_lines FROM {$sales_t} WHERE branch_location_id IN({$loc_ph}) AND sale_date=%s GROUP BY branch_location_id,variant_id,sku",array_merge($loc_ids,array($date))),ARRAY_A);
        if($wpdb->last_error){error_log('Stockland Daily Inventory sales query: '.$wpdb->last_error);return new WP_Error('daily_inventory_sales_query_failed','Daily sales summary could not be loaded safely.',array('status'=>500));}
        $financial=array();
        foreach($sales_rows as $row){$key=(int)$row['branch_location_id'].'|v:'.(int)($row['variant_id']??0);$financial[$key]=$row;if(!empty($row['sku']))$financial[(int)$row['branch_location_id'].'|s:'.$row['sku']]=$row;}

        Stockland_Runtime_Diagnostics::stage('daily_inventory_warehouse');
        $warehouse_id=(int)Stockland_DB::get_location_id('Central Warehouse');
        $warehouse_available=$this->current_warehouse_available($warehouse_id);
        $summary=array('opening_units'=>0,'receipts'=>0,'transfer_in'=>0,'transfer_out'=>0,'sales'=>0,'returns_in'=>0,'losses'=>0,'adjustments'=>0,'closing_units'=>0,'reserved_units'=>0,'available_units'=>0,'reorder_alerts'=>0,'warehouse_fulfillable'=>0,'warehouse_shortfall'=>0,'sales_gross'=>0,'sales_net'=>0,'sales_vat'=>0,'sales_cogs'=>0,'sales_gross_profit'=>0,'sales_lines'=>0,'sales_cost_missing_lines'=>0,'sales_master_price_missing_lines'=>0);
        $out=array();
        $matching_rows=0;

        Stockland_Runtime_Diagnostics::stage('daily_inventory_aggregate');
        foreach($locs as $loc){
            $loc_id=(int)$loc['id'];
            foreach($products as $x){
                $vid=(int)$x['variant_id'];$sku=(string)$x['sku'];
                $m=$movement[$loc_id.'|v:'.$vid]??array();
                $bkey=$loc_id.'|v:'.$vid;
                $f=$financial[$loc_id.'|v:'.$vid]??($financial[$loc_id.'|s:'.$sku]??null);
                $has_activity=isset($movement[$bkey])||isset($balance_map[$bkey])||isset($reserved_map[$loc_id.'|v:'.$vid])||$f!==null;
                $reorder_raw=$loc['location_type']==='Warehouse'?$x['central_reorder_level']:$x['branch_reorder_level'];
                if(!$has_activity && $reorder_raw===null)continue;
                $matching_rows++;

                $opening=(float)($m['opening_qty']??0);$receipts=(float)($m['receipts_qty']??0);$transfer_in=(float)($m['transfer_in_qty']??0);$transfer_out=(float)($m['transfer_out_qty']??0);$sales=(float)($m['sales_qty']??0);$returns_in=(float)($m['returns_in_qty']??0);$losses=(float)($m['loss_qty']??0);$adjustments=(float)($m['adjustment_qty']??0);
                $closing=$opening+$receipts+$transfer_in-$transfer_out-$sales+$returns_in-$losses+$adjustments;
                if(!$has_activity && isset($balance_map[$bkey]))$closing=(float)$balance_map[$bkey];
                $reserved=(float)($reserved_map[$loc_id.'|v:'.$vid]??($reserved_map[$loc_id.'|s:'.$sku]??0));
                $available=$closing-$reserved;
                $reorder_level=$reorder_raw===null?null:(float)$reorder_raw;$reorder_qty=$reorder_level===null?0:max(0,$reorder_level-$closing);
                $warehouse_avail=0;$warehouse_fillable=0;$warehouse_shortfall=0;
                if($loc_id!==$warehouse_id){$warehouse_avail=max(0,(float)($warehouse_available['variant:'.$vid]??($warehouse_available['sku:'.$sku]??0)));$warehouse_fillable=min($reorder_qty,$warehouse_avail);$warehouse_shortfall=max(0,$reorder_qty-$warehouse_avail);}else{$warehouse_avail=max(0,$available);}
                $status=$reorder_level===null?'NO_LEVEL':($reorder_qty>0?($warehouse_shortfall>0?'SHORTFALL':'REORDER'):'OK');

                $gross=(float)($f['gross_sales']??0);$net=(float)($f['net_sales']??0);$vat=(float)($f['vat_total']??0);$cogs=(float)($f['cogs']??0);$gp=(float)($f['gross_profit']??0);$sale_lines=(int)($f['sale_lines']??0);
                $summary['opening_units']+=$opening;$summary['receipts']+=$receipts;$summary['transfer_in']+=$transfer_in;$summary['transfer_out']+=$transfer_out;$summary['sales']+=$sales;$summary['returns_in']+=$returns_in;$summary['losses']+=$losses;$summary['adjustments']+=$adjustments;$summary['closing_units']+=$closing;$summary['reserved_units']+=$reserved;$summary['available_units']+=$available;$summary['warehouse_fulfillable']+=$warehouse_fillable;$summary['warehouse_shortfall']+=$warehouse_shortfall;$summary['sales_gross']+=$gross;$summary['sales_net']+=$net;$summary['sales_vat']+=$vat;$summary['sales_cogs']+=$cogs;$summary['sales_gross_profit']+=$gp;$summary['sales_lines']+=$sale_lines;if($sale_lines>0&&((float)($x['purchase_cost']??0)<=0))$summary['sales_cost_missing_lines']+=$sale_lines;if($sale_lines>0&&((float)($x['retail_price']??0)<=0))$summary['sales_master_price_missing_lines']+=$sale_lines;if($reorder_qty>0)$summary['reorder_alerts']++;
                if(count($out)<$detail_limit){
                    $out[]=array('location_id'=>$loc_id,'location_name'=>$loc['location_name'],'location_type'=>$loc['location_type'],'variant_id'=>$vid,'sku'=>$sku,'product_name'=>$x['product_name'],'unit'=>$x['unit'],'opening'=>round($opening,2),'receipts'=>round($receipts,2),'transfer_in'=>round($transfer_in,2),'transfer_out'=>round($transfer_out,2),'sales'=>round($sales,2),'returns_in'=>round($returns_in,2),'losses'=>round($losses,2),'adjustments'=>round($adjustments,2),'closing'=>round($closing,2),'reserved'=>round($reserved,2),'available'=>round($available,2),'reorder_level'=>$reorder_level===null?null:round($reorder_level,2),'reorder_qty'=>round($reorder_qty,2),'warehouse_available'=>round($warehouse_avail,2),'warehouse_fulfillable'=>round($warehouse_fillable,2),'warehouse_shortfall'=>round($warehouse_shortfall,2),'sales_gross'=>round($gross,2),'sales_net'=>round($net,2),'sales_vat'=>round($vat,2),'sales_cogs'=>round($cogs,2),'sales_gross_profit'=>round($gp,2),'sales_lines'=>$sale_lines,'purchase_cost'=>$x['purchase_cost']===null?null:round((float)$x['purchase_cost'],2),'master_price'=>$x['retail_price']===null?null:round((float)$x['retail_price'],2),'reorder_status'=>$status);
                }
            }
        }
        Stockland_Runtime_Diagnostics::stage('daily_inventory_finalize');
        foreach($summary as $k=>$v)$summary[$k]=round((float)$v,2);
        return rest_ensure_response(array('business_date'=>$date,'scope_location_id'=>$location,'warehouse_availability_basis'=>'ledger_transactions_plus_active_reservations','detail_limit'=>$detail_limit,'rows_truncated'=>$matching_rows>count($out),'total_matching_rows'=>$matching_rows,'finance_data_quality'=>array('cost_missing_sale_lines'=>(int)$summary['sales_cost_missing_lines'],'master_price_missing_sale_lines'=>(int)$summary['sales_master_price_missing_lines']),'rows'=>$out,'summary'=>$summary));
    }

}
