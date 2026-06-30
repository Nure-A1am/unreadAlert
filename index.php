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

function sendTelegramMessage(string $botToken, string $channelId, string $text): array
{
    $res = httpPost(TELEGRAM_API_BASE . $botToken . '/sendMessage', [
        'chat_id'                  => $channelId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ]);

    if ($res['error']) return ['ok' => false, 'message' => $res['error']];
    if ($res['status'] !== 200) {
        return ['ok' => false, 'message' => $res['data']['description'] ?? 'Telegram API error'];
    }
    return ['ok' => true];
}

function handleTestTelegram(): void
{
    $botToken  = trim($_POST['bot_token'] ?? '');
    $channelId = trim($_POST['channel_id'] ?? '');
    if (!$botToken || !$channelId) {
        jsonResponse(['ok' => false, 'message' => 'Bot token and channel ID required'], 400);
    }
    $msg = "✅ <b>Unread Alert System</b>\n\nTelegram connection successful! Your alerts will appear here.";
    jsonResponse(sendTelegramMessage($botToken, $channelId, $msg));
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
    $sent = sendTelegramMessage($tg['bot_token'] ?? '', $tg['channel_id'] ?? '', $message);
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
            'bot_token'  => trim($_POST['bot_token']  ?? $existing['telegram']['bot_token']  ?? ''),
            'channel_id' => trim($_POST['channel_id'] ?? $existing['telegram']['channel_id'] ?? ''),
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
    jsonResponse(['cron_key' => $settings['cron_key'] ?? '', 'api_key' => $settings['api_key'] ?? '']);
}

// ─── ROUTER ──────────────────────────────────────────────────

$action   = $_GET['action'] ?? '';
$settings = loadSettings();

switch ($action) {
    case 'run':           handleRun($settings);          break;
    case 'api':           handleApi($settings);          break;
    case 'save':          handleSave();                  break;
    case 'test_telegram': handleTestTelegram();          break;
    case 'verify_page':   handleVerifyPage();            break;
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
