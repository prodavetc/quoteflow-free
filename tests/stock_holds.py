"""Different quotes compete for the last unit using WooCommerce's atomic reservation."""
from concurrent.futures import ThreadPoolExecutor
import json,re
from support import BASE,Forms,browser,request,wp
for storage in ('enable','disable'):
    wp('wc','hpos','sync');wp('wc','hpos',storage);wp('option','update','woocommerce_custom_orders_table_data_sync_enabled','no')
    data=json.loads(wp('eval',"""
        $p=new WC_Product_Simple();$p->set_name('Last unit');$p->set_regular_price('20');$p->set_virtual(true);$p->set_manage_stock(true);$p->set_stock_quantity(1);$p->save();
        echo wp_json_encode(['product'=>$p->get_id(),'tokens'=>[Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15','hold-a@example.test'),Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15','hold-b@example.test')]]);
    """))
    submissions=[]
    for token in data['tokens']:
        client=browser();url=BASE+'/?qf_offer='+token;html=request(client,url)[2]
        nonce=re.search(r'name="qf_nonce" value="([^"]+)"',html)[1]
        submissions.append((client,url,{'qf_nonce':nonce},token))
    with ThreadPoolExecutor(2) as pool:
        results=list(pool.map(lambda x:request(x[0],x[1],x[2]),submissions))
    assert sorted(r[0] for r in results)==[303,409],[(r[0],r[2][:100]) for r in results]
    winner=next(i for i,r in enumerate(results) if r[0]==303);loser=1-winner
    winning_token=submissions[winner][3]
    winning_order=int(wp('eval',f"echo Latse\\QuoteFlow\\Offers::find('{winning_token}')->order_id;"))
    held=wp('eval',f"$p=wc_get_product({data['product']});echo wc_get_held_stock_quantity($p).':'.wc_get_held_stock_quantity($p,{winning_order});")
    assert held=='1:0',held
    # Simulate a declined payment. Failed orders no longer hold stock in WooCommerce.
    wp('eval',f"add_filter('pre_wp_mail','__return_true');$o=wc_get_order({winning_order});$o->set_status('failed');$o->save();")
    assert wp('eval',f"echo wc_get_held_stock_quantity(wc_get_product({data['product']}));")=='0'
    winner_client=submissions[winner][0];winner_payment=results[winner][1]['Location']
    stale_pay=next(f['fields'] for f in Forms(request(winner_client,winner_payment)[2]).forms if 'woocommerce-pay-nonce' in f['fields'])
    client,url,form,token=submissions[loser];response=request(client,url,form)
    assert response[0]==303,response[0]
    losing_order=int(wp('eval',f"echo Latse\\QuoteFlow\\Offers::find('{token}')->order_id;"))
    # A retry from an already-open page must not pay through another order's hold.
    assert request(winner_client,winner_payment,stale_pay|{'payment_method':'bacs'})[0]==200
    assert wp('eval',f"echo wc_get_order({winning_order})->get_status();")=='failed'
    wp('eval',f"add_filter('pre_wp_mail','__return_true');$o=wc_get_order({losing_order});$o->set_status('cancelled');$o->save();")
    assert wp('eval',f"echo wc_get_held_stock_quantity(wc_get_product({data['product']}));")=='0'
    pay=next(f['fields'] for f in Forms(request(winner_client,winner_payment)[2]).forms if 'woocommerce-pay-nonce' in f['fields'])
    assert request(winner_client,winner_payment,pay|{'payment_method':'bacs'})[0] in (302,303)
    assert wp('eval',f"echo wc_get_product({data['product']})->get_stock_quantity();")=='0'
    print(f'{storage}: competing quotes, native reservation, own-order exclusion, failed-payment guard, cancellation release and successful retry passed',flush=True)
