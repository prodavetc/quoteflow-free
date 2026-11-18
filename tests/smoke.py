"""Run against the disposable QuoteFlow M0 Compose project."""
from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
from http.cookiejar import CookieJar
from pathlib import Path
import re
import subprocess
import sys
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

BASE = "http://127.0.0.1:18972"
COMPOSE = ["docker", "compose", "-f", str(Path(sys.argv[1]).resolve())]


def wp(*args):
    result = subprocess.run(
        [*COMPOSE, "exec", "-T", "--user", "www-data", "wp", "php", "/downloads/wp-cli.phar", *args],
        check=True, text=True, stdout=subprocess.PIPE,
    )
    return result.stdout.strip()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def request(url, form=None, browser=None):
    body = None if form is None else urlencode(form).encode()
    try:
        result = (browser or build_opener(NoRedirect)).open(Request(url, data=body), timeout=15)
    except HTTPError as error:
        result = error
    with result:
        return result.code, result.headers.get("Location"), result.read().decode()


def table_eval(code):
    return wp("eval", 'global $wpdb; $table = $wpdb->prefix . "qf_offers"; ' + code)


def accept(link, nonce):
    status, location, _ = request(link, {"qf_nonce": nonce})
    return status, location


wp("option", "update", "woocommerce_coming_soon", "no")

for mode in ("enable", "disable"):
    wp("wc", "hpos", "sync")
    wp("wc", "hpos", mode)
    wp("option", "update", "woocommerce_custom_orders_table_data_sync_enabled", "no")
    token = wp("eval", """
        $p = new WC_Product_Simple();
        $p->set_name('QuoteFlow smoke fixture');
        $p->set_regular_price('29.90');
        $p->set_virtual(true);
        $p->set_manage_stock(true);
        $p->set_stock_quantity(4);
        $p->save();
        echo Latse\\QuoteFlow\\Offers::create($p->get_id(), 2, '39.80', 'beta@example.test');
    """)
    where = '["token_hash" => "' + sha256(token.encode()).hexdigest() + '"]'
    link = BASE + "/?qf_offer=" + token
    status, _, page = request(link)
    assert status == 200 and "39.80" in page, mode
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', page)[1]
    assert accept(link, "invalid") == (403, None), mode
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: accept(link, nonce), range(2)))
    status, location = results[0]
    assert status == 303 and location and results[0] == results[1], results
    assert accept(link, nonce) == results[0], mode
    payment_browser = build_opener(NoRedirect, HTTPCookieProcessor(CookieJar()))
    payment_page = request(location, browser=payment_browser)[2]
    payment_nonce = re.search(r'name="woocommerce-pay-nonce" value="([^"]+)"', payment_page)[1]
    payment_form = {"woocommerce-pay-nonce": payment_nonce, "woocommerce_pay": "1", "payment_method": "bacs"}
    parts = urlsplit(location)
    order_path = re.search(r"/order-pay/(\d+)", parts.path)
    order_id = int(order_path[1] if order_path else parse_qs(parts.query)["order-pay"][0])
    order_eval = f"$o=wc_get_order({order_id}); "
    assert wp("eval", order_eval + 'echo $o->get_total()."|".$o->get_customer_id();') == "39.80|0"
    wp("eval", order_eval + '$p=current($o->get_items())->get_product(); $p->set_stock_quantity(1); $p->save();')
    assert request(location, payment_form, payment_browser)[0] == 200, mode
    assert wp("eval", order_eval + 'echo $o->get_status();') == "pending", mode
    wp("eval", order_eval + '$p=current($o->get_items())->get_product(); $p->set_stock_quantity(4); $p->save();')
    # Simulate interruption after Woo saved the order and before QuoteFlow linked it.
    table_eval(f'$wpdb->update($table, ["state" => "creating", "order_id" => null], {where});')
    assert accept(link, nonce) == results[0], mode
    payment_page = request(location, browser=payment_browser)[2]
    payment_form["woocommerce-pay-nonce"] = re.search(r'name="woocommerce-pay-nonce" value="([^"]+)"', payment_page)[1]
    paid_status, paid_location, _ = request(location, payment_form, payment_browser)
    assert paid_status in (302, 303) and paid_location and "order-received" in paid_location, (mode, paid_status)
    assert wp("eval", order_eval + 'echo $o->get_status();') == "on-hold", mode
    # An unresolved attempt must remain blocked instead of creating a second order.
    table_eval(f'$wpdb->update($table, ["state" => "creating", "order_id" => null, "conversion_id" => wp_generate_uuid4()], {where});')
    assert accept(link, nonce) == (409, None), mode
    table_eval(f'$wpdb->update($table, ["expires_at" => "2000-01-01 00:00:00"], {where});')
    assert request(link)[0] == 404, mode
    print(f"{mode}: offer, concurrent acceptance, replay, recovery, stock guard, offline payment, ambiguous attempt and expiry passed")
