"""Native WordPress privacy hooks/AJAX, deletion batches and live-flow locks in M0."""
from concurrent.futures import ThreadPoolExecutor
import json
import re
from urllib.parse import urlencode
from pathlib import Path
import subprocess
from support import BASE, COMPOSE, browser, request, wp

admin = browser()
request(admin, BASE + '/wp-login.php')
assert request(admin, BASE + '/wp-login.php', {
    'log': 'quote-m0', 'pwd': 'quoteflow-m0-local-only', 'testcookie': '1',
})[0] == 302
ajax = BASE + '/wp-admin/admin-ajax.php'
mu = '/var/www/html/wp-content/mu-plugins/qf-privacy-test-mail.php'
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'test', '!', '-e', mu], check=True)
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'sh', '-c', 'mkdir -p /var/www/html/wp-content/mu-plugins'], check=True)
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'tee', mu], input="<?php add_filter('pre_wp_mail', '__return_true');", text=True, stdout=subprocess.DEVNULL, check=True)


def callback(kind, email, page=1):
    assert "'" not in email
    return json.loads(wp('eval', f"""
        global $wpdb; $wpdb->suppress_errors(true);
        $all=apply_filters('wp_privacy_personal_data_{kind}', []);
        $r=call_user_func($all['quoteflow']['callback'], '{email}', {page});
        echo wp_json_encode(is_wp_error($r)?['error'=>$r->get_error_code()]:$r);
    """))


def counts(email):
    return json.loads(wp('eval', f"""global $wpdb; echo wp_json_encode([
        'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {{$wpdb->prefix}}qf_requests WHERE email='{email}'"),
        'offers'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {{$wpdb->prefix}}qf_offers WHERE email='{email}'")]);"""))


def privacy_request(email, kind):
    info = json.loads(wp('eval', f"""
        wp_set_current_user(get_user_by('login','quote-m0')->ID);
        $id=wp_create_user_request('{email}', '{kind}_personal_data');
        if(is_wp_error($id)){{throw new Exception($id->get_error_message());}}
        wp_update_post(['ID'=>$id,'post_status'=>'request-confirmed']);
        $hooks=apply_filters('wp_privacy_personal_data_{'exporters' if kind=='export' else 'erasers'}', []);
        echo wp_json_encode(['id'=>$id,'index'=>array_search('quoteflow',array_keys($hooks),true)+1,
            'security'=>wp_create_nonce('wp-privacy-{'export' if kind=='export' else 'erase'}-personal-data-'.$id)]);
    """))
    screen = 'export' if kind == 'export' else 'erase'
    html = request(admin, BASE + '/wp-admin/' + screen + '-personal-data.php?' + urlencode({'s': email}))[2]
    info['security'] = re.search(r'data-request-id="' + str(info['id']) + r'"\s+data-nonce="([^"]+)"', html)[1]
    return info


def ajax_fields(info, kind, page=1):
    return {'action': f'wp-privacy-{kind}-personal-data', 'id': info['id'],
            'exporter' if kind == 'export' else 'eraser': info['index'],
            'page': page, 'security': info['security'], 'sendAsEmail': 'false'}


try:
    fixture = Path(__file__).with_name('fixtures') / 'privacy.php'
    subprocess.run([*COMPOSE, 'cp', str(fixture), 'wp:/tmp/qf-privacy-fixture.php'], check=True, stdout=subprocess.DEVNULL)
    wp('eval', """
        if (!get_user_by('login','qf-privacy-viewer')) {
            wp_insert_user(['user_login'=>'qf-privacy-viewer','user_pass'=>'privacy-local-only',
                'user_email'=>'privacy-viewer@example.test','role'=>'subscriber']);
        }
    """)
    viewer = browser()
    request(viewer, BASE + '/wp-login.php')
    assert request(viewer, BASE + '/wp-login.php', {
        'log': 'qf-privacy-viewer', 'pwd': 'privacy-local-only', 'testcookie': '1',
    })[0] == 302
    for storage in ('enable', 'disable'):
        wp('wc', 'hpos', 'sync')
        wp('wc', 'hpos', storage)
        wp('option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no')
        data = json.loads(wp('eval-file', '/tmp/qf-privacy-fixture.php'))
        email = data['email']
        assert counts(email) == {'requests': 30, 'offers': 8}
        exported = []
        for page in range(1, 5):
            result = callback('exporters', email, page)
            assert 'error' not in result, result
            exported += result['data']
            if result['done']:
                break
        assert len(exported) == 38 and len({row['item_id'] for row in exported}) == 38
        encoded = json.dumps(exported)
        assert email in encoded and 'Private buyer note <literal>' in encoded
        assert '123 Privacy Street' in encoded and '90210' in encoded
        for secret in ('token_hash', 'conversion_id', 'payload_hash', 'rate_key', 'submission_id'):
            assert secret not in encoded
        for token in data['tokens'].values():
            assert token not in encoded
        empty = callback('exporters', 'missing-' + email)
        assert empty == {'data': [], 'done': True}
        for kind in ('exporters', 'erasers'):
            assert callback(kind, 'invalid')['error'] == 'qf_privacy_input'
            assert callback(kind, email, 0)['error'] == 'qf_privacy_input'
        export_request = privacy_request(email, 'export')
        fields = ajax_fields(export_request, 'export')
        assert request(browser(), ajax, fields)[0] == 400
        assert not json.loads(request(viewer, ajax, fields)[2])['success']
        assert request(admin, ajax, fields | {'security': 'invalid'})[0] == 403
        native_response = request(admin, ajax, fields)
        assert native_response[2] != '0', native_response
        native = json.loads(native_response[2])
        assert native['success'] and not native['data']['done'], native
        erase_request = privacy_request(email, 'remove')
        fields = ajax_fields(erase_request, 'erase')
        assert request(browser(), ajax, fields)[0] == 400
        assert not json.loads(request(viewer, ajax, fields)[2])['success']
        assert request(admin, ajax, fields | {'security': 'invalid'})[0] == 403
        assert counts(email) == {'requests': 30, 'offers': 8}
        first = json.loads(request(admin, ajax, fields)[2])
        assert first['success'], first
        assert first['data']['items_removed'] and not first['data']['done'], first
        second = json.loads(request(admin, ajax, ajax_fields(erase_request, 'erase', 2))[2])
        assert second['success'], second
        result = second['data']
        assert result['done'] and result['items_removed'] and result['items_retained'] and result['messages'], result
        assert counts(email) == {'requests': 2, 'offers': 5}
        replay = callback('erasers', email, 3)
        assert replay['done'] and not replay['items_removed'] and replay['items_retained']
        for state in ('canceled', 'rejected', 'expired'):
            token = data['tokens'][state]
            assert request(browser(), BASE + '/?qf_offer=' + token)[0] == 404
        assert wp('eval', f"""
            if (!Latse\\QuoteFlow\\Offers::find('{data['foreign']}')){{throw new Exception('Other buyer changed');}}
            $o=wc_get_order({data['order']});Latse\\QuoteFlow\\Offers::validate_payment($o);
            $physical=wc_get_order({data['physical_order']});
            echo $physical->get_shipping_address_1()==='123 Privacy Street'?'passed':'failed';
        """) == 'passed'
        # Close a retained live offer; it becomes eligible on the next privacy request.
        token = data['tokens']['active']
        wp('eval', f"Latse\\QuoteFlow\\Offers::close('{token}', 'canceled');")
        assert callback('erasers', email)['items_removed']
        assert counts(email) == {'requests': 1, 'offers': 4}
        # Expired/closed rows with an uncertain email result stay until the operator reviews it.
        token = data['tokens']['sending']
        wp('eval', f"""global $wpdb; $o=Latse\\QuoteFlow\\Offers::find('{token}');
            $wpdb->update($wpdb->prefix.'qf_offers',['email_state'=>'failed'],['id'=>$o->id]);""")
        assert callback('erasers', email)['items_removed']
        assert counts(email) == {'requests': 1, 'offers': 3}
        print(f'{storage}: native privacy AJAX, permissions/nonces, export, deletion batches, replay and retained orders passed', flush=True)

    # Locks are held in a different database session, matching a live reply/mail/accept worker.
    for lock_kind in ('reply', 'email', 'decision'):
        setup = json.loads(wp('eval', """
            global $wpdb;$email='privacy-lock-'.wp_generate_uuid4().'@example.test';
            $p=new WC_Product_Simple();$p->set_name('Privacy lock');$p->set_regular_price('20');$p->set_virtual(true);$p->save();
            $uuid=wp_generate_uuid4();Latse\\QuoteFlow\\Requests::submit($uuid,$p->get_id(),1,$email,'Private note',$uuid);
            $id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}qf_requests WHERE submission_id=%s",$uuid));
            $token=Latse\\QuoteFlow\\Requests::respond((int)$id,'15');Latse\\QuoteFlow\\Offers::close($token,'canceled');
            $o=Latse\\QuoteFlow\\Offers::find($token);echo wp_json_encode(['email'=>$email,'request'=>$id,'offer'=>$o->id,'prefix'=>$wpdb->prefix]);
        """))
        import hashlib
        prefix = setup['prefix']
        if lock_kind == 'reply':
            lock = 'qf_reply_' + hashlib.md5(f"{prefix}qf_requests:{setup['request']}".encode()).hexdigest()
        else:
            lock = ('qf_email_' if lock_kind == 'email' else 'qf_') + hashlib.md5(f"{prefix}qf_offers:{setup['offer']}".encode()).hexdigest()
        code = f"global $wpdb; $wpdb->get_var(\"SELECT GET_LOCK('{lock}',0)\"); echo \"locked\\n\"; flush(); sleep(5); $wpdb->get_var(\"SELECT RELEASE_LOCK('{lock}')\");"
        proc = subprocess.Popen([*COMPOSE, 'exec', '-T', '--user', 'www-data', 'wp', 'php', '/downloads/wp-cli.phar', 'eval', code], stdout=subprocess.PIPE, text=True)
        try:
            assert proc.stdout.readline().strip() == 'locked'
            blocked = callback('erasers', setup['email'])
            assert blocked == {'error': 'qf_privacy_retry'}, blocked
            assert counts(setup['email']) == {'requests': 1, 'offers': 1}
        finally:
            proc.wait(timeout=15)
        if lock_kind == 'reply':
            # Failure of the second delete must roll back the offer delete too.
            trigger = prefix + 'qf_privacy_test_rollback'
            wp('eval', f"""global $wpdb;
                $wpdb->query(\"CREATE TRIGGER {trigger} BEFORE DELETE ON {prefix}qf_requests FOR EACH ROW
                    BEGIN IF OLD.id = {setup['request']} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test rollback'; END IF; END\");
                if ($wpdb->last_error){{throw new Exception('Could not install rollback fixture');}}
            """)
            try:
                failed = callback('erasers', setup['email'])
                assert failed == {'error': 'qf_privacy_retry'}, failed
                assert counts(setup['email']) == {'requests': 1, 'offers': 1}
            finally:
                wp('eval', f'global $wpdb;$wpdb->query("DROP TRIGGER IF EXISTS {trigger}");')
        with ThreadPoolExecutor(2) as pool:
            outcomes = list(pool.map(lambda _: callback('erasers', setup['email']), range(2)))
        # A colliding worker can request a retry; the final repeated call must converge.
        final = callback('erasers', setup['email'])
        assert final['done'] and not final['items_retained'] and counts(setup['email']) == {'requests': 0, 'offers': 0}
        print(f'{lock_kind}: live lock blocks erasure; concurrent erasers converge', flush=True)
finally:
    subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'rm', '-f', mu], check=True)
