"""Exercise requests through public and admin forms in the disposable M0 store."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import json
import re
from urllib.parse import parse_qs, urlsplit
from support import BASE, Forms, browser, form_where, request, request_row, reset_request_throttles, wp

admin = browser()
request(admin, BASE + "/wp-login.php")
assert request(admin, BASE + "/wp-login.php", {
    "log": "quote-m0", "pwd": "quoteflow-m0-local-only", "testcookie": "1",
})[0] == 302
admin_url = BASE + "/wp-admin/admin.php?page=quoteflow"

# Repeated runs reset only throttle keys for this test's fictional contact.
reset_request_throttles()
assert wp("option", "get", "qf_schema_version") == "1.0.0-rc.1"

wp("option", "update", "woocommerce_coming_soon", "no")

for storage in ("enable", "disable"):
    wp("wc", "hpos", "sync")
    wp("wc", "hpos", storage)
    wp("option", "update", "woocommerce_custom_orders_table_data_sync_enabled", "no")
    product_id = wp("eval", """
        $p = new WC_Product_Simple();
        $p->set_name('QuoteFlow request fixture');
        $p->set_regular_price('29.90');
        $p->set_virtual(true);
        $p->set_manage_stock(true);
        $p->set_stock_quantity(8);
        echo $p->save();
    """)
    client = browser()
    status, headers, html = request(client, BASE + "/?post_type=product&p=" + product_id)
    assert status == 200 and "no-cache" in headers["Cache-Control"]
    public = form_where(html, "action", "qf_request")
    fields = public["fields"] | {"email": "flow@example.test", "quantity": "2", "note": "Please quote two units"}
    submission = fields["submission_id"]
    assert request_row(submission) == []
    assert request(client, public["url"], fields | {"qf_request_nonce": "invalid"})[0] == 403
    assert request(client, public["url"], fields | {"website": "spam.example"})[0] == 422
    assert request(client, public["url"], fields | {"quantity": "1.5"})[0] == 422
    assert request(client, public["url"], fields | {"email": "invalid"})[0] == 422
    assert request(client, public["url"], fields | {"note": "x" * 2001})[0] == 422
    with ThreadPoolExecutor(2) as pool:
        statuses = list(pool.map(lambda _: request(browser(), public["url"], fields)[0], range(2)))
    assert statuses == [303, 303], statuses
    assert request(client, public["url"], fields)[0] == 303
    assert request(client, public["url"], fields | {"quantity": "3"})[0] == 422
    rows = request_row(submission)
    assert len(rows) == 1 and rows[0]["quantity"] == "2"
    request_id = rows[0]["id"]

    status, _, html = request(admin, admin_url)
    assert status == 200 and "flow@example.test" in html and "Please quote two units" in html
    reply = form_where(html, "request_id", request_id)["fields"] | {"total": "39.80"}
    assert request(client, admin_url, reply)[0] in (302, 403)
    with ThreadPoolExecutor(2) as pool:
        replies = list(pool.map(lambda _: request(admin, admin_url, reply), range(2)))
    assert all(response[0] == 200 for response in replies)
    reply_html = replies[0][2]
    link = unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', reply_html)[1])
    assert re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', replies[1][2])[1] == re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', reply_html)[1]
    _, _, replay_html = request(admin, admin_url, reply)
    assert link in unescape(replay_html)
    linked = json.loads(wp("eval", f"""global $wpdb;
        echo wp_json_encode($wpdb->get_results("SELECT * FROM {{$wpdb->prefix}}qf_offers WHERE request_id = {int(request_id)}"));
    """))
    assert len(linked) == 1 and linked[0]["email"] == "flow@example.test"
    guest = browser()
    offer_html = request(guest, link)[2]
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', offer_html)[1]
    status, headers, _ = request(guest, link, {"qf_nonce": nonce})
    assert status == 303
    payment_link = headers["Location"]
    status, headers, _ = request(guest, link, {"qf_nonce": nonce})
    assert status == 303 and headers["Location"] == payment_link
    payment_html = request(guest, payment_link)[2]
    payment = next(form["fields"] for form in Forms(payment_html).forms if "woocommerce-pay-nonce" in form["fields"])
    payment["payment_method"] = "bacs"
    status, headers, _ = request(guest, payment_link, payment)
    assert status in (302, 303) and "order-received" in headers["Location"], status
    token = parse_qs(urlsplit(link).query)["qf_offer"][0]
    order_status = wp("eval", f"""$offer=Latse\\QuoteFlow\\Offers::find('{token}');
        $order=wc_get_order($offer->order_id);
        if ($order->get_status() !== 'on-hold') {{ throw new Exception('Expected offline payment'); }}
        $order->payment_complete('qf-request-simulation');
        echo wc_get_order($order->get_id())->is_paid() ? 'paid' : 'unpaid';
    """)
    assert order_status == "paid"
    print(f"{storage}: public request → admin offer → guest acceptance → Woo payment passed")

# Domain rate limit with an isolated address. Replays do not consume extra slots.
assert wp("eval", """
    $source = wp_generate_uuid4();
    $product = wc_get_products(['name'=>'QuoteFlow request fixture', 'limit'=>1])[0];
    for ($i=0; $i<5; $i++) {
        Latse\\QuoteFlow\\Requests::submit(wp_generate_uuid4(), $product->get_id(), 1, 'rate@example.test', '', $source);
    }
    try {
        Latse\\QuoteFlow\\Requests::submit(wp_generate_uuid4(), $product->get_id(), 1, 'rate@example.test', '', $source);
        echo 'failed';
    } catch (RuntimeException $e) { echo $e->getCode() === 429 ? 'limited' : 'failed'; }
""") == "limited"
print("Validation, duplicate submission, replay conflict, permissions and throttling passed")
