"""Exercise physical product offers with a quoted shipping amount and fixed country."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
from decimal import Decimal
import json
import re
from urllib.parse import parse_qs, urlsplit
from support import BASE, Forms, browser, form_where, request, request_row, reset_request_throttles, wp


def order_details(token):
    return json.loads(wp("eval", f"""
        $offer=Latse\\QuoteFlow\\Offers::find('{token}');
        $o=wc_get_order($offer->order_id);
        echo wp_json_encode(['id'=>$o->get_id(),'total'=>$o->get_total(),'shipping'=>$o->get_shipping_total(),
            'shipping_count'=>count($o->get_items('shipping')),'shipping_address'=>$o->get_address('shipping'),
            'billing_address'=>$o->get_address('billing'),'state'=>$o->get_status()]);
    """))


admin_url = BASE + "/wp-admin/admin.php?page=quoteflow"
admin = browser()
request(admin, BASE + "/wp-login.php")
assert request(admin, BASE + "/wp-login.php", {
    "log": "quote-m0", "pwd": "quoteflow-m0-local-only", "testcookie": "1",
})[0] == 302
reset_request_throttles()

for storage in ("enable", "disable"):
    wp("wc", "hpos", "sync")
    wp("wc", "hpos", storage)
    wp("option", "update", "woocommerce_custom_orders_table_data_sync_enabled", "no")
    product = wp("eval", """
        $p=new WC_Product_Simple(); $p->set_name('Physical delivery fixture');
        $p->set_regular_price('25.00'); $p->set_virtual(false);
        $p->set_manage_stock(true); $p->set_stock_quantity(10);
        echo $p->save();
    """)
    guest = browser()
    status, _, html = request(guest, BASE + "/?post_type=product&p=" + product)
    assert status == 200
    public = form_where(html, "action", "qf_request")
    fields = public["fields"] | {"email": "physical@example.test", "quantity": "2"}
    assert request(guest, public["url"], fields)[0] == 303
    request_id = request_row(fields["submission_id"])[0]["id"]
    reply = form_where(request(admin, admin_url)[2], "request_id", request_id)["fields"]
    reply |= {"total": "39.80", "shipping_total": "5.50", "shipping_country": "US"}
    assert 'id="qf-offer-link"' not in request(admin, admin_url, reply | {"shipping_country": "ZZ"})[2]
    assert 'id="qf-offer-link"' not in request(admin, admin_url, reply | {"shipping_total": "-1.00"})[2]
    selected = request(admin, admin_url, reply)[2]
    link = unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', selected)[1])
    token = parse_qs(urlsplit(link).query)["qf_offer"][0]
    offer = request(guest, link)[2]
    assert "Quoted shipping" in offer and "United States" in offer and "5.50" in offer and "45.30" in offer
    assert 'name="delivery[country]"' in offer and "Delivery and billing address" in offer
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', offer)[1]
    decision = {"qf_nonce": nonce, "decision": "accept"}
    address = {
        "delivery[first_name]": "Ana", "delivery[last_name]": "Example",
        "delivery[country]": "US", "delivery[address_1]": "123 Test Street",
        "delivery[address_2]": "", "delivery[company]": "",
        "delivery[city]": "Beverly Hills", "delivery[state]": "CA", "delivery[postcode]": "90210",
    }
    assert wp("eval", f"""
        add_filter('pre_wp_mail',static function($result,$mail){{
            return str_contains($mail['message'],'Quoted shipping')
                && str_contains($mail['message'],'United States')
                && str_contains($mail['message'],'5.50')
                && str_contains($mail['message'],'45.30');
        }},10,2);
        Latse\\QuoteFlow\\Offer_Email::send('{token}',0);
        echo 'captured';
    """) == "captured"
    assert request(guest, link, decision)[0] == 409
    assert request(guest, link, decision | address | {"delivery[country]": "CA"})[0] == 409
    assert request(guest, link, decision | address | {"delivery[state]": "INVALID"})[0] == 409
    assert request(guest, link, decision | address | {"delivery[postcode]": "invalid"})[0] == 409
    assert wp("eval", f"echo Latse\\QuoteFlow\\Offers::find('{token}')->state;") == "offered"
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: request(browser(), link, decision | address), range(2)))
    assert all(result[0] == 303 for result in results)
    payment_link = results[0][1]["Location"]
    assert results[1][1]["Location"] == payment_link
    order = order_details(token)
    assert order["total"] == "45.30" and Decimal(order["shipping"]) == Decimal("5.50") and order["shipping_count"] == 1
    assert order["shipping_address"]["country"] == "US" and order["shipping_address"]["state"] == "CA"
    assert order["shipping_address"]["address_1"] == "123 Test Street"
    assert order["billing_address"]["email"] == "physical@example.test"
    assert request(guest, link, decision | address | {"delivery[address_1]": "Another address"})[1]["Location"] == payment_link
    assert order_details(token)["shipping_address"]["address_1"] == "123 Test Street"
    # The saved address is reused when recovering an interrupted conversion.
    wp("eval", f"""global $wpdb;$o=Latse\\QuoteFlow\\Offers::find('{token}');
        $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating','order_id'=>null],['id'=>$o->id]);""")
    assert request(guest, link, decision)[1]["Location"] == payment_link
    assert order_details(token)["id"] == order["id"]

    pay = next(form["fields"] for form in Forms(request(guest, payment_link)[2]).forms
               if "woocommerce-pay-nonce" in form["fields"]) | {"payment_method": "bacs"}
    wp("eval", f"$o=wc_get_order({order['id']});$o->set_shipping_address_1('Altered address');$o->save();")
    assert request(guest, payment_link, pay)[0] == 200 and order_details(token)["state"] == "pending"
    wp("eval", f"$o=wc_get_order({order['id']});$o->set_shipping_address_1('123 Test Street');$o->save();")
    # Change shipping and compensate on the product line to preserve the grand total.
    wp("eval", f"""$o=wc_get_order({order['id']});$s=current($o->get_items('shipping'));$s->set_total('6.50');$s->save();
        $p=current($o->get_items());$p->set_total('38.80');$p->save();$o->calculate_totals(false);""")
    assert request(guest, payment_link, pay)[0] == 200 and order_details(token)["state"] == "pending"
    wp("eval", f"""$o=wc_get_order({order['id']});$s=current($o->get_items('shipping'));$s->set_total('5.50');$s->save();
        $p=current($o->get_items());$p->set_total('39.80');$p->save();$o->calculate_totals(false);""")
    pay = next(form["fields"] for form in Forms(request(guest, payment_link)[2]).forms
               if "woocommerce-pay-nonce" in form["fields"]) | {"payment_method": "bacs"}
    status, headers, _ = request(guest, payment_link, pay)
    assert status in (302, 303) and "order-received" in headers["Location"]
    assert order_details(token)["state"] == "on-hold"
    assert wp("eval", f"echo wc_get_product({product})->get_stock_quantity();") == "8"
    assert wp("eval", f"""$o=wc_get_order({order['id']});$o->payment_complete('delivery-local-simulation');
        echo $o->is_paid() && wc_get_product({product})->get_stock_quantity()===8?'passed':'failed';""") == "passed"
    print(f"{storage}: physical request, country, address validation, shipping snapshot, replay/recovery and payment passed")

# A mixed quote supports free shipping and retains legacy virtual offer behavior.
assert wp("eval", """
    $physical=new WC_Product_Simple();$physical->set_name('Free shipping fixture');$physical->set_regular_price('10.00');$physical->save();
    $virtual=new WC_Product_Simple();$virtual->set_name('Virtual mixed fixture');$virtual->set_regular_price('5.00');$virtual->set_virtual(true);$virtual->save();
    $token=Latse\\QuoteFlow\\Offers::create_items([
        ['product_id'=>$physical->get_id(),'quantity'=>1,'total'=>'8.00'],
        ['product_id'=>$virtual->get_id(),'quantity'=>1,'total'=>'4.00']
    ],'mixed@example.test',0,null,'0.00','US');
    $order=Latse\\QuoteFlow\\Offers::accept($token,[
        'first_name'=>'Ana','last_name'=>'Example','country'=>'US','address_1'=>'123 Test Street',
        'city'=>'Beverly Hills','state'=>'CA','postcode'=>'90210'
    ]);
    if ($order->get_total()!=='12.00'||count($order->get_items())!==2||count($order->get_items('shipping'))!==1) {throw new Exception('Mixed offer failed');}
    $token=Latse\\QuoteFlow\\Offers::create($virtual->get_id(),1,'4.00','virtual@example.test');
    $virtual->set_virtual(false);$virtual->save();
    try {Latse\\QuoteFlow\\Offers::accept($token);echo 'failed';}
    catch (RuntimeException $e) {echo str_contains($e->getMessage(),'delivery requirements changed')?'passed':'failed';}
""") == "passed"
print("Mixed cart, zero shipping and delivery requirement changes passed")

assert wp("eval", """
    $currency=get_option('woocommerce_currency');$decimals=get_option('woocommerce_price_num_decimals');
    try {
        update_option('woocommerce_currency','JPY');update_option('woocommerce_price_num_decimals',0);
        $p=new WC_Product_Simple();$p->set_name('Zero decimal fixture');$p->set_regular_price('120');$p->set_virtual(true);$p->save();
        $token=Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'100','yen@example.test');
        $o=Latse\\QuoteFlow\\Offers::accept($token);
        if ($o->get_currency()!=='JPY'||(int)$o->get_total()!==100) {throw new Exception('JPY offer failed');}
        echo 'passed';
    } finally {update_option('woocommerce_currency',$currency);update_option('woocommerce_price_num_decimals',$decimals);}
""") == "passed"
print("Zero-decimal currency with default zero shipping passed")

assert wp("eval", """
    $p=new WC_Product_Simple();$p->set_name('GB address fixture');$p->set_regular_price('10.00');$p->save();
    $token=Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'8.00','gb@example.test',0,null,'2.00','GB');
    $o=Latse\\QuoteFlow\\Offers::accept($token,[
        'first_name'=>'Ana','last_name'=>'Example','country'=>'GB','address_1'=>'123 Test Street',
        'city'=>'London','postcode'=>'SW1A 1AA'
    ]);
    echo $o->get_shipping_country()==='GB'&&$o->get_shipping_postcode()==='SW1A 1AA'?'passed':'failed';
""") == "passed"
print("Country-specific address fields without a required state passed")
