"""Verify cart requests, line prices, stock checks and compatibility with older records."""
import atexit
from concurrent.futures import ThreadPoolExecutor
from html import unescape
from decimal import Decimal
import json
import re
from urllib.parse import parse_qs, urlsplit
from support import BASE, Forms, browser, form_where, request, request_row, reset_request_throttles, wp


def offer_link(html):
    return unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', html)[1])


def order_lines(token):
    result = json.loads(wp("eval", f"""
        $offer = Latse\\QuoteFlow\\Offers::find('{token}');
        $order = wc_get_order($offer->order_id);
        $lines = [];
        foreach ($order->get_items() as $item) {{
            $lines[] = ['product' => $item->get_product_id(), 'qty' => $item->get_quantity(), 'total' => $item->get_total()];
        }}
        echo wp_json_encode(['id' => $order->get_id(), 'total' => $order->get_total(), 'lines' => $lines, 'state' => $order->get_status()]);
    """))
    for line in result["lines"]:
        line["total"] = str(Decimal(line["total"]).quantize(Decimal("0.01")))
    return result


admin = browser()
request(admin, BASE + "/wp-login.php")
assert request(admin, BASE + "/wp-login.php", {
    "log": "quote-m0", "pwd": "quoteflow-m0-local-only", "testcookie": "1",
})[0] == 302
admin_url = BASE + "/wp-admin/admin.php?page=quoteflow"
cart_url = wp("eval", "echo wc_get_cart_url();")
cart_page = wp("option", "get", "woocommerce_cart_page_id")
cart_content = wp("post", "get", cart_page, "--field=post_content")
atexit.register(wp, "post", "update", cart_page, "--post_content=" + cart_content)
wp("option", "update", "woocommerce_coming_soon", "no")
reset_request_throttles()
for storage in ("enable", "disable"):
    if storage == "disable":
        wp("post", "update", cart_page, "--post_content=[woocommerce_cart]")
    wp("wc", "hpos", "sync")
    wp("wc", "hpos", storage)
    wp("option", "update", "woocommerce_custom_orders_table_data_sync_enabled", "no")
    products = json.loads(wp("eval", """
        $ids = [];
        foreach (['Multiline first', 'Multiline second'] as $name) {
            $product = new WC_Product_Simple();
            $product->set_name($name);
            $product->set_regular_price('25.00');
            $product->set_virtual(true);
            $product->set_manage_stock(true);
            $product->set_stock_quantity(10);
            $ids[] = $product->save();
        }
        echo wp_json_encode($ids);
    """))
    guest = browser()
    for product_id, quantity in zip(products, [2, 3]):
        status, _, _ = request(guest, BASE + f"/?add-to-cart={product_id}&quantity={quantity}")
        assert status in (200, 302, 303)
    status, headers, cart = request(guest, cart_url)
    assert status == 200 and "no-cache" in headers["Cache-Control"]
    public = form_where(cart, "action", "qf_request")
    fields = public["fields"] | {"email": "multi@example.test", "note": "One offer for both products"}
    assert fields["items[0][quantity]"] == "2" and fields["items[1][quantity]"] == "3"
    submission = fields["submission_id"]
    assert request_row(submission) == []
    assert request(guest, public["url"], fields | {"items[1][quantity]": "1.5"})[0] == 422
    assert request(guest, public["url"], fields | {"items[1][product_id]": str(products[0])})[0] == 403
    with ThreadPoolExecutor(2) as pool:
        statuses = list(pool.map(lambda _: request(browser(), public["url"], fields)[0], range(2)))
    assert statuses == [303, 303], statuses
    rows = request_row(submission)
    assert len(rows) == 1 and len(json.loads(rows[0]["items_json"])) == 2
    request_id = rows[0]["id"]
    html = request(admin, admin_url)[2]
    reply = form_where(html, "request_id", request_id)["fields"] | {"totals[0]": "19.95", "totals[1]": "9.99"}
    # Missing and invalid line prices leave the request available for a valid offer.
    missing = {key: value for key, value in reply.items() if key != "totals[1]"}
    assert 'id="qf-offer-link"' not in request(admin, admin_url, missing)[2]
    assert 'id="qf-offer-link"' not in request(admin, admin_url, reply | {"totals[1]": "-1.00"})[2]
    with ThreadPoolExecutor(2) as pool:
        replies = list(pool.map(lambda _: request(admin, admin_url, reply)[2], range(2)))
    link = offer_link(replies[0])
    assert offer_link(replies[1]) == link
    token = parse_qs(urlsplit(link).query)["qf_offer"][0]
    offer = request(guest, link)[2]
    assert all(label in offer for label in ("Multiline first", "Multiline second", "19.95", "9.99", "29.94"))
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', offer)[1]
    # A product on the second line becoming unavailable prevents any conversion.
    wp("eval", f"$p=wc_get_product({products[1]}); $p->set_stock_quantity(1); $p->save();")
    assert request(guest, link, {"qf_nonce": nonce})[0] == 409
    assert wp("eval", f"echo Latse\\QuoteFlow\\Offers::find('{token}')->state;") == "offered"
    wp("eval", f"$p=wc_get_product({products[1]}); $p->set_stock_quantity(10); $p->save();")
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: request(browser(), link, {"qf_nonce": nonce}), range(2)))
    assert results[0][0] == results[1][0] == 303
    payment_link = results[0][1]["Location"]
    assert results[1][1]["Location"] == payment_link
    order = order_lines(token)
    assert order["total"] == "29.94" and order["lines"] == [
        {"product": products[0], "qty": 2, "total": "19.95"},
        {"product": products[1], "qty": 3, "total": "9.99"},
    ], order
    # Recover both lines after an interrupted link operation.
    wp("eval", f"""global $wpdb; $wpdb->update($wpdb->prefix.'qf_offers',
        ['state'=>'creating', 'order_id'=>null], ['id'=>Latse\\QuoteFlow\\Offers::find('{token}')->id]);""")
    assert request(guest, link, {"qf_nonce": nonce})[1]["Location"] == payment_link
    assert order_lines(token)["id"] == order["id"]
    payment_page = request(guest, payment_link)[2]
    payment = next(form["fields"] for form in Forms(payment_page).forms if "woocommerce-pay-nonce" in form["fields"])
    payment["payment_method"] = "bacs"
    # Repricing lines while preserving the grand total must still block payment.
    wp("eval", f"""$o=wc_get_order({order['id']}); $items=array_values($o->get_items());
        $items[0]->set_total('18.95'); $items[0]->save(); $items[1]->set_total('10.99'); $items[1]->save(); $o->calculate_totals(false);""")
    assert request(guest, payment_link, payment)[0] == 200
    assert order_lines(token)["state"] == "pending"
    wp("eval", f"""$o=wc_get_order({order['id']}); $items=array_values($o->get_items());
        $items[0]->set_total('19.95'); $items[0]->save(); $items[1]->set_total('9.99'); $items[1]->save(); $o->calculate_totals(false);""")
    payment_page = request(guest, payment_link)[2]
    payment = next(form["fields"] for form in Forms(payment_page).forms if "woocommerce-pay-nonce" in form["fields"])
    payment["payment_method"] = "bacs"
    status, headers, _ = request(guest, payment_link, payment)
    assert status in (302, 303) and "order-received" in headers["Location"]
    assert order_lines(token)["state"] == "on-hold"
    print(f"{storage}: cart request, per-line prices, concurrent reply/acceptance, recovery, stock and payment guards passed")

# Older records contain a single line in header columns with no items_json.
assert wp("eval", """
    global $wpdb;
    $p=new WC_Product_Simple(); $p->set_name('Legacy snapshot');
    $p->set_regular_price('2.00'); $p->set_virtual(true); $p->save();
    $token=Latse\\QuoteFlow\\Offers::create($p->get_id(), 1, '1.25', 'legacy@example.test');
    $offer=Latse\\QuoteFlow\\Offers::find($token);
    $wpdb->update($wpdb->prefix.'qf_offers', ['items_json'=>null], ['id'=>$offer->id]);
    $order=Latse\\QuoteFlow\\Offers::accept($token);
    echo $order->get_total() === '1.25' && count($order->get_items()) === 1 ? 'passed' : 'failed';
""") == "passed"

assert wp("eval", """
    $p=wc_get_products(['name'=>'Legacy snapshot','limit'=>1])[0];
    try {
        Latse\\QuoteFlow\\Offers::create_items([
            ['product_id'=>$p->get_id(),'quantity'=>1,'total'=>'999999999999.99'],
            ['product_id'=>$p->get_id(),'quantity'=>1,'total'=>'1.00'],
        ],'limit@example.test');
        echo 'failed';
    } catch (RuntimeException $e) { echo 'rejected'; }
""") == "rejected"
print("Legacy single-line conversion and duplicate product rejection passed")

assert wp("eval", """
    global $wpdb;
    $products = wc_get_products(['limit'=>2, 'status'=>'publish', 'virtual'=>true]);
    $items = [
        ['product_id'=>$products[0]->get_id(),'quantity'=>1,'total'=>'0.10'],
        ['product_id'=>$products[1]->get_id(),'quantity'=>1,'total'=>'0.20'],
    ];
    $token=Latse\\QuoteFlow\\Offers::create_items($items, 'money@example.test');
    if (Latse\\QuoteFlow\\Offers::find($token)->total !== '0.300000') {throw new Exception('Inexact sum');}
    $items[0]['total']='999999999999.99'; $items[1]['total']='0.01';
    try {
        Latse\\QuoteFlow\\Offers::create_items($items, 'limit@example.test');
        throw new Exception('Oversized offer was saved');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'too large')) {throw $e;}
    }
    $submission=wp_generate_uuid4();
    Latse\\QuoteFlow\\Requests::submit($submission, $products[0]->get_id(), 1, 'legacy-request@example.test', '', wp_generate_uuid4());
    $wpdb->update($wpdb->prefix.'qf_requests', ['items_json'=>null], ['submission_id'=>$submission]);
    $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s",$submission));
    $token=Latse\\QuoteFlow\\Requests::respond((int)$id,'1.50');
    $order=Latse\\QuoteFlow\\Offers::accept($token);
    echo $order->get_total() === '1.50' ? 'passed' : 'failed';
""") == "passed"
print("Exact decimal sum, amount ceiling and legacy request response passed")
