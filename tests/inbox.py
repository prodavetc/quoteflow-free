"""Exercise merchant search, status filters, pagination and native order links in M0."""
from html import unescape
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import parse_qs, urlencode, urlsplit
from support import BASE, COMPOSE, browser, form_where, request, wp

admin = browser()
request(admin, BASE + '/wp-login.php')
assert request(admin, BASE + '/wp-login.php', {
    'log': 'quote-m0', 'pwd': 'quoteflow-m0-local-only', 'testcookie': '1',
})[0] == 302
admin_url = BASE + '/wp-admin/admin.php?page=quoteflow'
mu = '/var/www/html/wp-content/mu-plugins/qf-inbox-test-mail.php'
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'test', '!', '-e', mu], check=True)
subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'tee', mu], input="<?php add_filter('pre_wp_mail', '__return_true');", text=True, stdout=subprocess.DEVNULL, check=True)


class Links(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.links = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'a':
            self.links.append(attrs)


def page(search='', status='', number=1):
    query = urlencode({'qf_search': search, 'qf_status': status, 'qf_page': number})
    response = request(admin, admin_url + '&' + query)
    assert response[0] == 200
    return response[2]


def ids(html):
    return [int(value) for value in re.findall(r'name="request_id" value="([0-9]+)"', html)]


def paging(html, number):
    return next(link['href'] for link in Links(html).links
                if parse_qs(urlsplit(link.get('href', '')).query).get('qf_page') == [str(number)])


try:
    fixture = Path(__file__).with_name('fixtures') / 'inbox.php'
    subprocess.run([*COMPOSE, 'cp', str(fixture), 'wp:/tmp/qf-inbox-fixture.php'], check=True, stdout=subprocess.DEVNULL)
    for storage in ('enable', 'disable'):
        wp('wc', 'hpos', 'sync')
        wp('wc', 'hpos', storage)
        wp('option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no')
        data = json.loads(wp('eval-file', '/tmp/qf-inbox-fixture.php'))
        email = data['email']
        all_ids = sorted([n for group in data['ids'].values() for n in group], reverse=True)
        html = page(email)
        assert '30 requests. Page 1 of 2.' in html and ids(html) == all_ids[:20]
        assert 'id="qf-search"' in html and 'id="qf-status"' in html
        next_url = paging(html, 2)
        assert parse_qs(urlsplit(next_url).query)['qf_search'] == [email]
        second = request(admin, next_url)[2]
        assert '30 requests. Page 2 of 2.' in second and ids(second) == all_ids[20:]
        assert paging(second, 1) and not re.search(r'qf_page=3(?:&|\")', second)
        assert ids(page(email, number=999999999999)) == all_ids[20:]
        assert ids(page(email, number=-1)) == all_ids[:20]
        # Identical filtering is used for the displayed rows and total count.
        for state, expected in data['ids'].items():
            html = page(email, state)
            ordered = sorted(expected, reverse=True)
            pages = 2 if len(ordered) > 20 else 1
            noun = 'request' if len(ordered) == 1 else 'requests'
            assert f'{len(ordered)} {noun}. Page 1 of {pages}.' in html, state
            assert ids(html) == ordered[:20], state
            if pages == 2:
                link = paging(html, 2)
                query = parse_qs(urlsplit(link).query)
                assert query['qf_search'] == [email] and query['qf_status'] == [state]
                assert ids(request(admin, link)[2]) == ordered[20:]
        for request_id in (data['ids']['pending'][0], data['ids']['converted'][0]):
            assert ids(page(str(request_id))) == [request_id]
            assert ids(page('#' + str(request_id))) == [request_id]
        converted = data['ids']['converted'][0]
        assert ids(page('#' + str(converted), 'pending')) == []
        assert ids(page(email.split('@')[0])) == all_ids[:20]
        for text in (email + '%', email + '_', email + "' OR 1=1 --", 'missing-' + email):
            empty = page(text)
            assert not ids(empty) and '0 requests. Page 1 of 1.' in empty
        assert ids(page(email, 'unsupported')) == all_ids[:20]
        # Exactly 20 results have one page, with no empty extra page link.
        exact = page(data['exact_email'])
        assert '20 requests. Page 1 of 1.' in exact and len(ids(exact)) == 20
        assert not any('qf_page' in parse_qs(urlsplit(link.get('href', '')).query) for link in Links(exact).links)
        # Native order edit links route correctly in both storage engines.
        converted_html = page(email, 'converted')
        order_urls = [link['href'] for link in Links(converted_html).links if 'qf-order-link' in link.get('class', '')]
        assert sorted(order_urls) == sorted(order['url'] for order in data['orders'].values())
        for link in order_urls:
            assert request(admin, link)[0] == 200
            assert ('wc-orders' in link) == (storage == 'enable'), link
        assert not any('qf-order-link' in link.get('class', '') for link in Links(page(email, 'pending')).links)
        # Viewing a saved offer preserves the filter and does not prepare another offer.
        target = data['ids']['offered'][0]
        target_url = admin_url + '&' + urlencode({'qf_search': '#' + str(target), 'qf_status': 'offered'})
        target_html = request(admin, target_url)[2]
        view = form_where(target_html, 'request_id', str(target))['fields']
        viewed = request(admin, target_url, view)[2]
        assert 'id="qf-offer-link"' in viewed and ids(viewed) == [target]
        assert '1 request. Page 1 of 1.' in viewed
        assert wp('eval', f"echo Latse\\QuoteFlow\\Requests::inbox_count('{email}', 'offered');") == '2'
        # Repeated GET searches cannot create a quote or send an email.
        assert wp('eval', f"echo Latse\\QuoteFlow\\Requests::inbox_count('{email}');") == '30'
        assert request(browser(), admin_url + '&qf_search=' + email)[0] in (302, 403)
        malformed = request(admin, admin_url + '&qf_search[x]=bad&qf_status[x]=bad&qf_page[x]=bad')
        assert malformed[0] == 200
        print(f'{storage}: search, all states, real totals, paging, literal wildcards, native order links and filtered offer actions passed', flush=True)
finally:
    subprocess.run([*COMPOSE, 'exec', '-T', 'wp', 'rm', '-f', mu], check=True)
