<?php
declare(strict_types=1);

define('SETTINGS_FILE', __DIR__ . '/settings.json');
define('META_API_VERSION', 'v19.0');
define('META_API_BASE', 'https://graph.facebook.com/' . META_API_VERSION);
define('TELEGRAM_API_BASE', 'https://api.telegram.org/bot');

// ─── SETTINGS ────────────────────────────────────────────────

function loadSettings(): array
{
    if (!file_exists(SETTINGS_FILE)) return [];
    $json = file_get_contents(SETTINGS_FILE);
    $data = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Unread Alert — settings.json parse error: ' . json_last_error_msg());
        return [];
    }
    return $data ?? [];
}

function saveSettings(array $data): void
{
    $result = file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        throw new RuntimeException('Failed to write settings.json — check file permissions');
    }
}

function isSetupDone(): bool
{
    $s = loadSettings();
    return !empty($s['cron_key']) && !empty($s['telegram']['bot_token']) && !empty($s['pages']);
}

// ─── SECURITY ────────────────────────────────────────────────

function generateKey(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function verifyKey(string $provided, string $stored): bool
{
    if (empty($stored)) return false;
    return hash_equals($stored, $provided);
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── HTTP ────────────────────────────────────────────────────

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'UnreadAlert/1.0',
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['status' => 0, 'data' => null, 'error' => $error];
    return ['status' => $code, 'data' => json_decode($body, true), 'error' => null];
}

function httpPost(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['status' => 0, 'data' => null, 'error' => $error];
    return ['status' => $code, 'data' => json_decode($body, true), 'error' => null];
}

// ─── META API ────────────────────────────────────────────────

function fetchPageUnreadCount(string $pageId, string $token): int
{
    $unread = 0;
    $url    = META_API_BASE . "/{$pageId}/conversations"
            . "?platform=messenger&fields=unread_count&limit=100"
            . "&access_token=" . urlencode($token);

    while ($url) {
        $res = httpGet($url);
        if ($res['error'] || $res['status'] !== 200 || empty($res['data']['data'])) break;
        foreach ($res['data']['data'] as $conv) {
            $count = (int)($conv['unread_count'] ?? 0);
            if ($count > 0) $unread += $count;
        }
        $url = $res['data']['paging']['next'] ?? null;
    }

    return $unread;
}

function fetchAllPagesUnread(array $pages): array
{
    $results = [];
    foreach ($pages as $page) {
        $results[] = [
            'id'           => $page['id'],
            'name'         => $page['name'],
            'unread_count' => fetchPageUnreadCount($page['id'], $page['token']),
        ];
    }
    return $results;
}

function verifyPageToken(string $pageId, string $token): array
{
    $res = httpGet(META_API_BASE . "/{$pageId}?fields=name&access_token=" . urlencode($token));
    if ($res['error'] || $res['status'] !== 200 || empty($res['data']['name'])) {
        return ['ok' => false, 'message' => $res['data']['error']['message'] ?? 'Invalid token or page ID'];
    }
    return ['ok' => true, 'name' => $res['data']['name']];
}

function handleVerifyPage(): void
{
    $pageId = trim($_POST['page_id'] ?? '');
    $token  = trim($_POST['token'] ?? '');
    if (!$pageId || !$token) {
        jsonResponse(['ok' => false, 'message' => 'Page ID and token required'], 400);
    }
    jsonResponse(verifyPageToken($pageId, $token));
}

function handleFetchPages(): void
{
    $userToken = trim($_POST['user_token'] ?? '');
    if (!$userToken) {
        jsonResponse(['ok' => false, 'message' => 'User token required'], 400);
    }
    $url = META_API_BASE . '/me/accounts?fields=id,name,access_token&access_token=' . urlencode($userToken);
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error || !$body) {
        jsonResponse(['ok' => false, 'message' => 'Connection failed: ' . $error]);
    }
    $data = json_decode($body, true);
    if (isset($data['error'])) {
        jsonResponse(['ok' => false, 'message' => $data['error']['message'] ?? 'Facebook API error']);
    }
    $pages = $data['data'] ?? [];
    if (empty($pages)) {
        jsonResponse(['ok' => false, 'message' => 'No pages found. Make sure you are an admin of at least one Facebook Page.']);
    }
    jsonResponse(['ok' => true, 'pages' => $pages]);
}

function handleFetchPages(): void
{
    $userToken = trim($_POST['user_token'] ?? '');
    if (!$userToken) {
        jsonResponse(['ok' => false, 'message' => 'User access token required'], 400);
    }
    $url = META_API_BASE . '/me/accounts?fields=id,name,access_token&access_token=' . urlencode($userToken);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'UnreadAlert/1.0',
    ]);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        jsonResponse(['ok' => false, 'message' => 'Connection failed: ' . $error]);
    }
    $data = json_decode($body, true);
    if (isset($data['error'])) {
        jsonResponse(['ok' => false, 'message' => $data['error']['message'] ?? 'Facebook API error']);
    }
    $pages = $data['data'] ?? [];
    if (empty($pages)) {
        jsonResponse(['ok' => false, 'message' => 'No pages found. Make sure your token has pages_show_list permission and you have Page admin access.']);
    }
    jsonResponse(['ok' => true, 'pages' => $pages]);
}

// ─── TELEGRAM ────────────────────────────────────────────────

function buildTelegramMessage(array $results, string $timezone): string
{
    $tz    = new DateTimeZone($timezone ?: 'Asia/Dhaka');
    $now   = new DateTime('now', $tz);
    $time  = $now->format('Y-m-d h:i A');

    $lines     = [];
    $total     = 0;
    $pageCount = 0;

    $lines[] = "🔴 <b>Unread Message Alert</b>";
    $lines[] = "🕐 {$time} ({$timezone})";
    $lines[] = "━━━━━━━━━━━━━━━━━━━━";

    foreach ($results as $page) {
        if ((int)$page['unread_count'] <= 0) continue;
        $pageCount++;
        $total    += (int)$page['unread_count'];
        $inboxUrl  = 'https://business.facebook.com/latest/inbox/messenger?asset_id=' . urlencode($page['id']);
        $lines[]   = '';
        $lines[]   = '📄 <b>' . htmlspecialchars($page['name']) . '</b>';
        $lines[]   = '   💬 ' . $page['unread_count'] . ' unread messages';
        $lines[]   = '   🔗 <a href="' . $inboxUrl . '">Open Inbox</a>';
    }

    if ($total === 0) return '';

    $lines[] = '';
    $lines[] = '━━━━━━━━━━━━━━━━━━━━';
    $lines[] = '📊 Total: <b>' . $total . ' unread</b> across ' . $pageCount . ' ' . ($pageCount === 1 ? 'page' : 'pages');

    return implode("\n", $lines);
}

function sendTelegramMessage(string $botToken, string $chatId, string $text): array
{
    $url  = TELEGRAM_API_BASE . $botToken . '/sendMessage';
    $data = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 1,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['ok' => false, 'message' => $error];
    $res = json_decode($body, true);
    if (empty($res['ok'])) {
        return ['ok' => false, 'message' => $res['description'] ?? 'Telegram API error'];
    }
    return ['ok' => true];
}

function handleTestTelegram(): void
{
    $botToken = trim($_POST['bot_token'] ?? '');
    $chatId   = trim($_POST['chat_id'] ?? '');
    if (!$botToken || !$chatId) {
        jsonResponse(['ok' => false, 'message' => 'Bot token and chat ID required'], 400);
    }
    $msg = "✅ <b>Unread Alert System</b>\n\nTelegram connection successful! Your alerts will appear here.";
    jsonResponse(sendTelegramMessage($botToken, $chatId, $msg));
}

function handleGetChatId(): void
{
    $botToken = trim($_POST['bot_token'] ?? '');
    if (!$botToken) {
        jsonResponse(['ok' => false, 'message' => 'Bot token required'], 400);
    }
    $ch = curl_init(TELEGRAM_API_BASE . $botToken . '/getUpdates?limit=10&offset=-10');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    curl_close($ch);
    $res = ['status' => 200, 'data' => json_decode($body, true), 'error' => null];
    if (!$body) $res['error'] = 'Connection failed';
    if ($res['error'] || $res['status'] !== 200) {
        jsonResponse(['ok' => false, 'message' => $res['data']['description'] ?? 'Failed to reach Telegram API']);
    }
    $updates = $res['data']['result'] ?? [];
    if (empty($updates)) {
        jsonResponse(['ok' => false, 'message' => 'No messages found. Please send any message to your bot first, then try again.']);
    }
    $latest  = end($updates);
    $chat    = $latest['message']['chat'] ?? $latest['callback_query']['message']['chat'] ?? null;
    if (!$chat) {
        jsonResponse(['ok' => false, 'message' => 'Could not detect chat. Send a message to your bot and retry.']);
    }
    $name = trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? $chat['title'] ?? ''));
    jsonResponse(['ok' => true, 'chat_id' => (string)$chat['id'], 'name' => $name]);
}

// ─── OFFICE HOURS ────────────────────────────────────────────

function isWithinOfficeHours(array $oh): bool
{
    if (!empty($oh['always_on'])) return true;
    $tz      = new DateTimeZone($oh['timezone'] ?? 'Asia/Dhaka');
    $current = (new DateTime('now', $tz))->format('H:i');
    return $current >= ($oh['start'] ?? '09:00') && $current <= ($oh['end'] ?? '18:00');
}

// ─── CRON RUNNER ─────────────────────────────────────────────

function handleRun(array $settings): void
{
    if (!verifyKey($_GET['key'] ?? '', $settings['cron_key'] ?? '')) {
        jsonResponse(['ok' => false, 'message' => 'Unauthorized'], 401);
    }

    $oh = $settings['office_hours'] ?? ['always_on' => true];
    if (!isWithinOfficeHours($oh)) {
        jsonResponse(['ok' => true, 'message' => 'Outside office hours, skipped']);
    }

    $results = fetchAllPagesUnread($settings['pages'] ?? []);

    $tz = $settings['office_hours']['timezone'] ?? 'Asia/Dhaka';
    $settings['last_result'] = $results;
    $settings['last_check']  = (new DateTime('now', new DateTimeZone($tz)))->format(DateTime::ATOM);
    saveSettings($settings);

    $message = buildTelegramMessage($results, $tz);
    if (empty($message)) {
        jsonResponse(['ok' => true, 'message' => 'No unread messages, notification skipped', 'results' => $results]);
    }

    $tg   = $settings['telegram'] ?? [];
    $sent = sendTelegramMessage($tg['bot_token'] ?? '', $tg['chat_id'] ?? '', $message);
    jsonResponse([
        'ok'      => $sent['ok'],
        'message' => $sent['ok'] ? 'Alert sent successfully' : $sent['message'],
        'results' => $results,
    ]);
}

// ─── REST API ────────────────────────────────────────────────

function handleApi(array $settings): void
{
    if (!verifyKey($_GET['key'] ?? '', $settings['api_key'] ?? '')) {
        jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    $oh     = $settings['office_hours'] ?? ['always_on' => true];
    $cached = $settings['last_result'] ?? [];

    $pages = array_map(fn($r) => [
        'id'           => $r['id'],
        'name'         => $r['name'],
        'unread_count' => (int)($r['unread_count'] ?? 0),
        'inbox_url'    => 'https://business.facebook.com/latest/inbox/messenger?asset_id=' . urlencode($r['id']),
    ], $cached);

    jsonResponse([
        'status'              => 'ok',
        'checked_at'          => $settings['last_check'] ?? null,
        'office_hours_active' => isWithinOfficeHours($oh),
        'total_unread'        => array_sum(array_column($pages, 'unread_count')),
        'pages'               => $pages,
    ]);
}

// ─── SAVE & UTILS ────────────────────────────────────────────

function handleSave(): void
{
    $existing = loadSettings();

    $pages = [];
    foreach ($_POST['page_id'] ?? [] as $i => $id) {
        $id    = trim($id);
        $name  = trim($_POST['page_name'][$i] ?? '');
        $token = trim($_POST['page_token'][$i] ?? '');
        if ($id && $name && $token) {
            $pages[] = ['id' => $id, 'name' => $name, 'token' => $token];
        }
    }

    $data = [
        'cron_key'       => trim($_POST['cron_key'] ?? $existing['cron_key'] ?? generateKey()),
        'api_key'        => trim($_POST['api_key']  ?? $existing['api_key']  ?? generateKey()),
        'check_interval' => (int)($_POST['check_interval'] ?? 15),
        'office_hours'   => [
            'always_on' => ($_POST['always_on'] ?? '0') === '1',
            'start'     => $_POST['oh_start']  ?? '09:00',
            'end'       => $_POST['oh_end']    ?? '18:00',
            'timezone'  => $_POST['timezone']  ?? 'Asia/Dhaka',
        ],
        'telegram' => [
            'bot_token' => trim($_POST['bot_token'] ?? $existing['telegram']['bot_token'] ?? ''),
            'chat_id'   => trim($_POST['chat_id']   ?? $existing['telegram']['chat_id']   ?? $existing['telegram']['channel_id'] ?? ''),
        ],
        'pages'       => $pages ?: ($existing['pages'] ?? []),
        'last_check'  => $existing['last_check']  ?? null,
        'last_result' => $existing['last_result'] ?? [],
    ];

    saveSettings($data);
    header('Location: ' . ($_POST['redirect'] ?? 'index.php'));
    exit;
}

function handleGetKeys(array $settings): void
{
    // Only available during the setup wizard flow (before setup is complete)
    if (isSetupDone()) {
        jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 403);
    }
    jsonResponse(['cron_key' => $settings['cron_key'] ?? '', 'api_key' => $settings['api_key'] ?? '']);
}

// ─── UI: SETUP WIZARD ────────────────────────────────────────

function renderSetupWizard(): void
{
    $cronKey = generateKey();
    $apiKey  = generateKey();
    $proto   = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $base    = $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    $zones   = ['Asia/Dhaka','Asia/Kolkata','Asia/Karachi','Asia/Dubai','Europe/London','America/New_York','America/Los_Angeles','UTC'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unread Alert — Setup</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.wizard{background:#1e293b;border-radius:16px;width:100%;max-width:620px;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,.5)}
.wh{background:#0f172a;padding:24px}
.wt{font-size:20px;font-weight:700;color:#f8fafc}
.pb{margin-top:16px;background:#334155;border-radius:99px;height:6px}
.pf{height:6px;border-radius:99px;background:#3b82f6;transition:width .3s}
.sl{margin-top:8px;font-size:12px;color:#64748b}
.wb{padding:28px}
.step{display:none}.step.active{display:block}
.step h2{font-size:18px;font-weight:600;color:#f1f5f9;margin-bottom:6px}
.step p{font-size:14px;color:#94a3b8;margin-bottom:24px}
.fg{margin-bottom:18px}
label{display:block;font-size:13px;font-weight:500;color:#cbd5e1;margin-bottom:6px}
.iw{display:flex;gap:8px}
input[type=text],input[type=password],select{width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 14px;color:#f1f5f9;font-size:14px;outline:none;transition:border-color .2s}
input:focus,select:focus{border-color:#3b82f6}
.btn{padding:10px 18px;border-radius:8px;border:none;font-size:14px;font-weight:500;cursor:pointer;transition:all .2s;white-space:nowrap;text-decoration:none;display:inline-block}
.btn-primary{background:#3b82f6;color:#fff}.btn-primary:hover{background:#2563eb}
.btn-ghost{background:#334155;color:#cbd5e1}.btn-ghost:hover{background:#475569}
.btn-success{background:#22c55e;color:#fff}
.btn-danger{background:#ef4444;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer}
.btn-sm{padding:6px 14px;font-size:12px}
.wf{display:flex;justify-content:space-between;align-items:center;padding:20px 28px;border-top:1px solid #334155}
.pr{background:#0f172a;border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid #334155}
.prh{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.prt{font-size:13px;font-weight:600;color:#94a3b8}
.tw{display:flex;gap:12px;margin-bottom:16px}
.to{flex:1;background:#0f172a;border:2px solid #334155;border-radius:8px;padding:12px;cursor:pointer;text-align:center;transition:all .2s}
.to.active{border-color:#3b82f6;background:#1e3a5f}
.to span{display:block;font-size:13px;font-weight:600;color:#f1f5f9}
.to small{color:#64748b;font-size:11px}
.tg{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.st{font-size:13px;padding:8px 12px;border-radius:6px;margin-top:8px;display:none}
.st.success{background:#14532d;color:#86efac;display:block}
.st.error{background:#7f1d1d;color:#fca5a5;display:block}
.st.loading{background:#1e3a5f;color:#93c5fd;display:block}
.cb{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:14px 44px 14px 14px;font-family:monospace;font-size:13px;color:#86efac;position:relative;word-break:break-all}
.cp{position:absolute;top:8px;right:8px;background:#334155;border:none;color:#cbd5e1;padding:4px 10px;border-radius:4px;font-size:11px;cursor:pointer}
.cp:hover{background:#475569}
.di{font-size:48px;text-align:center;margin-bottom:16px}
.is{display:flex;gap:8px;flex-wrap:wrap}
.ib{background:#0f172a;border:2px solid #334155;color:#cbd5e1;border-radius:8px;padding:8px 16px;cursor:pointer;font-size:14px;transition:all .2s}
.ib.active,.ib:hover{border-color:#3b82f6;color:#f1f5f9;background:#1e3a5f}
input[name=check_interval]{display:none}
</style>
</head>
<body>
<div class="wizard">
  <div class="wh">
    <div class="wt">🔔 Unread Alert — First Time Setup</div>
    <div class="pb"><div class="pf" id="pf" style="width:20%"></div></div>
    <div class="sl" id="sl">Step 1 of 5 — Security</div>
  </div>

  <form method="POST" action="<?= htmlspecialchars($base) ?>?action=save" id="setupForm">
  <input type="hidden" name="redirect" value="<?= htmlspecialchars($base) ?>">

  <div class="wb">

    <!-- Step 1: Security -->
    <div class="step active" id="step1">
      <h2>🔐 Security Setup</h2>
      <p>These secret keys protect your cron trigger and API from unauthorized access.</p>
      <div class="fg">
        <label>Cron Secret Key</label>
        <div class="iw">
          <input type="text" name="cron_key" id="cronKey" value="<?= htmlspecialchars($cronKey) ?>" required>
          <button type="button" class="btn btn-ghost btn-sm" onclick="regen('cronKey')">Regenerate</button>
        </div>
      </div>
      <div class="fg">
        <label>API Key <small style="color:#64748b">(for third-party integrations)</small></label>
        <div class="iw">
          <input type="text" name="api_key" id="apiKey" value="<?= htmlspecialchars($apiKey) ?>">
          <button type="button" class="btn btn-ghost btn-sm" onclick="regen('apiKey')">Regenerate</button>
        </div>
      </div>
    </div>

    <!-- Step 2: Telegram -->
    <div class="step" id="step2">
      <h2>📨 Telegram Setup</h2>
      <p>Create a bot via <b>@BotFather</b>. Then open your bot in Telegram and send it <b>/start</b> or any message — then click "Get My Chat ID" below.</p>
      <div class="fg">
        <label>Bot Token</label>
        <input type="password" name="bot_token" id="botToken" placeholder="123456:ABC-DEF..." required>
      </div>
      <div class="fg">
        <label>Chat ID <small style="color:#64748b">(your Telegram user/group ID)</small></label>
        <div class="iw">
          <input type="text" name="chat_id" id="channelId" placeholder="e.g. 123456789 (click Get My Chat ID)" required>
          <button type="button" class="btn btn-ghost btn-sm" onclick="getChatId()">Get My Chat ID</button>
        </div>
      </div>
      <button type="button" class="btn btn-ghost" onclick="testTelegram()">📤 Send Test Message</button>
      <div class="st" id="tgSt"></div>
    </div>

    <!-- Step 3: Pages -->
    <div class="step" id="step3">
      <h2>📄 Facebook Pages</h2>
      <p>Enter your <b>Facebook User Access Token</b> to auto-fetch all pages you manage, then select which to monitor.</p>
      <div class="fg">
        <label>Facebook User Access Token</label>
        <div class="iw">
          <input type="password" id="userToken" placeholder="EAAxxxxx..." required>
          <button type="button" class="btn btn-primary btn-sm" onclick="fetchPages()">🔍 Fetch My Pages</button>
        </div>
      </div>
      <div class="st" id="fetchSt"></div>
      <div id="pageChecklist" style="display:none;margin-top:12px"></div>
    </div>

    <!-- Step 4: Settings -->
    <div class="step" id="step4">
      <h2>⚙️ Notification Settings</h2>
      <p>Choose how often to check for unread messages and when notifications should be sent.</p>
      <div class="fg">
        <label>Check Interval</label>
        <div class="is">
          <?php foreach ([5,10,15,30,60] as $m): ?>
          <button type="button" class="ib <?= $m===15?'active':'' ?>" onclick="setInt(<?= $m ?>)"><?= $m ?> min</button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="check_interval" id="intVal" value="15">
      </div>
      <div class="fg">
        <label>Notification Hours</label>
        <div class="tw">
          <div class="to active" id="t24" onclick="setHours('always')"><span>🌐 24/7 Always On</span><small>Notify any time</small></div>
          <div class="to" id="tCustom" onclick="setHours('custom')"><span>🕐 Office Hours</span><small>Set a time range</small></div>
        </div>
        <input type="hidden" name="always_on" id="alwaysOn" value="1">
        <div id="customHours" style="display:none">
          <div class="tg">
            <div class="fg"><label>Start Time</label><input type="text" name="oh_start" value="09:00"></div>
            <div class="fg"><label>End Time</label><input type="text" name="oh_end" value="18:00"></div>
          </div>
          <div class="fg">
            <label>Timezone</label>
            <select name="timezone">
              <?php foreach ($zones as $z): ?>
              <option value="<?= $z ?>" <?= $z==='Asia/Dhaka'?'selected':'' ?>><?= $z ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 5: Done -->
    <div class="step" id="step5">
      <div class="di">🎉</div>
      <h2 style="text-align:center;margin-bottom:8px">Setup Complete!</h2>
      <p style="text-align:center;margin-bottom:20px">Add this command to cPanel Cron Jobs:</p>
      <div class="fg">
        <label>cPanel Cron Command</label>
        <div class="cb" id="cronCmd"> <button type="button" class="cp" onclick="copyEl('cronCmd')">Copy</button></div>
      </div>
      <div class="fg">
        <label>REST API Endpoint</label>
        <div class="cb" id="apiUrl"> <button type="button" class="cp" onclick="copyEl('apiUrl')">Copy</button></div>
      </div>
    </div>

  </div><!-- wb -->

  <div class="wf">
    <button type="button" class="btn btn-ghost" id="prevBtn" onclick="go(-1)" style="visibility:hidden">← Back</button>
    <button type="button" class="btn btn-primary" id="nextBtn" onclick="go(1)">Next →</button>
    <a href="<?= htmlspecialchars($base) ?>" class="btn btn-success" id="doneBtn" style="display:none">Go to Dashboard →</a>
  </div>
  </form>
</div>

<script>
const BASE = '<?= addslashes($base) ?>';
let cur = 1;
const names = ['Security','Telegram','Pages','Settings','Done'];

function regen(id) {
  const a = new Uint8Array(16);
  crypto.getRandomValues(a);
  document.getElementById(id).value = Array.from(a, b => b.toString(16).padStart(2,'0')).join('');
}

function upd() {
  document.getElementById('pf').style.width = (cur/5*100) + '%';
  document.getElementById('sl').textContent = 'Step ' + cur + ' of 5 — ' + names[cur-1];
  document.getElementById('prevBtn').style.visibility = cur > 1 ? 'visible' : 'hidden';
  document.getElementById('nextBtn').style.display = cur < 5 ? 'inline-block' : 'none';
  document.getElementById('doneBtn').style.display = cur === 5 ? 'inline-block' : 'none';
  document.querySelectorAll('.step').forEach((s,i) => s.classList.toggle('active', i+1 === cur));
}

function go(dir) {
  if (dir === 1 && cur === 2) {
    const bt = document.getElementById('botToken').value.trim();
    const ch = document.getElementById('channelId').value.trim();
    if (!bt || !ch) { alert('Please fill in Bot Token and Channel ID'); return; }
  }
  if (dir === 1 && cur === 3) {
    const sel = document.querySelectorAll('#pageChecklist input[type=checkbox]:checked').length;
    if (sel === 0) { alert('Please fetch your pages and select at least one to monitor'); return; }
  }
  if (dir === 1 && cur === 4) {
    const fd = new FormData(document.getElementById('setupForm'));
    fetch(BASE + '?action=save', { method: 'POST', body: fd }).then(() => {
      cur = 5; upd(); loadDone();
    });
    return;
  }
  cur = Math.min(Math.max(cur + dir, 1), 5);
  upd();
}

function loadDone() {
  fetch(BASE + '?action=getkeys')
    .then(r => r.json())
    .then(d => {
      const iv = document.getElementById('intVal').value;
      document.getElementById('cronCmd').childNodes[0].textContent =
        '*/' + iv + ' * * * * curl "' + BASE + '?action=run&key=' + d.cron_key + '"';
      document.getElementById('apiUrl').childNodes[0].textContent =
        BASE + '?action=api&key=' + d.api_key;
    });
}

function getChatId() {
  const st = document.getElementById('tgSt');
  const bt = document.getElementById('botToken').value.trim();
  if (!bt) { alert('Please enter your Bot Token first'); return; }
  st.className = 'st loading'; st.textContent = 'Detecting your Chat ID...';
  const fd = new FormData();
  fd.append('bot_token', bt);
  fetch(BASE + '?action=get_chat_id', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        document.getElementById('channelId').value = d.chat_id;
        st.className = 'st success';
        st.textContent = '✅ Chat ID detected: ' + d.chat_id + (d.name ? ' (' + d.name + ')' : '');
      } else {
        st.className = 'st error';
        st.textContent = '❌ ' + d.message;
      }
    });
}

function testTelegram() {
  const st = document.getElementById('tgSt');
  st.className = 'st loading'; st.textContent = 'Sending test message...';
  const fd = new FormData();
  fd.append('bot_token', document.getElementById('botToken').value);
  fd.append('chat_id', document.getElementById('channelId').value);
  fetch(BASE + '?action=test_telegram', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      st.className = 'st ' + (d.ok ? 'success' : 'error');
      st.textContent = d.ok ? '✅ Test message sent! Check your bot chat.' : '❌ ' + d.message;
    });
}

let fetchedPages = [];

function fetchPages() {
  const st = document.getElementById('fetchSt');
  const token = document.getElementById('userToken').value.trim();
  if (!token) { alert('Please enter your Facebook User Access Token'); return; }
  st.className = 'st loading'; st.textContent = 'Fetching your pages...';
  document.querySelectorAll('.hidden-page-inp').forEach(el => el.remove());
  const fd = new FormData();
  fd.append('user_token', token);
  fetch(BASE + '?action=fetch_pages', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { st.className = 'st error'; st.textContent = '❌ ' + d.message; return; }
      fetchedPages = d.pages;
      st.className = 'st success';
      st.textContent = '✅ Found ' + d.pages.length + ' page(s). Select which to monitor, then click Next →';
      renderPageChecklist(d.pages);
    });
}

function renderPageChecklist(pages) {
  const wrap = document.getElementById('pageChecklist');
  wrap.style.display = 'block';
  wrap.innerHTML =
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' +
    '<span style="font-size:13px;font-weight:600;color:#94a3b8">' + pages.length + ' page(s) found</span>' +
    '<div style="display:flex;gap:6px">' +
    '<button type="button" class="btn btn-ghost btn-sm" onclick="selAllPages(true)">Select All</button>' +
    '<button type="button" class="btn btn-ghost btn-sm" onclick="selAllPages(false)">None</button>' +
    '</div></div>' +
    pages.map((p, i) =>
      '<label style="display:flex;align-items:center;gap:12px;padding:12px;background:#0f172a;border:1px solid #334155;border-radius:8px;margin-bottom:8px;cursor:pointer">' +
      '<input type="checkbox" data-idx="' + i + '" checked onchange="syncPageInputs()" style="width:18px;height:18px;accent-color:#3b82f6;flex-shrink:0">' +
      '<div><div style="font-weight:600;color:#f1f5f9">' + escHtml(p.name) + '</div>' +
      '<div style="font-size:12px;color:#64748b;margin-top:2px">ID: ' + escHtml(p.id) + '</div></div>' +
      '</label>'
    ).join('');
  syncPageInputs();
}

function syncPageInputs() {
  document.querySelectorAll('.hidden-page-inp').forEach(el => el.remove());
  const form = document.getElementById('setupForm');
  document.querySelectorAll('#pageChecklist input[type=checkbox]').forEach(cb => {
    if (!cb.checked) return;
    const p = fetchedPages[parseInt(cb.dataset.idx)];
    if (!p) return;
    [['page_id[]', p.id], ['page_name[]', p.name], ['page_token[]', p.access_token]].forEach(([n, v]) => {
      const inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = n; inp.value = v; inp.className = 'hidden-page-inp';
      form.appendChild(inp);
    });
  });
}

function selAllPages(checked) {
  document.querySelectorAll('#pageChecklist input[type=checkbox]').forEach(cb => cb.checked = checked);
  syncPageInputs();
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setInt(v) {
  document.getElementById('intVal').value = v;
  document.querySelectorAll('.ib').forEach(b => b.classList.toggle('active', b.textContent.trim() === v + ' min'));
}

function setHours(m) {
  const a = m === 'always';
  document.getElementById('alwaysOn').value = a ? '1' : '0';
  document.getElementById('t24').classList.toggle('active', a);
  document.getElementById('tCustom').classList.toggle('active', !a);
  document.getElementById('customHours').style.display = a ? 'none' : 'block';
}

function copyEl(id) {
  const el = document.getElementById(id);
  const txt = el.childNodes[0].textContent.trim();
  navigator.clipboard.writeText(txt).then(() => {
    const btn = el.querySelector('.cp');
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}

upd();
</script>
</body>
</html>
    <?php
}

// ─── UI: SHARED ──────────────────────────────────────────────

function sharedStyles(): string
{
    return '
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex}
.sidebar{width:220px;background:#020617;padding:24px 16px;flex-shrink:0;min-height:100vh}
.slogo{font-size:16px;font-weight:700;color:#f8fafc;padding:8px 12px;margin-bottom:24px}
.nav{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:8px;color:#94a3b8;text-decoration:none;font-size:14px;margin-bottom:4px;transition:all .2s}
.nav:hover,.nav.active{background:#1e293b;color:#f1f5f9}
.content{flex:1;padding:32px;max-width:900px}
h1{font-size:22px;font-weight:700;color:#f8fafc;margin-bottom:24px}
.card{background:#1e293b;border-radius:12px;padding:24px;margin-bottom:20px;border:1px solid #334155}
.ct{font-size:13px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:16px}
.sr{display:flex;gap:16px;flex-wrap:wrap}
.stat{background:#0f172a;border-radius:10px;padding:16px 20px;flex:1;min-width:130px}
.sv{font-size:22px;font-weight:700;color:#f1f5f9}
.sl{font-size:12px;color:#64748b;margin-top:4px}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:500}
.bg{background:#14532d;color:#86efac}.by{background:#713f12;color:#fde68a}.br{background:#7f1d1d;color:#fca5a5}
.btn{padding:10px 20px;border-radius:8px;border:none;font-size:14px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block}
.btn-primary{background:#3b82f6;color:#fff}.btn-primary:hover{background:#2563eb}
.btn-ghost{background:#334155;color:#cbd5e1}.btn-ghost:hover{background:#475569}
.btn-success{background:#22c55e;color:#fff}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:0}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;padding:10px 12px;color:#64748b;font-size:12px;font-weight:600;text-transform:uppercase;border-bottom:1px solid #334155}
td{padding:12px;border-bottom:1px solid #1e293b;color:#e2e8f0}
tr:last-child td{border-bottom:none}
.fg{margin-bottom:18px}
label{display:block;font-size:13px;font-weight:500;color:#cbd5e1;margin-bottom:6px}
input[type=text],input[type=password],select{width:100%;background:#0f172a;border:1px solid #334155;border-radius:8px;padding:10px 14px;color:#f1f5f9;font-size:14px;outline:none}
input:focus,select:focus{border-color:#3b82f6}
.iw{display:flex;gap:8px}
.st{font-size:13px;padding:8px 12px;border-radius:6px;margin-top:8px;display:none}
.st.success{background:#14532d;color:#86efac;display:block}
.st.error{background:#7f1d1d;color:#fca5a5;display:block}
.st.loading{background:#1e3a5f;color:#93c5fd;display:block}
.pr{background:#0f172a;border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid #334155}
.prh{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.prt{font-size:13px;font-weight:600;color:#94a3b8}
.btn-danger{background:#ef4444;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer}
.btn-sm{padding:6px 14px;font-size:12px}
.tw{display:flex;gap:12px;margin-bottom:16px}
.to{flex:1;background:#0f172a;border:2px solid #334155;border-radius:8px;padding:12px;cursor:pointer;text-align:center;transition:all .2s}
.to.active{border-color:#3b82f6;background:#1e3a5f}
.to span{display:block;font-size:13px;font-weight:600;color:#f1f5f9}
.to small{color:#64748b;font-size:11px}
.tg{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.is{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:0}
.ib{background:#0f172a;border:2px solid #334155;color:#cbd5e1;border-radius:8px;padding:8px 16px;cursor:pointer;font-size:14px;transition:all .2s}
.ib.active{border-color:#3b82f6;color:#f1f5f9;background:#1e3a5f}
.cb{background:#0f172a;border:1px solid #334155;border-radius:8px;padding:14px 44px 14px 14px;font-family:monospace;font-size:13px;color:#86efac;position:relative;word-break:break-all}
.cp{position:absolute;top:8px;right:8px;background:#334155;border:none;color:#cbd5e1;padding:4px 10px;border-radius:4px;font-size:11px;cursor:pointer}
.cp:hover{background:#475569}
input[name=check_interval]{display:none}
</style>';
}

function renderLayout(string $activePage, callable $body): void
{
    $proto = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $base  = $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Unread Alert</title>';
    echo sharedStyles();
    echo '</head><body>';
    echo '<div class="sidebar">';
    echo '<div class="slogo">🔔 Unread Alert</div>';
    echo '<a href="' . htmlspecialchars($base) . '" class="nav' . ($activePage === 'dashboard' ? ' active' : '') . '">📊 Dashboard</a>';
    echo '<a href="' . htmlspecialchars($base) . '?view=settings" class="nav' . ($activePage === 'settings' ? ' active' : '') . '">⚙️ Settings</a>';
    echo '</div><div class="content">';
    $body($base);
    echo '</div></body></html>';
}

// ─── UI: DASHBOARD ───────────────────────────────────────────

function renderDashboard(array $s): void
{
    renderLayout('dashboard', function(string $base) use ($s) {
        $results     = $s['last_result'] ?? [];
        $totalUnread = array_sum(array_column($results, 'unread_count'));
        $interval    = (int)($s['check_interval'] ?? 15);
        $oh          = $s['office_hours'] ?? ['always_on' => true];
        $active      = isWithinOfficeHours($oh);
        $lastCheck   = $s['last_check'] ?? null;
        $lastLabel   = $lastCheck ? (new DateTime($lastCheck))->format('Y-m-d h:i A') : 'Never';
        $statusBadge = $active
            ? '<span class="badge bg">✅ Active</span>'
            : '<span class="badge by">⏸ Outside Office Hours</span>';
        $cronKey = htmlspecialchars($s['cron_key'] ?? '');
        ?>
<h1>Dashboard</h1>
<div class="card">
  <div class="ct">System Status</div>
  <div class="sr">
    <div class="stat"><div class="sv"><?= $totalUnread ?></div><div class="sl">Total Unread Messages</div></div>
    <div class="stat"><div class="sv"><?= count($s['pages'] ?? []) ?></div><div class="sl">Pages Monitored</div></div>
    <div class="stat"><div class="sv"><?= $interval ?>m</div><div class="sl">Check Interval</div></div>
    <div class="stat"><div class="sv"><?= $statusBadge ?></div><div class="sl">Notification Status</div></div>
  </div>
</div>
<div class="card">
  <div class="ct">Last Check: <?= htmlspecialchars($lastLabel) ?></div>
  <div class="btn-row" style="margin-bottom:12px">
    <button class="btn btn-primary" onclick="runNow()">▶ Run Now</button>
    <a href="<?= htmlspecialchars($base) ?>?view=settings" class="btn btn-ghost">⚙️ Settings</a>
  </div>
  <div class="st" id="runSt"></div>
</div>
<?php if (!empty($results)): ?>
<div class="card">
  <div class="ct">Pages</div>
  <table>
    <thead><tr><th>Page</th><th>Unread</th><th>Inbox</th></tr></thead>
    <tbody>
    <?php foreach ($results as $p): ?>
    <tr>
      <td><?= htmlspecialchars($p['name']) ?></td>
      <td><?= (int)$p['unread_count'] > 0 ? '<span class="badge br">' . (int)$p['unread_count'] . '</span>' : '<span class="badge bg">0</span>' ?></td>
      <td><a href="https://business.facebook.com/latest/inbox/messenger?asset_id=<?= urlencode($p['id']) ?>" target="_blank" style="color:#3b82f6">Open Inbox ↗</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<script>
function runNow() {
  const st = document.getElementById('runSt');
  st.className = 'st loading'; st.textContent = 'Running check...';
  fetch('<?= htmlspecialchars($base) ?>?action=run&key=<?= $cronKey ?>')
    .then(r => r.json())
    .then(d => {
      st.className = 'st ' + (d.ok ? 'success' : 'error');
      st.textContent = d.ok ? '✅ ' + d.message : '❌ ' + d.message;
      if (d.ok) setTimeout(() => location.reload(), 1500);
    });
}
</script>
        <?php
    });
}

// ─── UI: SETTINGS PAGE ───────────────────────────────────────

function renderSettingsPage(array $s): void
{
    renderLayout('settings', function(string $base) use ($s) {
        $tg       = $s['telegram'] ?? [];
        $oh       = $s['office_hours'] ?? ['always_on' => true, 'start' => '09:00', 'end' => '18:00', 'timezone' => 'Asia/Dhaka'];
        $pages    = $s['pages'] ?? [];
        $interval = (int)($s['check_interval'] ?? 15);
        $alwaysOn = !empty($oh['always_on']);
        $zones    = ['Asia/Dhaka','Asia/Kolkata','Asia/Karachi','Asia/Dubai','Europe/London','America/New_York','America/Los_Angeles','UTC'];
        $cronCmd  = '*/' . $interval . ' * * * * curl "' . $base . '?action=run&key=' . ($s['cron_key'] ?? '') . '"';
        $apiUrl   = $base . '?action=api&key=' . ($s['api_key'] ?? '');
        ?>
<h1>Settings</h1>
<form method="POST" action="<?= htmlspecialchars($base) ?>?action=save">
<input type="hidden" name="redirect" value="<?= htmlspecialchars($base) ?>?view=settings">

<div class="card">
  <div class="ct">Security Keys</div>
  <div class="fg"><label>Cron Secret Key</label><input type="text" name="cron_key" value="<?= htmlspecialchars($s['cron_key'] ?? '') ?>"></div>
  <div class="fg"><label>API Key</label><input type="text" name="api_key" value="<?= htmlspecialchars($s['api_key'] ?? '') ?>"></div>
  <div class="fg">
    <label>cPanel Cron Command</label>
    <div class="cb" id="cronBox"><?= htmlspecialchars($cronCmd) ?><button type="button" class="cp" onclick="cp('cronBox')">Copy</button></div>
  </div>
  <div class="fg">
    <label>REST API Endpoint</label>
    <div class="cb" id="apiBox"><?= htmlspecialchars($apiUrl) ?><button type="button" class="cp" onclick="cp('apiBox')">Copy</button></div>
  </div>
</div>

<div class="card">
  <div class="ct">Telegram</div>
  <div class="fg"><label>Bot Token</label><input type="password" name="bot_token" id="settingsBotToken" value="<?= htmlspecialchars($tg['bot_token'] ?? '') ?>"></div>
  <div class="fg"><label>Chat ID</label>
    <div class="iw">
      <input type="text" name="chat_id" id="chId" value="<?= htmlspecialchars($tg['chat_id'] ?? $tg['channel_id'] ?? '') ?>">
      <button type="button" class="btn btn-ghost btn-sm" onclick="getChatId()">Get My Chat ID</button>
    </div>
  </div>
  <button type="button" class="btn btn-ghost" onclick="testTg()">📤 Send Test Message</button>
  <div class="st" id="tgSt"></div>
</div>

<div class="card">
  <div class="ct">Facebook Pages</div>
  <div style="background:#0f172a;border:1px solid #334155;border-radius:10px;padding:14px;margin-bottom:16px">
    <div style="font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:10px">Fetch Pages from Facebook</div>
    <div class="fg" style="margin-bottom:10px">
      <label>Facebook User Access Token</label>
      <div class="iw">
        <input type="password" id="userToken" placeholder="EAAxxxxx...">
        <button type="button" class="btn btn-ghost btn-sm" onclick="fetchPages()">Fetch My Pages</button>
      </div>
    </div>
    <div class="st" id="fetchSt"></div>
    <div id="pageChecklist" style="display:none;margin-top:12px"></div>
  </div>
  <div id="pagesWrap">
    <?php foreach ($pages as $i => $pg): $n = $i + 1; ?>
    <div class="pr" id="pr<?= $n ?>">
      <div class="prh"><span class="prt"><?= htmlspecialchars($pg['name']) ?></span>
        <button type="button" class="btn-danger" onclick="rmPg(<?= $n ?>)">Remove</button></div>
      <input type="hidden" name="page_name[]" value="<?= htmlspecialchars($pg['name']) ?>">
      <input type="hidden" name="page_id[]" value="<?= htmlspecialchars($pg['id']) ?>">
      <input type="hidden" name="page_token[]" value="<?= htmlspecialchars($pg['token']) ?>">
      <div style="font-size:12px;color:#64748b;padding:2px 0">ID: <?= htmlspecialchars($pg['id']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="ct">Notification Settings</div>
  <div class="fg">
    <label>Check Interval</label>
    <div class="is">
      <?php foreach ([5,10,15,30,60] as $m): ?>
      <button type="button" class="ib <?= $m === $interval ? 'active' : '' ?>" onclick="setInt(<?= $m ?>)"><?= $m ?> min</button>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="check_interval" id="intVal" value="<?= $interval ?>">
  </div>
  <div class="fg">
    <label>Notification Hours</label>
    <div class="tw">
      <div class="to <?= $alwaysOn ? 'active' : '' ?>" id="t24" onclick="setH('always')"><span>🌐 24/7 Always On</span><small>Notify any time</small></div>
      <div class="to <?= !$alwaysOn ? 'active' : '' ?>" id="tCustom" onclick="setH('custom')"><span>🕐 Office Hours</span><small>Set a time range</small></div>
    </div>
    <input type="hidden" name="always_on" id="ao" value="<?= $alwaysOn ? '1' : '0' ?>">
    <div id="ch" style="display:<?= $alwaysOn ? 'none' : 'block' ?>">
      <div class="tg">
        <div class="fg"><label>Start Time</label><input type="text" name="oh_start" value="<?= htmlspecialchars($oh['start'] ?? '09:00') ?>"></div>
        <div class="fg"><label>End Time</label><input type="text" name="oh_end" value="<?= htmlspecialchars($oh['end'] ?? '18:00') ?>"></div>
      </div>
      <div class="fg"><label>Timezone</label>
        <select name="timezone">
          <?php foreach ($zones as $z): ?>
          <option value="<?= $z ?>" <?= $z === ($oh['timezone'] ?? 'Asia/Dhaka') ? 'selected' : '' ?>><?= $z ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<div style="margin-bottom:32px">
  <button type="submit" class="btn btn-success">💾 Save Settings</button>
  <a href="<?= htmlspecialchars($base) ?>" class="btn btn-ghost">Cancel</a>
</div>
</form>

<script>
const BASE = '<?= addslashes($base) ?>';
let fetchedPages = [];

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function getExistingIds() {
  return Array.from(document.querySelectorAll('#pagesWrap input[name="page_id[]"]')).map(el => el.value);
}

function fetchPages() {
  const st = document.getElementById('fetchSt');
  const token = document.getElementById('userToken').value.trim();
  if (!token) { alert('Please enter your Facebook User Access Token'); return; }
  st.className = 'st loading'; st.textContent = 'Fetching your pages...';
  document.querySelectorAll('.hidden-page-inp').forEach(el => el.remove());
  const fd = new FormData();
  fd.append('user_token', token);
  fetch(BASE + '?action=fetch_pages', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { st.className = 'st error'; st.textContent = '❌ ' + d.message; return; }
      fetchedPages = d.pages;
      const existing = getExistingIds();
      const newPages = d.pages.filter(p => !existing.includes(p.id));
      if (newPages.length === 0) {
        st.className = 'st success'; st.textContent = '✅ All your pages are already added.'; return;
      }
      st.className = 'st success';
      st.textContent = '✅ Found ' + newPages.length + ' new page(s). Select which to add, then Save Settings.';
      renderPageChecklist(newPages);
    });
}

function renderPageChecklist(pages) {
  const wrap = document.getElementById('pageChecklist');
  wrap.style.display = 'block';
  wrap.innerHTML =
    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' +
    '<span style="font-size:13px;font-weight:600;color:#94a3b8">' + pages.length + ' new page(s) found</span>' +
    '<div style="display:flex;gap:6px">' +
    '<button type="button" class="btn btn-ghost btn-sm" onclick="selAllPages(true)">Select All</button>' +
    '<button type="button" class="btn btn-ghost btn-sm" onclick="selAllPages(false)">None</button>' +
    '</div></div>' +
    pages.map((p, i) =>
      '<label style="display:flex;align-items:center;gap:12px;padding:12px;background:#1e293b;border:1px solid #334155;border-radius:8px;margin-bottom:8px;cursor:pointer">' +
      '<input type="checkbox" data-idx="' + i + '" checked onchange="syncPageInputs()" style="width:18px;height:18px;accent-color:#3b82f6;flex-shrink:0">' +
      '<div><div style="font-weight:600;color:#f1f5f9">' + escHtml(p.name) + '</div>' +
      '<div style="font-size:12px;color:#64748b;margin-top:2px">ID: ' + escHtml(p.id) + '</div></div>' +
      '</label>'
    ).join('');
  // Store filtered list for syncPageInputs
  wrap._pages = pages;
  syncPageInputs();
}

function syncPageInputs() {
  document.querySelectorAll('.hidden-page-inp').forEach(el => el.remove());
  const form = document.querySelector('form');
  const pages = document.getElementById('pageChecklist')._pages || [];
  document.querySelectorAll('#pageChecklist input[type=checkbox]').forEach(cb => {
    if (!cb.checked) return;
    const p = pages[parseInt(cb.dataset.idx)];
    if (!p) return;
    [['page_id[]', p.id], ['page_name[]', p.name], ['page_token[]', p.access_token]].forEach(([n, v]) => {
      const inp = document.createElement('input');
      inp.type = 'hidden'; inp.name = n; inp.value = v; inp.className = 'hidden-page-inp';
      form.appendChild(inp);
    });
  });
}

function selAllPages(checked) {
  document.querySelectorAll('#pageChecklist input[type=checkbox]').forEach(cb => cb.checked = checked);
  syncPageInputs();
}

function rmPg(n) { document.getElementById('pr' + n)?.remove(); }

function getChatId() {
  const st = document.getElementById('tgSt');
  const bt = document.getElementById('settingsBotToken').value.trim();
  if (!bt) { alert('Please enter your Bot Token first'); return; }
  st.className = 'st loading'; st.style.display = 'block'; st.textContent = 'Detecting your Chat ID...';
  const fd = new FormData();
  fd.append('bot_token', bt);
  fetch(BASE + '?action=get_chat_id', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        document.getElementById('chId').value = d.chat_id;
        st.className = 'st success';
        st.textContent = '✅ Chat ID detected: ' + d.chat_id + (d.name ? ' (' + d.name + ')' : '');
      } else {
        st.className = 'st error';
        st.textContent = '❌ ' + d.message;
      }
    });
}

function testTg() {
  const st = document.getElementById('tgSt');
  st.className = 'st loading'; st.style.display = 'block'; st.textContent = 'Sending...';
  const fd = new FormData();
  fd.append('bot_token', document.getElementById('settingsBotToken').value);
  fd.append('chat_id', document.getElementById('chId').value);
  fetch(BASE + '?action=test_telegram', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => { st.className = 'st ' + (d.ok ? 'success' : 'error'); st.textContent = d.ok ? '✅ Test message sent!' : '❌ ' + d.message; });
}

function setInt(v) {
  document.getElementById('intVal').value = v;
  document.querySelectorAll('.ib').forEach(b => b.classList.toggle('active', b.textContent.trim() === v + ' min'));
}

function setH(m) {
  const a = m === 'always';
  document.getElementById('ao').value = a ? '1' : '0';
  document.getElementById('t24').classList.toggle('active', a);
  document.getElementById('tCustom').classList.toggle('active', !a);
  document.getElementById('ch').style.display = a ? 'none' : 'block';
}

function cp(id) {
  const el = document.getElementById(id);
  const txt = el.childNodes[0].textContent.trim();
  navigator.clipboard.writeText(txt).then(() => {
    const btn = el.querySelector('.cp'); btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 2000);
  });
}
</script>
        <?php
    });
}

// ─── ROUTER ──────────────────────────────────────────────────

$action   = $_GET['action'] ?? '';
$settings = loadSettings();

switch ($action) {
    case 'run':           handleRun($settings);          break;
    case 'api':           handleApi($settings);          break;
    case 'save':          handleSave();                  break;
    case 'test_telegram': handleTestTelegram();          break;
    case 'get_chat_id':   handleGetChatId();             break;
    case 'verify_page':   handleVerifyPage();            break;
    case 'fetch_pages':   handleFetchPages();            break;
    case 'getkeys':       handleGetKeys($settings);      break;
    default:
        if (!isSetupDone()) {
            renderSetupWizard();
        } else {
            $view = $_GET['view'] ?? 'dashboard';
            $view === 'settings' ? renderSettingsPage($settings) : renderDashboard($settings);
        }
        break;
}
