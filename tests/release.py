"""Verify tax modes, coordinated privacy and Spanish catalogs in the disposable store."""
import subprocess
import json,re
from pathlib import Path
from support import BASE, COMPOSE, Forms, browser, request, wp
for storage in ('enable','disable'):
    wp('wc','hpos','sync');wp('wc','hpos',storage)
    wp('option','update','woocommerce_custom_orders_table_data_sync_enabled','no')
    for name in ('taxes.php','privacy-orders.php'):
        source=Path(__file__).with_name('fixtures')/name
        target='/tmp/qf-release-'+name
        subprocess.run([*COMPOSE,'cp',str(source),'wp:'+target],check=True,stdout=subprocess.DEVNULL)
        print(wp('eval-file',target),flush=True)
# Exercise the actual guest form and native payment with taxes on virtual goods.
saved_tax = wp('option','get','woocommerce_calc_taxes')
rate = None
try:
    wp('option','update','woocommerce_calc_taxes','yes')
    fixture = json.loads(wp('eval', """
        $rate=WC_Tax::_insert_tax_rate(['tax_rate_country'=>'US','tax_rate_state'=>'','tax_rate'=>'21.0000','tax_rate_name'=>'HTTP fixture','tax_rate_priority'=>1,'tax_rate_compound'=>0,'tax_rate_shipping'=>1,'tax_rate_order'=>0,'tax_rate_class'=>'']);
        WC_Cache_Helper::invalidate_cache_group('taxes');
        $p=new WC_Product_Simple();$p->set_name('HTTP virtual tax');$p->set_regular_price('121');$p->set_virtual(true);$p->save();
        echo wp_json_encode(['rate'=>$rate,'token'=>Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'121','tax-http@example.test')]);
    """))
    rate=fixture['rate'];token=fixture['token'];link=BASE+'/?qf_offer='+token+'&qf_billing_country=US'
    guest=browser();html=request(guest,link)[2]
    form=next(f['fields'] for f in Forms(html).forms if 'qf_nonce' in f['fields'])
    assert form['billing_mode']=='separate' and 'billing[first_name]' in form and 'delivery[first_name]' not in form
    assert 'assets/offer.css' in html
    assert request(guest,link,form | {'decision':'accept'})[0]==409
    address={'billing[first_name]':'Ana','billing[last_name]':'Example','billing[country]':'US',
             'billing[state]':'CA','billing[postcode]':'90210','billing[city]':'Beverly Hills','billing[address_1]':'123 HTTP Street'}
    status,headers,_=request(guest,link,form | address | {'decision':'accept'})
    assert status==303
    payment=headers['Location']
    pay=next(f['fields'] for f in Forms(request(guest,payment)[2]).forms if 'woocommerce-pay-nonce' in f['fields'])
    status,headers,_=request(guest,payment,pay | {'payment_method':'bacs'})
    assert status in (302,303) and 'order-received' in headers['Location']
    result=json.loads(wp('eval',f"$f=Latse\\QuoteFlow\\Offers::find('{token}');$o=wc_get_order($f->order_id);echo wp_json_encode([(float)$o->get_total(),(float)$o->get_total_tax(),$o->get_status()]);"))
    assert result==[121,21,'on-hold'],result
    print('Taxed virtual form, billing validation and native WooCommerce payment passed',flush=True)
finally:
    if rate is not None:
        wp('eval',f'WC_Tax::_delete_tax_rate({rate});WC_Cache_Helper::invalidate_cache_group("taxes");')
    wp('option','update','woocommerce_calc_taxes',saved_tax)
assert wp('eval', "switch_to_locale('es_AR');echo __('Request a quote','quoteflow-free');")=='Solicitar cotización'
print('Spanish catalog loading passed',flush=True)
