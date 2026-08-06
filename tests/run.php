<?php
/**
 * Tests that need neither a web server nor a database.
 *
 * Run:  php tests/run.php
 *
 * Everything covered here decides who a request comes from and whether it is
 * encrypted - the kind of logic that fails silently and is hard to notice in
 * the browser, which is exactly why it is worth pinning down.
 */

declare(strict_types=1);

require __DIR__ . '/../api/lib/core.php';

$passed = 0;
$failed = 0;

function check(string $what, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL  {$what}\n";
    echo '      expected: ' . var_export($expected, true) . "\n";
    echo '      actual:   ' . var_export($actual, true) . "\n";
}

// --- ip_matches -------------------------------------------------------------
check('exact match', ip_matches('10.0.0.5', '10.0.0.5'), true);
check('exact mismatch', ip_matches('10.0.0.6', '10.0.0.5'), false);
check('/24 inside', ip_matches('192.168.1.77', '192.168.1.0/24'), true);
check('/24 outside', ip_matches('192.168.2.77', '192.168.1.0/24'), false);
check('/12 inside', ip_matches('172.20.5.5', '172.16.0.0/12'), true);
check('/12 outside', ip_matches('172.32.5.5', '172.16.0.0/12'), false);
check('/0 matches all', ip_matches('8.8.8.8', '0.0.0.0/0'), true);
check('/32 exact', ip_matches('10.0.0.5', '10.0.0.5/32'), true);
check('/32 neighbour', ip_matches('10.0.0.6', '10.0.0.5/32'), false);
check('ipv6 loopback', ip_matches('::1', '::1'), true);
check('ipv6 range', ip_matches('fd00::1234', 'fd00::/8'), true);
check('ipv4 not in ipv6 range', ip_matches('10.0.0.1', 'fd00::/8'), false);
check('garbage pattern', ip_matches('10.0.0.1', 'not-an-ip/24'), false);
check('empty pattern', ip_matches('10.0.0.1', ''), false);
check('bits beyond length', ip_matches('10.0.0.1', '10.0.0.0/99'), false);

// --- client_ip_from ---------------------------------------------------------
$direct = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];
check('forged header ignored without proxy', client_ip_from($direct, []), '203.0.113.9');
check('header honoured behind proxy',
    client_ip_from($direct, ['203.0.113.9']), '1.2.3.4');

$chain = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1, 127.0.0.1'];
check('client picked from a chain of own proxies',
    client_ip_from($chain, ['127.0.0.1', '10.0.0.0/8']), '9.9.9.9');

$spoof = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'];
check('invalid forwarded address falls back to remote',
    client_ip_from($spoof, ['127.0.0.1']), '127.0.0.1');
check('no forwarded header at all',
    client_ip_from(['REMOTE_ADDR' => '127.0.0.1'], ['127.0.0.1']), '127.0.0.1');
check('missing remote address', client_ip_from([], ['127.0.0.1']), '');

// --- is_https_from ----------------------------------------------------------
check('direct https', is_https_from(['HTTPS' => 'on'], []), true);
check('https=off is not https', is_https_from(['HTTPS' => 'off'], []), false);
check('plain http', is_https_from(['REMOTE_ADDR' => '10.0.0.1'], []), false);
check('forged proto ignored without proxy',
    is_https_from(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], []), false);
check('proto honoured behind proxy',
    is_https_from(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['127.0.0.1']), true);
check('proto chain uses the client end',
    is_https_from(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https, http'], ['127.0.0.1']), true);
check('proxy reports plain http',
    is_https_from(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'], ['127.0.0.1']), false);
check('forwarded port 443 counts',
    is_https_from(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PORT' => '443'], ['127.0.0.1']), true);
check('forwarded port 80 does not',
    is_https_from(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PORT' => '80'], ['127.0.0.1']), false);

// --- clean_value ------------------------------------------------------------
check('enum accepts a listed value', clean_value('red', 'enum:white,red,blue'), 'red');
check('enum rejects anything else', clean_value('a"onblur=x', 'enum:white,red,blue'), null);
check('bool from string', clean_value('1', 'bool'), 1);
check('bool from false-ish', clean_value('0', 'bool'), 0);
check('date is cut to ten characters', clean_value('2026-05-12T14:30', 'date'), '2026-05-12');
check('datetime gains seconds', clean_value('2026-05-12T14:30', 'datetime'), '2026-05-12 14:30:00');
check('decimal comma is accepted', clean_value('1,5', 'float'), 1.5);
check('empty becomes null', clean_value('', 'string'), null);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
