#!/usr/bin/env python3
# Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved. Licensed under the GNU General Public License v2.0 (or any later version).
"""tron browser runner: installs headless browser engines and runs browser jobs from tron's queue.

Part of the tron extension (doc/browser.md). It runs on the runner node only ([BrowserSettings] RunnerHosts[]),
started by systemd (tron-browser.path watches the queue, tron-browser.service runs `run --once`), never on a node
that serves the load balancer.

  tron_browser_runner.py engines  --browsers DIR                      installed engines as JSON
  tron_browser_runner.py install  ENGINE --browsers DIR [--adopt-from DIR ...] [--max-load N]
  tron_browser_runner.py run      --queue DIR --browsers DIR [--once] [--max-load N] [--timeout S]

Rules this script keeps:
- An engine is used only after it was installed by the install command (engines.json in the browsers directory,
  with the sha256 of its executable, checked again before every job). A job never downloads anything.
- Install adopts a build of the pinned Playwright version that is already on the box (another Playwright cache)
  before it downloads one; the download is Playwright's own, into the browsers directory only.
- Every request a page makes passes the SSRF guard (http and https only, every resolved address public, the
  installation's mesh networks and metadata addresses refused); Chromium engines also pin the page's host to the checked address.
- One job at a time per browser pool (a lock file), waiting while the load per CPU is above --max-load, every job
  with a time limit. Results and artifacts are written next to the queue; nothing else is changed.
"""

import argparse
import fcntl
import hashlib
import ipaddress
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import time
from urllib.parse import urlsplit

ENGINES = {
    # engine: (playwright install name, launcher, channel)
    'chromium': ('chromium-headless-shell', 'chromium', None),
    'chrome': ('chromium', 'chromium', 'chromium'),      # Chrome for Testing, new headless mode
    'firefox': ('firefox', 'firefox', None),
    'webkit': ('webkit', 'webkit', None),
}
EXECUTABLES = ('chrome-headless-shell', 'chrome', 'firefox', 'pw_run.sh')
# the installation's mesh ([WebSettings] MeshNetworks[], sent with each job as mesh_networks); tron ships none
MESH = []
METADATA = {ipaddress.ip_address(a) for a in ('169.254.169.254', '169.254.170.2', '100.100.100.200', '192.0.0.192', 'fd00:ec2::254')}
INTERNAL_NAMES = ('localhost', 'localhost.localdomain', 'metadata', 'metadata.google.internal', 'instance-data')
INTERNAL_SUFFIXES = ('.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet', '.corp', '.localdomain')


class JobError(Exception):
    pass


class Timeout(Exception):
    pass


def now():
    return time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def write_json(path, data):
    tmp = '%s.%d.tmp' % (path, os.getpid())
    with open(tmp, 'w') as f:
        json.dump(data, f, indent=1, sort_keys=True)
    os.chmod(tmp, 0o664)
    os.replace(tmp, path)


def read_json(path, default=None):
    try:
        with open(path) as f:
            return json.load(f)
    except (OSError, ValueError):
        return default


def load_per_cpu():
    return os.getloadavg()[0] / max(1, os.cpu_count() or 1)


def wait_for_load(max_load, give_up):
    """Waits while the load per CPU is above max_load; False when it stayed high until give_up seconds passed."""
    deadline = time.time() + give_up
    while load_per_cpu() > max_load:
        if time.time() > deadline:
            return False
        time.sleep(5)
    return True


# ---------------------------------------------------------------- the SSRF guard (same rules as tronWebGuard)

def address_kind(ip):
    a = ipaddress.ip_address(ip)
    if isinstance(a, ipaddress.IPv6Address):
        if a.ipv4_mapped:
            return address_kind(str(a.ipv4_mapped))
        if a.sixtofour:
            return address_kind(str(a.sixtofour))
        if a in ipaddress.ip_network('64:ff9b::/96'):
            return address_kind(str(ipaddress.IPv4Address(int(a) & 0xffffffff)))
    if a in METADATA:
        return 'metadata'
    if isinstance(a, ipaddress.IPv4Address) and any(a in n for n in MESH):
        return 'mesh'
    if a.is_loopback:
        return 'loopback'
    if a.is_link_local:
        return 'link-local'
    if a.is_multicast:
        return 'multicast'
    if a.is_unspecified:
        return 'unspecified'
    if a.is_private or a.is_reserved or not a.is_global:
        return 'private'
    return 'public'


class Guard:
    def __init__(self, private_hosts=()):
        self.private_hosts = [h.lower() for h in private_hosts if h]
        self.cache = {}

    def excepted(self, host):
        for p in self.private_hosts:
            if p.startswith('*.') and host.endswith(p[1:]) or p == host:
                return True
        return False

    def check(self, url):
        """(ok, reason, address) for a URL."""
        try:
            parts = urlsplit(url)
        except ValueError:
            return False, 'not a URL', None
        if parts.scheme not in ('http', 'https'):
            return False, 'the scheme %s is not allowed' % parts.scheme, None
        if parts.username or parts.password:
            return False, 'credentials in the URL are not allowed', None
        host = (parts.hostname or '').rstrip('.').lower()
        if not host:
            return False, 'no host', None
        if host in self.cache:
            return self.cache[host]
        literal = True
        try:
            ipaddress.ip_address(host)
        except ValueError:
            literal = False
        if not literal and not self.excepted(host) and (host in INTERNAL_NAMES or '.' not in host or host.endswith(INTERNAL_SUFFIXES)):
            result = (False, 'the host %s is an internal name' % host, None)
        else:
            try:
                addrs = [host] if literal else sorted({i[4][0] for i in socket.getaddrinfo(host, None)})
            except socket.gaierror:
                addrs = []
            if not addrs:
                result = (False, 'the host %s does not resolve' % host, None)
            else:
                bad = [(a, address_kind(a)) for a in addrs if address_kind(a) != 'public']
                if bad and not self.excepted(host):
                    result = (False, 'the host %s points to a non-public address (%s)' % (host, bad[0][1]), None)
                else:
                    v4 = [a for a in addrs if ':' not in a]
                    result = (True, 'public', (v4 or addrs)[0])
        self.cache[host] = result
        return result


# ---------------------------------------------------------------- engines

def manifest_path(browsers):
    return os.path.join(browsers, 'engines.json')


def playwright_version():
    try:
        from importlib.metadata import version
        return version('playwright')
    except Exception:
        return ''


def install_dirs(name, browsers):
    """The directory names Playwright installs for an install name (from its own --dry-run)."""
    env = dict(os.environ, PLAYWRIGHT_BROWSERS_PATH=browsers)
    out = subprocess.run([sys.executable, '-m', 'playwright', 'install', '--dry-run', name], env=env,
                         capture_output=True, text=True, timeout=120).stdout
    dirs = re.findall(r'Install location:\s+(\S+)', out)
    return [os.path.basename(d) for d in dirs if 'ffmpeg' not in d]


def find_executable(path):
    for root, _dirs, files in os.walk(path):
        for f in files:
            if f in EXECUTABLES and os.access(os.path.join(root, f), os.X_OK):
                return os.path.join(root, f)
    return None


def engines_status(browsers):
    m = read_json(manifest_path(browsers), {}) or {}
    out = []
    for engine in ENGINES:
        e = m.get(engine) or {}
        exe = e.get('executable')
        ok = bool(e.get('installed')) and exe and os.path.isfile(exe)
        if ok and e.get('sha256') and sha256_file(exe) != e['sha256']:
            ok = False
            e['error'] = 'the executable does not match the recorded checksum'
        out.append({'engine': engine, 'installed': bool(ok), 'version': e.get('version'), 'build': e.get('build'),
                    'path': e.get('path'), 'source': e.get('source'), 'installed_at': e.get('installed_at'),
                    'playwright': e.get('playwright'), 'error': e.get('error')})
    return {'playwright': playwright_version(), 'path': browsers, 'engines': out}


def require_engine(browsers, engine):
    if engine not in ENGINES:
        raise JobError('unknown engine %s' % engine)
    for e in engines_status(browsers)['engines']:
        if e['engine'] == engine and e['installed']:
            return e
    raise JobError("engine '%s' is not installed: run browser_install {\"engine\":\"%s\"} first" % (engine, engine))


def launch(p, engine, browsers, pin=None):
    name, launcher, channel = ENGINES[engine]
    kwargs = {'headless': True, 'timeout': 30000}
    if channel:
        kwargs['channel'] = channel
    if launcher == 'chromium' and pin:
        # the page's host goes to the address the guard checked (no second DNS answer)
        kwargs['args'] = ['--host-resolver-rules=MAP %s %s' % (pin[0], '[%s]' % pin[1] if ':' in pin[1] else pin[1])]
    return getattr(p, launcher).launch(**kwargs)


def cmd_install(args):
    engine = args.engine
    if engine not in ENGINES:
        print(json.dumps({'ok': False, 'error': 'unknown engine %s' % engine}))
        return 2
    os.environ['PLAYWRIGHT_BROWSERS_PATH'] = args.browsers
    os.makedirs(args.browsers, exist_ok=True)
    if not wait_for_load(args.max_load, 600):
        print(json.dumps({'ok': False, 'error': 'the load stayed above %.2f per CPU for 10 minutes' % args.max_load}))
        return 1
    name = ENGINES[engine][0]
    dirs = install_dirs(name, args.browsers)
    source = 'present'
    steps = []
    for d in dirs:
        target = os.path.join(args.browsers, d)
        if os.path.isfile(os.path.join(target, 'INSTALLATION_COMPLETE')):
            steps.append('present %s' % d)
            continue
        adopted = False
        for cache in args.adopt_from:
            src = os.path.join(cache, d)
            if os.path.isfile(os.path.join(src, 'INSTALLATION_COMPLETE')):
                shutil.copytree(src, target, symlinks=True)
                steps.append('adopted %s from %s' % (d, cache))
                source = 'adopted'
                adopted = True
                break
        if not adopted:
            source = 'downloaded'
    if source == 'downloaded':
        r = subprocess.run(['nice', '-n', '15', sys.executable, '-m', 'playwright', 'install', name],
                           env=dict(os.environ), capture_output=True, text=True, timeout=1800)
        steps.append('playwright install %s: exit %d' % (name, r.returncode))
        if r.returncode != 0:
            print(json.dumps({'ok': False, 'error': 'playwright install failed', 'steps': steps,
                              'output': (r.stdout + r.stderr)[-2000:]}))
            return 1
    build_dir = os.path.join(args.browsers, dirs[0]) if dirs else args.browsers
    exe = find_executable(build_dir)
    entry = {'installed': False, 'path': build_dir, 'build': dirs[0] if dirs else None, 'source': source,
             'playwright': playwright_version(), 'installed_at': now(), 'executable': exe,
             'sha256': sha256_file(exe) if exe else None}
    # proof: the engine starts and reports its version
    try:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            b = launch(p, engine, args.browsers)
            entry['version'] = b.version
            b.close()
        entry['installed'] = True
    except Exception as e:  # noqa: BLE001 - recorded as the reason
        entry['error'] = ('the engine does not start: %s' % e)[:1500]
    m = read_json(manifest_path(args.browsers), {}) or {}
    m[engine] = entry
    write_json(manifest_path(args.browsers), m)
    print(json.dumps({'ok': entry['installed'], 'engine': engine, 'steps': steps, 'entry': entry}))
    return 0 if entry['installed'] else 1


# ---------------------------------------------------------------- jobs

A11Y_JS = r"""
() => {
  const out = [];
  const sel = (e) => {
    if (e.id) return '#' + e.id;
    let s = e.tagName.toLowerCase();
    if (e.className && typeof e.className === 'string') s += '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.');
    const p = e.parentElement;
    if (p) { const same = [...p.children].filter(c => c.tagName === e.tagName); if (same.length > 1) s += ':nth-of-type(' + (same.indexOf(e) + 1) + ')'; }
    return s;
  };
  const path = (e) => { const parts = []; for (let n = e; n && n.nodeType === 1 && parts.length < 4; n = n.parentElement) parts.unshift(sel(n)); return parts.join(' > '); };
  const add = (sev, rule, msg, e) => out.push({severity: sev, rule: 'a11y.' + rule, message: msg, location: {line: null, column: null, path: e ? path(e) : null}});
  const visible = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const name = (e) => (e.getAttribute('aria-label') || '').trim() || (e.getAttribute('aria-labelledby') ? [...e.getAttribute('aria-labelledby').split(/\s+/)].map(i => (document.getElementById(i) || {}).textContent || '').join(' ').trim() : '') || (e.textContent || '').trim() || (e.getAttribute('title') || '').trim() || [...e.querySelectorAll('img[alt]')].map(i => i.alt).join(' ').trim();
  if (!document.documentElement.getAttribute('lang')) add('error', 'html-lang', 'The page has no language (html lang)', document.documentElement);
  if (!document.title.trim()) add('error', 'document-title', 'The page has no title', null);
  if (!document.querySelector('main, [role=main]')) add('warning', 'landmark-main', 'The page has no main landmark', null);
  document.querySelectorAll('img:not([alt])').forEach(e => add('error', 'img-alt', 'An image without alt text', e));
  document.querySelectorAll('a[href]').forEach(e => { if (visible(e) && !name(e)) add('error', 'link-name', 'A link without an accessible name', e); });
  document.querySelectorAll('button, [role=button]').forEach(e => { if (visible(e) && !name(e)) add('error', 'button-name', 'A button without an accessible name', e); });
  document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]), select, textarea').forEach(e => {
    const labelled = (e.labels && e.labels.length) || e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title');
    if (visible(e) && !labelled) add('error', 'label', 'A form field without a label', e);
  });
  const ids = {}; document.querySelectorAll('[id]').forEach(e => { if (ids[e.id]) add('warning', 'duplicate-id', 'The id "' + e.id.slice(0, 60) + '" is used more than once', e); ids[e.id] = 1; });
  let last = 0; document.querySelectorAll('h1,h2,h3,h4,h5,h6').forEach(h => { const l = +h.tagName[1]; if (last && l > last + 1) add('warning', 'heading-order', 'A heading jumps from h' + last + ' to h' + l, h); last = l; });
  document.querySelectorAll('[tabindex]').forEach(e => { if (+e.getAttribute('tabindex') > 0) add('warning', 'tabindex', 'A positive tabindex changes the focus order', e); });
  document.querySelectorAll('[aria-hidden=true] a[href], [aria-hidden=true] button, [aria-hidden=true] input, [aria-hidden=true] [tabindex]').forEach(e => add('error', 'aria-hidden-focus', 'A focusable element inside aria-hidden', e));
  const rgb = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const v = m[1].split(/[ ,\/]+/).map(Number); return {r: v[0], g: v[1], b: v[2], a: v.length > 3 ? v[3] : 1}; };
  const lum = (c) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const bg = (e) => { for (let n = e; n; n = n.parentElement) { const c = rgb(getComputedStyle(n).backgroundColor); if (c && c.a > 0.9) return c; if (getComputedStyle(n).backgroundImage !== 'none') return null; } return {r: 255, g: 255, b: 255, a: 1}; };
  let checked = 0, low = 0;
  const walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
  const seen = new Set();
  while (walker.nextNode() && checked < 1500) {
    const t = walker.currentNode; const e = t.parentElement;
    if (!e || seen.has(e) || !t.textContent.trim() || !visible(e)) continue;
    seen.add(e); checked++;
    const cs = getComputedStyle(e); const fg = rgb(cs.color); const b = bg(e);
    if (!fg || !b) continue;
    const L1 = lum(fg), L2 = lum(b); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const size = parseFloat(cs.fontSize); const bold = +cs.fontWeight >= 700;
    const need = (size >= 24 || (bold && size >= 18.66)) ? 3 : 4.5;
    if (ratio < need) { low++; if (low <= 50) add('error', 'contrast', 'Text contrast ' + ratio.toFixed(2) + ':1 is below ' + need + ':1', e); }
  }
  if (document.documentElement.scrollWidth > window.innerWidth + 1) add('warning', 'overflow', 'The page scrolls horizontally at this width (' + window.innerWidth + 'px)', null);
  return {findings: out, checked_text: checked};
}
"""

FORMS_JS = r"""
() => [...document.forms].slice(0, 50).map(f => ({
  action: f.getAttribute('action') || '', method: (f.getAttribute('method') || 'get').toLowerCase(), id: f.id || null,
  fields: [...f.elements].slice(0, 100).map(e => ({tag: e.tagName.toLowerCase(), type: e.type || null, name: e.name || null,
    required: !!e.required, label: (e.labels && e.labels[0] ? e.labels[0].textContent.trim().slice(0, 120) : (e.getAttribute('aria-label') || null))}))
}))
"""

LINKS_JS = r"""
() => [...document.querySelectorAll('a[href]')].slice(0, 1000).map(a => ({text: (a.textContent || '').trim().slice(0, 200), url: a.href, rel: a.rel || ''}))
"""


def on_alarm(_sig, _frame):
    raise Timeout('the job ran out of time')


def run_job(job, browsers, artifacts_dir):
    from playwright.sync_api import sync_playwright
    kind = job['kind']
    spec = job.get('spec') or {}
    engine = job.get('engine') or 'chromium'
    require_engine(browsers, engine)
    MESH[:] = []
    for cidr in spec.get('mesh_networks') or []:
        try:
            n = ipaddress.ip_network(str(cidr), strict=False)
            if n.version == 4:
                MESH.append(n)
        except ValueError:
            pass
    guard = Guard(spec.get('private_hosts') or [])
    url = spec.get('url')
    pin = None
    if url:
        ok, reason, addr = guard.check(url)
        if not ok:
            raise JobError('Refused by the SSRF guard: ' + reason)
        pin = ((urlsplit(url).hostname or '').lower(), addr)
    timeout_ms = int(spec.get('timeout', 30)) * 1000
    result = {}
    artifacts = []
    blocked = []

    def guard_route(route):
        ok, reason, _a = guard.check(route.request.url)
        if ok:
            route.continue_()
        else:
            blocked.append({'url': route.request.url[:300], 'reason': reason})
            route.abort('blockedbyclient')

    def context(b, width=None, scheme=None, scale=None):
        c = b.new_context(viewport={'width': int(width or spec.get('width', 1440)), 'height': int(spec.get('height', 900))},
                          device_scale_factor=float(scale or spec.get('scale', 1)), color_scheme=scheme or spec.get('color_scheme', 'light'),
                          accept_downloads=False, service_workers='block', ignore_https_errors=False,
                          extra_http_headers=spec.get('headers') or {})
        c.route('**/*', guard_route)
        c.set_default_timeout(timeout_ms)
        c.set_default_navigation_timeout(timeout_ms)
        return c

    def save(page, name, **meta):
        os.makedirs(artifacts_dir, exist_ok=True)
        safe = re.sub(r'[^A-Za-z0-9_.-]+', '-', name)[:80] + '.png'
        path = os.path.join(artifacts_dir, safe)
        if meta.get('selector'):
            page.locator(meta['selector']).first.screenshot(path=path)
        else:
            page.screenshot(path=path, full_page=bool(spec.get('full_page', True)))
        os.chmod(path, 0o664)
        artifacts.append(dict({'name': safe, 'path': path, 'bytes': os.path.getsize(path), 'sha256': sha256_file(path)},
                              **{k: v for k, v in meta.items() if k != 'selector'}))

    with sync_playwright() as p:
        b = launch(p, engine, browsers, pin)
        try:
            if kind == 'screenshot':
                for scheme in spec.get('color_schemes') or ['light']:
                    for width in spec.get('widths') or [1440]:
                        scale = spec.get('scale', 1)
                        c = context(b, width, scheme, scale)
                        page = c.new_page()
                        resp = page.goto(url, wait_until='load')
                        page.wait_for_timeout(int(spec.get('settle_ms', 500)))
                        save(page, '%s-%s-%sx%s' % (engine, scheme, width, scale), width=width, scale=scale, color_scheme=scheme,
                             status=resp.status if resp else None, selector=spec.get('selector'))
                        c.close()
                result = {'screenshots': len(artifacts)}
            elif kind == 'flow':
                c = context(b)
                page = c.new_page()
                steps = []
                for i, st in enumerate(spec.get('steps') or []):
                    t0 = time.time()
                    row = {'step': i + 1, 'action': st.get('action'), 'ok': True, 'error': None}
                    try:
                        a = st.get('action')
                        if a == 'goto':
                            ok, reason, _x = guard.check(st.get('url', ''))
                            if not ok:
                                raise JobError('Refused by the SSRF guard: ' + reason)
                            page.goto(st['url'], wait_until='load')
                        elif a == 'click':
                            page.click(st['selector'])
                        elif a == 'fill':
                            page.fill(st['selector'], st.get('value', ''))
                        elif a == 'press':
                            page.press(st['selector'], st.get('value', 'Enter'))
                        elif a == 'wait_for':
                            page.wait_for_selector(st['selector'])
                        elif a == 'expect_text':
                            text = page.inner_text(st['selector'])
                            if st.get('value', '') not in text:
                                raise JobError('the text was not found')
                        elif a == 'screenshot':
                            save(page, st.get('value') or 'step-%d' % (i + 1), step=i + 1, selector=st.get('selector'))
                        else:
                            raise JobError('unknown action')
                    except Exception as e:  # noqa: BLE001 - a step's failure is its result
                        row['ok'] = False
                        row['error'] = str(e).split('\n')[0][:300]
                    row['ms'] = int((time.time() - t0) * 1000)
                    steps.append(row)
                    if not row['ok'] and not spec.get('continue_on_error'):
                        break
                result = {'steps': steps, 'ok': all(s['ok'] for s in steps), 'final_url': page.url}
                c.close()
            else:
                c = context(b)
                page = c.new_page()
                console, page_errors, failed = [], [], []
                page.on('console', lambda m: console.append({'type': m.type, 'text': m.text[:500]}) if len(console) < 200 else None)
                page.on('pageerror', lambda e: page_errors.append(str(e)[:500]) if len(page_errors) < 100 else None)
                page.on('requestfailed', lambda r: failed.append({'url': r.url[:300], 'failure': (r.failure or '')[:200]}) if len(failed) < 100 else None)
                page.on('response', lambda r: failed.append({'url': r.url[:300], 'failure': 'HTTP %d' % r.status}) if r.status >= 400 and len(failed) < 100 else None)
                t0 = time.time()
                resp = page.goto(url, wait_until='load')
                page.wait_for_timeout(int(spec.get('settle_ms', 500)))
                base = {'status': resp.status if resp else None, 'final_url': page.url, 'title': page.title()[:300],
                        'ms': int((time.time() - t0) * 1000)}
                if kind == 'open':
                    timing = page.evaluate('() => { const n = performance.getEntriesByType("navigation")[0]; return n ? {dns: n.domainLookupEnd - n.domainLookupStart, connect: n.connectEnd - n.connectStart, ttfb: n.responseStart - n.requestStart, dom_content_loaded: n.domContentLoadedEventEnd, load: n.loadEventEnd} : {}; }')
                    result = dict(base, timings=timing, console_errors=len([m for m in console if m['type'] == 'error']) + len(page_errors))
                elif kind == 'dom':
                    what = spec.get('what', 'all')
                    result = dict(base)
                    root = spec.get('selector') or 'body'
                    if what in ('text', 'all'):
                        result['text'] = page.inner_text(root)[:int(spec.get('max_chars', 20000))]
                    if what in ('links', 'all'):
                        result['links'] = page.evaluate(LINKS_JS)
                    if what in ('forms', 'all'):
                        result['forms'] = page.evaluate(FORMS_JS)
                elif kind == 'console':
                    result = dict(base, messages=console, errors=[m for m in console if m['type'] == 'error'],
                                  page_errors=page_errors, failed_requests=failed)
                elif kind == 'a11y':
                    r = page.evaluate(A11Y_JS)
                    result = dict(base, findings=r['findings'], checked_text=r['checked_text'], color_scheme=spec.get('color_scheme', 'light'))
                else:
                    raise JobError('unknown job kind %s' % kind)
                c.close()
        finally:
            b.close()
    result['blocked_requests'] = blocked[:100]
    return result, artifacts


def cmd_run(args):
    q = args.queue
    for d in ('queue', 'running', 'done', 'artifacts', 'home'):
        os.makedirs(os.path.join(q, d), exist_ok=True)
    os.environ['HOME'] = os.path.join(q, 'home')
    os.environ['PLAYWRIGHT_BROWSERS_PATH'] = args.browsers
    lock = open(os.path.join(q, 'runner.lock'), 'w')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        print('another runner holds the pool; this one exits')
        return 0
    signal.signal(signal.SIGALRM, on_alarm)
    done = 0
    while True:
        jobs = sorted(f for f in os.listdir(os.path.join(q, 'queue')) if f.endswith('.json'))
        if not jobs:
            if args.once:
                break
            time.sleep(2)
            continue
        if not wait_for_load(args.max_load, 300):
            print('load above %.2f per CPU for 5 minutes; jobs stay queued' % args.max_load)
            if args.once:
                break
            continue
        name = jobs[0]
        running = os.path.join(q, 'running', name)
        try:
            os.replace(os.path.join(q, 'queue', name), running)
        except OSError:
            continue
        job = read_json(running, {}) or {}
        jid = re.sub(r'[^a-f0-9]', '', job.get('id', name[:-5]))[:32]
        out = {'id': jid, 'kind': job.get('kind'), 'engine': job.get('engine'), 'user_id': job.get('user_id'), 'state': 'running', 'started': now(), 'node': socket.gethostname()}
        write_json(running, dict(job, **out))
        t0 = time.time()
        limit = max(10, min(int(job.get('timeout', args.timeout)), 900))
        try:
            signal.alarm(limit)
            if job.get('kind') == 'install':
                ns = argparse.Namespace(engine=job.get('engine'), browsers=args.browsers, adopt_from=args.adopt_from, max_load=args.max_load)
                r = subprocess.run([sys.executable, os.path.abspath(__file__), 'install', ns.engine, '--browsers', args.browsers,
                                    '--max-load', str(args.max_load)] + sum((['--adopt-from', a] for a in args.adopt_from), []),
                                   capture_output=True, text=True, timeout=limit)
                lines = [l for l in r.stdout.splitlines() if l.startswith('{')]
                res = json.loads(lines[-1]) if lines else {'ok': False, 'error': (r.stderr or 'no output')[-500:]}
                out.update(state='done' if res.get('ok') else 'failed', result=res, error=None if res.get('ok') else res.get('error'))
            else:
                res, arts = run_job(job, args.browsers, os.path.join(q, 'artifacts', jid))
                out.update(state='done', result=res, artifacts=arts, error=None)
        except (JobError, Timeout) as e:
            out.update(state='failed', error=str(e)[:1000], result=None)
        except Exception as e:  # noqa: BLE001 - every failure ends as a failed job, the runner goes on
            out.update(state='failed', error=('%s: %s' % (type(e).__name__, str(e).split('\n')[0]))[:1000], result=None)
        finally:
            signal.alarm(0)
        out['finished'] = now()
        out['ms'] = int((time.time() - t0) * 1000)
        write_json(os.path.join(q, 'done', jid + '.json'), out)
        os.replace(running, os.path.join(q, 'done', jid + '.job.json'))
        done += 1
        print('%s %s %s %s %dms' % (out['finished'], jid, out['kind'], out['state'], out['ms']))
    print('runner: %d jobs' % done)
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    sub = ap.add_subparsers(dest='cmd', required=True)
    e = sub.add_parser('engines')
    e.add_argument('--browsers', required=True)
    i = sub.add_parser('install')
    i.add_argument('engine', choices=sorted(ENGINES))
    i.add_argument('--browsers', required=True)
    i.add_argument('--adopt-from', action='append', default=[])
    i.add_argument('--max-load', type=float, default=2.0)
    r = sub.add_parser('run')
    r.add_argument('--queue', required=True)
    r.add_argument('--browsers', required=True)
    r.add_argument('--once', action='store_true')
    r.add_argument('--max-load', type=float, default=2.0)
    r.add_argument('--timeout', type=int, default=120)
    r.add_argument('--adopt-from', action='append', default=[])
    a = ap.parse_args()
    if a.cmd == 'engines':
        print(json.dumps(engines_status(a.browsers), indent=1))
        return 0
    if a.cmd == 'install':
        return cmd_install(a)
    return cmd_run(a)


if __name__ == '__main__':
    sys.exit(main())
