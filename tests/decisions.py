"""Verify buyer decline and merchant cancellation against concurrent acceptance."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import json
import re
from urllib.parse import parse_qs, urlsplit
from support import BASE, browser, form_where, request, wp

admin_url = BASE + "/wp-admin/admin.php?page=quoteflow"
admin = browser()
request(admin, BASE + "/wp-login.php")
assert request(admin, BASE + "/wp-login.php", {
    "log": "quote-m0", "pwd": "quoteflow-m0-local-only", "testcookie": "1",
})[0] == 302


def fixture():
    product = wp("eval", """
        $p=new WC_Product_Simple(); $p->set_name('Decision fixture');
        $p->set_regular_price('20.00'); $p->set_virtual(true);
        echo $p->save();
    """)
    page = request(admin, admin_url)[2]
    fields = form_where(page, "product_id", "")["fields"] | {
        "product_id": product, "quantity": "1", "total": "15.00", "email": "decision@example.test",
    }
    page = request(admin, admin_url, fields)[2]
    link = unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', page)[1])
    cancel = form_where(page, "qf_action", "cancel")["fields"]
    cancel.pop("confirm_cancel", None)
    token = parse_qs(urlsplit(link).query)["qf_offer"][0]
    public = request(browser(), link)[2]
    nonce = re.search(r'name="qf_nonce" value="([^"]+)"', public)[1]
    return link, token, {"qf_nonce": nonce}, cancel


def state(token):
    assert re.fullmatch("[a-f0-9]{64}", token)
    return json.loads(wp("eval", f"""
        $o=Latse\\QuoteFlow\\Offers::find('{token}');
        echo wp_json_encode(['state'=>$o->state,'order'=>$o->order_id,
            'closed_at'=>$o->closed_at,'closed_by'=>$o->closed_by,'accepted_at'=>$o->accepted_at]);
    """))


def send_is_blocked(token):
    # Short-circuit the transport even if a regression accidentally calls wp_mail.
    assert wp("eval", f"""
        add_filter('pre_wp_mail',static function(){{throw new LogicException('Transport must not run');}});
        try {{
            Latse\\QuoteFlow\\Offer_Email::send('{token}',0);
            echo 'failed';
        }} catch (RuntimeException $e) {{echo str_contains($e->getMessage(),'Only an active offer')?'blocked':'failed';}}
    """) == "blocked"


for storage in ("enable", "disable"):
    wp("wc", "hpos", "sync")
    wp("wc", "hpos", storage)
    wp("option", "update", "woocommerce_custom_orders_table_data_sync_enabled", "no")

    link, token, decision, cancel = fixture()
    assert state(token)["state"] == "offered"
    # GET and invalid POST cannot make a decision.
    assert request(browser(), link + "&decision=decline")[0] == 200
    assert state(token)["state"] == "offered"
    assert request(browser(), link, decision | {"qf_nonce": "invalid", "decision": "decline"})[0] == 403
    assert request(browser(), link, decision | {"decision": "other"})[0] == 422
    assert request(browser(), link, decision | {"decision[0]": "decline"})[0] == 422
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: request(browser(), link, decision | {"decision": "decline"}), range(2)))
    assert all(result[0] == 200 and "You declined this offer" in result[2] for result in results)
    closed = state(token)
    assert closed["state"] == "rejected" and closed["closed_at"] and closed["closed_by"] == "0"
    assert closed["order"] is None and closed["accepted_at"] is None
    assert 'name="decision"' not in request(browser(), link)[2]
    assert request(browser(), link, decision | {"decision": "accept"})[0] == 409
    assert state(token) == closed
    send_is_blocked(token)

    link, token, decision, cancel = fixture()
    assert request(browser(), admin_url, cancel | {"confirm_cancel": "1"})[0] in (302, 403)
    assert request(admin, admin_url, cancel | {"_wpnonce": "invalid", "confirm_cancel": "1"})[0] == 403
    assert "Confirm that you want to cancel" in request(admin, admin_url, cancel)[2]
    assert state(token)["state"] == "offered"
    with ThreadPoolExecutor(2) as pool:
        results = list(pool.map(lambda _: request(admin, admin_url, cancel | {"confirm_cancel": "1"}), range(2)))
    assert all(result[0] == 200 and "The offer was canceled" in result[2] for result in results)
    closed = state(token)
    assert closed["state"] == "canceled" and int(closed["closed_by"]) > 0 and closed["closed_at"]
    assert closed["order"] is None and closed["accepted_at"] is None
    public = request(browser(), link)[2]
    assert "store canceled this offer" in public and 'name="decision"' not in public
    assert request(browser(), link, decision | {"decision": "accept"})[0] == 409
    send_is_blocked(token)

    # Acceptance racing with buyer decline has exactly one final outcome.
    link, token, decision, cancel = fixture()
    with ThreadPoolExecutor(2) as pool:
        accept = pool.submit(request, browser(), link, decision | {"decision": "accept"})
        decline = pool.submit(request, browser(), link, decision | {"decision": "decline"})
        accept_result, decline_result = accept.result(), decline.result()
    outcome = state(token)
    assert outcome["state"] in ("converted", "rejected")
    if outcome["state"] == "converted":
        assert accept_result[0] == 303 and decline_result[0] == 409 and outcome["order"]
        assert outcome["closed_at"] is None
    else:
        assert accept_result[0] == 409 and decline_result[0] == 200 and outcome["order"] is None

    # Acceptance racing with merchant cancellation preserves the winning state.
    link, token, decision, cancel = fixture()
    with ThreadPoolExecutor(2) as pool:
        accept = pool.submit(request, browser(), link, decision | {"decision": "accept"})
        cancel_result = pool.submit(request, admin, admin_url, cancel | {"confirm_cancel": "1"})
        accept_result, cancel_response = accept.result(), cancel_result.result()
    outcome = state(token)
    if outcome["state"] == "converted":
        assert accept_result[0] == 303 and outcome["order"] and "already has an order" in cancel_response[2]
    else:
        assert outcome["state"] == "canceled" and outcome["order"] is None and accept_result[0] == 409

    # A converted offer always directs cancellation to WooCommerce order management.
    link, token, decision, cancel = fixture()
    assert request(browser(), link, decision | {"decision": "accept"})[0] == 303
    before = state(token)
    assert "already has an order" in request(admin, admin_url, cancel | {"confirm_cancel": "1"})[2]
    assert request(browser(), link, decision | {"decision": "decline"})[0] == 409
    assert state(token) == before

    # An incomplete conversion remains available for order reconciliation.
    link, token, decision, cancel = fixture()
    wp("eval", f"""global $wpdb;$o=Latse\\QuoteFlow\\Offers::find('{token}');
        $wpdb->update($wpdb->prefix.'qf_offers',['state'=>'creating'],['id'=>$o->id]);""")
    assert "conversion in progress" in request(admin, admin_url, cancel | {"confirm_cancel": "1"})[2]
    assert request(browser(), link, decision | {"decision": "decline"})[0] == 409
    assert state(token)["state"] == "creating"

    print(f"{storage}: decline, cancellation, authorization, repeated decisions, email blocking and acceptance races passed")
