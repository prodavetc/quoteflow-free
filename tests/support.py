"""Shared HTTP fixtures for the disposable M0 store."""
from html import unescape
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import json
from pathlib import Path
import re
import subprocess
import sys
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

BASE = "http://127.0.0.1:18972"
COMPOSE = ["docker", "compose", "-f", str(Path(sys.argv[1]).resolve())]


def wp(*args):
    return subprocess.check_output(
        [*COMPOSE, "exec", "-T", "--user", "www-data", "wp", "php", "/downloads/wp-cli.phar", *args],
        text=True,
    ).strip()


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def browser():
    return build_opener(NoRedirect, HTTPCookieProcessor(CookieJar()))


def request(client, url, fields=None):
    data = None if fields is None else urlencode(fields).encode()
    try:
        response = client.open(Request(url, data=data), timeout=15)
    except HTTPError as error:
        response = error
    with response:
        return response.code, response.headers, response.read().decode()


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "form":
            self.current = {"url": unescape(attributes.get("action", "")), "fields": {}}
        if tag == "input" and self.current is not None and "name" in attributes:
            self.current["fields"][attributes["name"]] = attributes.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def form_where(html, name, value):
    return next(form for form in Forms(html).forms if form["fields"].get(name) == value)


def request_row(submission):
    # UUID comes from the server-rendered form, checked before interpolating into the test fixture.
    assert re.fullmatch(r"[a-f0-9-]{36}", submission)
    return json.loads(wp("eval", f"""global $wpdb;
        echo wp_json_encode($wpdb->get_results("SELECT * FROM {{$wpdb->prefix}}qf_requests WHERE submission_id = '{submission}'"));
    """))


def reset_request_throttles():
    """Reset only known fictional contacts in the disposable store."""
    wp("eval", """global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}qf_requests
        SET rate_key = SHA2(CONCAT('previous-test-', id), 256)
        WHERE email IN ('flow@example.test','multi@example.test','physical@example.test')");""")
