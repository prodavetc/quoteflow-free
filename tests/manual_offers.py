"""Reopen manual offers without changing their token, email or decision state in M0."""
from html import unescape
from concurrent.futures import ThreadPoolExecutor
import json
import re
import subprocess
from urllib.parse import parse_qs, urlsplit
from support import BASE, COMPOSE, Forms, browser, form_where, request, wp

admin_url = BASE + '/wp-admin/admin.php?page=quoteflow'
manual_url = admin_url + '&qf_view=manual'
admin = browser()
request(admin, BASE + '/wp-login.php')
assert request(admin, BASE + '/wp-login.php', {
    'log': 'quote-m0', 'pwd': 'quoteflow-m0-local-only', 'testcookie': '1',
})[0] == 302
mu = '/var/www/html/wp-content/mu-plugins/qf-manual-test-mail.php'
log = '/tmp/qf-manual-mail.jsonl'
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'test', '!', '-e', mu], check=True)
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'tee', mu], input="""<?php
add_filter('pre_wp_mail', static function($result, $mail) {
    if (false === file_put_contents('/tmp/qf-manual-mail.jsonl', wp_json_encode($mail).PHP_EOL, FILE_APPEND|LOCK_EX)) {
        throw new RuntimeException('Could not capture test mail');
    }
    return true;
}, 10, 2);
""", text=True, stdout=subprocess.DEVNULL, check=True)


def link(html):
    return unescape(re.search(r'id="qf-offer-link"[^>]+value="([^"]+)"', html)[1])


def row(token):
    assert re.fullmatch('[a-f0-9]{64}', token)
    return json.loads(wp('eval', f"""global $wpdb;$o=Latse\\QuoteFlow\\Offers::find('{token}');
        echo wp_json_encode($o);"""))


def reopened(offer_id):
    html = request(admin, manual_url)[2]
    view = form_where(html, 'offer_id', str(offer_id))['fields']
    response = request(admin, manual_url, view)
    assert response[0] == 200
    return response[2], view


try:
    for storage in ('enable', 'disable'):
        wp('wc', 'hpos', 'sync')
        wp('wc', 'hpos', storage)
        wp('option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no')
        fixture = json.loads(wp('eval', """
            $p=new WC_Product_Simple();$p->set_name('Manual recovery fixture');$p->set_regular_price('20');$p->set_virtual(true);$p->save();
            $tokens=[];for($i=0;$i<21;$i++){$tokens[]=Latse\\QuoteFlow\\Offers::create($p->get_id(),1,'15','manual@example.test');}
            $uuid=wp_generate_uuid4();Latse\\QuoteFlow\\Requests::submit($uuid,$p->get_id(),1,'manual-request@example.test','Manual request fixture',$uuid);
            global $wpdb;$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s",$uuid));
            $requested=Latse\\QuoteFlow\\Requests::respond((int)$id,'15');
            echo wp_json_encode(['product'=>$p->get_id(),'tokens'=>$tokens,'requested'=>$requested]);
        """))
        subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'truncate', '-s', '0', log], check=True)
        subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'chown', 'www-data:www-data', log], check=True)
        listing = request(admin, manual_url)[2]
        ids = [int(n) for n in re.findall(r'name="offer_id" value="([0-9]+)"', listing)]
        assert len(ids) == 20 and ids == sorted(ids, reverse=True)
        assert 'qf_manual_page=2' in listing and 'qf_view=manual' in listing
        requested_id = int(row(fixture['requested'])['id'])
        assert requested_id not in ids
        for token in fixture['tokens']:
            assert token not in listing
        first_page = ids
        second = request(admin, manual_url + '&qf_manual_page=2')[2]
        second_ids = [int(n) for n in re.findall(r'name="offer_id" value="([0-9]+)"', second)]
        assert set(first_page).isdisjoint(second_ids)
        assert int(row(fixture['tokens'][0])['id']) in second_ids
        # Create through the actual merchant form, close the screen, and recover it later.
        creation = form_where(listing, 'product_id', '')['fields'] | {
            'product_id': fixture['product'], 'quantity': '2', 'total': '29.00', 'email': 'manual-ui@example.test',
        }
        count_before_creation = int(wp('eval', 'echo Latse\\QuoteFlow\\Offers::manual_count();'))
        # A double click claims one durable form identifier, including lost-response retries.
        with ThreadPoolExecutor(2) as pool:
            responses = list(pool.map(lambda _: request(admin, manual_url, creation), range(2)))
        assert all(response[0] == 200 for response in responses)
        created = responses[0][2]
        original_link = link(created)
        assert link(responses[1][2]) == original_link
        assert int(wp('eval', 'echo Latse\\QuoteFlow\\Offers::manual_count();')) == count_before_creation + 1
        assert request(admin, manual_url, creation | {'_wpnonce': 'invalid'})[0] == 403
        for malformed in ('invalid', ''):
            assert 'Reload the form' in request(admin, manual_url, creation | {'creation_id': malformed})[2]
        for invalid in ({'quantity': '2.5'}, {'quantity': '0'}, {'quantity': '101'},
                        {'product_id': str(fixture['product']) + 'junk'}):
            assert 'whole quantity' in request(admin, manual_url, creation | invalid)[2]
        changed_uuid = wp('eval', 'echo wp_generate_uuid4();')
        assert request(admin, manual_url, creation | {'creation_id': changed_uuid})[0] == 403
        for change in ({'quantity': '3'}, {'total': '30.00'}, {'email': 'other-manual@example.test'},
                       {'product_id': '999999999'}, {'shipping_total': '1.00'}):
            rejected = request(admin, manual_url, creation | change)[2]
            assert 'already created a different offer' in rejected and 'id="qf-offer-link"' not in rejected
        assert link(request(admin, manual_url, creation | {'total': '29.0'})[2]) == original_link
        assert link(request(admin, manual_url, creation)[2]) == original_link
        token = parse_qs(urlsplit(original_link).query)['qf_offer'][0]
        saved = row(token)
        assert saved['conversion_id'] == creation['creation_id']
        total_before = wp('eval', 'echo Latse\\QuoteFlow\\Offers::manual_count();')
        clean_listing = request(admin, manual_url)[2]
        assert token not in clean_listing
        view = form_where(clean_listing, 'offer_id', str(saved['id']))['fields']
        assert request(browser(), manual_url, view)[0] in (302, 403)
        assert request(admin, manual_url, view | {'_wpnonce': 'invalid'})[0] == 403
        assert request(admin, manual_url, view | {'offer_id': requested_id})[0] == 403
        # Opening a saved offer is read-only, preserves email attempts and does not duplicate it.
        for _ in range(2):
            opened, _ = reopened(saved['id'])
            assert link(opened) == original_link
            assert row(token) == saved
        assert wp('eval', 'echo Latse\\QuoteFlow\\Offers::manual_count();') == total_before
        assert subprocess.check_output([*COMPOSE, 'exec', '-T', 'wp', 'cat', log], text=True) == ''
        # The raw token is not saved in any column or included in a personal-data export.
        assert token not in json.dumps(saved)
        assert wp('eval', f"""
            $r=Latse\\QuoteFlow\\Privacy::export('manual-ui@example.test');
            echo str_contains(wp_json_encode($r),'{token}')?'leaked':'passed';
        """) == 'passed'
        send = form_where(opened, 'qf_action', 'email')['fields']
        emailed = request(admin, manual_url, send)[2]
        assert row(token)['email_state'] == 'accepted' and row(token)['email_attempts'] == '1'
        captured = subprocess.check_output([*COMPOSE, 'exec', '-T', 'wp', 'cat', log], text=True)
        messages = [json.loads(line) for line in captured.splitlines()]
        assert len(messages) == 1 and original_link in unescape(messages[0]['message'])
        opened, _ = reopened(saved['id'])
        assert link(opened) == original_link and row(token)['email_attempts'] == '1'
        assert not any(f['fields'].get('qf_action') == 'email' for f in Forms(opened).forms)
        cancel = form_where(opened, 'qf_action', 'cancel')['fields'] | {'confirm_cancel': '1'}
        request(admin, manual_url, cancel)
        closed, _ = reopened(saved['id'])
        assert 'Canceled by the store' in closed and row(token)['state'] == 'canceled'
        public = request(browser(), original_link)[2]
        nonce = re.search(r'name="qf_nonce" value="([^"]+)"', public)
        assert nonce is None and 'The store canceled this offer' in public
        # An old creation form recovers the closed record without reopening or sending it.
        closed_snapshot = row(token)
        assert link(request(admin, manual_url, creation)[2]) == original_link
        assert row(token) == closed_snapshot
        fresh_creation = form_where(request(admin, manual_url)[2], 'product_id', '')['fields'] | {
            'product_id': fixture['product'], 'quantity': '2', 'total': '29.00', 'email': 'manual-ui@example.test',
        }
        assert fresh_creation['creation_id'] != creation['creation_id']
        assert link(request(admin, manual_url, fresh_creation)[2]) != original_link
        # A default-generated manual link remains unchanged through acceptance and recovery.
        accepted_token = fixture['tokens'][-1]
        accepted_row = row(accepted_token)
        opened, _ = reopened(accepted_row['id'])
        acceptance_link = link(opened)
        public = request(browser(), acceptance_link)[2]
        nonce = re.search(r'name="qf_nonce" value="([^"]+)"', public)[1]
        response = request(browser(), acceptance_link, {'qf_nonce': nonce})
        assert response[0] == 303
        opened, _ = reopened(accepted_row['id'])
        assert link(opened) == acceptance_link and 'Order created' in opened and 'qf-order-link' in opened
        assert request(browser(), acceptance_link, {'qf_nonce': nonce})[1]['Location'] == response[1]['Location']
        # Legacy random-token offers still work by their original public link, without rotation.
        legacy_token = wp('eval', f"""
            $token=bin2hex(random_bytes(32));echo Latse\\QuoteFlow\\Offers::create({fixture['product']},1,'15','manual-old@example.test',0,$token);
        """)
        legacy = row(legacy_token)
        legacy_view, _ = reopened(legacy['id'])
        assert 'needs its original private link' in legacy_view and 'id="qf-offer-link"' not in legacy_view
        assert row(legacy_token) == legacy
        assert request(browser(), BASE + '/?qf_offer=' + legacy_token)[0] == 200
        # Changed salts must fail hash validation rather than publish a different private link.
        assert wp('eval', f"""
            add_filter('salt',static fn($salt,$scheme)=>$scheme==='auth'?'changed-test-auth-salt':$salt,10,2);
            try{{Latse\\QuoteFlow\\Offers::recover_manual_token({saved['id']});echo 'failed';}}
            catch(RuntimeException $e){{echo str_contains($e->getMessage(),'original private link')?'passed':'failed';}}
        """) == 'passed'
        assert row(token)['token_hash'] == saved['token_hash']
        assert wp('eval', f"""
            try{{Latse\\QuoteFlow\\Offers::recover_manual_token({requested_id});echo 'failed';}}
            catch(RuntimeException $e){{echo 'passed';}}
        """) == 'passed'
        # Physical offers also bind the quoted shipping amount and destination to the form.
        assert wp('eval', f"""
            $p=new WC_Product_Simple();$p->set_name('Manual shipping retry');$p->set_regular_price('20');$p->save();
            $uuid=wp_generate_uuid4();$token=Latse\\QuoteFlow\\Offers::create_manual($uuid,$p->get_id(),1,'15','manual-shipping@example.test','3','US');
            if(Latse\\QuoteFlow\\Offers::create_manual($uuid,$p->get_id(),1,'15.0','manual-shipping@example.test','3.00','US')!==$token){{throw new Exception('Changed normalized token');}}
            try{{Latse\\QuoteFlow\\Offers::create_manual($uuid,$p->get_id(),1,'15','manual-shipping@example.test','3','CA');echo 'failed';}}
            catch(RuntimeException $e){{echo str_contains($e->getMessage(),'already created a different offer')?'passed':'failed';}}
        """) == 'passed'
        print(f'{storage}: concurrent creation, payload conflicts, normalized prices, manual list, pagination, permissions, repeatable links, mail, cancellation, conversion, legacy tokens and salt changes passed', flush=True)
finally:
    subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'rm', '-f', mu], check=True)
