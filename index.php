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
    return json_decode(file_get_contents(SETTINGS_FILE), true) ?? [];
}

function saveSettings(array $data): void
{
    file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
