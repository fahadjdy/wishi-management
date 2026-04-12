<?php
/**
 * End-to-end API test runner — simulates a browser SPA hitting the Laravel API.
 * Tests CSRF, auth, every endpoint, business logic.
 */

const BASE = 'http://127.0.0.1:8000';
$cookieJar = sys_get_temp_dir() . '/wishi-e2e-cookies-' . uniqid() . '.txt';
@unlink($cookieJar);

$results = [];
$xsrfToken = null;

function color($txt, $c) {
    $codes = ['red' => 31, 'green' => 32, 'yellow' => 33, 'cyan' => 36, 'gray' => 90];
    return "\033[" . $codes[$c] . "m{$txt}\033[0m";
}

function readXsrf(string $cookieJar): ?string {
    if (! file_exists($cookieJar)) return null;
    $lines = file($cookieJar);
    if (! $lines) return null;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') && ! str_contains($line, 'XSRF-TOKEN')) continue;
        if (strpos($line, 'XSRF-TOKEN') === false) continue;
        // Format: domain \t includesub \t path \t secure \t expires \t name \t value
        $parts = explode("\t", $line);
        if (count($parts) >= 7) {
            return urldecode(end($parts));
        }
        // Fallback: whitespace split
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 7) return urldecode(end($parts));
    }
    return null;
}

function request(string $method, string $path, ?array $body = null, ?string $cookieJar = null, bool $expectJson = true): array {
    global $xsrfToken;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => BASE . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $headers = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
        'Origin: ' . BASE,
        'Referer: ' . BASE . '/',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) && $xsrfToken) {
        $headers[] = 'X-XSRF-TOKEN: ' . $xsrfToken;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = substr($raw, $headerSize);
    $headersStr = substr($raw, 0, $headerSize);

    // Refresh xsrf from response Set-Cookie headers (more reliable than cookie jar on Windows)
    if (preg_match_all('/Set-Cookie:\s*XSRF-TOKEN=([^;]+);/i', $headersStr, $m)) {
        $xsrfToken = urldecode(end($m[1]));
    } else {
        // fallback to file
        $newXsrf = readXsrf($cookieJar);
        if ($newXsrf) $xsrfToken = $newXsrf;
    }

    return [
        'code' => $code,
        'body' => $body,
        'headers' => $headersStr,
        'error' => $err,
        'json' => $expectJson ? json_decode($body, true) : null,
    ];
}

function assertOk(string $name, array $resp, int $expectCode = 200, ?callable $extra = null): bool {
    global $results;
    $pass = $resp['code'] === $expectCode;
    if ($pass && $extra) $pass = $extra($resp);
    $results[] = ['name' => $name, 'pass' => $pass, 'code' => $resp['code'], 'expected' => $expectCode];
    $tag = $pass ? color('PASS', 'green') : color('FAIL', 'red');
    echo "[{$tag}] {$name} → HTTP {$resp['code']}\n";
    if (! $pass) {
        if ($resp['code'] === $expectCode) {
            // assertion failed despite right code — show data
            echo "  " . color('data: ' . substr(json_encode($resp['json']['data'] ?? $resp['json']), 0, 300), 'gray') . "\n";
        } else {
            $b = is_string($resp['body']) ? substr($resp['body'], 0, 300) : '';
            echo "  " . color('body: ' . $b, 'gray') . "\n";
        }
    }
    return $pass;
}

echo color("\n╔═══════════════════════════════════════════════════════════╗\n", 'cyan');
echo color("║  WISHI E2E API TEST SUITE                                 ║\n", 'cyan');
echo color("╚═══════════════════════════════════════════════════════════╝\n\n", 'cyan');

// 1. CSRF cookie
$r = request('GET', '/sanctum/csrf-cookie', null, $cookieJar, false);
assertOk('CSRF cookie endpoint', $r, 204);
echo "  Cookie jar: {$cookieJar} (exists: " . (file_exists($cookieJar) ? 'yes' : 'no') . ", " . (file_exists($cookieJar) ? filesize($cookieJar) : 0) . " bytes)\n";
echo "  XSRF token captured: " . ($xsrfToken ? color('yes (' . substr($xsrfToken, 0, 30) . '…)', 'green') : color('NO', 'red')) . "\n";

// 2. Login as admin
$r = request('POST', '/api/v1/login', ['email' => 'demo@wishi.test', 'password' => 'Demo@1234'], $cookieJar);
assertOk('Login as demo admin', $r, 200, fn($r) => isset($r['json']['user']));
$me = $r['json']['user'] ?? null;
echo "  Logged in as: " . ($me['name'] ?? 'unknown') . " (id={$me['id']})\n";

// 3. /me endpoint
$r = request('GET', '/api/v1/me', null, $cookieJar);
assertOk('/me returns current user', $r, 200, fn($r) => ($r['json']['user']['email'] ?? '') === 'demo@wishi.test');

// 4. Dashboard
$r = request('GET', '/api/v1/dashboard', null, $cookieJar);
assertOk('GET /dashboard', $r, 200, fn($r) => isset($r['json']['active_wishis_count']));

// 5. List wishis
$r = request('GET', '/api/v1/wishis', null, $cookieJar);
assertOk('GET /wishis', $r, 200, fn($r) => isset($r['json']['data']));
$wishis = $r['json']['data'] ?? [];
echo "  Wishis visible: " . count($wishis) . "\n";
$adminWishi = null;
foreach ($wishis as $w) {
    if (! empty($w['is_admin'])) { $adminWishi = $w; break; }
}

// 6. Show single wishi
if ($adminWishi) {
    $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'], null, $cookieJar);
    assertOk('GET /wishis/{uuid}', $r, 200);
}

// 7. Create new wishi
$newPayload = [
    'name' => 'E2E Test Pool ' . substr(uniqid(), -5),
    'total_members' => 5,
    'monthly_contribution' => 1000,
    'duration_months' => 5,
    'start_date' => date('Y-m-d', strtotime('+2 days')),
    'cycle_type' => 'random',
    'require_approval' => true,
    'status' => 'draft',
];
$r = request('POST', '/api/v1/wishis', $newPayload, $cookieJar);
assertOk('POST /wishis (create draft)', $r, 201, fn($r) => isset($r['json']['data']['uuid']));
$createdUuid = $r['json']['data']['uuid'] ?? null;
echo "  Created wishi uuid: {$createdUuid}\n";

// 8. Update wishi
if ($createdUuid) {
    $r = request('PUT', '/api/v1/wishis/' . $createdUuid, ['name' => 'E2E Updated Name'], $cookieJar);
    assertOk('PUT /wishis/{uuid}', $r, 200, fn($r) => ($r['json']['data']['name'] ?? '') === 'E2E Updated Name');
}

// 9. Activation MUST be refused until all seats are filled (only creator is a member so far).
if ($createdUuid) {
    $r = request('POST', '/api/v1/wishis/' . $createdUuid . '/activate', null, $cookieJar);
    assertOk('POST /activate refused (not full)', $r, 422, fn($r) => isset($r['json']['message']));
}

// 10. List members
if ($adminWishi) {
    $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/members', null, $cookieJar);
    assertOk('GET /wishis/{uuid}/members', $r, 200);
    $members = $r['json']['data'] ?? [];
    $pendingMember = null;
    foreach ($members as $m) {
        if ($m['status'] === 'pending') { $pendingMember = $m; break; }
    }

    // 11. Approve pending member
    if ($pendingMember) {
        $r = request('PUT', '/api/v1/wishis/' . $adminWishi['uuid'] . '/members/' . $pendingMember['id'] . '/approve', null, $cookieJar);
        assertOk('PUT /members/{id}/approve', $r, 200, fn($r) => in_array($r['json']['data']['status'] ?? '', ['approved', 'active']));
    } else {
        echo "  " . color('skipped: no pending member to approve', 'gray') . "\n";
    }
}

// 12. List cycles
if ($adminWishi) {
    $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles', null, $cookieJar);
    assertOk('GET /wishis/{uuid}/cycles', $r, 200);
    $cycles = $r['json']['data'] ?? [];
    $activeCycle = null;
    foreach ($cycles as $c) {
        if (in_array($c['status'], ['contribution_open', 'bidding_open'])) { $activeCycle = $c; break; }
    }
    echo "  Active cycle: " . ($activeCycle ? "#{$activeCycle['cycle_number']} ({$activeCycle['status']})" : 'none') . "\n";

    // 13. Show cycle
    if ($activeCycle) {
        $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles/' . $activeCycle['id'], null, $cookieJar);
        assertOk('GET /wishis/{uuid}/cycles/{id}', $r, 200);

        // 14. List contributions
        $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles/' . $activeCycle['id'] . '/contributions', null, $cookieJar);
        assertOk('GET /cycles/{id}/contributions', $r, 200);

        // 15. Record an unpaid contribution (admin records on behalf of any pending member)
        $rContribs = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles/' . $activeCycle['id'] . '/contributions', null, $cookieJar);
        $unpaid = null;
        foreach ($rContribs['json']['data'] ?? [] as $c) {
            if (in_array($c['status'], ['pending', 'late'])) { $unpaid = $c; break; }
        }
        if ($unpaid) {
            $r = request('POST', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles/' . $activeCycle['id'] . '/contributions',
                ['user_id' => $unpaid['user_id'], 'payment_method' => 'cash', 'payment_reference' => 'E2E-' . uniqid()], $cookieJar);
            assertOk('POST contribution (admin records pending member)', $r, 200);
        } else {
            echo "  " . color('skipped: no pending contribution to record', 'gray') . "\n";
        }

        // 16. List tenders (only meaningful if tender mode)
        $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/cycles/' . $activeCycle['id'] . '/tenders', null, $cookieJar);
        assertOk('GET tenders', $r, 200);
    }
}

// 17. Audit log
if ($adminWishi) {
    $r = request('GET', '/api/v1/wishis/' . $adminWishi['uuid'] . '/audit-logs', null, $cookieJar);
    assertOk('GET audit-logs', $r, 200, fn($r) => isset($r['json']['data']));
    echo "  Audit entries: " . count($r['json']['data'] ?? []) . "\n";
}

// 18. Credit score
$r = request('GET', '/api/v1/me/credit-score', null, $cookieJar);
assertOk('GET /me/credit-score', $r, 200, fn($r) => isset($r['json']['score']));

// 19. Notifications
$r = request('GET', '/api/v1/notifications', null, $cookieJar);
assertOk('GET /notifications', $r, 200, fn($r) => array_key_exists('unread_count', $r['json']));

// ===== Switch to a regular member to test member-side flows =====
echo color("\n--- Switching to regular member context ---\n", 'yellow');

$memberEmail = null;
$memberPwd = 'Password@123';
$puneHybrid = null;
$rWishis = request('GET', '/api/v1/wishis', null, $cookieJar);
foreach ($rWishis['json']['data'] ?? [] as $w) {
    if ($w['cycle_type'] === 'hybrid') { $puneHybrid = $w; break; }
}
if ($puneHybrid) {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=wishi', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->prepare("SELECT u.email FROM users u JOIN wishi_members wm ON wm.user_id = u.id JOIN wishis w ON w.id = wm.wishi_id WHERE w.uuid = ? AND wm.is_admin = 0 AND wm.has_won = 0 AND wm.status IN ('active','approved') LIMIT 1");
        $stmt->execute([$puneHybrid['uuid']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $memberEmail = $row['email'] ?? null;
    } catch (Exception $e) {
        echo "  PDO err: " . $e->getMessage() . "\n";
    }
}
echo "  Picked member: " . ($memberEmail ?: 'NONE') . "\n";

request('POST', '/api/v1/logout', null, $cookieJar);
@unlink($cookieJar);
$xsrfToken = null;
request('GET', '/sanctum/csrf-cookie', null, $cookieJar, false);

if ($memberEmail) {
    $r = request('POST', '/api/v1/login', ['email' => $memberEmail, 'password' => $memberPwd], $cookieJar);
    assertOk("Login as member ({$memberEmail})", $r, 200);

    if ($puneHybrid) {
        $r = request('GET', '/api/v1/wishis/' . $puneHybrid['uuid'] . '/cycles', null, $cookieJar);
        $tenderCycle = null;
        foreach ($r['json']['data'] ?? [] as $c) {
            if ($c['status'] === 'bidding_open') { $tenderCycle = $c; break; }
        }
        if ($tenderCycle) {
            $r = request('POST', '/api/v1/wishis/' . $puneHybrid['uuid'] . '/cycles/' . $tenderCycle['id'] . '/tenders',
                ['bid_amount' => 75000], $cookieJar);
            assertOk('POST bid as member', $r, 201);

            $r = request('PUT', '/api/v1/wishis/' . $puneHybrid['uuid'] . '/cycles/' . $tenderCycle['id'] . '/select-winner',
                ['method' => 'auto'], $cookieJar);
            assertOk('Member CANNOT select winner', $r, 403);
        } else {
            echo "  " . color('no active tender cycle found', 'gray') . "\n";
        }
    }
}

// Member cannot create wishi (admin-only now)
$r = request('POST', '/api/v1/wishis', [
    'name' => 'Member Attempt', 'total_members' => 5, 'monthly_contribution' => 100,
    'duration_months' => 5, 'start_date' => date('Y-m-d', strtotime('+2 days')),
    'cycle_type' => 'random',
], $cookieJar);
assertOk('Member CANNOT create WISHI', $r, 403);

// Member cannot access admin user list
$r = request('GET', '/api/v1/admin/users', null, $cookieJar);
assertOk('Member CANNOT list admin users', $r, 403);

// 24. Logout
$r = request('POST', '/api/v1/logout', null, $cookieJar);
assertOk('Logout', $r, 200);

// ===== Admin endpoints =====
echo color("\n--- Admin user management ---\n", 'yellow');
@unlink($cookieJar);
$xsrfToken = null;
request('GET', '/sanctum/csrf-cookie', null, $cookieJar, false);
$r = request('POST', '/api/v1/login', ['email' => 'demo@wishi.test', 'password' => 'Demo@1234'], $cookieJar);
assertOk('Admin re-login', $r, 200);

$r = request('GET', '/api/v1/admin/users', null, $cookieJar);
assertOk('GET /admin/users', $r, 200, fn($r) => isset($r['json']['data']) && isset($r['json']['summary']));
echo "  Total users: " . ($r['json']['summary']['total_users'] ?? 0) . " | Admins: " . ($r['json']['summary']['admins'] ?? 0) . "\n";
$users = $r['json']['data'] ?? [];
$nonAdminUser = null;
foreach ($users as $u) {
    if (! $u['is_admin'] && $u['id'] !== 1) { $nonAdminUser = $u; break; }
}

if ($nonAdminUser) {
    $uid = $nonAdminUser['id'];
    $r = request('GET', '/api/v1/admin/users/' . $uid, null, $cookieJar);
    assertOk('GET /admin/users/{id}', $r, 200);

    $r = request('PUT', '/api/v1/admin/users/' . $uid . '/lock', ['minutes' => 30, 'reason' => 'E2E test'], $cookieJar);
    assertOk('PUT lock user', $r, 200, fn($r) => $r['json']['data']['is_locked'] === true);

    $r = request('PUT', '/api/v1/admin/users/' . $uid . '/unlock', null, $cookieJar);
    assertOk('PUT unlock user', $r, 200, fn($r) => $r['json']['data']['is_locked'] === false);

    $r = request('PUT', '/api/v1/admin/users/' . $uid . '/credit-score', ['points' => 5, 'reason' => 'E2E adjustment'], $cookieJar);
    assertOk('PUT adjust credit (+5)', $r, 200);

    $r = request('PUT', '/api/v1/admin/users/' . $uid . '/toggle-admin', null, $cookieJar);
    assertOk('PUT toggle admin (grant)', $r, 200, fn($r) => $r['json']['data']['is_admin'] === true);

    $r = request('PUT', '/api/v1/admin/users/' . $uid . '/toggle-admin', null, $cookieJar);
    assertOk('PUT toggle admin (revoke)', $r, 200, fn($r) => $r['json']['data']['is_admin'] === false);
}

// Cannot toggle own admin status
$r = request('PUT', '/api/v1/admin/users/1/toggle-admin', null, $cookieJar);
assertOk('CANNOT toggle own admin', $r, 422);

// Admin CAN create wishi
$r = request('POST', '/api/v1/wishis', [
    'name' => 'Admin Test Pool', 'total_members' => 5, 'monthly_contribution' => 500,
    'duration_months' => 5, 'start_date' => date('Y-m-d', strtotime('+2 days')),
    'cycle_type' => 'random', 'status' => 'draft',
], $cookieJar);
assertOk('Admin CAN create WISHI', $r, 201);

request('POST', '/api/v1/logout', null, $cookieJar);

// 25. /me after logout = 401
$r = request('GET', '/api/v1/me', null, $cookieJar);
assertOk('/me after logout returns 401', $r, 401);

// ===== Test register flow with new user =====
echo color("\n--- Registering brand new user ---\n", 'yellow');
@unlink($cookieJar);
$xsrfToken = null;
request('GET', '/sanctum/csrf-cookie', null, $cookieJar, false);

$newEmail = 'qa-' . uniqid() . '@test.local';
$r = request('POST', '/api/v1/register', [
    'name' => 'QA Test User',
    'email' => $newEmail,
    'password' => 'StrongPass!2024',
    'password_confirmation' => 'StrongPass!2024',
], $cookieJar);
assertOk('POST /register new user', $r, 201);

$r = request('GET', '/api/v1/me', null, $cookieJar);
assertOk('Registered user is logged in', $r, 200, fn($r) => ($r['json']['user']['email'] ?? '') === $newEmail);

// ===== Summary =====
$pass = count(array_filter($results, fn($r) => $r['pass']));
$fail = count($results) - $pass;
echo color("\n╔══════════════════════════════════════════╗\n", 'cyan');
echo color("║  RESULTS                                 ║\n", 'cyan');
echo color("╚══════════════════════════════════════════╝\n", 'cyan');
echo "Total: " . count($results) . " | " . color("Pass: $pass", 'green') . " | " . color("Fail: $fail", 'red') . "\n";
if ($fail) {
    echo color("\nFailures:\n", 'red');
    foreach ($results as $r) {
        if (! $r['pass']) echo "  ✗ {$r['name']} (got {$r['code']}, expected {$r['expected']})\n";
    }
}
exit($fail > 0 ? 1 : 0);
