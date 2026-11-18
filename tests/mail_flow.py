"""Verify email through the merchant UI using a local capture-only mail transport."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import parse_qs, urlsplit
from support import BASE, COMPOSE, browser, form_where, request, wp


def compose(*args):
    return subprocess.check_output([*COMPOSE, *args], text=True).strip()


def captured():
    text = compose("exec", "-T", "wp", "cat", "/tmp/quoteflow-test-mail.jsonl")
    return [json.loads(line) for line in text.splitlines() if line]


def new_offer():
    product = wp("eval", """
        $p=new WC_Product_Simple(); $p->set_name('Email fixture & details');
        $p->set_regular_price('15.00'); $p->set_virtual(true);
        echo $p->save();
    """)
    page = request(admin, admin_url)[2]
    create = form_where(page, "product_id", "")["fields"] | {
        "product_id": product, "quantity": "2", "email": "email-test@example.test", "total": "19.95",
    }
    html = request(admin, admin_url, create)[2]
    send = form_where(html, "qf_action", "email")["fields"]
    return send, send["offer_token"]


def email_state(token):
    return json.loads(wp("eval", f"""
        $offer=Latse\\QuoteFlow\\Offers::find('{token}');
        echo wp_json_encode(['state'=>$offer->email_state,'attempts'=>(int)$offer->email_attempts,'accepted'=>$offer->email_accepted_at]);
    """))


def set_offer(token, fields):
    # Test tokens are generated locally and checked before embedding into the fixture.
    assert re.fullmatch("[a-f0-9]{64}", token)
    values = json.dumps(fields)
    wp("eval", f"""global $wpdb; $o=Latse\\QuoteFlow\\Offers::find('{token}');
        $wpdb->update($wpdb->prefix.'qf_offers',json_decode('{values}',true),['id'=>$o->id]);""")


admin_url = BASE + "/wp-admin/admin.php?page=quoteflow"
admin = browser()
request(admin, BASE + "/wp-login.php")
assert request(admin, BASE + "/wp-login.php", {
    "log": "quote-m0", "pwd": "quoteflow-m0-local-only", "testcookie": "1",
})[0] == 302

fixture = Path(__file__).parent / "fixtures" / "capture-mail.php"
target = "/var/www/html/wp-content/mu-plugins/qf-test-mail.php"
compose("exec", "-T", "wp", "mkdir", "-p", "/var/www/html/wp-content/mu-plugins")
# Refuse to replace an existing test fixture from another run.
exists = subprocess.run([*COMPOSE, "exec", "-T", "wp", "test", "-e", target]).returncode == 0
assert not exists, "A mail capture fixture is already installed"
compose("cp", str(fixture), "wp:" + target)
try:
    wp("option", "update", "qf_test_mail_mode", "accept")
    compose("exec", "-T", "--user", "www-data", "wp", "php", "-r",
            "file_put_contents('/tmp/quoteflow-test-mail.jsonl', '');")
    send, token = new_offer()
    assert captured() == [] and email_state(token)["state"] == "idle"
    assert request(browser(), admin_url, send)[0] in (302, 403)
    denied = request(admin, admin_url, send | {"_wpnonce": "invalid"})
    assert denied[0] == 403, (denied[0], re.findall(r"<p>(.*?)</p>", denied[2])[:2])

    assert captured() == []
    with ThreadPoolExecutor(2) as pool:
        responses = list(pool.map(lambda _: request(admin, admin_url, send), range(2)))
    assert all(response[0] == 200 and "mail service accepted" in response[2] for response in responses)
    assert len(captured()) == 1
    mail = captured()[0]
    assert mail["to"] == "email-test@example.test"
    assert "19.95" in mail["message"] and "Email fixture &amp; details" in mail["message"]
    assert "Valid until" in mail["message"] and "View and accept your quote" in mail["message"]
    link = unescape(re.search(r'href="([^"]+qf_offer=[a-f0-9]+)"', mail["message"])[1])
    assert parse_qs(urlsplit(link).query)["qf_offer"] == [token]
    assert request(browser(), link)[0] == 200
    assert request(admin, admin_url, send)[0] == 200 and len(captured()) == 1
    assert email_state(token) == {"state": "accepted", "attempts": 1, "accepted": email_state(token)["accepted"]}
    assert email_state(token)["accepted"]

    # A known failure can be retried, while replaying its old form cannot trigger another attempt.
    failed_send, failed_token = new_offer()
    wp("option", "update", "qf_test_mail_mode", "fail")
    html = request(admin, admin_url, failed_send)[2]
    assert "mail service did not accept" in html and email_state(failed_token)["state"] == "failed"
    assert request(admin, admin_url, failed_send)[0] == 200 and len(captured()) == 2
    retry = form_where(html, "qf_action", "email")["fields"]
    wp("option", "update", "qf_test_mail_mode", "accept")
    assert "mail service accepted" in request(admin, admin_url, retry)[2]
    assert email_state(failed_token)["attempts"] == 2 and len(captured()) == 3

    # An ambiguous result requires a new form and explicit confirmation after checking mail logs.
    uncertain_send, uncertain_token = new_offer()
    wp("option", "update", "qf_test_mail_mode", "throw")
    html = request(admin, admin_url, uncertain_send)[2]
    assert "email result is unknown" in html and email_state(uncertain_token)["state"] == "sending"
    retry = form_where(html, "qf_action", "email")["fields"]
    retry.pop("confirm_retry", None)
    assert "previous email result is unknown" in request(admin, admin_url, retry)[2]
    assert len(captured()) == 4
    wp("option", "update", "qf_test_mail_mode", "accept")
    with ThreadPoolExecutor(2) as pool:
        responses = list(pool.map(lambda _: request(admin, admin_url, retry | {"confirm_retry": "1"}), range(2)))
    assert all("mail service accepted" in response[2] for response in responses)
    assert len(captured()) == 5 and email_state(uncertain_token)["attempts"] == 2

    # Expired offers and offers already converted to orders cannot trigger a mail send.
    blocked_send, blocked_token = new_offer()
    set_offer(blocked_token, {"expires_at": "2000-01-01 00:00:00"})
    assert "Only an active offer" in request(admin, admin_url, blocked_send)[2]
    set_offer(blocked_token, {"expires_at": "2099-01-01 00:00:00", "state": "converted"})
    assert "Only an active offer" in request(admin, admin_url, blocked_send)[2]
    assert len(captured()) == 5

    # A multiline request can recover its private link and email the same itemized offer.
    request_id = wp("eval", """
        global $wpdb; $items=[];
        foreach (['First email line', 'Second email line'] as $label) {
            $p=new WC_Product_Simple(); $p->set_name($label); $p->set_regular_price('10.00');
            $p->set_virtual(true); $items[]=['product_id'=>$p->save(),'quantity'=>2];
        }
        $submission=wp_generate_uuid4();
        Latse\\QuoteFlow\\Requests::submit_items($submission,$items,'multimail@example.test','',wp_generate_uuid4());
        $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s",$submission));
        Latse\\QuoteFlow\\Requests::respond((int)$id,['12.50','4.25']); echo $id;
    """)
    page = request(admin, admin_url)[2]
    recover = form_where(page, "request_id", request_id)["fields"]
    selected = request(admin, admin_url, recover)[2]
    send = form_where(selected, "qf_action", "email")["fields"]
    assert "mail service accepted" in request(admin, admin_url, send)[2]
    mail = captured()[-1]
    assert len(captured()) == 6 and mail["to"] == "multimail@example.test"
    assert all(value in mail["message"] for value in ("First email line", "Second email line", "12.50", "4.25", "16.75"))
    print("Email UI, permissions, local capture, duplicate prevention, failure retry, ambiguous result recovery and multiline mail passed")
finally:
    compose("exec", "-T", "wp", "rm", "-f", target)
    wp("option", "delete", "qf_test_mail_mode")
