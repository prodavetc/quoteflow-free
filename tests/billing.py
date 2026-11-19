"""Separate billing/delivery addresses, country-specific forms and immutable acceptance in M0."""
from concurrent.futures import ThreadPoolExecutor
import json
from decimal import Decimal
import re
from support import BASE, Forms, browser, form_where, request, wp


def fixture():
    return wp('eval', """
        $p=new WC_Product_Simple();$p->set_name('Separate billing fixture');$p->set_regular_price('20.00');$p->save();
        echo Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15.00','billing@example.test',0,null,'3.00','US');
    """)


def details(token):
    return json.loads(wp('eval', f"""
        $f=Latse\\QuoteFlow\\Offers::find('{token}');$o=$f->order_id?wc_get_order($f->order_id):null;
        echo wp_json_encode(['state'=>$f->state,'billing_json'=>$f->accepted_billing_json,
            'id'=>$o?$o->get_id():null,'status'=>$o?$o->get_status():null,
            'billing'=>$o?$o->get_address('billing'):null,'shipping'=>$o?$o->get_address('shipping'):null,
            'total'=>$o?$o->get_total():null,'shipping_total'=>$o?$o->get_shipping_total():null]);
    """))


def fields(link):
    html = request(browser(), link)[2]
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', html)[1]
    return html, form_where(html, 'qf_nonce', nonce)['fields']


delivery = {'delivery[first_name]': 'Ana', 'delivery[last_name]': 'Example', 'delivery[company]': '',
            'delivery[address_1]': '123 Shipping Street', 'delivery[address_2]': '', 'delivery[city]': 'Beverly Hills',
            'delivery[country]': 'US', 'delivery[state]': 'CA', 'delivery[postcode]': '90210'}
billing = {'billing[first_name]': 'Bob', 'billing[last_name]': 'Example', 'billing[company]': 'Example Ltd',
           'billing[address_1]': '42 Billing Street', 'billing[address_2]': 'Suite 7', 'billing[city]': 'London',
           'billing[country]': 'GB', 'billing[state]': '', 'billing[postcode]': 'SW1A 1AA'}

saved = json.loads(wp('eval', """echo wp_json_encode(array_map('get_option',[
    'woocommerce_allowed_countries','woocommerce_specific_allowed_countries',
    'woocommerce_ship_to_countries','woocommerce_specific_ship_to_countries']));"""))
try:
    wp('option', 'update', 'woocommerce_allowed_countries', 'specific')
    wp('option', 'update', 'woocommerce_specific_allowed_countries', '["US","GB"]', '--format=json')
    wp('option', 'update', 'woocommerce_ship_to_countries', 'specific')
    wp('option', 'update', 'woocommerce_specific_ship_to_countries', '["US"]', '--format=json')
    for storage in ('enable', 'disable'):
        wp('wc', 'hpos', 'sync')
        wp('wc', 'hpos', storage)
        wp('option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no')
        token = fixture()
        link = BASE + '/?qf_offer=' + token
        html, same = fields(link)
        assert same['billing_mode'] == 'same' and 'billing[first_name]' not in same
        # Choosing presentation options is a GET and leaves the offer untouched.
        selector = form_where(html, 'qf_offer', token)
        assert 'Update address form' in html and selector['url'] == BASE + '/'
        separate_link = link + '&qf_billing_mode=separate&qf_billing_country=GB'
        html, form = fields(separate_link)
        assert form['billing_mode'] == 'separate' and form['billing[country]'] == 'GB'
        assert all(key in form for key in ('billing[first_name]', 'billing[last_name]', 'billing[address_1]',
                                         'billing[city]', 'billing[postcode]', 'delivery[address_1]'))
        assert 'United Kingdom' in html and 'United States' in html
        assert not re.search(r'<select[^>]+name="billing\[state\]"', html)  # GB has no state selector.
        assert details(token)['state'] == 'offered'
        us_html = fields(link + '&qf_billing_mode=separate&qf_billing_country=US')[0]
        state_select = re.search(r'<select[^>]+name="billing\[state\]"[^>]*>(.*?)</select>', us_html, re.S)[1]
        assert 'value="NY"' in state_select and 'value="CA"' in state_select
        forged = fields(link + '&qf_billing_mode=separate&qf_billing_country=ZZ')[1]
        assert forged['billing[country]'] == 'US'
        # Invalid or missing billing data never claims the offer or creates an order.
        payload = form | delivery | billing | {'decision': 'accept'}
        assert request(browser(), separate_link, payload | {'qf_nonce': 'invalid'})[0] == 403
        for bad in ({'billing[first_name]': ''}, {'billing[address_1]': ''}, {'billing[postcode]': 'bad'},
                    {'billing[country]': 'CA'}, {'billing[country]': 'ZZ'}, {'billing[country][x]': 'US'},
                    {'billing[first_name]': 'x' * 201}, {'billing_mode': 'invalid'}, {'billing_mode[x]': 'separate'},
                    {'delivery[country]': 'GB'}):
            attempt = payload.copy()
            if 'billing[country][x]' in bad:
                attempt.pop('billing[country]')
            if 'billing_mode[x]' in bad:
                attempt.pop('billing_mode')
            assert request(browser(), separate_link, attempt | bad)[0] == 409, bad
        assert request(browser(), separate_link, payload | {
            'billing[country]': 'US', 'billing[state]': 'INVALID', 'billing[postcode]': '10001',
        })[0] == 409
        missing = {key: value for key, value in payload.items() if not key.startswith('billing[')}
        assert request(browser(), separate_link, missing)[0] == 409
        assert details(token)['state'] == 'offered' and details(token)['id'] is None
        with ThreadPoolExecutor(2) as pool:
            results = list(pool.map(lambda _: request(browser(), separate_link, payload), range(2)))
        assert all(result[0] == 303 for result in results)
        payment = results[0][1]['Location']
        assert results[1][1]['Location'] == payment
        order = details(token)
        assert order['billing']['country'] == 'GB' and order['shipping']['country'] == 'US'
        assert order['billing']['address_1'] == '42 Billing Street' and order['billing']['first_name'] == 'Bob'
        assert order['shipping']['address_1'] == '123 Shipping Street' and order['shipping']['first_name'] == 'Ana'
        assert order['billing']['email'] == 'billing@example.test'
        assert Decimal(order['total']) == Decimal('18.00') and Decimal(order['shipping_total']) == Decimal('3.00')
        assert json.loads(order['billing_json'])['postcode'] == 'SW1A 1AA'
        # Replays and interrupted conversion recovery use the accepted pair of addresses.
        assert request(browser(), separate_link, payload | {'billing[address_1]': 'Changed'})[1]['Location'] == payment
        wp('eval', f"""global $wpdb; $f=Latse\\QuoteFlow\\Offers::find('{token}');
            $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating','order_id'=>null],['id'=>$f->id]);""")
        assert request(browser(), link, {'qf_nonce': form['qf_nonce'], 'decision': 'accept'})[1]['Location'] == payment
        assert details(token)['billing']['address_1'] == '42 Billing Street' and details(token)['id'] == order['id']
        # Payment and recovery reject a changed billing address, even with the same total.
        wp('eval', f"$o=wc_get_order({order['id']});$o->set_billing_address_1('Tampered');$o->save();")
        guest = browser()
        pay_html = request(guest, payment)[2]
        pay = next(f['fields'] for f in Forms(pay_html).forms if 'woocommerce-pay-nonce' in f['fields']) | {'payment_method': 'bacs'}
        assert request(guest, payment, pay)[0] == 200 and details(token)['status'] == 'pending'
        wp('eval', f"""global $wpdb; $f=Latse\\QuoteFlow\\Offers::find('{token}');
            $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating','order_id'=>null],['id'=>$f->id]);""")
        assert request(browser(), link, {'qf_nonce': form['qf_nonce']})[0] == 409
        wp('eval', f"$o=wc_get_order({order['id']});$o->set_billing_address_1('42 Billing Street');$o->save();")
        assert request(browser(), link, {'qf_nonce': form['qf_nonce']})[1]['Location'] == payment
        pay_html = request(guest, payment)[2]
        pay = next(f['fields'] for f in Forms(pay_html).forms if 'woocommerce-pay-nonce' in f['fields']) | {'payment_method': 'bacs'}
        assert request(guest, payment, pay)[0] in (302, 303) and details(token)['status'] == 'on-hold'
        # Both snapshots are visible through the privacy exporter.
        assert wp('eval', f"""
            $page=1;$found=false;do{{$r=Latse\\QuoteFlow\\Privacy::export('billing@example.test',$page++);
                foreach($r['data'] as $item){{if($item['item_id']==='quoteflow-offers-'.Latse\\QuoteFlow\\Offers::find('{token}')->id){{
                    $text=wp_json_encode($item);$found=str_contains($text,'42 Billing Street')&&str_contains($text,'123 Shipping Street');
                }}}}
            }}while(!$r['done']);echo $found?'passed':'failed';
        """) == 'passed'
        # Existing accepted offers with a NULL billing snapshot still use delivery for both.
        old = fixture()
        _, old_form = fields(BASE + '/?qf_offer=' + old)
        accepted = request(browser(), BASE + '/?qf_offer=' + old, old_form | delivery | {'decision': 'accept'})
        assert accepted[0] == 303
        old_order = details(old)
        wp('eval', f"""global $wpdb;$f=Latse\\QuoteFlow\\Offers::find('{old}');
            $wpdb->update($wpdb->prefix.'qf_offers',['accepted_billing_json'=>null,'state'=>'creating','order_id'=>null],['id'=>$f->id]);""")
        assert request(browser(), BASE + '/?qf_offer=' + old, {'qf_nonce': old_form['qf_nonce']})[1]['Location'] == accepted[1]['Location']
        assert details(old)['billing']['address_1'] == old_order['shipping']['address_1']
        print(f'{storage}: country forms, independent addresses, validation, concurrent acceptance, recovery, payment and legacy snapshots passed', flush=True)
finally:
    names = ['woocommerce_allowed_countries','woocommerce_specific_allowed_countries',
             'woocommerce_ship_to_countries','woocommerce_specific_ship_to_countries']
    for name, value in zip(names, saved):
        if value is False:
            wp('option', 'delete', name)
        else:
            wp('option', 'update', name, json.dumps(value), '--format=json')
