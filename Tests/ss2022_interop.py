"""Exercise real Xboard subscriptions with the released NodeRS and Mihomo."""

import base64
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.parse
import urllib.request

import yaml

root = Path.cwd()
sys.path.insert(0, str(root / '.interop/noders/workflows'))
from test_all_protocols_mihomo import (
    MihomoProtocolTester, free_port, generate_mihomo_config, kill_process, wait_for_server,
)

results = root / '.interop/results'
results.mkdir(parents=True, exist_ok=True)
fixture = root / 'plugins/Xbclient/Tests/ss2022_fixture.php'
data = json.loads(subprocess.check_output(['php', str(fixture)], text=True))
noders = root / '.interop/bin/noders-v0.1.47-linux-amd64/noders'
mihomo = root / '.interop/bin/mihomo'
panel_port = free_port()
base_url = f'http://127.0.0.1:{panel_port}'
config_path = results / 'noders.toml'
config_path.write_text(f'[panel]\napi = "{base_url}"\nkey = "stock-xboard-machine-token"\nmachine_id = {data["machine_id"]}\n')
tester = MihomoProtocolTester('shadowsocks', None, noders, mihomo, True)
logs = []


def start(args, label):
    log = (results / f'{label}.log').open('w')
    logs.append(log)
    return subprocess.Popen(args, stdout=log, stderr=subprocess.STDOUT, env={**os.environ, 'RUST_LOG': 'info'})


def subscription(user):
    query = urllib.parse.urlencode({'token': user['token'], 'flag': 'meta'})
    with urllib.request.urlopen(base_url + '/api/v1/client/subscribe?' + query, timeout=15) as response:
        proxies = yaml.safe_load(response.read())['proxies']
    assert len(proxies) == 3, proxies
    return proxies


def client_config(proxies, port):
    return {'mixed-port': port, 'log-level': 'info', 'proxies': proxies, 'rules': ['MATCH,DIRECT']}


try:
    tester.panel_proc = start(['php', 'artisan', 'serve', '--host=127.0.0.1', f'--port={panel_port}', '--no-reload'], 'panel')
    assert wait_for_server('127.0.0.1', panel_port), 'Xboard did not start'
    before = subscription(data['users'][0])
    before_path = results / 'before-plugin.yaml'
    before_path.write_text(yaml.safe_dump(client_config(before, free_port())))
    legacy_chacha = any(p['cipher'] == '2022-blake3-chacha20-poly1305' and ':' in p['password'] for p in before)
    check = subprocess.run([str(mihomo), '-t', '-f', str(before_path), '-d', str(results)], capture_output=True, text=True)
    (results / 'before-plugin-check.log').write_text(check.stdout + check.stderr)
    assert (check.returncode != 0) == legacy_chacha, 'Unexpected Mihomo baseline result'
    print(f'Legacy stock ChaCha20 subscription rejected by Mihomo: {legacy_chacha}', flush=True)

    subprocess.run(['php', str(fixture), 'enable'], check=True)
    tester.noders_proc = start([str(noders), str(config_path)], 'noders')
    for port in range(22000, 22003):
        assert wait_for_server('127.0.0.1', port, timeout=30), f'NodeRS did not listen on {port}'
    tcp_port = tester.tcp_echo.start()
    udp_port = tester.udp_echo.start()
    for index, user in enumerate(data['users']):
        proxies = subscription(user)
        (results / f'subscriber-{index}.yaml').write_text(yaml.safe_dump(proxies))
        assert len({p['cipher'] for p in proxies}) == 3
        for proxy in proxies:
            cipher = proxy['cipher']
            key_len = 16 if cipher == '2022-blake3-aes-128-gcm' else 32
            expected = base64.b64encode(user['uuid'].encode()[:key_len]).decode()
            assert proxy['password'].split(':')[-1] == expected
            assert proxy['password'].count(':') == (0 if 'chacha20' in cipher else 1)
            mixed_port, socks_port = free_port(), free_port()
            config = generate_mihomo_config('shadowsocks', proxy['port'], proxy['password'], user['uuid'], mixed_port, socks_port)
            config['proxies'] = [{**proxy, 'name': 'test-shadowsocks'}]
            path = results / f'client-{index}-{cipher}.yaml'
            path.write_text(yaml.safe_dump(config))
            tester.mihomo_proc = start([str(mihomo), '-f', str(path), '-d', str(results)], f'mihomo-{index}-{cipher}')
            try:
                assert wait_for_server('127.0.0.1', mixed_port), 'Mihomo did not start'
                ok, detail = tester._socks_echo_test(mixed_port, tcp_port, b'stock-xboard')
                assert ok, detail
                ok, detail = tester._udp_echo_test(mixed_port, udp_port)
                assert ok, detail
                print(f'PASS user={index} {cipher}: TCP and UDP', flush=True)
            finally:
                kill_process(tester.mihomo_proc)
                tester.mihomo_proc = None
finally:
    tester.cleanup()
    for log in logs:
        log.close()
