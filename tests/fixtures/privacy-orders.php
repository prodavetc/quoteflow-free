<?php
use Latse\QuoteFlow\Offers;
use Latse\QuoteFlow\Requests;
use Latse\QuoteFlow\Privacy;
add_filter('pre_wp_mail','__return_true');
$p=new WC_Product_Simple();$p->set_name('Order privacy fixture');$p->set_regular_price('20');$p->set_virtual(true);$p->save();
global $wpdb;
foreach(['completed','cancelled','refunded','pending','on-hold','processing'] as $status){
    $email='order-privacy-'.wp_generate_uuid4().'@example.test';$uuid=wp_generate_uuid4();
    Requests::submit($uuid,$p->get_id(),1,$email,'Private note',$uuid);
    $request_id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s",$uuid));
    $token=Requests::respond((int)$request_id,'15');$order=Offers::accept($token);$order->set_status($status);$order->save();
    $should=in_array($status,['completed','cancelled','refunded'],true);
    if(apply_filters('woocommerce_privacy_erase_order_personal_data',true,$order)!==$should){throw new Exception('Incorrect order protection: '.$status);}
    if($should){
        if($status==='completed'){$f=Offers::find($token);$wpdb->update($wpdb->prefix.'qf_offers',['email_state'=>'sending'],['id'=>$f->id]);}
        WC_Privacy_Erasers::remove_order_personal_data($order);
        if(Offers::find($token)||$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE id=%d",$request_id))){throw new Exception('Quote data remained after order erasure');}
        if(!wc_get_order($order->get_id())||'yes'!==wc_get_order($order->get_id())->get_meta('_anonymized')){throw new Exception('Woo order was removed');}
    }else{
        if(!Offers::find($token)||$order->get_billing_email()!==$email){throw new Exception('Active order data removed');}
    }
    echo $status.": coordinated erasure/protection passed\n";
}
