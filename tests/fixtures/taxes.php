<?php
use Latse\QuoteFlow\Offers;
use Latse\QuoteFlow\Taxes;

global $wpdb;
$names = ['woocommerce_calc_taxes','woocommerce_tax_based_on','woocommerce_tax_round_at_subtotal','woocommerce_shipping_tax_class','woocommerce_prices_include_tax','woocommerce_tax_classes'];
$saved = array_combine($names, array_map('get_option',$names));
$ids=[];
add_filter('pre_wp_mail','__return_true');
try {
    update_option('woocommerce_calc_taxes','yes');
    update_option('woocommerce_tax_based_on','shipping');
    update_option('woocommerce_shipping_tax_class','inherit');
    $ids[]=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'CA','tax_rate'=>'21.0000','tax_rate_name'=>'Fixture standard','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
    $ids[]=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'CA','tax_rate'=>'5.0000','tax_rate_name'=>'Fixture compound','tax_rate_priority'=>2,'tax_rate_compound'=>1,'tax_rate_shipping'=>1,'tax_rate_order'=>1,'tax_rate_class'=>'']);
    WC_Cache_Helper::invalidate_cache_group('taxes');
    $p=new WC_Product_Simple();$p->set_name('Taxed fixture');$p->set_regular_price('127.05');$p->set_tax_status('taxable');$p->save();
    $other=new WC_Product_Simple();$other->set_name('Other taxed fixture');$other->set_regular_price('15');$other->set_tax_status('taxable');$other->save();
    $address=['first_name'=>'Ana','last_name'=>'Example','country'=>'US','state'=>'CA','city'=>'Beverly Hills','address_1'=>'123 Tax Street','postcode'=>'90210'];
    foreach(['yes','no'] as $round) foreach(['yes','no'] as $catalog) {
        update_option('woocommerce_tax_round_at_subtotal',$round);update_option('woocommerce_prices_include_tax',$catalog);
        $token=Offers::create_items([
            ['product_id'=>$p->get_id(),'quantity'=>3,'total'=>'127.05'],
            ['product_id'=>$other->get_id(),'quantity'=>1,'total'=>'15.00']
        ],'taxes@example.test',0,null,'12.70','US');
        $order=Offers::accept($token,$address);
        if($order->get_total()!=='154.75'||count($order->get_items('tax'))!==2){throw new Exception('Gross price or rates changed: '.wp_json_encode($order->get_data()));}
        Offers::validate_payment($order);
        if(wc_notice_count('error')){throw new Exception('Valid tax order blocked: '.wp_json_encode(wc_get_notices()));}
        $offer=Offers::find($token);
        $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating','order_id'=>null],['id'=>$offer->id]);
        if(Offers::accept($token)->get_id()!==$order->get_id()){throw new Exception('Tax recovery duplicated an order');}
        $line=current($order->get_items());$original=$line->get_taxes();$changed=$original;
        $key=array_key_first($changed['total']);$changed['total'][$key]=(float)$changed['total'][$key]+1;
        $line->set_taxes($changed);$line->save();
        $check=wc_get_order($order->get_id());Offers::validate_payment($check);
        if(!wc_notice_count('error')){throw new Exception('Changed tax amounts accepted');}wc_clear_notices();
        $line->set_taxes($original);$line->save();
        echo "round={$round}, catalog={$catalog}: gross totals, compound rates, recovery and tamper guard passed\n";
    }
    // Non-taxable goods and billing-based location preserve the same gross quote.
    $p->set_tax_status('none');$p->save();
    $token=Offers::create($p->get_id(),1,'15','non-taxable@example.test',0,null,'3','US');
    $order=Offers::accept($token,$address);
    if((float)$order->get_total_tax()!==0.0||$order->get_total()!=='18.00'){throw new Exception('Non-taxable order was taxed');}
    $p->set_tax_status('taxable');$p->save();
    update_option('woocommerce_tax_based_on','billing');
    $billing=$address;$billing['state']='NY';$billing['postcode']='10001';
    $token=Offers::create($p->get_id(),1,'15','billing-location@example.test',0,null,'3','US');
    $order=Offers::accept($token,$address,$billing);
    if((float)$order->get_total_tax()!==0.0){throw new Exception('Billing location ignored');}
    update_option('woocommerce_tax_based_on','shipping');
    update_option('woocommerce_tax_classes',"Reduced rate\nZero rate");
    $ids[]=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'CA','tax_rate'=>'10.0000','tax_rate_name'=>'Fixture reduced','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'reduced-rate']);
    $ids[]=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'CA','tax_rate'=>'0.0000','tax_rate_name'=>'Fixture zero','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'zero-rate']);
    WC_Cache_Helper::invalidate_cache_group('taxes');
    foreach(['reduced-rate','zero-rate'] as $class){
        $p->set_tax_class($class);$p->save();
        $token=Offers::create($p->get_id(),1,'11','classes@example.test',0,null,'2.20','US');
        $order=Offers::accept($token,$address);
        if($order->get_total()!=='13.20'||($class==='zero-rate'&&(float)$order->get_total_tax()!==0.0)||($class==='reduced-rate'&&(float)$order->get_total_tax()<=0)){throw new Exception('Tax class or shipping inheritance failed');}
    }
    $p->set_tax_class('');$p->save();
    echo "Non-taxable, billing location, reduced/zero rates and shipping inheritance passed\n";
    // Virtual goods require billing address while taxes are enabled.
    $p->set_virtual(true);$p->save();
    $token=Offers::create($p->get_id(),1,'127.05','virtual-tax@example.test');
    try {Offers::accept($token);throw new LogicException('Missing address was accepted');}catch(RuntimeException $e){}
    if(Offers::find($token)->state!=='offered'){throw new Exception('Invalid address claimed offer');}
    $order=Offers::accept($token,[],$address);
    if($order->get_total()!=='127.05'||$order->get_billing_state()!=='CA'||(float)$order->get_total_tax()<=0){throw new Exception('Virtual tax address failed');}
    // A tax configuration change invalidates acceptance before any order is created.
    $token=Offers::create($p->get_id(),1,'127.05','changed-tax@example.test');
    update_option('woocommerce_tax_based_on','billing');
    try {Offers::accept($token,[],$address);throw new LogicException('Changed tax settings accepted');}catch(RuntimeException $e){}
    if(Offers::find($token)->state!=='offered'){throw new Exception('Changed config claimed offer');}
    echo "Virtual billing, required address and tax configuration drift passed\n";
} finally {
    foreach($ids as $id){WC_Tax::_delete_tax_rate($id);}
    WC_Cache_Helper::invalidate_cache_group('taxes');
    foreach($saved as $name=>$value){if(false===$value){delete_option($name);}else{update_option($name,$value);}}
}
