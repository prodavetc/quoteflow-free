"""Verify variable options, wildcard variations, shared stock and order identity."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import parse_qs, urlsplit
from support import BASE, COMPOSE, Forms, browser, form_where, request, request_row, reset_request_throttles, wp


def fixture():
    path = Path(__file__).parent / "fixtures" / "variable-products.php"
    subprocess.run([*COMPOSE, "cp", str(path), "wp:/tmp/qf-variable-products.php"], check=True, capture_output=True)
    return json.loads(wp("eval-file", "/tmp/qf-variable-products.php"))


def token_from(html):
    link = unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', html)[1])
    return link, parse_qs(urlsplit(link).query)["qf_offer"][0]


def read_order(token):
    return json.loads(wp("eval", f"""
        $offer=Latse\\QuoteFlow\\Offers::find('{token}');
        $o=wc_get_order($offer->order_id);$lines=[];
        foreach($o->get_items() as $item){{$lines[]=[
            'parent'=>$item->get_product_id(),'variation'=>$item->get_variation_id(),
            'qty'=>$item->get_quantity(),'color'=>$item->get_meta('color'),'size'=>$item->get_meta('size')
        ];}}
        echo wp_json_encode(['id'=>$o->get_id(),'state'=>$o->get_status(),'total'=>$o->get_total(),'lines'=>$lines]);
    """))


def accept(link, client):
    html = request(client, link)[2]
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', html)[1]
    fields = {"qf_nonce": nonce, "decision": "accept"}
    status, headers, _ = request(client, link, fields)
    assert status == 303
    return headers["Location"], fields


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
    ids = fixture()
    guest = browser()
    status, headers, html = request(guest, BASE + f"/?post_type=product&p={ids['parent']}")
    assert status == 200 and "no-cache" in headers["Cache-Control"]
    assert 'name="attributes[attribute_color]"' in html and 'name="attributes[attribute_size]"' in html
    public = form_where(html, "action", "qf_request")
    fields = public["fields"] | {"quantity": "2", "email": "variation@example.test"}
    assert request(guest, public["url"], fields)[0] == 422
    assert request(guest, public["url"], fields | {"attributes[attribute_color]": "Blue", "attributes[attribute_size]": "Small"})[0] == 422
    fields |= {"attributes[attribute_color]": "Red", "attributes[attribute_size]": "Small"}
    assert request(guest, public["url"], fields)[0] == 303
    assert request(guest, public["url"], fields)[0] == 303
    assert request(guest, public["url"], fields | {"attributes[attribute_size]": "Large"})[0] == 422
    row = request_row(fields["submission_id"])[0]
    line = json.loads(row["items_json"])[0]
    assert line["product_id"] == ids["red"] and line["parent_id"] == ids["parent"]
    assert line["attributes"] == {"attribute_color": "Red", "attribute_size": "Small"}
    reply = form_where(request(admin, admin_url)[2], "request_id", row["id"])["fields"] | {"total": "19.95"}
    link, token = token_from(request(admin, admin_url, reply)[2])
    assert all(value in request(guest, link)[2].lower() for value in ("color: red", "size: small", "19.95"))
    payment_link, decision = accept(link, guest)
    order = read_order(token)
    assert order["lines"] == [{"parent": ids["parent"], "variation": ids["red"], "qty": 2, "color": "Red", "size": "Small"}]
    # Recovered orders must preserve option metadata as well as price and product identity.
    wp("eval", f"""global $wpdb;$offer=Latse\\QuoteFlow\\Offers::find('{token}');
        $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating','order_id'=>null],['id'=>$offer->id]);
        $o=wc_get_order({order['id']});$item=current($o->get_items());$item->update_meta_data('size','Large');$item->save();""")
    assert request(guest, link, decision)[0] == 409
    wp("eval", f"$o=wc_get_order({order['id']});$item=current($o->get_items());$item->update_meta_data('size','Small');$item->save();")
    assert request(guest, link, decision)[1]["Location"] == payment_link
    assert read_order(token)["id"] == order["id"]

    pay = next(form["fields"] for form in Forms(request(guest, payment_link)[2]).forms
               if "woocommerce-pay-nonce" in form["fields"]) | {"payment_method": "bacs"}
    wp("eval", f"$o=wc_get_order({order['id']});$item=current($o->get_items());$item->update_meta_data('color','Blue');$item->save();")
    assert request(guest, payment_link, pay)[0] == 200 and read_order(token)["state"] == "pending"
    wp("eval", f"$o=wc_get_order({order['id']});$item=current($o->get_items());$item->update_meta_data('color','Red');$item->save();")
    status, headers, _ = request(guest, payment_link, pay)
    assert status in (302, 303) and "order-received" in headers["Location"]
    assert wp("eval", f"echo wc_get_product({ids['parent']})->get_stock_quantity();") == "4"

    # Two option selections of one wildcard variation and a second variant share parent stock.
    ids = fixture()
    cart_guest = browser()
    for color, size, quantity in (("Red", "Small", 2), ("Red", "Large", 2), ("Blue", "Large", 1)):
        data = {"add-to-cart": ids["parent"], "variation_id": ids["red"] if color == "Red" else ids["blue"],
                "attribute_color": color, "attribute_size": size, "quantity": quantity}
        assert request(cart_guest, BASE + "/", data)[0] in (200, 302, 303)
    cart_url = wp("eval", "echo wc_get_cart_url();")
    html = request(cart_guest, cart_url)[2]
    public = form_where(html, "action", "qf_request")
    cart_fields = public["fields"] | {"email": "variantcart@example.test"}
    assert cart_fields["items[0][attributes][attribute_size]"] == "Small"
    assert cart_fields["items[1][attributes][attribute_size]"] == "Large"
    assert request(cart_guest, public["url"], cart_fields)[0] == 303
    row = request_row(cart_fields["submission_id"])[0]
    assert len(json.loads(row["items_json"])) == 3
    reply = form_where(request(admin, admin_url)[2], "request_id", row["id"])["fields"]
    reply |= {"totals[0]": "10.00", "totals[1]": "11.00", "totals[2]": "12.00"}
    wp("eval", f"$p=wc_get_product({ids['parent']});$p->set_stock_quantity(4);$p->save();")
    assert 'id="qf-offer-link"' not in request(admin, admin_url, reply)[2]
    wp("eval", f"$p=wc_get_product({ids['parent']});$p->set_stock_quantity(6);$p->save();")
    link, token = token_from(request(admin, admin_url, reply)[2])
    offer = request(cart_guest, link)[2]
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', offer)[1]
    wp("eval", f"$p=wc_get_product({ids['parent']});$p->set_stock_quantity(4);$p->save();")
    assert request(cart_guest, link, {"qf_nonce": nonce})[0] == 409
    wp("eval", f"$p=wc_get_product({ids['parent']});$p->set_stock_quantity(6);$p->save();")
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: request(browser(), link, {"qf_nonce": nonce}), range(2)))
    assert results[0][0] == results[1][0] == 303 and results[0][1]["Location"] == results[1][1]["Location"]
    order = read_order(token)
    assert len(order["lines"]) == 3 and order["total"] == "33.00"
    payment_link = results[0][1]["Location"]
    pay = next(form["fields"] for form in Forms(request(cart_guest, payment_link)[2]).forms
               if "woocommerce-pay-nonce" in form["fields"]) | {"payment_method": "bacs"}
    assert request(cart_guest, payment_link, pay)[0] in (302, 303)
    assert wp("eval", f"echo wc_get_product({ids['parent']})->get_stock_quantity();") == "1"
    print(f"{storage}: product option form, wildcard selection, cart options, stock aggregation, order recovery and payment passed")

ids = fixture()
assert wp("eval", f"""
    $p=wc_get_product({ids['blue']});$p->set_virtual(false);$p->save();
    $token=Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'20.00','physical-variant@example.test',0,null,'3.00','US');
    $o=Latse\\QuoteFlow\\Offers::accept($token,[
        'first_name'=>'Ana','last_name'=>'Example','country'=>'US','address_1'=>'123 Test Street',
        'city'=>'Beverly Hills','state'=>'CA','postcode'=>'90210'
    ]);
    $item=current($o->get_items());
    echo $item->get_variation_id()==={ids['blue']} && $o->get_total()==='23.00' && $o->get_shipping_country()==='US'?'passed':'failed';
""") == "passed"
print("Physical variation with quoted shipping passed")

assert wp("eval", """
    $slug='qfmat'.substr(str_replace('-','',wp_generate_uuid4()),0,8);
    $attribute_id=wc_create_attribute(['name'=>'Material','slug'=>$slug]);
    $taxonomy='pa_'.$slug;
    register_taxonomy($taxonomy,'product');
    $term=wp_insert_term('Cotton',$taxonomy,['slug'=>'cotton']);
    $parent=new WC_Product_Variable();$parent->set_name('Global attribute fixture');
    $attribute=new WC_Product_Attribute();$attribute->set_id($attribute_id);$attribute->set_name($taxonomy);
    $attribute->set_options([$term['term_id']]);$attribute->set_variation(true);
    $parent->set_attributes([$attribute]);$parent->save();
    $variant=new WC_Product_Variation();$variant->set_parent_id($parent->get_id());$variant->set_attributes([$taxonomy=>'cotton']);
    $variant->set_regular_price('15.00');$variant->set_virtual(true);$variant->set_manage_stock(true);$variant->set_stock_quantity(3);$variant->save();
    WC_Product_Variable::sync($parent->get_id());
    $items=[['product_id'=>$parent->get_id(),'quantity'=>2,'total'=>'20.00','attributes'=>['attribute_'.$taxonomy=>'cotton']]];
    $token=Latse\\QuoteFlow\\Offers::create_items($items,'global-attribute@example.test');
    $offer=Latse\\QuoteFlow\\Offers::find($token);
    if (!str_contains($offer->product_label,'Cotton')) {throw new Exception('Global label missing');}
    $order=Latse\\QuoteFlow\\Offers::accept($token);
    if (current($order->get_items())->get_meta($taxonomy)!=='cotton') {throw new Exception('Global option missing');}
    $items[0]['quantity']=4;
    try {Latse\\QuoteFlow\\Offers::create_items($items,'stock@example.test');throw new Exception('Variant stock not enforced');}
    catch (RuntimeException $e) {}
    $items[0]['quantity']=1;
    $token=Latse\\QuoteFlow\\Offers::create_items($items,'changed@example.test');
    $variant->set_attributes([$taxonomy=>'linen']);$variant->save();
    try {Latse\\QuoteFlow\\Offers::accept($token);echo 'failed';}
    catch (RuntimeException $e) {echo 'passed';}
""") == "passed"
print("Global attributes, variant-managed stock and changed options passed")

assert wp("eval", """
    $parent=new WC_Product_Variable();$parent->set_name('Numeric options fixture');
    $attribute=new WC_Product_Attribute();$attribute->set_name('Slot');$attribute->set_options(['0','1']);$attribute->set_variation(true);
    $parent->set_attributes([$attribute]);$parent->save();
    $variant=new WC_Product_Variation();$variant->set_parent_id($parent->get_id());$variant->set_attributes(['slot'=>'0']);
    $variant->set_virtual(true);$variant->set_regular_price('10.00');$variant->save();WC_Product_Variable::sync($parent->get_id());
    try {
        Latse\\QuoteFlow\\Offers::create_items([
            ['product_id'=>$variant->get_id(),'quantity'=>1,'total'=>'8.00','attributes'=>['attribute_slot'=>'1']]
        ],'numeric@example.test');
        throw new Exception('Fixed zero attribute accepted a different option');
    } catch (RuntimeException $e) {}
    $token=Latse\\QuoteFlow\\Offers::create_items([
        ['product_id'=>$parent->get_id(),'quantity'=>1,'total'=>'8.00','attributes'=>['attribute_slot'=>'0']]
    ],'numeric@example.test');
    $o=Latse\\QuoteFlow\\Offers::accept($token);
    echo current($o->get_items())->get_meta('slot')==='0'?'passed':'failed';
""") == "passed"
print("Numeric zero is preserved as a fixed option")
