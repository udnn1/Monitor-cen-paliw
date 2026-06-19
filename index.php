<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function normalize_display_dashes(string $value): string
{
    return str_replace(['–', '—', '−', '‒', '―'], '-', $value);
}

function cli_has_flag(string $flag): bool
{
    global $argv;

    return PHP_SAPI === 'cli' && isset($argv) && is_array($argv) && in_array($flag, $argv, true);
}

function curl_shell_binary(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl';
}

function cache_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '.paliwa-cache';
}

function apply_cache_acl(string $path, bool $isDir): void
{
    if (!function_exists('exec')) {
        return;
    }

    $setfacl = is_file('/usr/bin/setfacl') ? '/usr/bin/setfacl' : 'setfacl';
    $escapedPath = escapeshellarg($path);

    if ($isDir) {
        @exec($setfacl . ' -m u:www-data:rwx,g:www-data:rwx,m:rwx ' . $escapedPath . ' 2>/dev/null');
        @exec($setfacl . ' -d -m u:www-data:rwX,g:www-data:rwX,m:rwX ' . $escapedPath . ' 2>/dev/null');
        return;
    }

    @exec($setfacl . ' -m u:www-data:rw-,g:www-data:rw-,m:rw- ' . $escapedPath . ' 2>/dev/null');
}

function normalize_cache_permissions(?string $path = null): void
{
    $dir = cache_dir();

    if (is_dir($dir)) {
        @chown($dir, 'www-data');
        @chgrp($dir, 'www-data');
        @chmod($dir, 02775);
        apply_cache_acl($dir, true);
    }

    if ($path !== null && is_file($path)) {
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');
        @chmod($path, 0664);
        apply_cache_acl($path, false);
    }
}


function dashboard_snapshot_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'dashboard-current.json';
}

function manual_refresh_cooldown_seconds(): int
{
    return 300;
}

function manual_refresh_cooldown_state_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'manual-refresh-cooldown.json';
}

function manual_refresh_cooldown_lock_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'manual-refresh-cooldown.lock';
}

function dashboard_refresh_lock_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'dashboard-refresh.lock';
}

function auto_refresh_throttle_seconds(): int
{
    return 180;
}

function auto_refresh_state_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'auto-refresh-state.json';
}



function auto_refresh_throttle_status(DateTimeImmutable $targetDate): array
{
    $now = time();
    $targetIso = $targetDate->format('Y-m-d');
    $state = read_json_array_file(auto_refresh_state_path());

    if ((string) ($state['targetDateIso'] ?? '') !== $targetIso) {
        return [
            'active' => false,
            'targetDateIso' => $targetIso,
            'remainingSeconds' => 0,
        ];
    }

    $cooldownUntil = (int) ($state['cooldownUntil'] ?? 0);

    if ($cooldownUntil > $now) {
        return [
            'active' => true,
            'targetDateIso' => $targetIso,
            'cooldownUntil' => $cooldownUntil,
            'remainingSeconds' => max(1, $cooldownUntil - $now),
        ];
    }

    return [
        'active' => false,
        'targetDateIso' => $targetIso,
        'remainingSeconds' => 0,
    ];
}
function auto_refresh_state_lock_path(): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . 'auto-refresh-state.lock';
}

function auto_refresh_session_stale_seconds(): int
{
    return 300;
}

function ensure_cache_dir(): bool
{
    $dir = cache_dir();

    if (is_dir($dir)) {
        normalize_cache_permissions();
        return true;
    }

    $created = @mkdir($dir, 02775, true) || is_dir($dir);

    if ($created) {
        normalize_cache_permissions();
    }

    return $created;
}

function read_json_array_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_json_array_file(string $path, array $payload): bool
{
    if (!ensure_cache_dir()) {
        return false;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        return false;
    }

    if (@file_put_contents($path, $encoded, LOCK_EX) !== false) {
        normalize_cache_permissions($path);
        return true;
    }

    if (is_file($path)) {
        normalize_cache_permissions($path);
    }

    if (@file_put_contents($path, $encoded, LOCK_EX) !== false) {
        normalize_cache_permissions($path);
        return true;
    }

    $tmpPath = $path . '.' . getmypid() . '.tmp';

    if (@file_put_contents($tmpPath, $encoded, LOCK_EX) === false) {
        return false;
    }

    normalize_cache_permissions($tmpPath);

    if (!@rename($tmpPath, $path)) {
        @unlink($tmpPath);
        return false;
    }

    normalize_cache_permissions($path);
    return true;
}

function open_cache_lock_file(string $path)
{
    if (!ensure_cache_dir()) {
        return null;
    }

    $lock = @fopen($path, 'c+');

    if (!is_resource($lock) && is_file($path)) {
        normalize_cache_permissions($path);
        $lock = @fopen($path, 'c+');
    }

    if (!is_resource($lock) && is_file($path)) {
        $lock = @fopen($path, 'r');
    }

    if (!is_resource($lock)) {
        return null;
    }

    normalize_cache_permissions($path);
    return $lock;
}

function acquire_dashboard_refresh_lock(bool $blocking = false)
{
    $lock = open_cache_lock_file(dashboard_refresh_lock_path());

    if (!is_resource($lock)) {
        return null;
    }

    $operation = LOCK_EX | ($blocking ? 0 : LOCK_NB);

    if (!@flock($lock, $operation)) {
        @fclose($lock);
        return null;
    }

    return $lock;
}

function release_dashboard_refresh_lock($lock): void
{
    if (!is_resource($lock)) {
        return;
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);
}


function auto_refresh_article_cache_seconds(): int
{
    return 45;
}


function auto_refresh_expected_duration_seconds(): int
{
    return 75;
}

function acquire_auto_refresh_state_lock(bool $blocking = false)
{
    $lock = open_cache_lock_file(auto_refresh_state_lock_path());

    if (!is_resource($lock)) {
        return null;
    }

    $operation = LOCK_EX | ($blocking ? 0 : LOCK_NB);

    if (!@flock($lock, $operation)) {
        @fclose($lock);
        return null;
    }

    return $lock;
}

function release_auto_refresh_state_lock($lock): void
{
    if (!is_resource($lock)) {
        return;
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);
}

function auto_refresh_loading_status(DateTimeImmutable $targetDate): array
{
    $now = time();
    $targetIso = $targetDate->format('Y-m-d');
    $state = read_json_array_file(auto_refresh_state_path());

    if ((string) ($state['targetDateIso'] ?? '') === $targetIso) {
        $inProgress = !empty($state['inProgress']);
        $startedAt = (int) ($state['startedAt'] ?? 0);
        $updatedAt = (int) ($state['updatedAt'] ?? $startedAt);
        $lastActivityAt = $updatedAt > 0 ? $updatedAt : $startedAt;
        $expectedUntil = (int) ($state['expectedRefreshUntil'] ?? 0);
        $isFresh = $lastActivityAt > 0 && ($lastActivityAt + auto_refresh_session_stale_seconds()) > $now;

        if ($inProgress && $isFresh) {
            return [
                'active' => true,
                'targetDateIso' => $targetIso,
                'remainingSeconds' => $expectedUntil > $now ? max(1, $expectedUntil - $now) : 8,
                'reason' => 'refresh_state',
            ];
        }
    }

    $dashboardLock = acquire_dashboard_refresh_lock(false);

    if (!is_resource($dashboardLock)) {
        $expectedUntil = (int) ($state['expectedRefreshUntil'] ?? 0);

        return [
            'active' => true,
            'targetDateIso' => $targetIso,
            'remainingSeconds' => $expectedUntil > $now ? max(1, $expectedUntil - $now) : 8,
            'reason' => 'refresh_lock',
        ];
    }

    release_dashboard_refresh_lock($dashboardLock);

    return [
        'active' => false,
        'targetDateIso' => $targetIso,
        'remainingSeconds' => 0,
        'reason' => 'none',
    ];
}



function find_expected_fuel_update_article_cached(DateTimeImmutable $targetDate, bool $allowNetwork = true): ?array
{
    $now = time();
    $targetIso = $targetDate->format('Y-m-d');
    $statePath = auto_refresh_state_path();
    $state = read_json_array_file($statePath);

    if ((string) ($state['targetDateIso'] ?? '') === $targetIso) {
        $articleCacheUntil = (int) ($state['articleCacheUntil'] ?? 0);

        if ($articleCacheUntil > $now) {
            $article = $state['article'] ?? null;

            if (is_array($article)) {
                return $article;
            }

            if (!empty($state['articleMissing'])) {
                return null;
            }
        }
    }

    if (!$allowNetwork) {
        return null;
    }

    $lock = acquire_auto_refresh_state_lock(false);

    if (!is_resource($lock)) {
        return null;
    }

    try {
        $now = time();
        $state = read_json_array_file($statePath);

        if ((string) ($state['targetDateIso'] ?? '') === $targetIso) {
            $articleCacheUntil = (int) ($state['articleCacheUntil'] ?? 0);

            if ($articleCacheUntil > $now) {
                $article = $state['article'] ?? null;

                if (is_array($article)) {
                    return $article;
                }

                if (!empty($state['articleMissing'])) {
                    return null;
                }
            }
        } else {
            $state = [];
        }

        $state['targetDateIso'] = $targetIso;
        $state['articleCheckingStartedAt'] = $now;
        $state['articleCheckingUntil'] = $now + 30;
        $state['updatedAt'] = $now;
        write_json_array_file($statePath, $state);

        $article = find_expected_fuel_update_article($targetDate);

        $state = read_json_array_file($statePath);
        $state['targetDateIso'] = $targetIso;
        $state['articleCheckedAt'] = time();
        $state['articleCacheUntil'] = time() + auto_refresh_article_cache_seconds();
        $state['articleCheckingUntil'] = 0;
        $state['updatedAt'] = time();

        if (is_array($article)) {
            $state['article'] = $article;
            unset($state['articleMissing']);
        } else {
            unset($state['article']);
            $state['articleMissing'] = true;
        }

        write_json_array_file($statePath, $state);

        return is_array($article) ? $article : null;
    } finally {
        release_auto_refresh_state_lock($lock);
    }
}



function auto_refresh_probe_busy_status(DateTimeImmutable $targetDate): array
{
    $now = time();
    $targetIso = $targetDate->format('Y-m-d');
    $state = read_json_array_file(auto_refresh_state_path());

    if ((string) ($state['targetDateIso'] ?? '') !== $targetIso) {
        return [
            'active' => false,
            'targetDateIso' => $targetIso,
            'remainingSeconds' => 0,
        ];
    }

    $checkingUntil = (int) ($state['articleCheckingUntil'] ?? 0);

    if ($checkingUntil > $now) {
        return [
            'active' => true,
            'targetDateIso' => $targetIso,
            'remainingSeconds' => max(1, $checkingUntil - $now),
        ];
    }

    return [
        'active' => false,
        'targetDateIso' => $targetIso,
        'remainingSeconds' => 0,
    ];
}

function auto_refresh_mark_loading(DateTimeImmutable $targetDate): void
{
    if (!ensure_cache_dir()) {
        return;
    }

    $targetIso = $targetDate->format('Y-m-d');
    $lock = acquire_auto_refresh_state_lock(true);

    if (!is_resource($lock)) {
        return;
    }

    try {
        $now = time();
        $state = read_json_array_file(auto_refresh_state_path());

        if ((string) ($state['targetDateIso'] ?? '') !== $targetIso) {
            $state = [];
        }

        try {
            $sessionId = bin2hex(random_bytes(8));
        } catch (Throwable $exception) {
            $sessionId = str_replace('.', '', uniqid('auto-', true));
        }

        $state['targetDateIso'] = $targetIso;
        $state['inProgress'] = true;
        $state['sessionId'] = $sessionId;
        $state['startedAt'] = $now;
        $state['updatedAt'] = $now;
        $state['expectedRefreshUntil'] = $now + auto_refresh_expected_duration_seconds();
        $state['articleCheckingUntil'] = 0;

        write_json_array_file(auto_refresh_state_path(), $state);
    } finally {
        release_auto_refresh_state_lock($lock);
    }
}


function auto_refresh_clear_loading(DateTimeImmutable $targetDate): void
{
    if (!ensure_cache_dir()) {
        return;
    }

    $targetIso = $targetDate->format('Y-m-d');
    $lock = acquire_auto_refresh_state_lock(true);

    if (!is_resource($lock)) {
        return;
    }

    try {
        $state = read_json_array_file(auto_refresh_state_path());

        if ((string) ($state['targetDateIso'] ?? '') === $targetIso) {
            $now = time();
            $state['inProgress'] = false;
            $state['updatedAt'] = $now;
            $state['endedAt'] = $now;
            $state['expectedRefreshUntil'] = 0;

            write_json_array_file(auto_refresh_state_path(), $state);
        }
    } finally {
        release_auto_refresh_state_lock($lock);
    }
}


function auto_refresh_throttle_claim(DateTimeImmutable $targetDate): array
{
    if (!ensure_cache_dir()) {
        return ['allowed' => true];
    }

    $now = time();
    $targetIso = $targetDate->format('Y-m-d');
    $statePath = auto_refresh_state_path();
    $state = read_json_array_file($statePath);
    $stateTargetIso = (string) ($state['targetDateIso'] ?? '');
    $cooldownUntil = (int) ($state['cooldownUntil'] ?? 0);

    if ($stateTargetIso === $targetIso && $cooldownUntil > $now) {
        return [
            'allowed' => false,
            'targetDateIso' => $targetIso,
            'cooldownUntil' => $cooldownUntil,
            'remainingSeconds' => $cooldownUntil - $now,
        ];
    }

    $cooldownSeconds = auto_refresh_throttle_seconds();
    $state = array_merge(
        $stateTargetIso === $targetIso ? $state : [],
        [
            'targetDateIso' => $targetIso,
            'attemptedAt' => $now,
            'cooldownUntil' => $now + $cooldownSeconds,
            'cooldownSeconds' => $cooldownSeconds,
        ]
    );

    write_json_array_file($statePath, $state);

    return [
        'allowed' => true,
        'targetDateIso' => $targetIso,
        'cooldownUntil' => $state['cooldownUntil'],
        'remainingSeconds' => $cooldownSeconds,
    ];
}

function manual_refresh_cooldown_claim(): array
{
    if (!ensure_cache_dir()) {
        return ['allowed' => true];
    }

    $now = time();
    $cooldownSeconds = manual_refresh_cooldown_seconds();
    $lock = open_cache_lock_file(manual_refresh_cooldown_lock_path());

    if (!is_resource($lock)) {
        return ['allowed' => true];
    }

    @flock($lock, LOCK_EX);

    $statePath = manual_refresh_cooldown_state_path();
    $state = read_json_array_file($statePath);
    $startedAt = (int) ($state['startedAt'] ?? 0);
    $cooldownUntil = (int) ($state['cooldownUntil'] ?? 0);

    if ($cooldownUntil > $now) {
        @flock($lock, LOCK_UN);
        @fclose($lock);

        return [
            'allowed' => false,
            'startedAt' => $startedAt > 0 ? $startedAt : ($cooldownUntil - $cooldownSeconds),
            'cooldownUntil' => $cooldownUntil,
            'remainingSeconds' => $cooldownUntil - $now,
        ];
    }

    $state = [
        'startedAt' => $now,
        'cooldownUntil' => $now + $cooldownSeconds,
        'cooldownSeconds' => $cooldownSeconds,
    ];

    write_json_array_file($statePath, $state);
    @flock($lock, LOCK_UN);
    @fclose($lock);

    return [
        'allowed' => true,
        'startedAt' => $state['startedAt'],
        'cooldownUntil' => $state['cooldownUntil'],
        'remainingSeconds' => $cooldownSeconds,
    ];
}

function manual_refresh_cooldown_status(): array
{
    $now = time();
    $state = read_json_array_file(manual_refresh_cooldown_state_path());
    $startedAt = (int) ($state['startedAt'] ?? 0);
    $cooldownUntil = (int) ($state['cooldownUntil'] ?? 0);

    return [
        'active' => $cooldownUntil > $now,
        'startedAt' => $startedAt,
        'cooldownUntil' => $cooldownUntil,
        'remainingSeconds' => max(0, $cooldownUntil - $now),
    ];
}

function format_cooldown_minutes(int $remainingSeconds): string
{
    $minutes = max(1, (int) ceil($remainingSeconds / 60));

    if ($minutes === 1) {
        return '1 minutę';
    }

    if ($minutes >= 2 && $minutes <= 4) {
        return $minutes . ' minuty';
    }

    return $minutes . ' minut';
}

function redirect_after_manual_refresh(string $status): void
{
    $redirectPath = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    if ($redirectPath === '') {
        $redirectPath = './';
    }

    if (preg_match('~/index\.php$~i', $redirectPath) === 1) {
        $redirectPath = substr($redirectPath, 0, -strlen('index.php'));

        if ($redirectPath === '') {
            $redirectPath = '/';
        }
    }

    header(
        'Location: ' . $redirectPath
        . '?refreshed=1&status=' . rawurlencode($status)
        . '&t=' . time(),
        true,
        303
    );
    exit;
}

function send_json_response(array $payload, int $statusCode = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_dashboard_snapshot(): ?array
{
    $path = dashboard_snapshot_path();

    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function save_dashboard_snapshot(array $snapshot): bool
{
    return write_json_array_file(dashboard_snapshot_path(), $snapshot);
}

function http_get(string $url, array $headers = []): ?string
{
    $headers = array_merge([
        'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
        'Accept-Language: pl-PL,pl;q=0.9,en;q=0.6',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'User-Agent: FuelMonitor/2.2 (+local dashboard)',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($body) && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    if (function_exists('shell_exec')) {
        $command = curl_shell_binary() . ' -L -s --connect-timeout 5 --max-time 12 '
            . '-A "FuelMonitor/2.2 (+local dashboard)" '
            . escapeshellarg($url);

        $body = shell_exec($command);

        if (is_string($body) && trim($body) !== '') {
            return $body;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (is_string($body) && trim($body) !== '') {
        return $body;
    }

    return null;
}

function http_get_light(string $url, array $headers = []): ?string
{
    $headers = array_merge([
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pl-PL,pl;q=0.9,en;q=0.6',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'User-Agent: FuelMonitor/2.3 (+local dashboard; light update check)',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    if (function_exists('shell_exec')) {
        $command = curl_shell_binary() . ' -L -s --connect-timeout 2 --max-time 4 '
            . '-A "FuelMonitor/2.3 (+local dashboard; light update check)" '
            . escapeshellarg($url);

        $body = shell_exec($command);

        if (is_string($body) && trim($body) !== '') {
            return $body;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 4,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    return is_string($body) && trim($body) !== '' ? $body : null;
}

function station_promotions_source_url(): string
{
    return '';
}

function bp_official_promotions_source_url(): string
{
    return 'https://www.bp.com/pl_pl/poland/home/produkty_uslugi/promocje.html';
}

function shell_promotions_source_url(): string
{
    return 'https://www.shell.pl/stacje-shell/oferty-i-promocje.html';
}

function orlen_vitay_promotions_source_url(): string
{
    return 'https://vitay.pl/rabaty';
}

function orlen_press_root_url(): string
{
    return 'https://www.orlen.pl/pl';
}

function orlen_press_absolute_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://www.orlen.pl' . $url;
    }

    return 'https://www.orlen.pl/' . ltrim($url, '/');
}

function orlen_press_fuel_promotion_url(): ?string
{
    $html = http_get_orlen_vitay(orlen_press_root_url());

    if (!is_string($html) || trim($html) === '') {
        return null;
    }

    if (preg_match_all('~href="(/pl/o-firmie/media/komunikaty-prasowe/[^"]+)"~i', $html, $matches) <= 0) {
        return null;
    }

    foreach ($matches[1] as $href) {
        $slug = strtolower($href);

        if (strpos($slug, 'promocj') !== false && strpos($slug, 'paliw') !== false) {
            return orlen_press_absolute_url($href);
        }
    }

    return null;
}

function orlen_press_promotion_end_date(string $text): ?DateTimeImmutable
{
    $months = [
        'stycznia' => 1,
        'lutego' => 2,
        'marca' => 3,
        'kwietnia' => 4,
        'maja' => 5,
        'czerwca' => 6,
        'lipca' => 7,
        'sierpnia' => 8,
        'września' => 9,
        'wrzesnia' => 9,
        'października' => 10,
        'pazdziernika' => 10,
        'listopada' => 11,
        'grudnia' => 12,
    ];

    $monthPattern = 'stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|września|wrzesnia|października|pazdziernika|listopada|grudnia';

    if (preg_match('/\bdo\s+(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
        $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
        $month = $months[$monthName] ?? null;
        $day = (int) $match[1];

        if ($month !== null) {
            $today = new DateTimeImmutable('today');
            $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : (int) $today->format('Y');

            if (checkdate($month, $day, $year)) {
                $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

                if ((!isset($match[3]) || $match[3] === '') && $candidate < $today->modify('-31 days')) {
                    $year += 1;

                    if (checkdate($month, $day, $year)) {
                        $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
                    }
                }

                return $candidate;
            }
        }
    }

    return orlen_vitay_promotion_end_date($text);
}

function telegram_alert_bot_url(): string
{
    return 'https://t.me/CenyCPNpl';
}

function bp_official_absolute_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://www.bp.com' . $url;
    }

    return 'https://www.bp.com/' . ltrim($url, '/');
}

function http_get_browser_page(string $url, string $referer = ''): ?string
{
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Upgrade-Insecure-Requests: 1',
    ];
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($body) && trim($body) !== '' && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    if (function_exists('shell_exec')) {
        $command = curl_shell_binary() . ' -L -s --connect-timeout 5 --max-time 12 '
            . '-A ' . escapeshellarg($userAgent) . ' ';

        foreach ($headers as $header) {
            $command .= '-H ' . escapeshellarg($header) . ' ';
        }

        $body = shell_exec($command . escapeshellarg($url));

        if (is_string($body) && trim($body) !== '') {
            return $body;
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$userAgent}\r\n" . implode("\r\n", $headers),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (is_string($body) && trim($body) !== '') {
        return $body;
    }

    return null;
}

function http_get_bp_official(string $url): ?string
{
    return http_get_browser_page($url, bp_official_promotions_source_url());
}

function text_contains_ci(string $haystack, string $needle): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }

    return stripos($haystack, $needle) !== false;
}

function station_network_initial(string $network): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($network, 0, 1, 'UTF-8');
    }

    return substr($network, 0, 1);
}

function station_logo_url(string $network): ?string
{
    return match ($network) {
        'ORLEN' => 'media/logos/orlen.png',
        'BP' => 'media/logos/bp.png',
        'Shell' => 'media/logos/shell.png',
        'MOYA' => 'media/logos/moya.png',
        default => null,
    };
}

function html_to_clean_lines(string $html): array
{
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', '', $html) ?? $html;
    $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(?:p|div|section|article|li|h[1-6]|header|footer)>/iu', "\n", $html) ?? $html;

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = preg_split('/\R+/u', $text) ?: [];

    $cleaned = [];

    foreach ($lines as $line) {
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        $line = trim($line);

        if ($line !== '') {
            $cleaned[] = $line;
        }
    }

    return $cleaned;
}

function line_is_icon_only(string $line): bool
{
    $line = trim($line);

    if ($line === '') {
        return true;
    }

    return preg_match('/^[\s\p{So}\x{FE0F}\x{200D}]+$/u', $line) === 1;
}

function station_promotion_is_active(?string $fromIso, ?string $toIso): bool
{
    if (!is_string($fromIso) || !is_string($toIso) || $fromIso === '' || $toIso === '') {
        return false;
    }

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    return $today >= $fromIso && $today <= $toIso;
}

function station_discount_value_gr_per_l(string $title, string $description): ?int
{
    $text = clean_text($title . ' ' . $description);
    $values = [];

    if (preg_match_all('/\b(?:do\s+)?([0-9]{1,3})\s*gr\s*\/\s*l\b/iu', $text, $matches) > 0) {
        foreach ($matches[1] as $rawValue) {
            $values[] = (int) $rawValue;
        }
    }

    if (preg_match_all('/\b(?:do\s+)?0[,.]([0-9]{1,2})\s*zł\s*\/\s*l\b/iu', $text, $matches) > 0) {
        foreach ($matches[1] as $rawValue) {
            $digits = str_pad((string) $rawValue, 2, '0', STR_PAD_RIGHT);
            $values[] = (int) $digits;
        }
    }

    if ($values === []) {
        return null;
    }

    return max($values);
}

function station_discount_is_up_to(string $title, string $description, ?string $discountLabel = null): bool
{
    $text = clean_text($title . ' ' . $description . ' ' . ($discountLabel ?? ''));

    return preg_match('/\bdo\s+[0-9]{1,3}\s*gr\s*\/\s*l\b/iu', $text) === 1
        || preg_match('/\bdo\s+0[,.][0-9]{1,2}\s*zł\s*\/\s*l\b/iu', $text) === 1;
}

function station_discount_has_extra_purchase_condition(string $title, string $description, ?string $discountLabel = null): bool
{
    $text = clean_text($title . ' ' . $description . ' ' . ($discountLabel ?? ''));

    return preg_match('/\bprzy\s+zakupie\b/iu', $text) === 1
        || preg_match('/\bzakup(?:ie|u)?\s+w\s+sklepie\b/iu', $text) === 1
        || preg_match('/\bw\s+sklepie\b/iu', $text) === 1
        || preg_match('/\bmin\.?\s*[0-9]+(?:[,.][0-9]{1,2})?\s*zł\b/iu', $text) === 1
        || preg_match('/\bminimum\s+[0-9]+(?:[,.][0-9]{1,2})?\s*zł\b/iu', $text) === 1;
}

function station_discount_condition_penalty(string $title, string $description, ?string $discountLabel = null): int
{
    if (station_discount_is_up_to($title, $description, $discountLabel)) {
        return 2;
    }

    if (station_discount_has_extra_purchase_condition($title, $description, $discountLabel)) {
        return 1;
    }

    return 0;
}

function extract_station_discount_label(string $title, string $description): ?string
{
    $text = clean_text($title . ' ' . $description);

    $patterns = [
        '/\bdo\s+[0-9]+\s*gr\s*\/\s*l\b/iu',
        '/\b[0-9]+\s*gr\s*\/\s*l\b/iu',
        '/\bdo\s+0[,.][0-9]{1,2}\s*zł\s*\/\s*l\b/iu',
        '/\b0[,.][0-9]{1,2}\s*zł\s*\/\s*l\b/iu',
        '/\bpaliwa premium w cenie podstawowych\b/iu',
        '/\bpaliw[ao]\s+premium\s+w\s+cenie\s+paliwa\s+podstawowego\b/iu',
        '/\bw\s+cenie\s+paliwa\s+podstawowego\b/iu',
        '/\bV-Power\s*95\s*=\s*cena\s*FuelSave\s*95\b/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match) === 1) {
            return clean_text($match[0]);
        }
    }

    return null;
}

function build_station_promotion_payload(
    string $network,
    string $title,
    string $description,
    string $url,
    string $sourceUrl,
    array $dateRange
): array {
    $discountLabel = extract_station_discount_label($title, $description);
    $discountValue = station_discount_value_gr_per_l($title, $description);
    $discountIsUpTo = station_discount_is_up_to($title, $description, $discountLabel);
    $discountConditionPenalty = station_discount_condition_penalty($title, $description, $discountLabel);

    return [
        'network' => $network,
        'title' => $title,
        'description' => $description,
        'discountLabel' => $discountLabel,
        'discountValueGrPerL' => $discountValue,
        'discountIsUpTo' => $discountIsUpTo,
        'discountConditionPenalty' => $discountConditionPenalty,
        'url' => $url,
        'sourceUrl' => $sourceUrl,
        'dateRangeLabel' => $dateRange['rangeLabel'] ?? null,
        'fromIso' => $dateRange['fromIso'] ?? null,
        'toIso' => $dateRange['toIso'] ?? null,
        'isActive' => station_promotion_is_active($dateRange['fromIso'] ?? null, $dateRange['toIso'] ?? null),
        'isTopPromotion' => false,
    ];
}

function station_promotion_discount_is_up_to(array $item): bool
{
    if (array_key_exists('discountIsUpTo', $item)) {
        return !empty($item['discountIsUpTo']);
    }

    return station_discount_is_up_to(
        (string) ($item['title'] ?? ''),
        (string) ($item['description'] ?? ''),
        isset($item['discountLabel']) && is_string($item['discountLabel']) ? $item['discountLabel'] : null
    );
}

function station_promotion_discount_condition_penalty(array $item): int
{
    if (isset($item['discountConditionPenalty']) && is_numeric($item['discountConditionPenalty'])) {
        return (int) $item['discountConditionPenalty'];
    }

    return station_discount_condition_penalty(
        (string) ($item['title'] ?? ''),
        (string) ($item['description'] ?? ''),
        isset($item['discountLabel']) && is_string($item['discountLabel']) ? $item['discountLabel'] : null
    );
}

function station_promotions_sort(array &$items): void
{
    usort($items, static function (array $left, array $right): int {
        $leftActive = !empty($left['isActive']) ? 1 : 0;
        $rightActive = !empty($right['isActive']) ? 1 : 0;

        if ($leftActive !== $rightActive) {
            return $rightActive <=> $leftActive;
        }

        $leftDiscount = is_numeric($left['discountValueGrPerL'] ?? null) ? (int) $left['discountValueGrPerL'] : -1;
        $rightDiscount = is_numeric($right['discountValueGrPerL'] ?? null) ? (int) $right['discountValueGrPerL'] : -1;

        if ($leftDiscount !== $rightDiscount) {
            return $rightDiscount <=> $leftDiscount;
        }

        $leftPenalty = station_promotion_discount_condition_penalty($left);
        $rightPenalty = station_promotion_discount_condition_penalty($right);

        if ($leftPenalty !== $rightPenalty) {
            return $leftPenalty <=> $rightPenalty;
        }

        $leftTo = $left['toIso'] ?? '9999-12-31';
        $rightTo = $right['toIso'] ?? '9999-12-31';

        if ($leftTo !== $rightTo) {
            return strcmp((string) $leftTo, (string) $rightTo);
        }

        return strcmp((string) ($left['network'] ?? ''), (string) ($right['network'] ?? ''));
    });
}

function mark_top_station_promotions(array &$items): void
{
    foreach ($items as &$item) {
        $item['isTopPromotion'] = false;
    }
    unset($item);

    if ($items === []) {
        return;
    }

    $bestIndex = null;
    $bestScore = -1;
    $bestPenalty = PHP_INT_MAX;

    foreach ($items as $index => $item) {
        if (empty($item['isActive'])) {
            continue;
        }

        $score = is_numeric($item['discountValueGrPerL'] ?? null)
            ? (int) $item['discountValueGrPerL']
            : 0;

        $penalty = station_promotion_discount_condition_penalty($item);

        if (
            $score > $bestScore
            || ($score === $bestScore && $penalty < $bestPenalty)
        ) {
            $bestScore = $score;
            $bestPenalty = $penalty;
            $bestIndex = $index;
        }
    }

    if ($bestIndex !== null && $bestScore > 0) {
        $items[$bestIndex]['isTopPromotion'] = true;
    }
}

function normalized_station_text(string $value): string
{
    $value = clean_text($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function normalized_station_line_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function bp_official_fuel_section_html(string $html): ?string
{
    if (preg_match('/<h2\b[^>]*class=("|\')[^"\']*\bnr-list__title\b[^"\']*\1[^>]*>\s*Promocje\s+na\s+paliwa\s*<\/h2>(.*?)(?=<h2\b[^>]*class=("|\')[^"\']*\bnr-list__title\b[^"\']*\3|$)/isu', $html, $match) === 1) {
        return $match[2];
    }

    return null;
}

function bp_official_fuel_section_links(string $html): array
{
    $section = bp_official_fuel_section_html($html);

    if (!is_string($section) || trim($section) === '') {
        return [];
    }

    if (preg_match_all('/<a\b(?=[^>]*\bnr-list__info-title\b)[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/isu', $section, $matches, PREG_SET_ORDER) <= 0) {
        return [];
    }

    $items = [];
    $seen = [];

    foreach ($matches as $match) {
        $title = clean_text($match[3]);
        $url = bp_official_absolute_url($match[2]);

        if ($title === '' || $url === '') {
            continue;
        }

        $key = normalized_station_text($title . ' ' . $url);

        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $items[] = [
            'title' => $title,
            'url' => $url,
        ];
    }

    return $items;
}

function bp_official_rte_text_blocks(string $html): array
{
    if (preg_match_all('/<div\b[^>]*data-component-name=("|\')RTE\1[^>]*>(.*?)<\/div>/isu', $html, $matches, PREG_SET_ORDER) <= 0) {
        return [];
    }

    $blocks = [];

    foreach ($matches as $match) {
        $lines = html_to_clean_lines($match[2]);

        if ($lines !== []) {
            $blocks[] = $lines;
        }
    }

    return $blocks;
}

function bp_official_detail_line_is_stop(string $line): bool
{
    return text_contains_ci($line, 'Regulamin')
        || text_contains_ci($line, 'Pobierz aplikacj')
        || text_contains_ci($line, 'Dołącz do nas')
        || text_contains_ci($line, 'Dolacz do nas');
}

function bp_official_detail_line_looks_relevant(string $line): bool
{
    return text_contains_ci($line, 'tankuj')
        || text_contains_ci($line, 'rabat')
        || text_contains_ci($line, 'promocj')
        || text_contains_ci($line, 'kupon')
        || text_contains_ci($line, 'paliw')
        || text_contains_ci($line, 'litr')
        || text_contains_ci($line, 'gr/l')
        || text_contains_ci($line, 'grosz')
        || text_contains_ci($line, 'BPme');
}

function bp_official_fuel_promotion_kind(string $title, string $url, string $description = ''): ?string
{
    $text = normalized_station_text($title . ' ' . $url . ' ' . $description);

    if (
        text_contains_ci($text, 'media markt')
        || text_contains_ci($text, 'mediamarkt')
        || text_contains_ci($text, 'bon ')
    ) {
        return null;
    }

    if (text_contains_ci($text, 'lpg') || text_contains_ci($text, 'autogaz')) {
        return 'lpg';
    }

    if (text_contains_ci($text, 'pb95') || (text_contains_ci($text, '95') && text_contains_ci($text, 'benzyn'))) {
        return 'main';
    }

    if (text_contains_ci($text, 'ultimate diesel')) {
        return 'ultimate_diesel';
    }

    if (text_contains_ci($text, 'ultimate 98') || (text_contains_ci($text, '98') && text_contains_ci($text, 'benzyn'))) {
        return 'ultimate_98';
    }

    if (text_contains_ci($text, 'paliwo podstawowe') || text_contains_ci($text, 'paliwa podstawowego')) {
        return 'premium';
    }

    return null;
}

function bp_official_gr_discount_label(string $text): ?string
{
    $text = clean_text($text);
    $patterns = [
        '/\b(?:do\s+)?([0-9]{1,3})\s*gr\s*\/\s*l\b/iu',
        '/\b(?:do\s+)?([0-9]{1,3})\s*gr\s*\/?\s*litr\b/iu',
        '/\b(?:do\s+)?([0-9]{1,3})\s*grosz(?:y|e)?\s+za\s+litr\b/iu',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $match) === 1) {
            return (int) $match[1] . ' gr/l';
        }
    }

    return null;
}

function bp_official_detail_text(string $html): string
{
    $parts = [];

    foreach (bp_official_rte_text_blocks($html) as $lines) {
        $blockText = implode(' ', $lines);

        if (!bp_official_detail_line_looks_relevant($blockText)) {
            continue;
        }

        foreach ($lines as $line) {
            if (bp_official_detail_line_is_stop($line)) {
                break;
            }

            if (line_is_icon_only($line)) {
                continue;
            }

            $parts[] = $line;

            if (count($parts) >= 5) {
                break 2;
            }
        }
    }

    return clean_text(implode(' ', $parts));
}

function bp_official_parse_date(string $day, string $month, string $year): ?DateTimeImmutable
{
    $dayInt = (int) $day;
    $monthInt = (int) $month;
    $yearInt = (int) $year;

    if (!checkdate($monthInt, $dayInt, $yearInt)) {
        return null;
    }

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $yearInt, $monthInt, $dayInt));
}

function bp_official_date_range_from_text(string $text): array
{
    $text = clean_text($text);

    if (preg_match('/\bod\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*(?:r\.?)?\s*(?:do|-|–|—)\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/iu', $text, $match) === 1) {
        $from = bp_official_parse_date($match[1], $match[2], $match[3]);
        $to = bp_official_parse_date($match[4], $match[5], $match[6]);

        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable) {
            return [
                'fromLabel' => $from->format('d.m.Y'),
                'toLabel' => $to->format('d.m.Y'),
                'fromIso' => $from->format('Y-m-d'),
                'toIso' => $to->format('Y-m-d'),
                'rangeLabel' => $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y'),
            ];
        }
    }

    if (preg_match('/\bdo\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\s*r?\.?/iu', $text, $match) === 1) {
        $to = bp_official_parse_date($match[1], $match[2], $match[3]);

        if ($to instanceof DateTimeImmutable) {
            return [
                'fromLabel' => null,
                'toLabel' => $to->format('d.m.Y'),
                'fromIso' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'toIso' => $to->format('Y-m-d'),
                'rangeLabel' => 'do ' . $to->format('d.m.Y'),
            ];
        }
    }

    if (preg_match('/\bdo\s*(\d{1,2})\.(\d{1,2})(?!\.\d)/iu', $text, $match) === 1) {
        $today = new DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $to = bp_official_parse_date($match[1], $match[2], (string) $year);

        if ($to instanceof DateTimeImmutable && $to < $today) {
            $to = bp_official_parse_date($match[1], $match[2], (string) ($year + 1));
        }

        if ($to instanceof DateTimeImmutable) {
            return [
                'fromLabel' => null,
                'toLabel' => $to->format('d.m.Y'),
                'fromIso' => $today->format('Y-m-d'),
                'toIso' => $to->format('Y-m-d'),
                'rangeLabel' => 'do ' . $to->format('d.m.Y'),
            ];
        }
    }

    return [
        'fromLabel' => null,
        'toLabel' => null,
        'fromIso' => null,
        'toIso' => null,
        'rangeLabel' => null,
    ];
}

function build_bp_official_fuel_promotion(string $title, string $url, ?string $detailHtml): array
{
    $description = is_string($detailHtml) && trim($detailHtml) !== ''
        ? bp_official_detail_text($detailHtml)
        : '';

    $dateRange = bp_official_date_range_from_text($title . ' ' . $description);

    if ($description !== '' && normalized_station_line_length($description) > 260 && function_exists('mb_substr')) {
        $description = rtrim(mb_substr($description, 0, 257, 'UTF-8')) . '...';
    } elseif ($description !== '' && strlen($description) > 260) {
        $description = rtrim(substr($description, 0, 257)) . '...';
    }

    $item = build_station_promotion_payload(
        'BP',
        $title,
        $description,
        $url,
        bp_official_promotions_source_url(),
        $dateRange
    );

    if (($dateRange['fromIso'] ?? null) === null || ($dateRange['toIso'] ?? null) === null) {
        $item['isActive'] = true;
    }

    $item['sourceMode'] = 'bp_official_fallback';

    return $item;
}

function bp_official_combined_fuel_promotion(array $promotions): ?array
{
    if ($promotions === []) {
        return null;
    }

    $main = null;
    $ultimateDiesel = null;
    $ultimate98 = null;
    $lpg = null;
    $fallbackBase = null;

    foreach ($promotions as $promotion) {
        if (!is_array($promotion)) {
            continue;
        }

        $kind = $promotion['kind'] ?? null;

        if ($kind !== 'lpg' && $fallbackBase === null) {
            $fallbackBase = $promotion;
        }

        if ($kind === 'main') {
            $main = $promotion;
        } elseif ($kind === 'ultimate_diesel') {
            $ultimateDiesel = $promotion;
        } elseif ($kind === 'ultimate_98') {
            $ultimate98 = $promotion;
        } elseif ($kind === 'lpg') {
            $lpg = $promotion;
        }
    }

    $base = $main ?? $fallbackBase;

    if (!is_array($base)) {
        return null;
    }

    $item = build_bp_official_fuel_promotion(
        (string) ($base['title'] ?? 'Promocje paliwowe BP'),
        (string) ($base['url'] ?? bp_official_promotions_source_url()),
        isset($base['detailHtml']) && is_string($base['detailHtml']) ? $base['detailHtml'] : null
    );

    $mainText = (string) ($base['title'] ?? '') . ' ' . (string) ($base['description'] ?? '');
    $mainDiscount = bp_official_gr_discount_label($mainText) ?? ($item['discountLabel'] ?? null);
    $descriptionParts = [];

    if (is_string($mainDiscount) && trim($mainDiscount) !== '') {
        $descriptionParts[] = 'Z aplikacją BPme.';
    }

    $weekendOffers = [];

    if (is_array($ultimateDiesel)) {
        $weekendOffers[] = 'Ultimate Diesel w cenie paliwa podstawowego';
    }

    if (is_array($ultimate98)) {
        $ultimate98Text = (string) ($ultimate98['title'] ?? '') . ' ' . (string) ($ultimate98['description'] ?? '');
        $ultimate98Discount = bp_official_gr_discount_label($ultimate98Text);
        $weekendOffers[] = is_string($ultimate98Discount) && trim($ultimate98Discount) !== ''
            ? trim($ultimate98Discount) . ' na Ultimate 98'
            : 'rabat na Ultimate 98';
    }

    if ($weekendOffers !== []) {
        $descriptionParts[] = 'Dodatkowo od czwartku do niedzieli: ' . implode(' oraz ', $weekendOffers) . '.';
    }

    if (is_array($lpg)) {
        $lpgText = (string) ($lpg['title'] ?? '') . ' ' . (string) ($lpg['description'] ?? '');
        $lpgDiscount = bp_official_gr_discount_label($lpgText);
        $descriptionParts[] = is_string($lpgDiscount) && trim($lpgDiscount) !== ''
            ? 'Jest też rabat ' . trim($lpgDiscount) . ' na LPG.'
            : 'Jest też rabat na LPG.';
    }

    if ($descriptionParts !== []) {
        $item['description'] = clean_text(implode(' ', $descriptionParts));
    }

    $item['sourceMode'] = 'bp_official_combined_fallback';

    return $item;
}

function fetch_bp_official_fuel_promotions(): array
{
    $items = [];
    $warnings = [];
    $fetchedAt = new DateTimeImmutable();
    $sourceUrl = bp_official_promotions_source_url();
    $html = http_get_bp_official($sourceUrl);

    if (!is_string($html) || trim($html) === '') {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => ['Nie udalo sie pobrac oficjalnej strony promocji BP.'],
            'warning' => 'Nie udalo sie pobrac oficjalnej strony promocji BP.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'bp_official_failed',
        ];
    }

    $promotions = [];

    foreach (bp_official_fuel_section_links($html) as $link) {
        $detailHtml = http_get_bp_official($link['url']);
        $description = is_string($detailHtml) ? bp_official_detail_text($detailHtml) : '';
        $kind = bp_official_fuel_promotion_kind((string) $link['title'], (string) $link['url'], $description);

        if ($kind === null) {
            continue;
        }

        $promotions[] = [
            'kind' => $kind,
            'title' => (string) $link['title'],
            'url' => (string) $link['url'],
            'description' => $description,
            'detailHtml' => is_string($detailHtml) ? $detailHtml : null,
        ];
    }

    $combinedPromotion = bp_official_combined_fuel_promotion($promotions);

    if ($combinedPromotion !== null) {
        $items[] = $combinedPromotion;
    }

    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $sourceUrl,
        'items' => $items,
        'warnings' => array_values(array_unique($warnings)),
        'warning' => $items === [] ? 'Nie znaleziono aktualnych promocji w oficjalnej sekcji BP Promocje na paliwa.' : null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'bp_official_fuel_promotions',
    ];
}

function http_get_orlen_vitay(string $url): ?string
{
    return http_get_browser_page($url, 'https://www.orlen.pl/');
}

function orlen_vitay_textual_dates(string $text): array
{
    $months = [
        'stycznia' => 1,
        'lutego' => 2,
        'marca' => 3,
        'kwietnia' => 4,
        'maja' => 5,
        'czerwca' => 6,
        'lipca' => 7,
        'sierpnia' => 8,
        'września' => 9,
        'wrzesnia' => 9,
        'października' => 10,
        'pazdziernika' => 10,
        'listopada' => 11,
        'grudnia' => 12,
    ];

    if (preg_match_all('/\b(\d{1,2})\s+(stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|września|wrzesnia|października|pazdziernika|listopada|grudnia)\s+(\d{4})\b/iu', $text, $matches, PREG_SET_ORDER) <= 0) {
        return [];
    }

    $dates = [];

    foreach ($matches as $match) {
        $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
        $month = $months[$monthName] ?? null;
        $day = (int) $match[1];
        $year = (int) $match[3];

        if ($month === null || !checkdate($month, $day, $year)) {
            continue;
        }

        $dates[] = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    return $dates;
}

function orlen_vitay_promotion_end_date(string $text): ?DateTimeImmutable
{
    $dates = orlen_vitay_textual_dates($text);

    if ($dates === []) {
        return null;
    }

    usort($dates, static function (DateTimeImmutable $left, DateTimeImmutable $right): int {
        return strcmp($right->format('Y-m-d'), $left->format('Y-m-d'));
    });

    return $dates[0];
}

function orlen_vitay_discount_label(string $text): ?string
{
    if (preg_match_all('/(?:-|−)?\s*([0-9]{1,3})\s*gr\s*\/\s*l/iu', $text, $matches) <= 0) {
        return null;
    }

    $values = array_map('intval', $matches[1]);

    if ($values === []) {
        return null;
    }

    return max($values) . ' gr/l';
}

function orlen_vitay_promotion_title(string $text): string
{
    if (preg_match('/\b([\p{Lu}][\p{Ll}]+)\s+Promocja\b/u', $text, $match) === 1) {
        return $match[1] . ' Promocja ORLEN VITAY';
    }

    return 'Promocja paliwowa ORLEN VITAY';
}

function fetch_orlen_vitay_fuel_promotions(): array
{
    $items = [];
    $warnings = [];
    $fetchedAt = new DateTimeImmutable();

    $sourceUrl = orlen_press_fuel_promotion_url();

    if ($sourceUrl === null) {
        return [
            'url' => orlen_press_root_url(),
            'items' => [],
            'warnings' => ['Nie udalo sie ustalic adresu komunikatu o promocji paliwowej ORLEN.'],
            'warning' => 'Nie udalo sie ustalic adresu komunikatu o promocji paliwowej ORLEN.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'orlen_press_no_url',
        ];
    }

    $html = http_get_orlen_vitay($sourceUrl);

    if (!is_string($html) || trim($html) === '') {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => ['Nie udalo sie pobrac komunikatu o promocji paliwowej ORLEN.'],
            'warning' => 'Nie udalo sie pobrac komunikatu o promocji paliwowej ORLEN.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'orlen_press_failed',
        ];
    }

    $lines = html_to_clean_lines($html);
    $text = clean_text(implode(' ', $lines));

    $text = preg_replace('/(\d+)\s*gr\s+na\s+litr/iu', '$1 gr/l', $text) ?? $text;

    $fuelSignals = 0;
    foreach (['tankuj', 'benzyn', 'oleju napędowego', 'oleju napedowego', 'efecta', 'verva', 'paliw'] as $needle) {
        if (text_contains_ci($text, $needle)) {
            $fuelSignals++;
        }
    }

    $hasPromotion = text_contains_ci($text, 'promocj');
    $hasDiscount = orlen_vitay_discount_label($text) !== null;

    if (!$hasPromotion || !$hasDiscount || $fuelSignals < 2) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie znaleziono aktualnej promocji paliwowej ORLEN VITAY.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'orlen_press_no_fuel_promo',
        ];
    }

    $to = orlen_press_promotion_end_date($text);
    $discountLabel = orlen_vitay_discount_label($text) ?? '35 gr/l';

    $grValues = [];
    if (preg_match_all('/([0-9]{1,3})\s*gr\s*\/\s*l/iu', $text, $grMatches) > 0) {
        $grValues = array_values(array_unique(array_map('intval', $grMatches[1])));
        sort($grValues);
    }
    $baseGr = $grValues[0] ?? 20;
    $maxGr = $grValues !== [] ? (int) max($grValues) : 35;

    $dateRange = [
        'fromLabel' => null,
        'toLabel' => $to?->format('d.m.Y'),
        'fromIso' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'rangeLabel' => $to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null,
    ];

    $description = sprintf(
        '%d gr/l na benzyny i olej napędowy (VERVA i EFECTA), do %d gr/l przy jednoczesnych zakupach pozapaliwowych za min. 5 zł. Rabat z kuponem w aplikacji ORLEN VITAY, obowiązuje w weekendy.',
        $baseGr,
        $maxGr
    );
    $title = orlen_vitay_promotion_title($text);
    $item = build_station_promotion_payload(
        'ORLEN',
        $title,
        $description,
        $sourceUrl,
        $sourceUrl,
        $dateRange
    );
    $item['discountLabel'] = $discountLabel;
    $item['sourceMode'] = 'orlen_press_fuel_promotions';

    $items[] = $item;
    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $sourceUrl,
        'items' => $items,
        'warnings' => array_values(array_unique($warnings)),
        'warning' => null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'orlen_press_fuel_promotions',
    ];
}

function shell_model_json_url(string $htmlUrl): string
{
    return preg_replace('/\.html(\?.*)?$/i', '.model.json', $htmlUrl) ?? $htmlUrl;
}

function http_get_shell_model(string $htmlUrl): ?array
{
    $raw = http_get(shell_model_json_url($htmlUrl), ['Accept: application/json,text/plain,*/*']);

    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function shell_clean_node_text(string $value): string
{
    $value = str_replace(['&nbsp;', '<br>', '<br/>', '<br />'], ' ', $value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return clean_text(strip_tags($value));
}

function shell_collect_listing_teasers(array $model): array
{
    $teasers = [];

    $walker = static function ($node) use (&$walker, &$teasers): void {
        if (!is_array($node)) {
            return;
        }

        $title = is_string($node['title'] ?? null) ? shell_clean_node_text($node['title']) : null;
        $url = null;

        if (isset($node['links']) && is_array($node['links'])) {
            foreach ($node['links'] as $link) {
                if (is_array($link) && is_string($link['value'] ?? null) && stripos($link['value'], '/oferty-i-promocje/') !== false) {
                    $url = $link['value'];
                    break;
                }
            }
        }

        if ($title !== null && $title !== '' && is_string($url)) {
            $text = is_string($node['text'] ?? null) ? shell_clean_node_text($node['text']) : '';
            $alt = '';

            if (isset($node['image']) && is_array($node['image']) && is_string($node['image']['alt'] ?? null)) {
                $alt = shell_clean_node_text($node['image']['alt']);
            }

            $teasers[] = ['title' => $title, 'text' => $text, 'url' => $url, 'alt' => $alt];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $walker($value);
            }
        }
    };

    $walker($model);

    return $teasers;
}

function shell_model_text_nodes(array $model): array
{
    $nodes = [];

    $walker = static function ($node) use (&$walker, &$nodes): void {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($value) && in_array($key, ['title', 'text', 'jcr:title'], true)) {
                $clean = shell_clean_node_text($value);

                if ($clean !== '') {
                    $nodes[] = $clean;
                }
            } elseif (is_array($value)) {
                $walker($value);
            }
        }
    };

    $walker($model);

    return $nodes;
}

function shell_numeric_dates(string $text): array
{
    if (preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/', $text, $matches, PREG_SET_ORDER) <= 0) {
        return [];
    }

    $dates = [];

    foreach ($matches as $match) {
        $day = (int) $match[1];
        $month = (int) $match[2];
        $year = (int) $match[3];

        if (checkdate($month, $day, $year)) {
            $dates[] = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }
    }

    return $dates;
}

function shell_min_max_dates(array $dates): array
{
    if ($dates === []) {
        return [null, null];
    }

    usort($dates, static function (DateTimeImmutable $a, DateTimeImmutable $b): int {
        return strcmp($a->format('Y-m-d'), $b->format('Y-m-d'));
    });

    return [$dates[0], end($dates)];
}

function shell_validity_dates(array $nodes, callable $extractor): array
{
    $keywords = [
        'obowiązuje od',
        'rozpoczyna się',
        'kończy się',
        'czas trwania',
        'możesz skorzystać od',
        'mozesz skorzystac od',
    ];

    foreach ($nodes as $node) {
        $matchesKeyword = false;

        foreach ($keywords as $keyword) {
            if (text_contains_ci($node, $keyword)) {
                $matchesKeyword = true;
                break;
            }
        }

        if (!$matchesKeyword) {
            continue;
        }

        $dates = $extractor($node);

        if (count($dates) >= 2) {
            return shell_min_max_dates($dates);
        }
    }

    return shell_min_max_dates($extractor(clean_text(implode(' ', $nodes))));
}

function shell_build_summer_segment(array $teaser): ?array
{
    $model = http_get_shell_model($teaser['url']);

    if ($model === null) {
        return null;
    }

    $nodes = shell_model_text_nodes($model);
    $full = clean_text(implode(' ', $nodes));

    $tiers = [];
    $count = count($nodes);

    for ($i = 0; $i < $count; $i++) {
        if (preg_match('/^[−-]?\s*(\d{1,3})\s*gr\s*\/?\s*lit?r/iu', $nodes[$i], $match) === 1) {
            $value = (int) $match[1];
            $tiers[$value] = trim($nodes[$i] . ' ' . ($nodes[$i + 1] ?? ''));
        }
    }

    if ($tiers === []) {
        return null;
    }

    krsort($tiers);

    $lines = [];

    foreach ($tiers as $value => $context) {
        if (text_contains_ci($context, 'lpg') || text_contains_ci($context, 'autogas')) {
            $scope = 'na Shell AutoGas LPG';
        } elseif (text_contains_ci($context, 'v-power') || text_contains_ci($context, 'fuelsave')) {
            $scope = 'na paliwa Shell V-Power i FuelSave (95 i Diesel)';
        } else {
            $scope = 'na wybrane paliwa Shell';
        }

        $condition = '';

        if (
            text_contains_ci($context, 'kup dowolny produkt')
            || text_contains_ci($context, 'gdy kupisz')
            || text_contains_ci($context, 'marki własnej shell')
            || text_contains_ci($context, 'marki wlasnej shell')
        ) {
            $condition = ' - tylko przy zakupie dowolnego produktu marki Shell (np. Shell Café lub myjnia)';
        } elseif (text_contains_ci($context, 'racing')) {
            $condition = ' - standardowo, bez dodatkowych zakupów (poza Shell V-Power Racing)';
        }

        $lines[] = '-' . $value . ' gr/l ' . $scope . $condition;
    }

    [$from, $to] = shell_validity_dates($nodes, 'orlen_vitay_textual_dates');

    $extra = [];

    if (preg_match('/do\s*(?:maksymalnie\s*)?(\d{1,3})\s*litr/iu', $full, $litres) === 1) {
        $extra[] = 'rabat do ' . (int) $litres[1] . ' l na tankowanie';
    }

    if (preg_match('/\b(trzy|dwa|cztery|pięć|piec|\d+)\s*razy?\s*w\s*(?:każdym\s*|kazdym\s*)?miesi/iu', $full, $freq) === 1) {
        $extra[] = $freq[1] . ' razy w miesiącu na ofertę';
    }

    $extra[] = 'po aktywacji w aplikacji Shell ClubSmart';

    $maxValue = (int) array_key_first($tiers);
    $text = implode('. ', $lines) . '. ' . ucfirst(implode(', ', $extra)) . '.';

    return [
        'heading' => 'Tankuj taniej do -' . $maxValue . ' gr/l',
        'text' => $text,
        'discountLabel' => 'do ' . $maxValue . ' gr/l',
        'discountValueGrPerL' => $maxValue,
        'fromIso' => $from?->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'dateLabel' => $to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null,
        'url' => $teaser['url'],
        'isActive' => station_promotion_is_active($from?->format('Y-m-d'), $to?->format('Y-m-d')),
    ];
}

function shell_build_premium_segment(array $teaser): ?array
{
    $model = http_get_shell_model($teaser['url']);

    if ($model === null) {
        return null;
    }

    $nodes = shell_model_text_nodes($model);
    $full = clean_text(implode(' ', $nodes));

    $window = null;

    if (preg_match('/(\d{1,2}:\d{2})\s*[-–—]\s*(\d{1,2}:\d{2})/u', $full, $match) === 1) {
        $window = $match[1] . '-' . $match[2];
    }

    [$from, $to] = shell_validity_dates($nodes, 'shell_numeric_dates');

    $text = 'Shell V-Power 95 i V-Power Diesel w cenie paliw podstawowych';

    if ($window !== null) {
        $text .= ' - codziennie w godz. ' . $window;
    }

    $text .= '.';

    return [
        'heading' => 'Paliwa premium w cenie paliw podstawowych',
        'text' => $text,
        'discountLabel' => null,
        'discountValueGrPerL' => null,
        'fromIso' => $from?->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'dateLabel' => $to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null,
        'url' => $teaser['url'],
        'isActive' => station_promotion_is_active($from?->format('Y-m-d'), $to?->format('Y-m-d')),
    ];
}

function fetch_shell_fuel_promotions(): array
{
    $fetchedAt = new DateTimeImmutable();
    $listingUrl = shell_promotions_source_url();
    $model = http_get_shell_model($listingUrl);

    if ($model === null) {
        return [
            'url' => $listingUrl,
            'items' => [],
            'warnings' => ['Nie udalo sie pobrac oficjalnej strony promocji Shell.'],
            'warning' => 'Nie udalo sie pobrac oficjalnej strony promocji Shell.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'shell_failed',
        ];
    }

    $teasers = shell_collect_listing_teasers($model);
    $summerTeaser = null;
    $premiumTeaser = null;

    foreach ($teasers as $teaser) {
        $haystack = $teaser['title'] . ' ' . $teaser['text'] . ' ' . $teaser['alt'];

        if (
            $summerTeaser === null
            && (preg_match('/\d+\s*gr\s*\/?\s*lit?r/iu', $haystack) === 1 || preg_match('/-?\d+\s*gr\b/i', $teaser['alt']) === 1)
            && (text_contains_ci($haystack, 'tankuj') || text_contains_ci($haystack, 'rabat'))
        ) {
            $summerTeaser = $teaser;
        }

        if (
            $premiumTeaser === null
            && text_contains_ci($haystack, 'premium')
            && text_contains_ci($haystack, 'cenie paliw podstawow')
        ) {
            $premiumTeaser = $teaser;
        }
    }

    $segments = [];

    if ($summerTeaser !== null) {
        $segment = shell_build_summer_segment($summerTeaser);

        if ($segment !== null) {
            $segments[] = $segment;
        }
    }

    if ($premiumTeaser !== null) {
        $segment = shell_build_premium_segment($premiumTeaser);

        if ($segment !== null) {
            $segments[] = $segment;
        }
    }

    if ($segments === []) {
        return [
            'url' => $listingUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie znaleziono aktualnych promocji paliwowych Shell.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'shell_no_fuel_promo',
        ];
    }

    $headlineDiscount = null;
    $headlineValue = null;
    $cardFrom = null;
    $cardTo = null;

    foreach ($segments as $segment) {
        if ($segment['discountValueGrPerL'] !== null && ($headlineValue === null || $segment['discountValueGrPerL'] > $headlineValue)) {
            $headlineValue = $segment['discountValueGrPerL'];
            $headlineDiscount = $segment['discountLabel'];
        }

        if ($segment['fromIso'] !== null && ($cardFrom === null || $segment['fromIso'] < $cardFrom)) {
            $cardFrom = $segment['fromIso'];
        }

        if ($segment['toIso'] !== null && ($cardTo === null || $segment['toIso'] > $cardTo)) {
            $cardTo = $segment['toIso'];
        }
    }

    $dateRange = [
        'fromLabel' => null,
        'toLabel' => $cardTo,
        'fromIso' => $cardFrom,
        'toIso' => $cardTo,
        'rangeLabel' => null,
    ];

    $item = build_station_promotion_payload(
        'Shell',
        'Promocje paliwowe',
        'Dwie aktualne promocje na paliwo na stacjach Shell:',
        $summerTeaser['url'] ?? $listingUrl,
        $listingUrl,
        $dateRange
    );

    if ($headlineDiscount !== null) {
        $item['discountLabel'] = $headlineDiscount;
        $item['discountValueGrPerL'] = $headlineValue;
    }

    $item['segments'] = $segments;
    $item['sourceMode'] = 'shell_official';

    $items = [$item];
    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $listingUrl,
        'items' => $items,
        'warnings' => [],
        'warning' => null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'shell_fuel_promotions',
    ];
}

function carry_over_station_promotions(array $previousItems, array $freshNetworks): array
{
    if ($previousItems === []) {
        return [];
    }

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $carried = [];
    $seenNetworks = [];

    foreach ($previousItems as $item) {
        if (!is_array($item) || empty($item['network'])) {
            continue;
        }

        $network = (string) $item['network'];

        if (isset($freshNetworks[$network]) || isset($seenNetworks[$network])) {
            continue;
        }

        $toIso = $item['toIso'] ?? null;

        if (is_string($toIso) && $toIso !== '' && $toIso < $today) {
            continue;
        }

        $item['carriedOver'] = true;
        $item['isTopPromotion'] = false;
        $carried[] = $item;
        $seenNetworks[$network] = true;
    }

    return $carried;
}

function fetch_station_promotions(array $previousItems = []): array
{
    $bpOfficialPromotions = fetch_bp_official_fuel_promotions();
    $orlenVitayPromotions = fetch_orlen_vitay_fuel_promotions();
    $shellOfficialPromotions = fetch_shell_fuel_promotions();

    $items = [];
    $freshNetworks = [];

    foreach ([$bpOfficialPromotions, $orlenVitayPromotions, $shellOfficialPromotions] as $source) {
        if (!is_array($source)) {
            continue;
        }

        foreach (($source['items'] ?? []) as $item) {
            if (is_array($item) && !empty($item['network'])) {
                $items[] = $item;
                $freshNetworks[(string) $item['network']] = true;
            }
        }
    }

    foreach (carry_over_station_promotions($previousItems, $freshNetworks) as $carriedItem) {
        $items[] = $carriedItem;
    }

    station_promotions_sort($items);
    mark_top_station_promotions($items);

    $warnings = array_values(array_unique(array_merge(
        $bpOfficialPromotions['warnings'] ?? [],
        $orlenVitayPromotions['warnings'] ?? [],
        $shellOfficialPromotions['warnings'] ?? []
    )));

    return [
        'url' => station_promotions_source_url(),
        'items' => $items,
        'warnings' => $warnings,
        'warning' => $items === [] ? 'Nie znaleziono aktualnych promocji paliwowych na oficjalnych stronach stacji.' : null,
        'fetchedAtLabel' => $bpOfficialPromotions['fetchedAtLabel']
            ?? $orlenVitayPromotions['fetchedAtLabel']
            ?? $shellOfficialPromotions['fetchedAtLabel']
            ?? (new DateTimeImmutable())->format('d.m.Y H:i'),
        'sourceMode' => 'official_direct',
    ];
}

function extract_match(string $pattern, string $html, int $group = 1): ?string
{
    if (preg_match($pattern, $html, $matches) === 1 && isset($matches[$group])) {
        return clean_text($matches[$group]);
    }

    return null;
}

function polish_month_genitive(int $month): string
{
    return [
        1 => 'stycznia',
        2 => 'lutego',
        3 => 'marca',
        4 => 'kwietnia',
        5 => 'maja',
        6 => 'czerwca',
        7 => 'lipca',
        8 => 'sierpnia',
        9 => 'września',
        10 => 'października',
        11 => 'listopada',
        12 => 'grudnia',
    ][$month] ?? '';
}

function normalize_gov_article_title(string $value): string
{
    $value = clean_text($value);
    $value = str_replace(['–', '—'], '-', $value);
    $value = preg_replace('/[[:punct:]]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function gov_slugify_pl(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

    $value = strtr($value, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ź' => 'z',
        'ż' => 'z',
    ]);

    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-');
}

function gov_target_date_variants(DateTimeImmutable $targetDate): array
{
    $day = (int) $targetDate->format('j');
    $month = polish_month_genitive((int) $targetDate->format('n'));
    $year = $targetDate->format('Y');

    return array_values(array_unique([
        $targetDate->format('d.m.Y'),
        $targetDate->format('j.m.Y'),
        $day . ' ' . $month . ' ' . $year,
        $day . ' ' . $month . ' ' . $year . ' r',
        $day . ' ' . $month . ' ' . $year . ' r.',
    ]));
}

function gov_date_range_from_parts(int $fromDay, int $fromMonth, ?int $fromYear, int $toDay, int $toMonth, int $toYear): ?array
{
    if ($fromYear === null) {
        $fromYear = $fromMonth > $toMonth ? $toYear - 1 : $toYear;
    }

    if (!checkdate($fromMonth, $fromDay, $fromYear) || !checkdate($toMonth, $toDay, $toYear)) {
        return null;
    }

    $from = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $fromYear, $fromMonth, $fromDay));
    $to = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $toYear, $toMonth, $toDay));

    if ($from > $to) {
        return null;
    }

    return [
        'from' => $from,
        'to' => $to,
    ];
}

function polish_month_number_from_name(string $monthName): ?int
{
    $months = [
        'stycznia' => 1,
        'lutego' => 2,
        'marca' => 3,
        'kwietnia' => 4,
        'maja' => 5,
        'czerwca' => 6,
        'lipca' => 7,
        'sierpnia' => 8,
        'września' => 9,
        'wrzesnia' => 9,
        'października' => 10,
        'pazdziernika' => 10,
        'listopada' => 11,
        'grudnia' => 12,
    ];

    $key = function_exists('mb_strtolower') ? mb_strtolower(trim($monthName), 'UTF-8') : strtolower(trim($monthName));
    return $months[$key] ?? null;
}

function gov_numeric_date_ranges(string $value): array
{
    $haystack = clean_text($value);

    if ($haystack === '') {
        return [];
    }

    $ranges = [];
    $rangePatterns = [
        '/\b(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?\.?\s*[\-\x{2013}\x{2014}]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u',
        '/\b(\d{1,2})-(\d{1,2})(?:-(\d{4}))?-(\d{1,2})-(\d{1,2})-(\d{4})\b/u',
    ];

    foreach ($rangePatterns as $pattern) {
        if (preg_match_all($pattern, $haystack, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) <= 0) {
            continue;
        }

        foreach ($matches as $match) {
            $fromYear = isset($match[3]) && $match[3] !== null && $match[3] !== '' ? (int) $match[3] : null;
            $range = gov_date_range_from_parts((int) $match[1], (int) $match[2], $fromYear, (int) $match[4], (int) $match[5], (int) $match[6]);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }
    }

    if (preg_match_all('/\b(\d{2})(\d{2})[\-\x{2013}\x{2014}](\d{2})(\d{2})(\d{4})\b/u', $haystack, $compactMatches, PREG_SET_ORDER) > 0) {
        foreach ($compactMatches as $match) {
            $range = gov_date_range_from_parts((int) $match[1], (int) $match[2], null, (int) $match[3], (int) $match[4], (int) $match[5]);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }
    }

    $monthPattern = 'stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|września|wrzesnia|października|pazdziernika|listopada|grudnia';

    if (preg_match_all('/\b(\d{1,2})\s*[\-\x{2013}\x{2014}]\s*(\d{1,2})[\s\-]+(' . $monthPattern . ')[\s\-]+(\d{4})\b/iu', $haystack, $textualSameMonthMatches, PREG_SET_ORDER) > 0) {
        foreach ($textualSameMonthMatches as $match) {
            $month = polish_month_number_from_name($match[3]);

            if ($month === null) {
                continue;
            }

            $range = gov_date_range_from_parts((int) $match[1], $month, null, (int) $match[2], $month, (int) $match[4]);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }
    }

    if (preg_match_all('/\b(\d{1,2})[\s\-]+(' . $monthPattern . ')\s*[\-\x{2013}\x{2014}]\s*(\d{1,2})[\s\-]+(' . $monthPattern . ')[\s\-]+(\d{4})\b/iu', $haystack, $textualCrossMonthMatches, PREG_SET_ORDER) > 0) {
        foreach ($textualCrossMonthMatches as $match) {
            $fromMonth = polish_month_number_from_name($match[2]);
            $toMonth = polish_month_number_from_name($match[4]);

            if ($fromMonth === null || $toMonth === null) {
                continue;
            }

            $range = gov_date_range_from_parts((int) $match[1], $fromMonth, null, (int) $match[3], $toMonth, (int) $match[5]);

            if ($range !== null) {
                $ranges[] = $range;
            }
        }
    }

    return $ranges;
}

function gov_article_date_range_points_to_target_date(string $value, DateTimeImmutable $targetDate): bool
{
    $targetIso = $targetDate->format('Y-m-d');

    foreach (gov_numeric_date_ranges($value) as $range) {
        $from = $range['from'];
        $to = $range['to'];

        if ($targetIso >= $from->format('Y-m-d') && $targetIso <= $to->format('Y-m-d')) {
            return true;
        }
    }

    return false;
}

function expected_gov_fuel_update_title(DateTimeImmutable $targetDate): string
{
    return 'Maksymalna cena detaliczna paliw obowiązująca ' . (int) $targetDate->format('j') . ' ' . polish_month_genitive((int) $targetDate->format('n')) . ' ' . $targetDate->format('Y') . ' r.';
}

function gov_absolute_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://www.gov.pl' . $url;
    }

    return 'https://www.gov.pl/' . ltrim($url, '/');
}

function gov_article_points_to_target_date(string $title, string $url, DateTimeImmutable $targetDate): bool
{
    $titleKey = normalize_gov_article_title($title);
    $urlKey = gov_slugify_pl($url);

    if (
        !text_contains_ci($titleKey, 'maksymalna cena detaliczna paliw')
        && !str_contains($urlKey, 'maksymalna-cena-detaliczna-paliw')
    ) {
        return false;
    }

    if (gov_article_date_range_points_to_target_date($title . ' ' . $url, $targetDate)) {
        return true;
    }

    foreach (gov_target_date_variants($targetDate) as $variant) {
        $variantKey = normalize_gov_article_title($variant);

        if ($variantKey !== '' && text_contains_ci($titleKey, $variantKey)) {
            return true;
        }

        $variantSlug = gov_slugify_pl($variant);

        if ($variantSlug !== '' && str_contains($urlKey, $variantSlug)) {
            return true;
        }
    }

    $day = (int) $targetDate->format('j');
    $month = polish_month_genitive((int) $targetDate->format('n'));
    $year = $targetDate->format('Y');
    $textualSlug = gov_slugify_pl($day . ' ' . $month . ' ' . $year);

    return $textualSlug !== '' && str_contains($urlKey, $textualSlug);
}

function find_expected_gov_fuel_update_article(DateTimeImmutable $targetDate): ?array
{
    $listingUrls = [
        'https://www.gov.pl/web/energia/wiadomosci?page=0&size=20',
        'https://www.gov.pl/web/energia/wiadomosci',
    ];

    foreach ($listingUrls as $listingUrl) {
        $html = http_get_light($listingUrl);

        if (!is_string($html) || trim($html) === '') {
            continue;
        }

        if (preg_match_all('/<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $href = gov_absolute_url($match[2]);
                $title = clean_text($match[3]);

                if ($href === '' || $title === '') {
                    continue;
                }

                if (gov_article_points_to_target_date($title, $href, $targetDate)) {
                    return [
                        'title' => $title,
                        'expectedTitle' => expected_gov_fuel_update_title($targetDate),
                        'url' => $href,
                        'targetDateIso' => $targetDate->format('Y-m-d'),
                        'targetDateLabel' => $targetDate->format('d.m.Y'),
                        'foundAtIso' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                    ];
                }
            }
        }

        if (gov_article_points_to_target_date(clean_text($html), '', $targetDate)) {
            return [
                'title' => expected_gov_fuel_update_title($targetDate),
                'expectedTitle' => expected_gov_fuel_update_title($targetDate),
                'url' => $listingUrl,
                'targetDateIso' => $targetDate->format('Y-m-d'),
                'targetDateLabel' => $targetDate->format('d.m.Y'),
                'foundAtIso' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
        }
    }

    return null;
}

function find_expected_monitor_polish_fuel_update_article(DateTimeImmutable $targetDate): ?array
{
    $warnings = [];
    $notice = fetch_monitor_polish_latest_fuel_notice($warnings);

    if ($notice === null) {
        return null;
    }

    $publishedIso = $notice['publishedDateIso'] ?? null;
    $expectedPublishedIso = $targetDate->modify('-1 day')->format('Y-m-d');

    if (!is_string($publishedIso) || $publishedIso === '' || $publishedIso !== $expectedPublishedIso) {
        return null;
    }

    return [
        'title' => (string) ($notice['title'] ?? 'Obwieszczenie Monitor Polski'),
        'expectedTitle' => expected_gov_fuel_update_title($targetDate),
        'url' => (string) ($notice['url'] ?? monitor_polish_source_url()),
        'source' => 'monitor_polish',
        'targetDateIso' => $targetDate->format('Y-m-d'),
        'targetDateLabel' => $targetDate->format('d.m.Y'),
        'foundAtIso' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];
}

function find_expected_fuel_update_article(DateTimeImmutable $targetDate): ?array
{
    return find_expected_gov_fuel_update_article($targetDate)
        ?? find_expected_monitor_polish_fuel_update_article($targetDate);
}

function announcement_matches_effective_date(?array $announcement, DateTimeImmutable $targetDate): bool
{
    if (!is_array($announcement)) {
        return false;
    }

    $prices = $announcement['prices'] ?? [];

    if (!is_array($prices) || $prices === []) {
        return false;
    }

    $targetIso = $targetDate->format('Y-m-d');
    $fromIso = $announcement['effectiveFromIso'] ?? null;
    $toIso = $announcement['effectiveToIso'] ?? null;

    if (!is_string($fromIso) || $fromIso === '') {
        return false;
    }

    if (!is_string($toIso) || $toIso === '') {
        $toIso = $fromIso;
    }

    return $targetIso >= $fromIso && $targetIso <= $toIso;
}

function dashboard_snapshot_has_prices_for_date(array $snapshot, DateTimeImmutable $targetDate): bool
{
    if (announcement_matches_effective_date($snapshot['currentAnnouncement'] ?? null, $targetDate)) {
        return true;
    }

    if (announcement_matches_effective_date($snapshot['tomorrowAnnouncement'] ?? null, $targetDate)) {
        return true;
    }

    $announcements = $snapshot['announcements'] ?? [];

    if (is_array($announcements)) {
        foreach ($announcements as $announcement) {
            if (is_array($announcement) && announcement_matches_effective_date($announcement, $targetDate)) {
                return true;
            }
        }
    }

    return false;
}




function gov_fuel_update_button_state(array $snapshot): array
{
    $targetDate = new DateTimeImmutable('tomorrow');

    return [
        'available' => false,
        'targetDateIso' => $targetDate->format('Y-m-d'),
        'targetDateLabel' => $targetDate->format('d.m.Y'),
        'article' => null,
        'loading' => false,
    ];
}




function http_get_binary(string $url, array $headers = []): ?string
{
    $headers = array_merge([
        'Accept: application/pdf,*/*;q=0.8',
        'Accept-Language: pl-PL,pl;q=0.9,en;q=0.6',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'User-Agent: FuelMonitor/2.4 (+local dashboard; binary fallback)',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 16,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
            return $body;
        }
    }

    if (function_exists('shell_exec')) {
        $tmpPath = tempnam(sys_get_temp_dir(), 'fuel-pdf-');

        if (is_string($tmpPath) && $tmpPath !== '') {
            $command = curl_shell_binary() . ' -L -s --connect-timeout 5 --max-time 16 '
                . '-A "FuelMonitor/2.4 (+local dashboard; binary fallback)" '
                . '-o ' . escapeshellarg($tmpPath) . ' '
                . escapeshellarg($url);

            shell_exec($command);

            $body = is_file($tmpPath) ? @file_get_contents($tmpPath) : false;
            @unlink($tmpPath);

            if (is_string($body) && $body !== '') {
                return $body;
            }
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 16,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (is_string($body) && $body !== '') {
        return $body;
    }

    return null;
}

function monitor_polish_source_url(): string
{
    return 'https://monitorpolski.gov.pl/MP';
}

function monitor_polish_absolute_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://monitorpolski.gov.pl' . $url;
    }

    return 'https://monitorpolski.gov.pl/' . ltrim($url, '/');
}

function monitor_polish_title_is_fuel_notice(string $title): bool
{
    $title = clean_text($title);

    return (
        text_contains_ci($title, 'maksymalnej ceny paliw')
        || text_contains_ci($title, 'maksymalna cena paliw')
    ) && text_contains_ci($title, 'stacji paliw');
}

function monitor_polish_parse_iso_date(?string $raw): ?DateTimeImmutable
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($raw));
    return $date instanceof DateTimeImmutable ? $date : null;
}

function fetch_monitor_polish_notice_detail(string $url, ?string $listingTitle, array &$warnings): ?array
{
    $html = http_get_light($url);

    if (!is_string($html) || trim($html) === '') {
        $warnings[] = 'Nie udalo sie pobrac szczegolow aktu z Monitor Polski.';
        return null;
    }

    $title = extract_match('/<h2\b[^>]*class=("|\')[^"\']*\bone-item-heading\b[^"\']*\1[^>]*>(.*?)<\/h2>/isu', $html, 2)
        ?? extract_match('/<h1\b[^>]*id=("|\')h_title\1[^>]*>(.*?)<\/h1>/isu', $html, 2)
        ?? $listingTitle
        ?? 'Obwieszczenie Monitor Polski';

    if (!monitor_polish_title_is_fuel_notice($title)) {
        return null;
    }

    $publishedDateIso = extract_match('/Data\s+og[łl]oszenia:\s*<\/td>\s*<td\b[^>]*>.*?<span>\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/isu', $html);

    if (preg_match_all('/<a\b[^>]*href=("|\')([^"\']+\.pdf(?:\?[^"\']*)?)\1/isu', $html, $pdfMatches, PREG_SET_ORDER) <= 0) {
        $warnings[] = 'Akt Monitor Polski nie zawiera linku do PDF.';
        return null;
    }

    $pdfUrl = '';

    foreach ($pdfMatches as $match) {
        $candidate = monitor_polish_absolute_url($match[2]);

        if ($candidate !== '') {
            $pdfUrl = $candidate;
            break;
        }
    }

    if ($pdfUrl === '') {
        $warnings[] = 'Nie udalo sie ustalic adresu PDF w Monitor Polski.';
        return null;
    }

    $publishedDate = null;
    $publishedDateObj = monitor_polish_parse_iso_date($publishedDateIso);

    if ($publishedDateObj instanceof DateTimeImmutable) {
        $publishedDate = $publishedDateObj->format('d.m.Y');
    }

    return [
        'title' => $title,
        'url' => $url,
        'pdfUrl' => $pdfUrl,
        'publishedDate' => $publishedDate,
        'publishedDateIso' => $publishedDateObj?->format('Y-m-d'),
    ];
}

function fetch_monitor_polish_latest_fuel_notice(array &$warnings): ?array
{
    $listingHtml = http_get_light(monitor_polish_source_url());

    if (!is_string($listingHtml) || trim($listingHtml) === '') {
        $warnings[] = 'Nie udalo sie pobrac listy aktow z Monitor Polski.';
        return null;
    }

    $detailUrls = [];

    if (preg_match_all('/<a\b[^>]*href=("|\')(\/MP\/[0-9]{4}\/[0-9]+)\1[^>]*>(.*?)<\/a>/isu', $listingHtml, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $url = monitor_polish_absolute_url($match[2]);
            $title = clean_text($match[3]);

            if ($url === '') {
                continue;
            }

            if (!in_array($url, $detailUrls, true)) {
                $detailUrls[] = $url;
            }

            if (monitor_polish_title_is_fuel_notice($title)) {
                return fetch_monitor_polish_notice_detail($url, $title, $warnings);
            }
        }
    }

    foreach (array_slice($detailUrls, 0, 12) as $url) {
        $notice = fetch_monitor_polish_notice_detail($url, null, $warnings);

        if ($notice !== null) {
            return $notice;
        }
    }

    $warnings[] = 'Na liscie Monitor Polski nie znaleziono najnowszego aktu o maksymalnej cenie paliw.';
    return null;
}

function monitor_polish_title_points_to_published_date(string $title, DateTimeImmutable $publishedDate): bool
{
    $day = (int) $publishedDate->format('j');
    $month = polish_month_genitive((int) $publishedDate->format('n'));
    $year = $publishedDate->format('Y');

    return text_contains_ci($title, 'z dnia ' . $day . ' ' . $month . ' ' . $year);
}

function fetch_monitor_polish_fuel_notice_for_published_date(DateTimeImmutable $publishedDate, array &$warnings): ?array
{
    $listingHtml = http_get_light(monitor_polish_source_url());

    if (!is_string($listingHtml) || trim($listingHtml) === '') {
        $warnings[] = 'Nie udalo sie pobrac listy aktow z Monitor Polski.';
        return null;
    }

    $detailUrls = [];

    if (preg_match_all('/<a\b[^>]*href=("|\')(\/MP\/[0-9]{4}\/[0-9]+)\1[^>]*>(.*?)<\/a>/isu', $listingHtml, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $url = monitor_polish_absolute_url($match[2]);
            $title = clean_text($match[3]);

            if ($url === '') {
                continue;
            }

            if (!in_array($url, $detailUrls, true)) {
                $detailUrls[] = $url;
            }

            if (
                monitor_polish_title_is_fuel_notice($title)
                && monitor_polish_title_points_to_published_date($title, $publishedDate)
            ) {
                $notice = fetch_monitor_polish_notice_detail($url, $title, $warnings);

                if (
                    is_array($notice)
                    && ($notice['publishedDateIso'] ?? null) === $publishedDate->format('Y-m-d')
                ) {
                    return $notice;
                }
            }
        }
    }

    foreach (array_slice($detailUrls, 0, 12) as $url) {
        $notice = fetch_monitor_polish_notice_detail($url, null, $warnings);

        if (
            is_array($notice)
            && ($notice['publishedDateIso'] ?? null) === $publishedDate->format('Y-m-d')
        ) {
            return $notice;
        }
    }

    return null;
}

function pdf_unicode_char_from_hex(string $hex): string
{
    $hex = ltrim($hex, '0');

    if ($hex === '') {
        return '';
    }

    $codepoint = hexdec($hex);

    if ($codepoint <= 0) {
        return '';
    }

    return html_entity_decode('&#x' . strtoupper(dechex($codepoint)) . ';', ENT_NOQUOTES, 'UTF-8');
}

function pdf_decoded_streams(string $pdf): array
{
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) <= 0) {
        return [];
    }

    $streams = [];

    foreach ($matches[1] as $stream) {
        $decoded = @gzuncompress($stream);

        if ($decoded === false) {
            $decoded = @gzdecode($stream);
        }

        if ($decoded === false) {
            $decoded = @gzinflate($stream);
        }

        if (is_string($decoded) && $decoded !== '') {
            $streams[] = $decoded;
        }
    }

    return $streams;
}

function pdf_extract_unicode_map(array $streams): array
{
    $map = [];

    foreach ($streams as $stream) {
        if (!str_contains($stream, 'beginbf')) {
            continue;
        }

        if (preg_match_all('/beginbfchar\s*(.*?)\s*endbfchar/s', $stream, $blocks) > 0) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER) <= 0) {
                    continue;
                }

                foreach ($pairs as $pair) {
                    $map[strtoupper($pair[1])] = pdf_unicode_char_from_hex($pair[2]);
                }
            }
        }

        if (preg_match_all('/beginbfrange\s*(.*?)\s*endbfrange/s', $stream, $blocks) <= 0) {
            continue;
        }

        foreach ($blocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER) > 0) {
                foreach ($ranges as $range) {
                    $from = hexdec($range[1]);
                    $to = hexdec($range[2]);
                    $dest = hexdec($range[3]);
                    $width = strlen($range[1]);

                    for ($code = $from; $code <= $to; $code++) {
                        $sourceKey = strtoupper(str_pad(strtoupper(dechex($code)), $width, '0', STR_PAD_LEFT));
                        $map[$sourceKey] = pdf_unicode_char_from_hex(strtoupper(dechex($dest + ($code - $from))));
                    }
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*\[(.*?)\]/s', $block, $arrayRanges, PREG_SET_ORDER) <= 0) {
                continue;
            }

            foreach ($arrayRanges as $range) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>/', $range[3], $targets) <= 0) {
                    continue;
                }

                $from = hexdec($range[1]);
                $width = strlen($range[1]);

                foreach ($targets[1] as $offset => $targetHex) {
                    $sourceKey = strtoupper(str_pad(strtoupper(dechex($from + $offset)), $width, '0', STR_PAD_LEFT));
                    $map[$sourceKey] = pdf_unicode_char_from_hex($targetHex);
                }
            }
        }
    }

    return $map;
}

function pdf_unescape_literal_string(string $value): string
{
    $output = '';
    $length = strlen($value);

    for ($i = 0; $i < $length; $i++) {
        $char = $value[$i];

        if ($char !== '\\') {
            $output .= $char;
            continue;
        }

        $i++;

        if ($i >= $length) {
            break;
        }

        $escaped = $value[$i];

        if ($escaped === 'n') {
            $output .= "\n";
        } elseif ($escaped === 'r') {
            $output .= "\r";
        } elseif ($escaped === 't') {
            $output .= "\t";
        } elseif ($escaped === 'b') {
            $output .= "\b";
        } elseif ($escaped === 'f') {
            $output .= "\f";
        } elseif ($escaped >= '0' && $escaped <= '7') {
            $octal = $escaped;

            for ($j = 0; $j < 2 && $i + 1 < $length && $value[$i + 1] >= '0' && $value[$i + 1] <= '7'; $j++) {
                $i++;
                $octal .= $value[$i];
            }

            $output .= chr(octdec($octal));
        } else {
            $output .= $escaped;
        }
    }

    return $output;
}

function pdf_text_from_hex_string(string $hex, array $unicodeMap): string
{
    $hex = preg_replace('/\s+/', '', $hex) ?? $hex;
    $output = '';
    $length = strlen($hex);

    for ($i = 0; $i < $length;) {
        $chunk = substr($hex, $i, 4);
        $key = strtoupper($chunk);

        if (strlen($chunk) === 4 && isset($unicodeMap[$key])) {
            $output .= $unicodeMap[$key];
            $i += 4;
            continue;
        }

        if (strlen($chunk) === 4 && hexdec($chunk) > 255) {
            $output .= pdf_unicode_char_from_hex($chunk);
            $i += 4;
            continue;
        }

        $byte = substr($hex, $i, 2);

        if (strlen($byte) < 2) {
            break;
        }

        $value = hexdec($byte);

        if ($value >= 32 && $value < 127) {
            $output .= chr($value);
        }

        $i += 2;
    }

    return $output;
}

function pdf_extract_text(string $pdf): string
{
    $streams = pdf_decoded_streams($pdf);

    if ($streams === []) {
        return '';
    }

    $unicodeMap = pdf_extract_unicode_map($streams);
    $text = '';

    foreach ($streams as $stream) {
        if (!str_contains($stream, ' TJ') && !str_contains($stream, ' Tj')) {
            continue;
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $stream, $arrays) > 0) {
            foreach ($arrays[1] as $arrayBody) {
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>|\((?:\\\\.|[^\\\\)])*\)/s', $arrayBody, $tokens, PREG_SET_ORDER) <= 0) {
                    continue;
                }

                foreach ($tokens as $token) {
                    if (isset($token[1]) && $token[1] !== '') {
                        $text .= pdf_text_from_hex_string($token[1], $unicodeMap);
                    } else {
                        $text .= pdf_unescape_literal_string(substr($token[0], 1, -1));
                    }
                }

                $text .= "\n";
            }
        }

        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj|\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $stream, $items, PREG_SET_ORDER) <= 0) {
            continue;
        }

        foreach ($items as $item) {
            if (isset($item[1]) && $item[1] !== '') {
                $text .= pdf_text_from_hex_string($item[1], $unicodeMap);
            } else {
                $end = strrpos($item[0], ')');
                $text .= $end === false ? '' : pdf_unescape_literal_string(substr($item[0], 1, $end - 1));
            }

            $text .= "\n";
        }
    }

    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

function parse_monitor_polish_pdf_prices(string $pdf): array
{
    $text = pdf_extract_text($pdf);

    if ($text === '') {
        return [];
    }

    $patterns = [
        'PB95' => '/benzyn[^;]{0,180}\b95\b[^;]{0,260}powi[^;]{0,160}wynosi\s*([0-9]+[.,][0-9]{2})\s*z/i',
        'PB98' => '/benzyn[^;]{0,180}\b98\b[^;]{0,260}powi[^;]{0,160}wynosi\s*([0-9]+[.,][0-9]{2})\s*z/i',
        'ON' => '/oleju[^;]{0,260}powi[^;]{0,160}wynosi\s*([0-9]+[.,][0-9]{2})\s*z/i',
    ];

    $prices = [];

    foreach ($patterns as $fuel => $pattern) {
        if (preg_match($pattern, $text, $match) !== 1) {
            continue;
        }

        $value = parse_price_value($match[1]);

        if ($value !== null) {
            $prices[$fuel] = $value;
        }
    }

    return $prices;
}

function monitor_polish_fallback_announcement(DateTimeImmutable $targetDate, array &$warnings): ?array
{
    $expectedPublishedDate = $targetDate->modify('-1 day');
    $notice = fetch_monitor_polish_fuel_notice_for_published_date($expectedPublishedDate, $warnings);

    if ($notice === null) {
        return null;
    }

    $publishedIso = $notice['publishedDateIso'] ?? null;
    $expectedPublishedIso = $expectedPublishedDate->format('Y-m-d');

    if (is_string($publishedIso) && $publishedIso !== '' && $publishedIso !== $expectedPublishedIso) {
        $warnings[] = 'Najnowszy akt Monitor Polski nie odpowiada oczekiwanej dacie publikacji.';
        return null;
    }

    $pdfUrl = $notice['pdfUrl'] ?? null;

    if (!is_string($pdfUrl) || $pdfUrl === '') {
        return null;
    }

    $pdf = http_get_binary($pdfUrl);

    if (!is_string($pdf) || $pdf === '') {
        $warnings[] = 'Nie udalo sie pobrac PDF z Monitor Polski.';
        return null;
    }

    $prices = parse_monitor_polish_pdf_prices($pdf);

    if ($prices === []) {
        $warnings[] = 'PDF z Monitor Polski zostal pobrany, ale nie udalo sie odczytac cen paliw.';
        return null;
    }

    return [
        'title' => (string) ($notice['title'] ?? 'Obwieszczenie Monitor Polski'),
        'publishedDate' => $notice['publishedDate'] ?? null,
        'publishedDateIso' => $notice['publishedDateIso'] ?? null,
        'intro' => null,
        'url' => (string) ($notice['url'] ?? monitor_polish_source_url()),
        'source' => 'monitor_polish',
        'sourcePdfUrl' => $pdfUrl,
        'prices' => $prices,
        'effectiveFromIso' => $targetDate->format('Y-m-d'),
        'effectiveToIso' => $targetDate->format('Y-m-d'),
        'effectiveLabel' => $targetDate->format('d.m.Y'),
        'effectiveDateWasParsed' => true,
    ];
}

function announcements_have_prices_for_date(array $announcements, DateTimeImmutable $targetDate): bool
{
    foreach ($announcements as $announcement) {
        if (is_array($announcement) && announcement_matches_effective_date($announcement, $targetDate)) {
            return true;
        }
    }

    return false;
}

function append_monitor_polish_fallback_announcement(array $announcements, DateTimeImmutable $targetDate): array
{
    if (announcements_have_prices_for_date($announcements, $targetDate)) {
        return $announcements;
    }

    $warnings = [];
    $announcement = monitor_polish_fallback_announcement($targetDate, $warnings);

    if ($announcement === null) {
        return $announcements;
    }

    $targetIso = $targetDate->format('Y-m-d');
    $sourcePdfUrl = $announcement['sourcePdfUrl'] ?? null;
    $url = $announcement['url'] ?? null;
    $filtered = [];

    foreach ($announcements as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sameUrl = is_string($url) && $url !== '' && ($item['url'] ?? null) === $url;
        $samePdf = is_string($sourcePdfUrl) && $sourcePdfUrl !== '' && ($item['sourcePdfUrl'] ?? null) === $sourcePdfUrl;
        $sameTargetFallback = ($item['source'] ?? null) === 'monitor_polish'
            && ($item['effectiveFromIso'] ?? null) === $targetIso;

        if ($sameUrl || $samePdf || $sameTargetFallback) {
            continue;
        }

        $filtered[] = $item;
    }

    $filtered[] = $announcement;
    sort_announcements_by_effective_date($filtered);

    return $filtered;
}

function sort_announcements_by_effective_date(array &$announcements): void
{
    usort($announcements, static function (array $left, array $right): int {
        $leftSort = $left['effectiveFromIso'] ?? $left['publishedDateIso'] ?? '';
        $rightSort = $right['effectiveFromIso'] ?? $right['publishedDateIso'] ?? '';
        return strcmp((string) $rightSort, (string) $leftSort);
    });
}

function rebuild_dashboard_price_fields(array $snapshot, array $announcements): array
{
    sort_announcements_by_effective_date($announcements);

    $today = new DateTimeImmutable('today');
    $now = new DateTimeImmutable('now');
    $tomorrow = $today->modify('+1 day');

    $currentAnnouncement = find_active_announcement($announcements, $today);

    if ($currentAnnouncement === null && $announcements !== []) {
        $currentAnnouncement = $announcements[0];
    }

    $previousAnnouncement = find_previous_announcement($announcements, $currentAnnouncement);
    $tomorrowAnnouncement = find_active_announcement_strict($announcements, $tomorrow);

    $fuelLabels = is_array($snapshot['fuelLabels'] ?? null) && $snapshot['fuelLabels'] !== []
        ? $snapshot['fuelLabels']
        : [
            'PB95' => 'PB95',
            'PB98' => 'PB98',
            'ON' => 'ON',
        ];

    $lastMonthAnnouncements = filter_announcements_last_month($announcements, $now);
    $lastMonthAnnouncements = deduplicate_announcements_by_effective_date($lastMonthAnnouncements);

    $chartAnnouncements = array_map(static function (array $item): array {
        $chartDateIso = announcement_chart_date_iso($item);

        return [
            'label' => $item['effectiveLabel'] ?? ($item['publishedDate'] ?? 'brak daty'),
            'title' => $item['title'],
            'effectiveFromIso' => $chartDateIso,
            'effectiveToIso' => $item['effectiveToIso'] ?? null,
            'publishedDateIso' => $item['publishedDateIso'] ?? null,
            'prices' => [
                'PB95' => $item['prices']['PB95'] ?? null,
                'PB98' => $item['prices']['PB98'] ?? null,
                'ON' => $item['prices']['ON'] ?? null,
            ],
        ];
    }, $lastMonthAnnouncements);

    $generatedAt = new DateTimeImmutable();

    $snapshot['announcements'] = $announcements;
    $snapshot['currentAnnouncement'] = $currentAnnouncement;
    $snapshot['previousAnnouncement'] = $previousAnnouncement;
    $snapshot['tomorrowAnnouncement'] = $tomorrowAnnouncement;
    $snapshot['fuelLabels'] = $fuelLabels;
    $snapshot['fuelCards'] = build_fuel_cards($fuelLabels, $currentAnnouncement, $previousAnnouncement, $tomorrowAnnouncement);
    $snapshot['dashboardData'] = [
        'recentAnnouncements' => $chartAnnouncements,
    ];
    $snapshot['currentEffectiveLabel'] = $currentAnnouncement['effectiveLabel'] ?? 'dzisiaj';
    $snapshot['tomorrowEffectiveLabel'] = $tomorrowAnnouncement['effectiveLabel'] ?? null;
    $snapshot['lastDataUpdateLabel'] = $generatedAt->format('d.m.Y H:i');
    $snapshot['lastDataUpdateDateLabel'] = $generatedAt->format('d.m.Y');
    $snapshot['lastDataUpdateTimeLabel'] = $generatedAt->format('H:i');
    $snapshot['generatedAtIso'] = $generatedAt->format(DateTimeInterface::ATOM);

    return $snapshot;
}

function snapshot_with_monitor_polish_fallback(array $snapshot, DateTimeImmutable $targetDate): array
{
    if (dashboard_snapshot_has_prices_for_date($snapshot, $targetDate)) {
        return $snapshot;
    }

    $warnings = [];
    $announcement = monitor_polish_fallback_announcement($targetDate, $warnings);

    if ($announcement === null) {
        return $snapshot;
    }

    $announcements = is_array($snapshot['announcements'] ?? null) ? $snapshot['announcements'] : [];
    $targetIso = $targetDate->format('Y-m-d');
    $sourcePdfUrl = $announcement['sourcePdfUrl'] ?? null;
    $url = $announcement['url'] ?? null;
    $filtered = [];

    foreach ($announcements as $item) {
        if (!is_array($item)) {
            continue;
        }

        $sameUrl = is_string($url) && $url !== '' && ($item['url'] ?? null) === $url;
        $samePdf = is_string($sourcePdfUrl) && $sourcePdfUrl !== '' && ($item['sourcePdfUrl'] ?? null) === $sourcePdfUrl;
        $sameTargetFallback = ($item['source'] ?? null) === 'monitor_polish'
            && ($item['effectiveFromIso'] ?? null) === $targetIso;

        if ($sameUrl || $samePdf || $sameTargetFallback) {
            continue;
        }

        $filtered[] = $item;
    }

    $filtered[] = $announcement;
    $snapshot = rebuild_dashboard_price_fields($snapshot, $filtered);
    $snapshot['monitorPolishFallback'] = [
        'usedAtIso' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'targetDateIso' => $targetIso,
        'url' => $announcement['url'] ?? null,
        'pdfUrl' => $announcement['sourcePdfUrl'] ?? null,
    ];

    return $snapshot;
}

function parse_price_value(string $raw): ?float
{
    $normalized = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $raw) ?? '');
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return round((float) $normalized, 2);
}

function normalize_fuel_name(string $label): ?string
{
    $label = clean_text($label);
    $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

    if (str_contains($label, 'benzyna 95')) {
        return 'PB95';
    }

    if (str_contains($label, 'benzyna 98')) {
        return 'PB98';
    }

    if (str_contains($label, 'olej napędowy') || str_contains($label, 'olej napedowy')) {
        return 'ON';
    }

    if (str_contains($label, 'lpg') || str_contains($label, 'autogaz')) {
        return 'LPG';
    }

    return null;
}

function parse_fuel_prices(string $html, ?string $intro = null): array
{
    $prices = [];

    if (preg_match_all('/<li>\s*(.*?)\s*<\/li>/isu', $html, $items) > 0) {
        foreach ($items[1] as $item) {
            $line = clean_text($item);
            $fuel = normalize_fuel_name($line);

            if ($fuel === null) {
                continue;
            }

            if (preg_match('/([0-9]+[.,][0-9]+)/u', $line, $match) === 1) {
                $value = parse_price_value($match[1]);

                if ($value !== null) {
                    $prices[$fuel] = $value;
                }
            }
        }
    }

    if ($prices !== [] || $intro === null) {
        return $prices;
    }

    if (preg_match_all('/(benzyna\s*95|benzyna\s*98|olej napędowy|olej napedowy|lpg|autogaz)\s*[-–—]\s*([0-9]+[.,][0-9]+)/iu', $intro, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $match) {
            $fuel = normalize_fuel_name($match[1]);
            $value = parse_price_value($match[2]);

            if ($fuel !== null && $value !== null) {
                $prices[$fuel] = $value;
            }
        }
    }

    return $prices;
}

function parse_iso_date(?string $raw): ?DateTimeImmutable
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($raw));
    return $date instanceof DateTimeImmutable ? $date : null;
}

function parse_polish_date(?string $raw): ?DateTimeImmutable
{
    if ($raw === null) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('d.m.Y', trim($raw));
    return $date instanceof DateTimeImmutable ? $date : null;
}

function parse_polish_textual_date(string $raw): ?DateTimeImmutable
{
    $raw = clean_text($raw);

    if ($raw === '') {
        return null;
    }

    $months = [
        'stycznia' => 1,
        'lutego' => 2,
        'marca' => 3,
        'kwietnia' => 4,
        'maja' => 5,
        'czerwca' => 6,
        'lipca' => 7,
        'sierpnia' => 8,
        'września' => 9,
        'wrzesnia' => 9,
        'października' => 10,
        'pazdziernika' => 10,
        'listopada' => 11,
        'grudnia' => 12,
    ];

    if (preg_match('/\b(\d{1,2})\s+([[:alpha:]ąćęłńóśźż]+)\s+(\d{4})\b/iu', $raw, $match) !== 1) {
        return null;
    }

    $day = (int) $match[1];
    $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
    $year = (int) $match[3];
    $month = $months[$monthName] ?? null;

    if ($month === null || !checkdate($month, $day, $year)) {
        return null;
    }

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function extract_effective_range(string $title, ?string $intro, string $html): array
{
    $haystack = clean_text($title . ' ' . ($intro ?? '') . ' ' . $html);

    $ranges = gov_numeric_date_ranges($haystack);
    if ($ranges !== []) {
        $range = $ranges[0];
        $from = $range['from'];
        $to = $range['to'];

        return [
            'from' => $from,
            'to' => $to,
            'label' => $from->format('d.m') . '-' . $to->format('d.m.Y'),
            'parsed' => true,
        ];
    }

    $date = parse_polish_textual_date($haystack);
    if ($date instanceof DateTimeImmutable) {
        return [
            'from' => $date,
            'to' => $date,
            'label' => $date->format('d.m.Y'),
            'parsed' => true,
        ];
    }

    if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $haystack, $match) === 1) {
        $date = parse_polish_date($match[1] . '.' . $match[2] . '.' . $match[3]);

        if ($date instanceof DateTimeImmutable) {
            return [
                'from' => $date,
                'to' => $date,
                'label' => $date->format('d.m.Y'),
                'parsed' => true,
            ];
        }
    }

    return [
        'from' => null,
        'to' => null,
        'label' => null,
        'parsed' => false,
    ];
}

function fetch_fuel_listing_links(int $maxLinks, array &$warnings): array
{
    $links = [];
    $maxPages = 8;

    for ($page = 0; $page < $maxPages; $page++) {
        $listingUrl = 'https://www.gov.pl/web/energia/wiadomosci?page=' . $page . '&size=10';
        $listingHtml = http_get($listingUrl);

        if ($listingHtml === null) {
            if ($page === 0) {
                $warnings[] = 'Nie udało się pobrać listy komunikatów z gov.pl.';
            }

            break;
        }

        preg_match_all('/href="(\/web\/energia\/maksymalna-cena-detaliczna-paliw[^"]+)"/i', $listingHtml, $matches);
        $pageLinks = array_values(array_unique($matches[1] ?? []));

        if ($pageLinks === []) {
            if ($page > 0) {
                break;
            }

            continue;
        }

        foreach ($pageLinks as $relativeUrl) {
            $absolute = 'https://www.gov.pl' . $relativeUrl;

            if (!in_array($absolute, $links, true)) {
                $links[] = $absolute;
            }

            if (count($links) >= $maxLinks) {
                break 2;
            }
        }
    }

    return $links;
}

function fetch_ministry_announcements(int $limit, array &$warnings): array
{
    $links = fetch_fuel_listing_links($limit, $warnings);
    $announcements = [];

    foreach ($links as $url) {
        $html = http_get($url);

        if ($html === null) {
            continue;
        }

        $title = extract_match('/<h1[^>]*>(.*?)<\/h1>/isu', $html)
            ?? extract_match('/<meta property="og:title" content="([^"]+)"/i', $html)
            ?? 'Komunikat ministerstwa';

        $publishedDate = extract_match('/<p class="event-date">([^<]+)<\/p>/i', $html);
        $intro = extract_match('/<p class="intro">([^<]+)<\/p>/isu', $html)
            ?? extract_match('/<meta name="description" content="([^"]+)"/i', $html);

        $prices = parse_fuel_prices($html, $intro);

        if ($prices === []) {
            continue;
        }

        $effective = extract_effective_range($title, $intro, $html);
        $publishedDateObj = parse_polish_date($publishedDate);
        $effectiveDateWasParsed = ($effective['from'] ?? null) instanceof DateTimeImmutable;
        $effectiveFrom = $effectiveDateWasParsed ? $effective['from'] : $publishedDateObj;
        $effectiveTo = ($effective['to'] ?? null) instanceof DateTimeImmutable ? $effective['to'] : $effectiveFrom;
        $effectiveLabel = is_string($effective['label'] ?? null) && $effective['label'] !== ''
            ? $effective['label']
            : ($effectiveFrom instanceof DateTimeImmutable ? $effectiveFrom->format('d.m.Y') : ($publishedDate ?: 'brak'));

        $announcements[] = [
            'title' => $title,
            'publishedDate' => $publishedDate,
            'publishedDateIso' => $publishedDateObj?->format('Y-m-d'),
            'intro' => $intro,
            'url' => $url,
            'prices' => $prices,
            'effectiveFromIso' => $effectiveFrom?->format('Y-m-d'),
            'effectiveToIso' => $effectiveTo?->format('Y-m-d'),
            'effectiveLabel' => $effectiveLabel,
            'effectiveDateWasParsed' => $effectiveDateWasParsed,
        ];
    }

    usort($announcements, static function (array $left, array $right): int {
        $leftSort = $left['effectiveFromIso'] ?? $left['publishedDateIso'] ?? '';
        $rightSort = $right['effectiveFromIso'] ?? $right['publishedDateIso'] ?? '';
        return strcmp($rightSort, $leftSort);
    });

    if ($announcements === []) {
        $warnings[] = 'Komunikaty zostały znalezione, ale nie udało się odczytać z nich cen.';
    }

    return $announcements;
}

function announcement_is_active_on_date(array $announcement, DateTimeImmutable $date): bool
{
    $from = parse_iso_date($announcement['effectiveFromIso'] ?? null);
    $to = parse_iso_date($announcement['effectiveToIso'] ?? null);

    if (!$from instanceof DateTimeImmutable) {
        return false;
    }

    $to ??= $from;
    $stamp = $date->format('Y-m-d');

    return $stamp >= $from->format('Y-m-d') && $stamp <= $to->format('Y-m-d');
}

function find_active_announcement_strict(array $announcements, DateTimeImmutable $date): ?array
{
    foreach ($announcements as $announcement) {
        if (announcement_is_active_on_date($announcement, $date)) {
            return $announcement;
        }
    }

    return null;
}

function find_active_announcement(array $announcements, DateTimeImmutable $date): ?array
{
    $strict = find_active_announcement_strict($announcements, $date);

    if ($strict !== null) {
        return $strict;
    }

    $target = $date->format('Y-m-d');

    foreach ($announcements as $announcement) {
        $fromIso = $announcement['effectiveFromIso'] ?? '';

        if ($fromIso !== '' && $fromIso <= $target) {
            return $announcement;
        }
    }

    return $announcements[0] ?? null;
}

function find_previous_announcement(array $announcements, ?array $current): ?array
{
    if ($current === null) {
        return $announcements[1] ?? null;
    }

    $currentFrom = $current['effectiveFromIso'] ?? '';

    if ($currentFrom === '') {
        return null;
    }

    foreach ($announcements as $announcement) {
        $fromIso = $announcement['effectiveFromIso'] ?? '';

        if ($fromIso !== '' && $fromIso < $currentFrom) {
            return $announcement;
        }
    }

    return null;
}

function announcement_chart_date_iso(array $announcement): ?string
{
    $hasTrustedEffectiveDate = ($announcement['effectiveDateWasParsed'] ?? false) === true
        || ($announcement['source'] ?? null) === 'monitor_polish';

    if (!$hasTrustedEffectiveDate) {
        return null;
    }

    $date = $announcement['effectiveFromIso'] ?? null;

    if (!is_string($date) || trim($date) === '') {
        return null;
    }

    return $date;
}

function filter_announcements_last_month(array $announcements, DateTimeImmutable $now): array
{
    $today = $now->format('Y-m-d');
    $threshold = $now->modify('-1 month')->format('Y-m-d');

    $filtered = array_values(array_filter($announcements, static function (array $announcement) use ($threshold, $today): bool {
        $from = announcement_chart_date_iso($announcement);

        if ($from === null) {
            return false;
        }

        return $from >= $threshold && $from <= $today;
    }));

    usort($filtered, static function (array $left, array $right): int {
        $leftDate = announcement_chart_date_iso($left) ?? '';
        $rightDate = announcement_chart_date_iso($right) ?? '';
        $dateCompare = strcmp($leftDate, $rightDate);

        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp($left['publishedDateIso'] ?? '', $right['publishedDateIso'] ?? '');
    });

    return $filtered;
}

function deduplicate_announcements_by_effective_date(array $announcements): array
{
    $byDate = [];

    foreach ($announcements as $announcement) {
        $date = announcement_chart_date_iso($announcement);

        if ($date === null) {
            continue;
        }

        if (!isset($byDate[$date])) {
            $byDate[$date] = $announcement;
            continue;
        }

        $existingPublished = $byDate[$date]['publishedDateIso'] ?? '';
        $newPublished = $announcement['publishedDateIso'] ?? '';

        if ($newPublished >= $existingPublished) {
            $byDate[$date] = $announcement;
        }
    }

    ksort($byDate);

    return array_values($byDate);
}

function format_price(?float $value): string
{
    if ($value === null) {
        return 'brak danych';
    }

    return number_format($value, 2, ',', ' ') . ' zł/l';
}

function format_delta(?float $value): string
{
    if ($value === null) {
        return 'brak';
    }

    $prefix = $value > 0 ? '+' : '';
    return $prefix . number_format($value, 2, ',', ' ') . ' zł/l';
}

function delta_direction(?float $value): string
{
    if ($value === null || abs($value) < 0.0001) {
        return 'neutral';
    }

    return $value > 0 ? 'up' : 'down';
}

function delta_class(?float $value): string
{
    return match (delta_direction($value)) {
        'up' => 'delta-up',
        'down' => 'delta-down',
        default => 'delta-neutral',
    };
}

function delta_arrow(?float $value): string
{
    return match (delta_direction($value)) {
        'up' => '↑',
        'down' => '↓',
        default => '→',
    };
}

function fuel_recent_prices(array $dashboardData, string $code): array
{
    if ($code === '') {
        return [];
    }

    $recent = $dashboardData['recentAnnouncements'] ?? [];

    if (!is_array($recent)) {
        return [];
    }

    $values = [];

    foreach ($recent as $item) {
        if (!is_array($item)) {
            continue;
        }

        $price = $item['prices'][$code] ?? null;

        if (is_numeric($price)) {
            $values[] = (float) $price;
        }
    }

    return array_slice($values, -24);
}

function fuel_sparkline_svg(array $values, int $w = 150, int $h = 38): string
{
    $n = count($values);

    if ($n < 2) {
        return '';
    }

    $pad = 3.0;
    $min = min($values);
    $max = max($values);
    $span = $max - $min;
    $stepX = ($w - 2 * $pad) / ($n - 1);

    $points = [];

    foreach (array_values($values) as $i => $value) {
        $x = $pad + $i * $stepX;
        $y = $span > 0.0 ? $pad + (1 - ($value - $min) / $span) * ($h - 2 * $pad) : $h / 2;
        $points[] = round($x, 1) . ',' . round($y, 1);
    }

    $line = implode(' ', $points);
    [$lastX, $lastY] = explode(',', $points[$n - 1]);
    $gradientId = 'spark-' . substr(md5($line), 0, 8);
    $area = $line
        . ' ' . round($pad + ($n - 1) * $stepX, 1) . ',' . ($h - $pad)
        . ' ' . $pad . ',' . ($h - $pad);

    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" focusable="false" aria-hidden="true">'
        . '<defs><linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="currentColor" stop-opacity="0.22"></stop>'
        . '<stop offset="1" stop-color="currentColor" stop-opacity="0"></stop>'
        . '</linearGradient></defs>'
        . '<polygon points="' . $area . '" fill="url(#' . $gradientId . ')" stroke="none"></polygon>'
        . '<polyline points="' . $line . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></polyline>'
        . '<circle cx="' . $lastX . '" cy="' . $lastY . '" r="2.4" fill="currentColor"></circle>'
        . '</svg>';
}

function sparkline_trend_class(array $values): string
{
    if (count($values) < 2) {
        return 'metric-sparkline-neutral';
    }

    $first = $values[array_key_first($values)];
    $last = $values[array_key_last($values)];

    return 'metric-sparkline-' . delta_direction($last - $first);
}

function build_fuel_cards(array $fuelLabels, ?array $currentAnnouncement, ?array $previousAnnouncement, ?array $tomorrowAnnouncement): array
{
    $cards = [];

    foreach ($fuelLabels as $code => $label) {
        $todayPrice = $currentAnnouncement['prices'][$code] ?? null;
        $previousPrice = $previousAnnouncement['prices'][$code] ?? null;
        $tomorrowPrice = $tomorrowAnnouncement['prices'][$code] ?? null;

        $todayDelta = ($todayPrice !== null && $previousPrice !== null) ? round($todayPrice - $previousPrice, 2) : null;
        $tomorrowDelta = ($todayPrice !== null && $tomorrowPrice !== null) ? round($tomorrowPrice - $todayPrice, 2) : null;

        $cards[] = [
            'code' => $code,
            'label' => $label,
            'todayPrice' => $todayPrice,
            'todayDelta' => $todayDelta,
            'tomorrowPrice' => $tomorrowPrice,
            'tomorrowDelta' => $tomorrowDelta,
        ];
    }

    return $cards;
}

function build_dashboard_payload(array $previousSnapshot = []): array
{
    $warnings = [];
    $today = new DateTimeImmutable('today');
    $now = new DateTimeImmutable('now');
    $tomorrow = $today->modify('+1 day');

    $previousPromotionItems = is_array($previousSnapshot['stationPromotions']['items'] ?? null)
        ? $previousSnapshot['stationPromotions']['items']
        : [];

    $announcements = fetch_ministry_announcements(80, $warnings);
    $stationPromotions = fetch_station_promotions($previousPromotionItems);
    $announcements = append_monitor_polish_fallback_announcement($announcements, $today);
    $announcements = append_monitor_polish_fallback_announcement($announcements, $tomorrow);

    $currentAnnouncement = find_active_announcement($announcements, $today);
    $previousAnnouncement = find_previous_announcement($announcements, $currentAnnouncement);

    $tomorrowAnnouncement = find_active_announcement_strict($announcements, $tomorrow);

    $lastMonthAnnouncements = filter_announcements_last_month($announcements, $now);
    $lastMonthAnnouncements = deduplicate_announcements_by_effective_date($lastMonthAnnouncements);

    if ($currentAnnouncement === null && $announcements !== []) {
        $currentAnnouncement = $announcements[0];
    }

    $fuelLabels = [
        'PB95' => 'PB95',
        'PB98' => 'PB98',
        'ON' => 'ON',
    ];

    $fuelCards = build_fuel_cards($fuelLabels, $currentAnnouncement, $previousAnnouncement, $tomorrowAnnouncement);

    $chartAnnouncements = array_map(static function (array $item): array {
        $chartDateIso = announcement_chart_date_iso($item);

        return [
            'label' => $item['effectiveLabel'] ?? ($item['publishedDate'] ?? 'brak daty'),
            'title' => $item['title'],
            'effectiveFromIso' => $chartDateIso,
            'effectiveToIso' => $item['effectiveToIso'] ?? null,
            'publishedDateIso' => $item['publishedDateIso'] ?? null,
            'prices' => [
                'PB95' => $item['prices']['PB95'] ?? null,
                'PB98' => $item['prices']['PB98'] ?? null,
                'ON' => $item['prices']['ON'] ?? null,
            ],
        ];
    }, $lastMonthAnnouncements);

    $generatedAt = new DateTimeImmutable();

    return [
        'warnings' => array_values(array_unique($warnings)),
        'announcements' => $announcements,
        'currentAnnouncement' => $currentAnnouncement,
        'previousAnnouncement' => $previousAnnouncement,
        'tomorrowAnnouncement' => $tomorrowAnnouncement,
        'stationPromotions' => $stationPromotions,
        'fuelLabels' => $fuelLabels,
        'fuelCards' => $fuelCards,
        'dashboardData' => [
            'recentAnnouncements' => $chartAnnouncements,
        ],
        'currentEffectiveLabel' => $currentAnnouncement['effectiveLabel'] ?? 'dzisiaj',
        'tomorrowEffectiveLabel' => $tomorrowAnnouncement['effectiveLabel'] ?? null,
        'lastDataUpdateLabel' => $generatedAt->format('d.m.Y H:i'),
        'lastDataUpdateDateLabel' => $generatedAt->format('d.m.Y'),
        'lastDataUpdateTimeLabel' => $generatedAt->format('H:i'),
        'generatedAtIso' => $generatedAt->format(DateTimeInterface::ATOM),
    ];
}

function empty_dashboard_snapshot(): array
{
    return [
        'warnings' => [
            'Brak zapisanego snapshotu. Dane zostaną pobrane dopiero po uruchomieniu crona z flagą --refresh-cache albo po kliknięciu przycisku odświeżania.',
        ],
        'announcements' => [],
        'currentAnnouncement' => null,
        'previousAnnouncement' => null,
        'tomorrowAnnouncement' => null,
        'stationPromotions' => [
            'url' => station_promotions_source_url(),
            'items' => [],
            'warnings' => [],
            'warning' => 'Brak zapisanego snapshotu promocji ze stacji.',
            'fetchedAtLabel' => 'brak danych',
            'sourceMode' => 'empty_snapshot',
        ],
        'fuelLabels' => [
            'PB95' => 'PB95',
            'PB98' => 'PB98',
            'ON' => 'ON',
        ],
        'fuelCards' => [],
        'dashboardData' => [
            'recentAnnouncements' => [],
        ],
        'currentEffectiveLabel' => 'brak danych',
        'tomorrowEffectiveLabel' => 'brak danych',
        'lastDataUpdateLabel' => 'brak danych',
        'lastDataUpdateDateLabel' => 'brak danych',
        'lastDataUpdateTimeLabel' => null,
        'generatedAtIso' => null,
    ];
}

function refresh_dashboard_snapshot_for_target(?DateTimeImmutable $refreshTargetDate): array
{
    $previousSnapshot = load_dashboard_snapshot();
    $freshSnapshot = build_dashboard_payload(is_array($previousSnapshot) ? $previousSnapshot : []);
    $refreshTargetArticle = null;
    $freshSnapshotHasTargetDate = true;

    if ($refreshTargetDate instanceof DateTimeImmutable) {
        $freshSnapshotHasTargetDate = dashboard_snapshot_has_prices_for_date($freshSnapshot, $refreshTargetDate);

        if (!$freshSnapshotHasTargetDate) {
            $refreshTargetArticle = find_expected_fuel_update_article($refreshTargetDate);

            if ($refreshTargetArticle === null) {
                $freshSnapshotHasTargetDate = true;
            }
        }
    }

    if ($refreshTargetDate instanceof DateTimeImmutable && $refreshTargetArticle !== null && !$freshSnapshotHasTargetDate) {
        $fallbackSnapshot = snapshot_with_monitor_polish_fallback($freshSnapshot, $refreshTargetDate);

        if (dashboard_snapshot_has_prices_for_date($fallbackSnapshot, $refreshTargetDate)) {
            $freshSnapshot = $fallbackSnapshot;
            $freshSnapshotHasTargetDate = true;
        } else {
            $freshSnapshot = $fallbackSnapshot;
        }
    }

    if (!empty($freshSnapshot['announcements']) && $freshSnapshotHasTargetDate) {
        $saveOk = save_dashboard_snapshot($freshSnapshot);

        if ($saveOk) {
            $snapshot = $freshSnapshot;
            $refreshStatus = 'fresh_saved';
        } else {
            $existingSnapshot = load_dashboard_snapshot();
            $snapshot = is_array($existingSnapshot) ? $existingSnapshot : $freshSnapshot;
            $refreshStatus = 'save_failed';
        }
    } elseif (!empty($freshSnapshot['announcements'])) {
        $existingSnapshot = load_dashboard_snapshot();

        if (is_array($existingSnapshot)) {
            $snapshot = $existingSnapshot;
            $refreshStatus = 'target_missing_existing_kept';
        } else {
            $saveOk = save_dashboard_snapshot($freshSnapshot);
            $snapshot = $freshSnapshot;
            $refreshStatus = $saveOk ? 'target_missing_saved' : 'target_missing_save_failed';
        }
    } else {
        $existingSnapshot = load_dashboard_snapshot();

        if (is_array($existingSnapshot)) {
            $snapshot = $existingSnapshot;
            $refreshStatus = 'failed_existing_kept';
        } else {
            $saveOk = save_dashboard_snapshot($freshSnapshot);
            $snapshot = $freshSnapshot;
            $refreshStatus = $saveOk ? 'failed_empty_saved' : 'failed_empty_save_failed';
        }
    }

    return [
        'snapshot' => $snapshot,
        'status' => $refreshStatus,
        'hasTargetDate' => $freshSnapshotHasTargetDate,
    ];
}



function handle_auto_refresh_probe_request(): void
{
    $targetDate = new DateTimeImmutable('tomorrow');
    $targetIso = $targetDate->format('Y-m-d');
    $snapshot = load_dashboard_snapshot();

    if (is_array($snapshot) && dashboard_snapshot_has_prices_for_date($snapshot, $targetDate)) {
        send_json_response([
            'ok' => true,
            'status' => 'up_to_date',
            'updateAvailable' => false,
            'loading' => false,
            'reload' => true,
            'targetDateIso' => $targetIso,
        ]);
    }

    $loading = auto_refresh_loading_status($targetDate);

    if (!empty($loading['active']) && (string) ($loading['reason'] ?? '') === 'refresh_state') {
        send_json_response([
            'ok' => true,
            'status' => 'refresh_busy',
            'updateAvailable' => true,
            'loading' => true,
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($loading['remainingSeconds'] ?? 8)),
            'targetDateIso' => $targetIso,
        ]);
    }

    $probeBusy = auto_refresh_probe_busy_status($targetDate);

    if (!empty($probeBusy['active'])) {
        send_json_response([
            'ok' => true,
            'status' => 'probe_busy',
            'updateAvailable' => false,
            'loading' => false,
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($probeBusy['remainingSeconds'] ?? 6)),
            'targetDateIso' => $targetIso,
        ]);
    }

    $article = find_expected_fuel_update_article_cached($targetDate);

    if ($article === null) {
        send_json_response([
            'ok' => true,
            'status' => 'no_update',
            'updateAvailable' => false,
            'loading' => false,
            'reload' => false,
            'targetDateIso' => $targetIso,
        ]);
    }

    $loading = auto_refresh_loading_status($targetDate);

    if (!empty($loading['active'])) {
        send_json_response([
            'ok' => true,
            'status' => 'refresh_busy',
            'updateAvailable' => true,
            'loading' => true,
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($loading['remainingSeconds'] ?? 8)),
            'targetDateIso' => $targetIso,
            'articleTitle' => (string) ($article['title'] ?? ''),
        ]);
    }

    $throttle = auto_refresh_throttle_status($targetDate);

    if (!empty($throttle['active'])) {
        send_json_response([
            'ok' => true,
            'status' => 'auto_throttled',
            'updateAvailable' => true,
            'loading' => false,
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($throttle['remainingSeconds'] ?? 30)),
            'targetDateIso' => $targetIso,
            'articleTitle' => (string) ($article['title'] ?? ''),
        ]);
    }

    send_json_response([
        'ok' => true,
        'status' => 'update_available',
        'updateAvailable' => true,
        'loading' => false,
        'reload' => false,
        'retryAfterSeconds' => auto_refresh_expected_duration_seconds(),
        'targetDateIso' => $targetIso,
        'articleTitle' => (string) ($article['title'] ?? ''),
    ]);
}



function handle_auto_refresh_request(): void
{
    $targetDate = new DateTimeImmutable('tomorrow');
    $targetIso = $targetDate->format('Y-m-d');

    if (PHP_SAPI !== 'cli' && function_exists('ignore_user_abort')) {
        @ignore_user_abort(true);
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $snapshot = load_dashboard_snapshot();

    if (is_array($snapshot) && dashboard_snapshot_has_prices_for_date($snapshot, $targetDate)) {
        send_json_response([
            'ok' => true,
            'status' => 'up_to_date',
            'reload' => true,
            'targetDateIso' => $targetIso,
        ]);
    }

    $article = find_expected_fuel_update_article_cached($targetDate);

    if ($article === null) {
        send_json_response([
            'ok' => true,
            'status' => 'no_update',
            'reload' => false,
            'targetDateIso' => $targetIso,
        ]);
    }

    $loading = auto_refresh_loading_status($targetDate);

    if (!empty($loading['active'])) {
        send_json_response([
            'ok' => true,
            'status' => 'refresh_busy',
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($loading['remainingSeconds'] ?? 8)),
            'targetDateIso' => $targetIso,
        ]);
    }

    $refreshLock = acquire_dashboard_refresh_lock(false);

    if (!is_resource($refreshLock)) {
        $loading = auto_refresh_loading_status($targetDate);

        send_json_response([
            'ok' => true,
            'status' => 'refresh_busy',
            'reload' => false,
            'retryAfterSeconds' => max(1, (int) ($loading['remainingSeconds'] ?? 8)),
            'targetDateIso' => $targetIso,
        ]);
    }

    $payload = null;
    $loadingMarked = false;

    try {
        $snapshot = load_dashboard_snapshot();

        if (is_array($snapshot) && dashboard_snapshot_has_prices_for_date($snapshot, $targetDate)) {
            $payload = [
                'ok' => true,
                'status' => 'up_to_date',
                'reload' => true,
                'targetDateIso' => $targetIso,
            ];
        } else {
            $throttle = auto_refresh_throttle_claim($targetDate);

            if (empty($throttle['allowed'])) {
                $payload = [
                    'ok' => true,
                    'status' => 'auto_throttled',
                    'reload' => false,
                    'retryAfterSeconds' => max(1, (int) ($throttle['remainingSeconds'] ?? 30)),
                    'targetDateIso' => $targetIso,
                ];
            } else {
                auto_refresh_mark_loading($targetDate);
                $loadingMarked = true;
                $result = refresh_dashboard_snapshot_for_target($targetDate);
                $resultSnapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];
                $hasTargetDate = dashboard_snapshot_has_prices_for_date($resultSnapshot, $targetDate);

                $payload = [
                    'ok' => $hasTargetDate,
                    'status' => $hasTargetDate ? (string) ($result['status'] ?? 'fresh_saved') : 'no_update',
                    'reload' => $hasTargetDate,
                    'targetDateIso' => $targetIso,
                ];

                if (!$hasTargetDate) {
                    $payload['retryAfterSeconds'] = 30;
                }
            }
        }
    } finally {
        if ($loadingMarked) {
            auto_refresh_clear_loading($targetDate);
        }

        release_dashboard_refresh_lock($refreshLock);
    }

    send_json_response($payload ?? [
        'ok' => false,
        'status' => 'unknown',
        'reload' => false,
        'targetDateIso' => $targetIso,
    ]);
}



$isAutoRefreshProbeRequest = PHP_SAPI !== 'cli' && isset($_GET['probe_update']) && $_GET['probe_update'] === '1';
$isAutoRefreshRequest = PHP_SAPI !== 'cli' && isset($_GET['auto_refresh']) && $_GET['auto_refresh'] === '1';

if ($isAutoRefreshProbeRequest) {
    handle_auto_refresh_probe_request();
}

if ($isAutoRefreshRequest) {
    handle_auto_refresh_request();
}

$isCronRefresh = cli_has_flag('--refresh-cache');
$isUrlRefresh = PHP_SAPI !== 'cli' && isset($_GET['refresh']) && $_GET['refresh'] === '1';
$isRefresh = $isCronRefresh || $isUrlRefresh;
$refreshTargetDate = $isRefresh
    ? new DateTimeImmutable('tomorrow')
    : null;

if ($isUrlRefresh) {
    $manualRefreshCooldown = manual_refresh_cooldown_status();

    if (!empty($manualRefreshCooldown['active'])) {
        redirect_after_manual_refresh('refresh_cooldown');
    }
}

if ($isRefresh) {
    $refreshLock = acquire_dashboard_refresh_lock($isCronRefresh);

    if (!is_resource($refreshLock)) {
        $existingSnapshot = load_dashboard_snapshot();
        $snapshot = is_array($existingSnapshot) ? $existingSnapshot : empty_dashboard_snapshot();
        $refreshStatus = 'refresh_busy_existing_kept';
    } else {
        if ($isUrlRefresh) {
            $manualRefreshCooldown = manual_refresh_cooldown_claim();

            if (empty($manualRefreshCooldown['allowed'])) {
                release_dashboard_refresh_lock($refreshLock);
                redirect_after_manual_refresh('refresh_cooldown');
            }
        }

        try {
            $refreshResult = refresh_dashboard_snapshot_for_target($refreshTargetDate);
            $snapshot = is_array($refreshResult['snapshot'] ?? null)
                ? $refreshResult['snapshot']
                : empty_dashboard_snapshot();
            $refreshStatus = (string) ($refreshResult['status'] ?? 'unknown');
        } finally {
            release_dashboard_refresh_lock($refreshLock);
        }
    }

    if ($isUrlRefresh) {
        redirect_after_manual_refresh((string) ($refreshStatus ?? 'unknown'));
    }
} else {
    $snapshot = load_dashboard_snapshot();

    if (!is_array($snapshot)) {
        $snapshot = empty_dashboard_snapshot();
    }
}

$govUpdateButtonState = gov_fuel_update_button_state($snapshot);
$govUpdateAvailable = !empty($govUpdateButtonState['available']);
$govUpdateTargetDateLabel = (string) ($govUpdateButtonState['targetDateLabel'] ?? '');
$govUpdateArticle = is_array($govUpdateButtonState['article'] ?? null) ? $govUpdateButtonState['article'] : null;
$manualRefreshUrl = './?refresh=1';
$autoRefreshMissingTomorrowPrices = !dashboard_snapshot_has_prices_for_date($snapshot, new DateTimeImmutable('tomorrow'));
$autoRefreshShouldCheck = $autoRefreshMissingTomorrowPrices;

$autoRefreshConfig = [
    'enabled' => $autoRefreshShouldCheck,
    'probeUrl' => './?probe_update=1&target=tomorrow',
    'url' => './?auto_refresh=1&target=tomorrow',
    'targetDateLabel' => (new DateTimeImmutable('tomorrow'))->format('d.m.Y'),
    'articleTitle' => '',
    'loading' => false,
];

$warnings = array_values(array_unique($snapshot['warnings'] ?? []));
$announcements = $snapshot['announcements'] ?? [];
$currentAnnouncement = $snapshot['currentAnnouncement'] ?? null;
$previousAnnouncement = $snapshot['previousAnnouncement'] ?? null;
$tomorrowAnnouncement = $snapshot['tomorrowAnnouncement'] ?? null;
$stationPromotions = $snapshot['stationPromotions'] ?? [
    'url' => station_promotions_source_url(),
    'items' => [],
    'warnings' => [],
    'warning' => 'Brak danych o promocjach.',
    'fetchedAtLabel' => 'brak danych',
    'sourceMode' => 'missing_snapshot_field',
];

if (isset($stationPromotions['items']) && is_array($stationPromotions['items'])) {
    foreach ($stationPromotions['items'] as &$stationPromotionItem) {
        if (!is_array($stationPromotionItem)) {
            continue;
        }

        if (!array_key_exists('discountIsUpTo', $stationPromotionItem)) {
            $stationPromotionItem['discountIsUpTo'] = station_promotion_discount_is_up_to($stationPromotionItem);
        }

        if (!array_key_exists('discountConditionPenalty', $stationPromotionItem)) {
            $stationPromotionItem['discountConditionPenalty'] = station_promotion_discount_condition_penalty($stationPromotionItem);
        }
    }
    unset($stationPromotionItem);

    station_promotions_sort($stationPromotions['items']);
    mark_top_station_promotions($stationPromotions['items']);
}

$fuelLabels = $snapshot['fuelLabels'] ?? [];
$fuelCards = $snapshot['fuelCards'] ?? [];
$dashboardData = $snapshot['dashboardData'] ?? ['recentAnnouncements' => []];
$currentEffectiveLabel = $snapshot['currentEffectiveLabel'] ?? 'brak danych';
$tomorrowEffectiveLabel = $snapshot['tomorrowEffectiveLabel'] ?? 'brak danych';
$lastDataUpdateLabel = $snapshot['lastDataUpdateLabel'] ?? 'brak danych';

$lastDataUpdateVisibleLabel = $snapshot['lastDataUpdateDateLabel'] ?? null;
$lastDataUpdateTimeLabel = $snapshot['lastDataUpdateTimeLabel'] ?? null;

if (!is_string($lastDataUpdateVisibleLabel) || trim($lastDataUpdateVisibleLabel) === '') {
    $lastDataUpdateVisibleLabel = $lastDataUpdateLabel;

    if (preg_match('/^(\d{1,2}\.\d{1,2}\.\d{4})(?:\s+(\d{1,2}:\d{2}))?$/', $lastDataUpdateLabel, $updateMatch) === 1) {
        $lastDataUpdateVisibleLabel = $updateMatch[1];
        $lastDataUpdateTimeLabel = $updateMatch[2] ?? $lastDataUpdateTimeLabel;
    }
}

$lastDataUpdateTooltipLabel = is_string($lastDataUpdateTimeLabel) && trim($lastDataUpdateTimeLabel) !== ''
    ? 'Godzina aktualizacji: ' . trim($lastDataUpdateTimeLabel)
    : $lastDataUpdateLabel;

$manualRefreshMessage = null;
$manualRefreshClass = 'alert-info';

if (isset($_GET['refreshed']) && $_GET['refreshed'] === '1') {
    $status = isset($_GET['status']) ? (string) $_GET['status'] : '';

    if ($status === 'fresh_saved') {
        $manualRefreshMessage = 'Dane zostały ręcznie odświeżone. Aktualny czas zapisu: ' . $lastDataUpdateLabel . '.';
        $manualRefreshClass = 'alert-success';
    } elseif ($status === 'refresh_cooldown') {
        $cooldownStatus = manual_refresh_cooldown_status();
        $startedAt = (int) ($cooldownStatus['startedAt'] ?? 0);
        $remainingSeconds = (int) ($cooldownStatus['remainingSeconds'] ?? 0);
        $startedLabel = $startedAt > 0
            ? (new DateTimeImmutable('@' . $startedAt))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('H:i')
            : 'przed chwilą';

        $manualRefreshMessage = !empty($cooldownStatus['active'])
            ? 'Odświeżanie zostało wywołane o ' . $startedLabel . ', ponowne użycie za ' . format_cooldown_minutes($remainingSeconds) . '.'
            : 'Odświeżanie było wywołane niedawno. Spróbuj ponownie za chwilę.';
        $manualRefreshClass = 'alert-danger';
    } elseif ($status === 'save_failed') {
        $manualRefreshMessage = 'Dane zostały pobrane, ale nie udało się zapisać snapshotu. Sprawdź uprawnienia katalogu .paliwa-cache.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'target_missing_existing_kept') {
        $manualRefreshMessage = 'Wykryto artykuł z nową aktualizacją, ale odświeżony snapshot nie zawiera jeszcze cen dla jutra. Zostawiono poprzedni zapis.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'target_missing_saved') {
        $manualRefreshMessage = 'Dane zostały pobrane, ale nie udało się potwierdzić cen dla jutra w snapshotcie.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'target_missing_save_failed') {
        $manualRefreshMessage = 'Dane zostały pobrane, ale nie udało się potwierdzić cen dla jutra ani zapisać snapshotu. Sprawdź uprawnienia katalogu .paliwa-cache.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_existing_kept') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych danych. Zostawiono poprzedni zapisany snapshot.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_empty_saved') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych danych, ale zapisano pusty snapshot diagnostyczny.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_empty_save_failed') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych danych ani zapisać snapshotu. Sprawdź uprawnienia katalogu .paliwa-cache.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'refresh_busy_existing_kept') {
        $manualRefreshMessage = 'Odświeżanie już trwa. Ponowne odświeżenie będzie możliwe po zakończeniu bieżącej próby.';
        $manualRefreshClass = 'alert-info';
    } else {
        $manualRefreshMessage = 'Próba ręcznego odświeżenia została zakończona.';
        $manualRefreshClass = 'alert-info';
    }
}

if ($isCronRefresh) {
    echo 'Cache refreshed at ' . $lastDataUpdateLabel . PHP_EOL;
    echo 'Announcements: ' . count($announcements) . PHP_EOL;
    echo 'Chart points: ' . count($dashboardData['recentAnnouncements'] ?? []) . PHP_EOL;
    echo 'Station promotions: ' . count($stationPromotions['items'] ?? []) . PHP_EOL;
    echo 'Station promotions source mode: ' . (string) ($stationPromotions['sourceMode'] ?? 'unknown') . PHP_EOL;
    echo 'Gov update available: ' . ($govUpdateAvailable ? 'yes' : 'no') . PHP_EOL;
    echo 'Gov update target: ' . $govUpdateTargetDateLabel . PHP_EOL;
    echo 'Status: ' . ($refreshStatus ?? 'unknown') . PHP_EOL;
    echo 'Snapshot: ' . dashboard_snapshot_path() . PHP_EOL;
    exit(0);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitor cen paliw w Polsce</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2064%2064'%3E%3Cdefs%3E%3CradialGradient%20id='sphere'%20cx='34%25'%20cy='24%25'%20r='72%25'%3E%3Cstop%20offset='0'%20stop-color='%23dffaf2'/%3E%3Cstop%20offset='0.28'%20stop-color='%236bd6bc'/%3E%3Cstop%20offset='0.6'%20stop-color='%231f8a70'/%3E%3Cstop%20offset='1'%20stop-color='%2312343b'/%3E%3C/radialGradient%3E%3ClinearGradient%20id='gloss'%20x1='18'%20y1='10'%20x2='42'%20y2='42'%20gradientUnits='userSpaceOnUse'%3E%3Cstop%20offset='0'%20stop-color='%23ffffff'%20stop-opacity='0.82'/%3E%3Cstop%20offset='1'%20stop-color='%23ffffff'%20stop-opacity='0'/%3E%3C/linearGradient%3E%3Cfilter%20id='shadow'%20x='-20%25'%20y='-20%25'%20width='140%25'%20height='140%25'%3E%3CfeDropShadow%20dx='0'%20dy='4'%20stdDeviation='4'%20flood-color='%23061316'%20flood-opacity='0.35'/%3E%3C/filter%3E%3C/defs%3E%3Ccircle%20cx='32'%20cy='32'%20r='27'%20fill='url(%23sphere)'%20filter='url(%23shadow)'/%3E%3Cpath%20d='M16%2035C21%2040%2031%2043%2042%2040C48%2038%2052%2034%2054%2030C53%2044%2043%2057%2030%2058C20%2058%2011%2052%208%2043C10%2040%2013%2037%2016%2035Z'%20fill='%230b1f23'%20opacity='0.22'/%3E%3Cellipse%20cx='24'%20cy='20'%20rx='10'%20ry='7'%20fill='url(%23gloss)'%20transform='rotate(-24%2024%2020)'/%3E%3Cpath%20d='M45%2012C51%2018%2055%2025%2055%2033'%20fill='none'%20stroke='%236bd6bc'%20stroke-width='3'%20stroke-linecap='round'%20opacity='0.7'/%3E%3C/svg%3E">

    <script>
        (() => {
            try {
                const storedTheme = localStorage.getItem('fuelDashboardTheme');
                document.documentElement.dataset.theme = storedTheme === 'dark' ? 'dark' : 'light';
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>

    <style>
        :root {
            color-scheme: light;
            --ink: #12343b;
            --muted: #5b6b74;
            --page-bg-a: rgba(31, 138, 112, 0.10);
            --page-bg-b: rgba(244, 185, 66, 0.10);
            --page-gradient: linear-gradient(180deg, #f8f5f0 0%, #f2f7f5 55%, #edf2f7 100%);
            --surface: rgba(255, 255, 255, 0.96);
            --surface-soft: rgba(255, 255, 255, 0.94);
            --line: rgba(18, 52, 59, 0.12);
            --shadow: rgba(18, 52, 59, 0.06);
            --amber: #f4b942;
            --red: #c62828;
            --green: #198754;
            --sky: #4f86f7;
            --mint: #1f8a70;
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --ink: #eaf3f1;
            --muted: #9fb2b7;
            --page-bg-a: rgba(31, 138, 112, 0.22);
            --page-bg-b: rgba(244, 185, 66, 0.11);
            --page-gradient: linear-gradient(180deg, #061316 0%, #0b1f23 52%, #101827 100%);
            --surface: rgba(14, 30, 34, 0.94);
            --surface-soft: rgba(18, 39, 43, 0.92);
            --line: rgba(234, 243, 241, 0.12);
            --shadow: rgba(0, 0, 0, 0.28);
        }

        * { box-sizing: border-box; }
        html { font-size: 16px; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: "Aptos", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            line-height: 1.5;
            background:
                radial-gradient(circle at top left, var(--page-bg-a), transparent 24%),
                radial-gradient(circle at right 10% top 20%, var(--page-bg-b), transparent 18%),
                var(--page-gradient);
            transition: background 0.25s ease, color 0.25s ease;
        }

        a, button { font: inherit; }
        canvas { display: block; max-width: 100%; }

        .container-xxl { width: min(100%, 1440px); margin-inline: auto; }
        .page-main { display: flex; flex-direction: column; min-height: 100vh; }
        .row { --gutter-x: 0; --gutter-y: 0; display: flex; flex-wrap: wrap; margin: calc(var(--gutter-y) * -0.5) calc(var(--gutter-x) * -0.5); }
        .row > * { width: 100%; padding: calc(var(--gutter-y) * 0.5) calc(var(--gutter-x) * 0.5); }

        .g-3 { --gutter-x: 1rem; --gutter-y: 1rem; }
        .g-4 { --gutter-x: 1.5rem; --gutter-y: 1.5rem; }
        .d-flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .justify-content-between { justify-content: space-between; }
        .align-items-start { align-items: flex-start; }
        .gap-3 { gap: 1rem; }
        .position-relative { position: relative; }
        .h-100 { height: 100%; }
        .p-4 { padding: 1.5rem; }
        .px-3 { padding-inline: 1rem; }
        .py-4 { padding-block: 1.5rem; }
        .mb-0 { margin-bottom: 0; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        .small { font-size: 0.875rem; }
        .text-uppercase { text-transform: uppercase; }
        .text-secondary { color: var(--muted); }
        .fw-bold { font-weight: 700; }
        .fw-semibold { font-weight: 600; }
        .font-display { font-family: "Bahnschrift", "Aptos Display", "Segoe UI", sans-serif; letter-spacing: -0.04em; }
        .display-5 { font-size: clamp(2.1rem, 4vw, 3.6rem); line-height: 0.98; }
        .h1 { font-size: clamp(1.6rem, 2vw, 2.2rem); line-height: 1.1; }
        .fs-3 { font-size: clamp(1.35rem, 2vw, 1.8rem); }
        .rounded-4 { border-radius: 1.5rem; }
        .border-0 { border: 0; }

        .alert {
            padding: 1rem 1.25rem;
            background: rgba(18, 52, 59, 0.06);
            transition: opacity 0.3s ease, transform 0.3s ease, max-height 0.3s ease, margin 0.3s ease, padding 0.3s ease;
            overflow: hidden;
        }

        .alert.is-hiding { opacity: 0; transform: translateY(-0.35rem); max-height: 0; margin-top: 0; margin-bottom: 0; padding-top: 0; padding-bottom: 0; }
        .alert-warning { background: rgba(180, 35, 24, 0.10); color: var(--ink); }
        .alert-danger { background: rgba(198, 40, 40, 0.16); color: var(--ink); }
        .alert-success { background: rgba(25, 135, 84, 0.12); color: var(--ink); }
        .alert-info { background: rgba(79, 134, 247, 0.12); color: var(--ink); }

        .shell { position: relative; min-height: 100vh; }
        .card-surface { background: var(--surface); border: 1px solid var(--line); box-shadow: 0 10px 28px var(--shadow); border-radius: 24px; }

        .hero-panel {
            overflow: hidden;
            position: relative;
            background:
                linear-gradient(135deg, rgba(18, 52, 59, 0.97), rgba(31, 138, 112, 0.93)),
                linear-gradient(160deg, rgba(244, 185, 66, 0.12), transparent 42%);
            color: #fff;
        }

        .hero-panel::after {
            content: "";
            position: absolute;
            right: -40px;
            top: -40px;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.14), transparent 70%);
        }

        .hero-kicker { letter-spacing: 0.3em; color: rgba(255, 255, 255, 0.72); }
        .hero-title { margin-top: 0; }
        .hero-copy { max-width: 48rem; color: rgba(255, 255, 255, 0.82); }
        .hero-meta-card {
            width: 100%;
            max-width: 25rem;
            margin-left: auto;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 0.85rem 0.9rem;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 24px;
        }
        .hero-meta-label { grid-column: 1 / -1; margin-bottom: 0 !important; text-align: center; letter-spacing: 0.22em; color: rgba(255, 255, 255, 0.62); }
        .hero-meta-card > .font-display { min-width: 0; margin-bottom: 0 !important; }

        .update-date {
            position: relative;
            display: inline-flex;
            cursor: help;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.65);
            line-height: 1.12;
            outline: none;
        }

        .update-date::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.65rem);
            z-index: 5;
            width: max-content;
            max-width: min(260px, 70vw);
            padding: 0.48rem 0.68rem;
            border-radius: 0.75rem;
            background: rgba(18, 52, 59, 0.96);
            color: #fff;
            font-family: "Aptos", "Segoe UI", sans-serif;
            font-size: 0.82rem;
            font-weight: 650;
            letter-spacing: 0;
            line-height: 1.35;
            text-align: center;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 0.3rem);
            transition: opacity 0.18s ease, transform 0.18s ease;
            white-space: nowrap;
        }

        .update-date::before {
            content: "";
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.3rem);
            z-index: 6;
            width: 0;
            height: 0;
            border: 0.35rem solid transparent;
            border-top-color: rgba(18, 52, 59, 0.96);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 0.3rem);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .update-date:hover::after,
        .update-date:hover::before,
        .update-date:focus::after,
        .update-date:focus::before {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .refresh-action { margin-top: 1rem; }

        .refresh-button {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            width: 100%;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.10);
            color: #fff;
            user-select: none;
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .theme-switch {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            width: auto;
            min-width: 13.4rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            padding: 0.58rem 0.72rem;
            cursor: pointer;
            user-select: none;
            box-shadow: 0 10px 24px var(--shadow);
            transition: background 0.18s ease, border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .theme-switch:hover { background: var(--surface-soft); border-color: rgba(31, 138, 112, 0.24); transform: translateY(-1px); box-shadow: 0 12px 28px var(--shadow); }
        .refresh-button:hover { background: rgba(255, 255, 255, 0.16); border-color: rgba(255, 255, 255, 0.34); transform: translateY(-1px); box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14); }

        .theme-switch input { position: absolute; opacity: 0; pointer-events: none; }
        .theme-switch-track { position: relative; flex: 0 0 auto; width: 3.15rem; height: 1.72rem; border-radius: 999px; background: rgba(18, 52, 59, 0.10); box-shadow: inset 0 0 0 1px rgba(18, 52, 59, 0.08); transition: background 0.2s ease; }
        .theme-switch-thumb { position: absolute; top: 0.18rem; left: 0.18rem; width: 1.36rem; height: 1.36rem; border-radius: 999px; display: grid; place-items: center; background: #fff; color: #12343b; font-size: 0.82rem; line-height: 1; box-shadow: 0 5px 14px rgba(0, 0, 0, 0.22); transition: transform 0.22s ease, background 0.22s ease, color 0.22s ease; }
        .theme-switch input:checked + .theme-switch-track { background: rgba(244, 185, 66, 0.42); }
        .theme-switch input:checked + .theme-switch-track .theme-switch-thumb { transform: translateX(1.42rem); background: #f4b942; color: #101827; }
        .theme-switch-text { display: flex; flex-direction: column; align-items: flex-start; line-height: 1.15; }
        .theme-switch-label { font-size: 0.88rem; font-weight: 800; letter-spacing: -0.01em; }
        .theme-switch-hint { margin-top: 0.15rem; font-size: 0.74rem; color: var(--muted); }

        .refresh-button { justify-content: center; padding: 0.78rem 1rem; text-decoration: none; font-weight: 800; letter-spacing: -0.01em; box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12); }
        .refresh-button:hover { color: #fff; }

                .refresh-button[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.65rem);
            z-index: 5;
            width: max-content;
            max-width: min(260px, 70vw);
            padding: 0.48rem 0.68rem;
            border-radius: 0.75rem;
            background: rgba(18, 52, 59, 0.96);
            color: #fff;
            font-family: "Aptos", "Segoe UI", sans-serif;
            font-size: 0.82rem;
            font-weight: 650;
            letter-spacing: 0;
            line-height: 1.35;
            text-align: center;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 0.3rem);
            transition: opacity 0.18s ease, transform 0.18s ease;
            white-space: nowrap;
        }

                .refresh-button[data-tooltip]::before {
            content: "";
            position: absolute;
            left: 50%;
            bottom: calc(100% + 0.3rem);
            z-index: 6;
            width: 0;
            height: 0;
            border: 0.35rem solid transparent;
            border-top-color: rgba(18, 52, 59, 0.96);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 0.3rem);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .refresh-button[data-tooltip]:hover::after,
        .refresh-button[data-tooltip]:hover::before,
        .refresh-button[data-tooltip]:focus::after,
        .refresh-button[data-tooltip]:focus::before {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .refresh-button.is-auto-blocked {
            background: rgba(198, 40, 40, 0.22);
            border-color: rgba(198, 40, 40, 0.46);
            cursor: not-allowed;
            transform: none;
        }

        .refresh-button.is-auto-blocked:hover {
            background: rgba(198, 40, 40, 0.28);
            border-color: rgba(198, 40, 40, 0.58);
            transform: none;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
        }

        .refresh-icon { display: inline-flex; font-size: 1.05rem; line-height: 1; }
        .refresh-button.is-loading .refresh-icon { animation: refresh-spin 0.9s linear infinite; }
        .hero-meta-card .refresh-action { display: flex; justify-content: flex-end; margin-top: 0; }
        .hero-meta-card .refresh-button { width: auto; max-width: 100%; padding: 0.72rem 0.92rem; font-size: 0.95rem; white-space: nowrap; }
        .hero-meta-card .refresh-copy { min-width: 0; text-align: left; }
        .hero-meta-card .refresh-copy [data-refresh-label],
        .hero-meta-card .refresh-copy [data-refresh-sublabel] { white-space: nowrap; overflow-wrap: normal; }

        .refresh-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.08;
        }

        .refresh-copy small {
            display: block;
            margin-top: 0.18rem;
            font-size: 0.66rem;
            font-weight: 950;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.76);
        }

        @keyframes refresh-spin { to { transform: rotate(360deg); } }

        .dashboard-tabs-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.5rem;
        }
        .dashboard-theme-toggle { margin-left: auto; }

        .dashboard-tabs {
            --tab-indicator-left: 0.35rem;
            --tab-indicator-top: 0.35rem;
            --tab-indicator-width: 0px;
            --tab-indicator-height: 2.45rem;
            --tab-indicator-opacity: 0;
            --tab-indicator-bg: linear-gradient(135deg, rgba(18, 52, 59, 0.98), rgba(31, 138, 112, 0.92));
            --tab-indicator-shadow: 0 10px 22px rgba(18, 52, 59, 0.16);
            position: relative;
            isolation: isolate;
            display: inline-flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            padding: 0.35rem;
            margin-bottom: 0;
            border-radius: 999px;
            background: var(--surface);
            border: 1px solid var(--line);
            box-shadow: 0 10px 28px var(--shadow);
            overflow: hidden;
        }
        .dashboard-tabs::before {
            content: "";
            position: absolute;
            z-index: 0;
            left: var(--tab-indicator-left);
            top: var(--tab-indicator-top);
            width: var(--tab-indicator-width);
            height: var(--tab-indicator-height);
            border-radius: 999px;
            background: var(--tab-indicator-bg);
            box-shadow: var(--tab-indicator-shadow);
            opacity: var(--tab-indicator-opacity);
            pointer-events: none;
            transition: left 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), top 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), width 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.26s cubic-bezier(0.2, 0.8, 0.2, 1), background 0.18s ease, box-shadow 0.18s ease, opacity 0.14s ease;
        }
        .dashboard-tabs[data-indicator-tone="alerts"] {
            --tab-indicator-bg: linear-gradient(135deg, rgba(18, 52, 59, 0.98), rgba(31, 138, 112, 0.92));
            --tab-indicator-shadow: 0 10px 22px rgba(18, 52, 59, 0.16);
        }
        .dashboard-tab { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; min-height: 2.45rem; padding: 0.55rem 1rem; border: 0; border-radius: 999px; background: transparent; color: var(--muted); cursor: pointer; font-weight: 800; letter-spacing: -0.01em; transition: background 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease; }
        .dashboard-tab:hover { color: var(--ink); transform: translateY(-1px); }
        .dashboard-tab.is-active { color: var(--ink); background: transparent; box-shadow: none; }
        .dashboard-tab.is-indicator-target { color: #fff; }
        .dashboard-tab-alerts { color: var(--muted); background: transparent; box-shadow: none; }
        .dashboard-tab-alerts:hover { color: var(--ink); background: transparent; }
        .dashboard-tab-alerts.is-active { color: var(--ink); background: transparent; box-shadow: none; }
        .dashboard-tab-alerts.is-indicator-target { color: #fff; }
        :root[data-theme="dark"] .dashboard-tabs {
            --tab-indicator-bg: linear-gradient(135deg, #f4b942, #f7d57c);
            --tab-indicator-shadow: 0 10px 24px rgba(244, 185, 66, 0.13);
        }
        :root[data-theme="dark"] .dashboard-tabs[data-indicator-tone="alerts"] {
            --tab-indicator-bg: linear-gradient(135deg, rgba(31, 138, 112, 0.98), rgba(142, 224, 180, 0.78));
            --tab-indicator-shadow: 0 10px 24px rgba(31, 138, 112, 0.16);
        }
        :root[data-theme="dark"] .dashboard-tab { background: transparent; }
        :root[data-theme="dark"] .dashboard-tab.is-active { color: var(--ink); background: transparent; box-shadow: none; }
        :root[data-theme="dark"] .dashboard-tab.is-indicator-target { color: #101827; }
        :root[data-theme="dark"] .dashboard-tab-alerts { color: var(--muted); background: transparent; box-shadow: none; }
        :root[data-theme="dark"] .dashboard-tab-alerts:hover { color: var(--ink); background: transparent; }
        :root[data-theme="dark"] .dashboard-tab-alerts.is-active { color: var(--ink); background: transparent; }
        :root[data-theme="dark"] .dashboard-tab-alerts.is-indicator-target { color: #101827; }
        .tab-panel[hidden] { display: none; }

        .metric-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 248px;
            padding: 1.65rem 1.5rem;
            text-align: center;
            border-radius: 20px;
            border: 1px solid var(--line);
            background:
                radial-gradient(circle at 50% 0%, rgba(31, 138, 112, 0.06), transparent 54%),
                var(--surface-soft);
            box-shadow: 0 10px 28px var(--shadow);
        }

        .metric-price-row {
            display: flex;
            align-items: baseline;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.78rem;
            width: 100%;
            margin-top: 0.05rem;
        }

        .metric-value {
            font-family: "Bahnschrift", "Aptos Display", "Segoe UI", sans-serif;
            font-size: clamp(2rem, 3.2vw, 2.55rem);
            line-height: 1;
            letter-spacing: -0.055em;
        }

        .metric-sparkline {
            display: block;
            width: 100%;
            max-width: 200px;
            margin: 0.1rem auto 0;
            color: var(--mint);
        }
        .metric-sparkline svg { display: block; width: 100%; height: 34px; }
        :root[data-theme="dark"] .metric-sparkline { color: #6bd6bc; }
        .metric-sparkline-up { color: var(--red); }
        .metric-sparkline-down { color: var(--green); }
        .metric-sparkline-neutral { color: #667085; }
        :root[data-theme="dark"] .metric-sparkline-up { color: #ff6b6b; }
        :root[data-theme="dark"] .metric-sparkline-down { color: #4cc38a; }
        :root[data-theme="dark"] .metric-sparkline-neutral { color: #b8c3cc; }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .section-title { font-family: "Bahnschrift", "Aptos Display", "Segoe UI", sans-serif; letter-spacing: -0.03em; }
        .chart-wrap { position: relative; height: 420px; }

        .delta-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border-radius: 999px;
            padding: 0.32rem 0.72rem;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .metric-price-row .delta-chip {
            position: relative;
            top: -0.18rem;
            padding: 0.34rem 0.72rem;
            font-size: 0.78rem;
            font-weight: 850;
        }

        .delta-up { color: var(--red); background: rgba(198, 40, 40, 0.10); }
        .delta-down { color: var(--green); background: rgba(25, 135, 84, 0.10); }
        .delta-neutral { color: #667085; background: rgba(102, 112, 133, 0.10); }
        :root[data-theme="dark"] .delta-neutral { color: #b8c3cc; background: rgba(184, 195, 204, 0.12); }

        .tomorrow-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.68rem;
            width: 100%;
            margin-top: 1.15rem;
            padding: 0.78rem 1rem;
            border: 1px solid rgba(31, 138, 112, 0.13);
            border-radius: 17px;
            background:
                radial-gradient(circle at top left, rgba(31, 138, 112, 0.11), transparent 58%),
                linear-gradient(135deg, rgba(31, 138, 112, 0.075), rgba(79, 134, 247, 0.055));
            color: var(--ink);
            font-size: 0.88rem;
            line-height: 1.35;
            text-align: left;
        }

        .tomorrow-note.delta-up {
            border-color: rgba(198, 40, 40, 0.16);
            background:
                radial-gradient(circle at top left, rgba(198, 40, 40, 0.10), transparent 58%),
                linear-gradient(135deg, rgba(198, 40, 40, 0.075), rgba(244, 185, 66, 0.055));
            color: var(--ink);
        }

        .tomorrow-note.delta-down {
            border-color: rgba(25, 135, 84, 0.18);
            background:
                radial-gradient(circle at top left, rgba(25, 135, 84, 0.11), transparent 58%),
                linear-gradient(135deg, rgba(25, 135, 84, 0.08), rgba(31, 138, 112, 0.055));
            color: var(--ink);
        }

        .tomorrow-note.delta-neutral {
            border-color: rgba(102, 112, 133, 0.16);
            background:
                radial-gradient(circle at top left, rgba(102, 112, 133, 0.08), transparent 58%),
                linear-gradient(135deg, rgba(102, 112, 133, 0.065), rgba(148, 163, 184, 0.045));
            color: var(--ink);
        }

        .tomorrow-empty-note {
            border: 1px solid rgba(102, 112, 133, 0.10);
            background: rgba(102, 112, 133, 0.032);
            color: var(--muted);
            font-weight: 400;
            text-align: center;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.55);
        }


        .tomorrow-icon {
            flex: 0 0 auto;
            display: grid;
            place-items: center;
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            background: rgba(31, 138, 112, 0.12);
            color: var(--mint);
            font-size: 0.92rem;
            font-weight: 950;
            line-height: 1;
            box-shadow: inset 0 0 0 1px rgba(31, 138, 112, 0.10);
        }

        .tomorrow-note.delta-up .tomorrow-icon {
            background: rgba(198, 40, 40, 0.11);
            color: var(--red);
            box-shadow: inset 0 0 0 1px rgba(198, 40, 40, 0.10);
        }

        .tomorrow-note.delta-down .tomorrow-icon {
            background: rgba(25, 135, 84, 0.13);
            color: var(--green);
            box-shadow: inset 0 0 0 1px rgba(25, 135, 84, 0.11);
        }

        .tomorrow-note.delta-neutral .tomorrow-icon {
            background: rgba(102, 112, 133, 0.10);
            color: #667085;
            box-shadow: inset 0 0 0 1px rgba(102, 112, 133, 0.08);
        }

        .tomorrow-copy { min-width: 0; }

        .tomorrow-title {
            display: block;
            color: var(--ink);
            font-weight: 900;
            letter-spacing: -0.015em;
        }

        .tomorrow-main {
            display: inline-flex;
            align-items: center;
            margin-top: 0.08rem;
            color: var(--muted);
            font-weight: 500;
        }

        .tomorrow-value-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem 0.5rem;
            align-items: center;
            margin-top: 0.18rem;
        }

        .tomorrow-price {
            font-weight: 500;
            color: var(--ink);
        }

        .tomorrow-delta-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.24rem 0.56rem;
            font-size: 0.74rem;
            font-weight: 850;
            line-height: 1;
            white-space: nowrap;
        }

        .tomorrow-note.delta-up .tomorrow-delta-badge {
            color: var(--red);
            background: rgba(198, 40, 40, 0.10);
            box-shadow: inset 0 0 0 1px rgba(198, 40, 40, 0.045);
        }

        .tomorrow-note.delta-down .tomorrow-delta-badge {
            color: var(--green);
            background: rgba(25, 135, 84, 0.10);
            box-shadow: inset 0 0 0 1px rgba(25, 135, 84, 0.045);
        }

        .tomorrow-note.delta-neutral .tomorrow-delta-badge {
            color: #667085;
            background: rgba(102, 112, 133, 0.10);
            box-shadow: inset 0 0 0 1px rgba(102, 112, 133, 0.045);
        }

        :root[data-theme="dark"] .metric-card {
            background:
                radial-gradient(circle at 50% 0%, rgba(244, 185, 66, 0.08), transparent 54%),
                var(--surface-soft);
        }

        :root[data-theme="dark"] .tomorrow-note {
            border-color: rgba(234, 243, 241, 0.11);
            background:
                radial-gradient(circle at top left, rgba(244, 185, 66, 0.10), transparent 60%),
                linear-gradient(135deg, rgba(234, 243, 241, 0.045), rgba(31, 138, 112, 0.065));
        }

        :root[data-theme="dark"] .tomorrow-note.delta-neutral {
            border-color: rgba(184, 195, 204, 0.18);
            background:
                radial-gradient(circle at top left, rgba(184, 195, 204, 0.12), transparent 60%),
                linear-gradient(135deg, rgba(184, 195, 204, 0.075), rgba(102, 112, 133, 0.045));
        }

        :root[data-theme="dark"] .tomorrow-empty-note {
            border-color: rgba(184, 195, 204, 0.10);
            background: rgba(184, 195, 204, 0.048);
            color: var(--muted);
            font-weight: 400;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.035);
        }

        :root[data-theme="dark"] .tomorrow-icon {
            background: rgba(234, 243, 241, 0.08);
            color: #8ee0b4;
        }

        :root[data-theme="dark"] .tomorrow-note.delta-up {
            border-color: rgba(198, 40, 40, 0.26);
            background:
                radial-gradient(circle at top left, rgba(198, 40, 40, 0.16), transparent 60%),
                linear-gradient(135deg, rgba(198, 40, 40, 0.10), rgba(244, 185, 66, 0.045));
        }

        :root[data-theme="dark"] .tomorrow-note.delta-up .tomorrow-icon {
            background: rgba(198, 40, 40, 0.16);
            color: #ff6b6b;
            box-shadow: inset 0 0 0 1px rgba(198, 40, 40, 0.16);
        }

        :root[data-theme="dark"] .tomorrow-note.delta-down {
            border-color: rgba(25, 135, 84, 0.24);
            background:
                radial-gradient(circle at top left, rgba(25, 135, 84, 0.16), transparent 60%),
                linear-gradient(135deg, rgba(25, 135, 84, 0.10), rgba(31, 138, 112, 0.055));
        }

        :root[data-theme="dark"] .tomorrow-note.delta-down .tomorrow-icon {
            background: rgba(25, 135, 84, 0.16);
            color: #8ee0b4;
            box-shadow: inset 0 0 0 1px rgba(25, 135, 84, 0.16);
        }

        :root[data-theme="dark"] .tomorrow-note.delta-neutral .tomorrow-icon {
            background: rgba(184, 195, 204, 0.12);
            color: #b8c3cc;
            box-shadow: inset 0 0 0 1px rgba(184, 195, 204, 0.10);
        }

        :root[data-theme="dark"] .tomorrow-note.delta-neutral .tomorrow-delta-badge {
            color: #b8c3cc;
            background: rgba(184, 195, 204, 0.12);
        }

        .promo-toolbar { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .promo-section-card { padding-top: 0.65rem !important; padding-bottom: 3.8rem !important; }
        .promo-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 1rem; align-items: start; }

        .promo-card {
            position: relative;
            display: grid;
            grid-template-columns: 96px 1fr;
            gap: 1rem;
            min-height: 132px;
            padding: 0.9rem;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
            box-shadow: 0 10px 28px var(--shadow);
            transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .promo-card:hover { color: var(--ink); transform: translateY(-2px); border-color: rgba(31, 138, 112, 0.35); box-shadow: 0 16px 34px var(--shadow); }

        .promo-card.is-top-promo {
            border-color: rgba(25, 135, 84, 0.58);
            background:
                linear-gradient(135deg, rgba(25, 135, 84, 0.16), rgba(244, 185, 66, 0.12)),
                var(--surface-soft);
            box-shadow: 0 18px 42px rgba(25, 135, 84, 0.14), 0 10px 28px var(--shadow);
        }

        .promo-card.is-top-promo::before {
            content: "";
            position: absolute;
            inset: -1px;
            border-radius: 20px;
            pointer-events: none;
            border: 1px solid rgba(244, 185, 66, 0.52);
        }

        .promo-logo { display: grid; place-items: center; width: 96px; height: 96px; align-self: start; border-radius: 16px; background: radial-gradient(circle at top left, rgba(31, 138, 112, 0.20), transparent 55%), rgba(18, 52, 59, 0.06); color: var(--ink); font-family: "Bahnschrift", "Aptos Display", "Segoe UI", sans-serif; font-size: 2.1rem; font-weight: 900; letter-spacing: -0.08em; overflow: hidden; }
        .promo-card.is-top-promo .promo-logo { background: radial-gradient(circle at top left, rgba(244, 185, 66, 0.42), transparent 55%), rgba(25, 135, 84, 0.13); }
        .promo-logo-img { display: block; width: 82%; height: 82%; object-fit: contain; }
        .promo-logo-img-shell,
        .promo-logo-img-orlen,
        .promo-logo-img-moya { width: 86%; height: 86%; }
        .promo-logo-img-bp { width: 74%; height: 74%; }

        :root[data-theme="dark"] .promo-logo { background: radial-gradient(circle at top left, rgba(244, 185, 66, 0.20), transparent 55%), rgba(234, 243, 241, 0.06); }

        .promo-body { min-width: 0; }
        .promo-top-line { display: flex; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-bottom: 0.35rem; }
        .promo-top-badge { display: inline-flex; align-items: center; gap: 0.28rem; padding: 0.3rem 0.62rem; border-radius: 999px; color: #083b24; background: linear-gradient(135deg, rgba(244, 185, 66, 0.95), rgba(25, 135, 84, 0.25)); font-size: 0.76rem; font-weight: 950; letter-spacing: 0.02em; text-transform: uppercase; }
        :root[data-theme="dark"] .promo-top-badge { color: #101827; background: linear-gradient(135deg, #f4b942, rgba(25, 135, 84, 0.72)); }
        .promo-title { display: block; margin: 0; font-weight: 850; line-height: 1.22; letter-spacing: -0.02em; }
        .promo-card.is-top-promo .promo-title { color: #0c5b38; }
        :root[data-theme="dark"] .promo-card.is-top-promo .promo-title { color: #8ee0b4; }
        .promo-description { display: block; margin-top: 0.45rem; }
        .promo-segments { display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.75rem; }
        .promo-segment { display: block; padding: 0.7rem 0.8rem; border-radius: 0.85rem; background: rgba(102, 112, 133, 0.07); border: 1px solid rgba(102, 112, 133, 0.12); text-decoration: none; color: inherit; cursor: pointer; transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease; }
        :root[data-theme="dark"] .promo-segment { background: rgba(184, 195, 204, 0.06); border-color: rgba(184, 195, 204, 0.12); }
        .promo-segment:hover { color: inherit; border-color: rgba(31, 138, 112, 0.45); background: rgba(31, 138, 112, 0.07); transform: translateY(-1px); }
        .promo-segment:focus-visible { outline: 2px solid rgba(31, 138, 112, 0.6); outline-offset: 2px; }
        .promo-card-grouped { cursor: default; }
        .promo-card-grouped:hover { transform: none; border-color: var(--line); box-shadow: 0 10px 28px var(--shadow); }
        .promo-segment-title { display: block; font-weight: 850; line-height: 1.25; }
        .promo-segment-text { display: block; margin-top: 0.3rem; font-size: 0.86rem; color: var(--muted); line-height: 1.4; }
        .promo-collapsible { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; line-clamp: 3; overflow: hidden; }
        .promo-collapsible.is-expanded { display: block; -webkit-line-clamp: unset; line-clamp: unset; overflow: visible; }
        .promo-toggle { display: inline-block; margin-top: 0.4rem; font-size: 0.8rem; font-weight: 850; color: #0c7a52; cursor: pointer; user-select: none; }
        .promo-toggle:hover { text-decoration: underline; }
        .promo-toggle[hidden] { display: none; }
        :root[data-theme="dark"] .promo-toggle { color: #7ee0b2; }
        .promo-segment .promo-meta { margin-top: 0.6rem; }
        .promo-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem; margin-top: 0.75rem; }
        .promo-chip { display: inline-flex; align-items: center; gap: 0.28rem; padding: 0.28rem 0.62rem; border-radius: 999px; font-size: 0.78rem; font-weight: 850; line-height: 1; }
        .promo-status-active { color: #7a2300; background: rgba(244, 185, 66, 0.34); }
        :root[data-theme="dark"] .promo-status-active { color: #101827; background: rgba(244, 185, 66, 0.78); }
        .promo-status-muted { color: var(--muted); background: rgba(102, 112, 133, 0.10); }
        .promo-discount { color: var(--green); background: rgba(25, 135, 84, 0.12); }
        .promo-card.is-top-promo .promo-discount { color: #083b24; background: rgba(25, 135, 84, 0.20); box-shadow: inset 0 0 0 1px rgba(25, 135, 84, 0.20); }
        :root[data-theme="dark"] .promo-card.is-top-promo .promo-discount { color: #d7ffe9; background: rgba(25, 135, 84, 0.35); }
        .promo-date { color: var(--ink); background: rgba(31, 138, 112, 0.10); }
        .promo-empty { padding: 1.25rem; border-radius: 20px; border: 1px dashed var(--line); color: var(--muted); background: rgba(102, 112, 133, 0.06); }
        .alerts-card {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1.35rem;
            align-items: center;
            overflow: hidden;
            border-color: rgba(31, 138, 112, 0.16);
            background: var(--surface);
        }
        .alerts-card::before {
            content: "";
            position: absolute;
            inset: 1px;
            border-radius: 23px;
            pointer-events: none;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.62);
        }
        .alerts-content { position: relative; z-index: 1; min-width: 0; }
        .alerts-copy {
            max-width: 760px;
            margin: 0;
            color: var(--ink);
            font-size: 1rem;
            font-weight: 400;
            line-height: 1.5;
            letter-spacing: 0;
        }
        .alerts-copy strong { font-weight: 850; }
        .telegram-cta {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.58rem;
            min-height: 3rem;
            padding: 0.82rem 1.2rem;
            border-radius: 999px;
            border: 1px solid rgba(0, 136, 204, 0.22);
            background: linear-gradient(135deg, #2aabee, #178fcb);
            color: #fff;
            text-decoration: none;
            font-weight: 950;
            letter-spacing: -0.01em;
            white-space: nowrap;
            box-shadow: 0 14px 30px rgba(0, 136, 204, 0.20), 0 8px 18px var(--shadow);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }
        .telegram-cta:hover { color: #fff; transform: translateY(-1px); box-shadow: 0 18px 38px rgba(0, 136, 204, 0.26), 0 10px 24px var(--shadow); filter: brightness(1.03); }
        .telegram-icon { width: 1.14rem; height: 1.14rem; flex: 0 0 auto; }
        :root[data-theme="dark"] .alerts-card {
            border-color: rgba(244, 185, 66, 0.13);
            background: var(--surface);
        }
        :root[data-theme="dark"] .alerts-card::before { box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05); }
        .source-link { color: var(--ink); text-decoration: none; border-bottom: 1px dashed rgba(18, 52, 59, 0.35); }
        :root[data-theme="dark"] .source-link { border-bottom-color: rgba(234, 243, 241, 0.35); }
        .source-link:hover { color: var(--mint); border-bottom-color: rgba(31, 138, 112, 0.55); }
        .page-footer { margin-top: auto; }

        .tomorrow-loader {
            position: fixed;
            left: 50%;
            bottom: 1.25rem;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 0.9rem;
            width: min(92vw, 430px);
            padding: 1rem 1.1rem;
            border-radius: 1.25rem;
            border: 1px solid rgba(31, 138, 112, 0.22);
            background: var(--surface);
            color: var(--ink);
            box-shadow: 0 18px 48px rgba(18, 52, 59, 0.18), 0 10px 28px var(--shadow);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 1rem);
            transition: opacity 0.22s ease, transform 0.22s ease;
        }

        .tomorrow-loader[hidden] {
            display: none;
        }

        .tomorrow-loader.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translate(-50%, 0);
        }

        .tomorrow-loader-spinner {
            flex: 0 0 auto;
            width: 2.15rem;
            height: 2.15rem;
            border-radius: 999px;
            border: 3px solid rgba(31, 138, 112, 0.16);
            border-top-color: var(--mint);
            border-right-color: var(--amber);
            animation: tomorrow-loader-spin 0.78s linear infinite;
        }

        .tomorrow-loader-copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 0.12rem;
            line-height: 1.28;
        }

        .tomorrow-loader-title {
            font-weight: 400;
            letter-spacing: -0.005em;
        }

        .tomorrow-loader-text {
            color: var(--muted);
            font-size: 0.86rem;
        }

        :root[data-theme="dark"] .tomorrow-loader {
            border-color: rgba(244, 185, 66, 0.16);
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.38), 0 10px 28px var(--shadow);
        }

        @keyframes tomorrow-loader-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .tomorrow-loader,
            .tomorrow-loader-spinner {
                animation: none;
                transition: none;
            }
        }
        @media (min-width: 768px) {
            .col-md-6 { width: 50%; }
            .promo-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 992px) {
            .col-lg-4 { width: 33.333333%; }
            .col-lg-8 { width: 66.666667%; }
            .p-lg-5 { padding: 3rem; }
            .px-lg-4 { padding-inline: 1.5rem; }
            .py-lg-5 { padding-block: 3rem; }
            .g-lg-4 { --gutter-x: 1.5rem; --gutter-y: 1.5rem; }
        }

        @media (min-width: 1200px) {
            .promo-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 767.98px) {
            .chart-wrap { height: 320px; }
            .hero-meta-card { width: 100%; margin-left: 0; }
        }

        @media (max-width: 399.98px) {
            .hero-meta-card { grid-template-columns: 1fr; }
            .hero-meta-card .refresh-action { justify-content: stretch; }
            .hero-meta-card .refresh-button { width: 100%; justify-content: center; white-space: normal; }
            .hero-meta-card .refresh-copy { text-align: center; }
            .hero-meta-card .refresh-copy [data-refresh-label],
            .hero-meta-card .refresh-copy [data-refresh-sublabel] { white-space: normal; overflow-wrap: anywhere; }
        }

        @media (max-width: 575.98px) {
            .dashboard-tabs-row { align-items: stretch; }
            .dashboard-tabs { width: 100%; border-radius: 1.25rem; }
            .dashboard-theme-toggle { width: 100%; margin-left: 0; display: flex; justify-content: center; }
            .dashboard-theme-toggle .theme-switch { width: 100%; min-width: 0; justify-content: center; }
            .dashboard-tab { flex: 1 1 100%; }
            .metric-card { min-height: 238px; }
            .metric-price-row { gap: 0.55rem; }
            .tomorrow-note { align-items: center; justify-content: center; }
            .tomorrow-value-row { justify-content: center; }
            .promo-card { grid-template-columns: 76px 1fr; }
            .promo-logo { width: 76px; height: 76px; border-radius: 14px; font-size: 1.65rem; }
            .alerts-card { grid-template-columns: 1fr; }
            .telegram-cta { width: 100%; }
        }
    </style>
</head>
<body>
<div class="shell">
    <main class="container-xxl page-main px-3 px-lg-4 py-4 py-lg-5 position-relative">
        <section class="card-surface hero-panel p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-start position-relative">
                <div class="col-lg-8">
                    <h1 class="hero-title font-display fw-bold display-5 mb-3">Oficjalne limity cen paliw</h1>
                    <p class="hero-copy mb-0">
                        Widok pokazuje ceny obowiązujące <strong><?= e((string) $currentEffectiveLabel) ?></strong>,
                        zmiany względem poprzedniego komunikatu, podgląd na <strong>jutro</strong>
                        oraz aktualne promocje paliwowe ze stacji.
                    </p>
                </div>
                <div class="col-lg-4">
                    <div class="hero-meta-card p-4">
                        <p class="hero-meta-label small text-uppercase mb-2">Ostatnie pobranie danych</p>
                        <div class="font-display fs-3 fw-bold mb-0">
                            <span
                                class="update-date"
                                tabindex="0"
                                aria-label="<?= e($lastDataUpdateTooltipLabel) ?>"
                                data-tooltip="<?= e($lastDataUpdateTooltipLabel) ?>"
                            ><?= e((string) $lastDataUpdateVisibleLabel) ?></span>
                        </div>

                        <div class="refresh-action">
                            <a
                                class="refresh-button"
                                id="manualRefreshButton"
                                href="<?= e($manualRefreshUrl) ?>"
                            >
                                <span class="refresh-icon" aria-hidden="true">↻</span>

                                <span class="refresh-copy">
                                    <span data-refresh-label>Odśwież dane</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if ($warnings !== []): ?>
            <section class="alert alert-warning border-0 rounded-4 mb-4">
                <strong>Uwaga:</strong>
                <?= e(implode(' ', $warnings)) ?>
            </section>
        <?php endif; ?>

        <?php if ($manualRefreshMessage !== null): ?>
            <section id="manualRefreshAlert" class="alert <?= e($manualRefreshClass) ?> border-0 rounded-4 mb-4">
                <strong>Odświeżanie:</strong>
                <?= e($manualRefreshMessage) ?>
            </section>
        <?php endif; ?>

        <section id="autoRefreshBlockAlert" class="alert alert-danger border-0 rounded-4 mb-4" hidden>
            <strong>Odświeżanie:</strong>
            Przycisk chwilowo wyłączony, ceny na jutro są ładowane.
        </section>

        <div class="dashboard-tabs-row">
            <nav class="dashboard-tabs" role="tablist" aria-label="Sekcje dashboardu">
                <button class="dashboard-tab is-active" type="button" role="tab" aria-selected="true" data-dashboard-tab="prices">
                    Limity cen
                </button>
                <button class="dashboard-tab" type="button" role="tab" aria-selected="false" data-dashboard-tab="promotions">
                    Aktualne promocje
                </button>
                <?php /*
                <button class="dashboard-tab dashboard-tab-alerts" type="button" role="tab" aria-selected="false" data-dashboard-tab="alerts">
                    Powiadomienia
                </button>
                */ ?>
            </nav>

            <div class="theme-toggle dashboard-theme-toggle">
                <label class="theme-switch" for="themeToggle">
                    <input type="checkbox" id="themeToggle" aria-label="Przełącz ciemne tło">
                    <span class="theme-switch-track" aria-hidden="true">
                        <span class="theme-switch-thumb" id="themeToggleIcon">☀</span>
                    </span>
                    <span class="theme-switch-text">
                        <span class="theme-switch-label" id="themeToggleLabel">Ciemne tło</span>
                        <span class="theme-switch-hint" id="themeToggleHint">Tryb nocny panelu</span>
                    </span>
                </label>
            </div>
        </div>

        <div class="tab-panel" data-dashboard-panel="prices">
            <section class="row g-3 g-lg-4 mb-4">
                <?php foreach ($fuelCards as $card): ?>
                    <?php
                    $todayDeltaClass = delta_class($card['todayDelta'] ?? null);
                    $tomorrowDeltaClass = delta_class($card['tomorrowDelta'] ?? null);
                    $todayArrow = delta_arrow($card['todayDelta'] ?? null);
                    $tomorrowArrow = delta_arrow($card['tomorrowDelta'] ?? null);
                    $hasTomorrowPrice = ($card['tomorrowPrice'] ?? null) !== null;
                    $isSameTomorrow = $hasTomorrowPrice && ($card['todayPrice'] ?? null) !== null && abs((float) $card['tomorrowPrice'] - (float) $card['todayPrice']) < 0.0001;
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <article class="metric-card h-100 p-4">
                            <p class="text-uppercase small fw-semibold text-secondary mb-2"><?= e((string) ($card['label'] ?? '')) ?></p>

                            <div class="metric-price-row">
                                <div class="metric-value"><?= e(format_price($card['todayPrice'] ?? null)) ?></div>

                                <span class="delta-chip <?= e($todayDeltaClass) ?>">
                                    <span><?= e($todayArrow) ?></span>
                                    <span><?= e(format_delta($card['todayDelta'] ?? null)) ?></span>
                                </span>
                            </div>

                            <?php $sparkValues = fuel_recent_prices($dashboardData, (string) ($card['code'] ?? '')); ?>
                            <?php $sparklineSvg = fuel_sparkline_svg($sparkValues); ?>
                            <?php if ($sparklineSvg !== ''): ?>
                                <span class="metric-sparkline <?= e(sparkline_trend_class($sparkValues)) ?>" aria-hidden="true"><?= $sparklineSvg ?></span>
                                <span class="sr-only">Trend ceny <?= e((string) ($card['label'] ?? '')) ?> z ostatniego miesiąca</span>
                            <?php endif; ?>

                            <?php if ($hasTomorrowPrice): ?>
                                <div class="tomorrow-note <?= e($tomorrowDeltaClass) ?>">
                                    <span class="tomorrow-icon" aria-hidden="true">
                                        <?= $isSameTomorrow ? '✓' : e($tomorrowArrow) ?>
                                    </span>

                                    <span class="tomorrow-copy">
                                        <span class="tomorrow-title">Jutro</span>

                                        <span class="tomorrow-value-row">
                                            <span class="tomorrow-price"><?= e(format_price($card['tomorrowPrice'] ?? null)) ?></span>

                                            <?php if ($isSameTomorrow): ?>
                                                <span class="tomorrow-main">bez zmian</span>
                                            <?php else: ?>
                                                <span class="tomorrow-delta-badge"><?= e(format_delta($card['tomorrowDelta'] ?? null)) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="tomorrow-note tomorrow-empty-note">
                                    Brak opublikowanej zmiany na jutro
                                </div>
                            <?php endif; ?>
                        </article>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="row g-4 mb-4">
                <div>
                    <article class="card-surface p-4 p-lg-5 h-100">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                            <div>
                                <p class="text-uppercase small fw-semibold text-secondary mb-2">Bieżący monitoring</p>
                                <h2 class="section-title h1 mb-0">Ostatni miesiąc publikacji</h2>
                            </div>
                        </div>
                        <div class="chart-wrap">
                            <canvas id="recentChart"></canvas>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div class="tab-panel" data-dashboard-panel="promotions" hidden>
            <section class="mb-4">
                <article class="card-surface p-4 p-lg-5 h-100 promo-section-card">
                    <div class="promo-toolbar">
                        <div>
                            <h2 class="section-title h1 mb-0">Aktualne promocje paliwowe</h2>
                        </div>
                    </div>

                    <?php if (!empty($stationPromotions['warning'])): ?>
                        <div class="promo-empty mb-4">
                            <?= e((string) $stationPromotions['warning']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($stationPromotions['warnings']) && is_array($stationPromotions['warnings'])): ?>
                        <div class="promo-empty mb-4">
                            <?= e(implode(' ', $stationPromotions['warnings'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php $promotionItems = is_array($stationPromotions['items'] ?? null) ? $stationPromotions['items'] : []; ?>

                    <?php if ($promotionItems === []): ?>
                        <div class="promo-empty">
                            Brak zapisanych promocji do pokazania. Kliknij „Odśwież dane”, żeby spróbować pobrać najnowszy snapshot.
                        </div>
                    <?php else: ?>
                        <div class="promo-grid">
                            <?php foreach ($promotionItems as $promo): ?>
                                <?php
                                $network = (string) ($promo['network'] ?? 'Stacja');
                                $title = normalize_display_dashes((string) ($promo['title'] ?? 'Promocja'));
                                $description = normalize_display_dashes((string) ($promo['description'] ?? ''));
                                $discountLabel = is_string($promo['discountLabel'] ?? null) ? normalize_display_dashes($promo['discountLabel']) : null;
                                $dateRangeLabel = is_string($promo['dateRangeLabel'] ?? null) ? normalize_display_dashes($promo['dateRangeLabel']) : null;
                                $toIso = $promo['toIso'] ?? null;
                                $displayDateLabel = $dateRangeLabel;

                                if (is_string($toIso) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $toIso, $toMatch) === 1) {
                                    $displayDateLabel = 'do ' . $toMatch[3] . '.' . $toMatch[2] . '.' . $toMatch[1];
                                }

                                $url = (string) ($promo['url'] ?? station_promotions_source_url());
                                $isActive = !empty($promo['isActive']);
                                $isTopPromotion = !empty($promo['isTopPromotion']);
                                $segments = is_array($promo['segments'] ?? null) ? $promo['segments'] : [];
                                $logoUrl = station_logo_url($network);
                                $logoClassSuffix = strtolower(str_replace(' ', '-', $network));
                                ?>
                                <?php if ($segments !== []): ?>
                                <div class="promo-card promo-card-grouped <?= $isTopPromotion ? 'is-top-promo' : '' ?>">
                                <?php else: ?>
                                <a class="promo-card <?= $isTopPromotion ? 'is-top-promo' : '' ?>" href="<?= e($url) ?>" target="_blank" rel="noreferrer">
                                <?php endif; ?>
                                    <span class="promo-logo" aria-hidden="true">
                                        <?php if (is_string($logoUrl) && trim($logoUrl) !== ''): ?>
                                            <img
                                                class="promo-logo-img promo-logo-img-<?= e($logoClassSuffix) ?>"
                                                src="<?= e($logoUrl) ?>"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            >
                                        <?php else: ?>
                                            <?= e(station_network_initial($network)) ?>
                                        <?php endif; ?>
                                    </span>

                                    <span class="promo-body">
                                        <?php if ($isTopPromotion): ?>
                                            <span class="promo-top-line">
                                                <span class="promo-top-badge">★ TOP okazja</span>
                                            </span>
                                        <?php endif; ?>

                                        <span class="promo-title"><?= e($network) ?> - <?= e($title) ?></span>

                                        <?php if ($segments !== []): ?>
                                            <?php if ($description !== ''): ?>
                                                <span class="small text-secondary promo-description">
                                                    <?= e($description) ?>
                                                </span>
                                            <?php endif; ?>

                                            <span class="promo-segments">
                                                <?php foreach ($segments as $segment): ?>
                                                    <?php
                                                    $segHeading = normalize_display_dashes((string) ($segment['heading'] ?? ''));
                                                    $segText = normalize_display_dashes((string) ($segment['text'] ?? ''));
                                                    $segDiscount = is_string($segment['discountLabel'] ?? null) ? normalize_display_dashes($segment['discountLabel']) : null;
                                                    $segDate = is_string($segment['dateLabel'] ?? null) ? normalize_display_dashes($segment['dateLabel']) : null;
                                                    $segActive = !empty($segment['isActive']);
                                                    $segUrl = (string) ($segment['url'] ?? $url);
                                                    ?>
                                                    <a class="promo-segment" href="<?= e($segUrl) ?>" target="_blank" rel="noreferrer">
                                                        <?php if ($segHeading !== ''): ?>
                                                            <span class="promo-segment-title"><?= e($segHeading) ?></span>
                                                        <?php endif; ?>

                                                        <?php if ($segText !== ''): ?>
                                                            <span class="promo-segment-text promo-collapsible"><?= e($segText) ?></span>
                                                            <span class="promo-toggle" role="button" tabindex="0" aria-expanded="false" hidden>Pokaż więcej</span>
                                                        <?php endif; ?>

                                                        <span class="promo-meta">
                                                            <span class="promo-chip <?= $segActive ? 'promo-status-active' : 'promo-status-muted' ?>">
                                                                <?= $segActive ? 'Aktywna' : 'Poza terminem / do weryfikacji' ?>
                                                            </span>

                                                            <?php if (is_string($segDiscount) && trim($segDiscount) !== ''): ?>
                                                                <span class="promo-chip promo-discount"><?= e($segDiscount) ?></span>
                                                            <?php endif; ?>

                                                            <?php if (is_string($segDate) && trim($segDate) !== ''): ?>
                                                                <span class="promo-chip promo-date"><?= e($segDate) ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php else: ?>
                                            <?php if ($description !== ''): ?>
                                                <span class="small text-secondary promo-description promo-collapsible"><?= e($description) ?></span>
                                                <span class="promo-toggle" role="button" tabindex="0" aria-expanded="false" hidden>Pokaż więcej</span>
                                            <?php endif; ?>

                                            <span class="promo-meta">
                                                <span class="promo-chip <?= $isActive ? 'promo-status-active' : 'promo-status-muted' ?>">
                                                    <?= $isActive ? 'Aktywna' : 'Poza terminem / do weryfikacji' ?>
                                                </span>

                                                <?php if (is_string($discountLabel) && trim($discountLabel) !== ''): ?>
                                                    <span class="promo-chip promo-discount"><?= e($discountLabel) ?></span>
                                                <?php endif; ?>

                                                <?php if (is_string($displayDateLabel) && trim($displayDateLabel) !== ''): ?>
                                                    <span class="promo-chip promo-date"><?= e($displayDateLabel) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                <?php if ($segments !== []): ?>
                                </div>
                                <?php else: ?>
                                </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        </div>

        <div class="tab-panel" data-dashboard-panel="alerts" hidden>
            <section class="mb-4">
                <article class="card-surface p-4 p-lg-5 h-100 alerts-card">
                    <div class="alerts-content">
                        <p class="alerts-copy">
                            Powiadomienia są wysyłane za pośrednictwem bota na Telegramie. Dostaniesz powiadomienie, gdy cena paliwa się zmieni. <strong>Maksymalnie raz dziennie.</strong>
                        </p>
                    </div>

                    <a class="telegram-cta" href="<?= e(telegram_alert_bot_url()) ?>" target="_blank" rel="noreferrer">
                        <svg class="telegram-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path fill="currentColor" d="M21.7 3.3a1.2 1.2 0 0 0-1.2-.18L2.9 10.08c-.52.2-.86.65-.89 1.17-.03.53.27 1.01.76 1.27l4.32 2.17 2.05 5.94c.15.43.5.72.94.78.44.06.86-.14 1.12-.51l2.35-3.34 4.54 3.32c.36.26.82.31 1.22.13.39-.18.67-.55.74-.98l1.99-15.56c.06-.46-.07-.86-.34-1.16Zm-4.02 3.54-7.85 7.25-.3 3.71-1.22-3.56 9.37-7.4Z"/>
                        </svg>
                        <span>Kanał Telegram</span>
                    </a>
                </article>
            </section>
        </div>

        <footer class="page-footer py-4 text-secondary small">
            Źródła:
            <a class="source-link" href="https://www.gov.pl/web/energia/wiadomosci" target="_blank" rel="noreferrer">Ministerstwo Energii na gov.pl</a>
            ,
            <a class="source-link" href="<?= e(monitor_polish_source_url()) ?>" target="_blank" rel="noreferrer">Monitor Polski</a>.
            <br>
            Kod źródłowy projektu:
            <a class="source-link" href="https://github.com/udnn1/Monitor-cen-paliw" target="_blank" rel="noreferrer">github.com/udnn1/Monitor-cen-paliw</a>.
        </footer>
    </main>

    <div id="tomorrowPriceLoader" class="tomorrow-loader" role="status" aria-live="polite" hidden>
        <span class="tomorrow-loader-spinner" aria-hidden="true"></span>

        <span class="tomorrow-loader-copy">
            <span class="tomorrow-loader-title" data-tomorrow-loader-title>
                Wykryto ceny na jutro
            </span>
            <span class="tomorrow-loader-text" data-tomorrow-loader-text>
                Trwa wczytywanie najnowszej aktualizacji
            </span>
        </span>
    </div>
</div>

<script>
    const dashboardData = <?= json_encode($dashboardData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const autoRefreshConfig = <?= json_encode($autoRefreshConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const root = document.documentElement;
    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');
    const themeToggleLabel = document.getElementById('themeToggleLabel');
    const themeToggleHint = document.getElementById('themeToggleHint');
    const getCurrentTheme = () => root.dataset.theme === 'dark' ? 'dark' : 'light';

    const setThemeControlState = () => {
        const isDark = getCurrentTheme() === 'dark';
        if (themeToggle) {
            themeToggle.checked = isDark;
            themeToggle.setAttribute('aria-label', isDark ? 'Przełącz na jasne tło' : 'Przełącz na ciemne tło');
        }
        if (themeToggleIcon) themeToggleIcon.textContent = isDark ? '☾' : '☀';
        if (themeToggleLabel) themeToggleLabel.textContent = isDark ? 'Jasne tło' : 'Ciemne tło';
        if (themeToggleHint) themeToggleHint.textContent = isDark ? 'Tryb jasny panelu' : 'Tryb nocny panelu';
    };

    const saveThemePreference = (theme) => {
        try { localStorage.setItem('fuelDashboardTheme', theme); } catch (error) {}
    };

    const applyTheme = (theme) => {
        root.dataset.theme = theme === 'dark' ? 'dark' : 'light';
        setThemeControlState();
        saveThemePreference(getCurrentTheme());
        if (window.recentChartInstance) applyChartTheme(window.recentChartInstance);
    };

    setThemeControlState();

    if (themeToggle) {
        themeToggle.addEventListener('change', () => applyTheme(themeToggle.checked ? 'dark' : 'light'));
    }

    const formatPrice = (value) => {
        if (value === null || value === undefined) return 'brak danych';
        return new Intl.NumberFormat('pl-PL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' zł/l';
    };

    const recentCtx = document.getElementById('recentChart');
    const recentItems = dashboardData.recentAnnouncements || [];
    const bodyFontFamily = 'Aptos, Segoe UI, Helvetica Neue, Arial, sans-serif';
    let chartLibraryPromise;
    let chartsRendered = false;

    const getThemePalette = () => {
        const styles = getComputedStyle(root);
        const isDark = getCurrentTheme() === 'dark';

        return {
            ink: styles.getPropertyValue('--ink').trim() || (isDark ? '#eaf3f1' : '#12343b'),
            muted: styles.getPropertyValue('--muted').trim() || (isDark ? '#9fb2b7' : '#4b5563'),
            grid: isDark ? 'rgba(234, 243, 241, 0.10)' : 'rgba(18, 52, 59, 0.08)',
            gridSoft: isDark ? 'rgba(234, 243, 241, 0.07)' : 'rgba(18, 52, 59, 0.06)'
        };
    };

    const applyChartTheme = (chart) => {
        const palette = getThemePalette();
        chart.options.plugins.legend.labels.color = palette.ink;
        chart.options.scales.x.grid.color = palette.gridSoft;
        chart.options.scales.x.ticks.color = palette.muted;
        chart.options.scales.y.grid.color = palette.grid;
        chart.options.scales.y.ticks.color = palette.muted;
        chart.update('none');
    };

    const scheduleAfterLoad = (callback, delay = 0) => {
        const run = () => window.setTimeout(callback, delay);
        if (document.readyState === 'complete') {
            run();
            return;
        }

        window.addEventListener('load', run, { once: true });
    };

    const loadChartLibrary = () => {
        if (typeof window.Chart !== 'undefined') return Promise.resolve(window.Chart);
        if (chartLibraryPromise) return chartLibraryPromise;

        chartLibraryPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
            script.async = true;
            script.onload = () => resolve(window.Chart);
            script.onerror = reject;
            document.head.appendChild(script);
        });

        return chartLibraryPromise;
    };

    const CHART_HIDDEN_FUELS_KEY = 'fuelDashboardChartHiddenFuels';

    const getStoredHiddenFuels = () => {
        try {
            const raw = localStorage.getItem(CHART_HIDDEN_FUELS_KEY);
            if (!raw) return {};
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const storeHiddenFuels = (state) => {
        try {
            localStorage.setItem(CHART_HIDDEN_FUELS_KEY, JSON.stringify(state));
        } catch (error) {}
    };

    const renderCharts = () => {
        if (chartsRendered || typeof window.Chart === 'undefined') return;
        chartsRendered = true;

        if (recentCtx && recentItems.length > 0) {
            const palette = getThemePalette();

            const fuelStyles = {
                PB95: { border: '#1f8a70', background: 'rgba(31, 138, 112, 0.15)' },
                PB98: { border: '#f4b942', background: 'rgba(244, 185, 66, 0.18)' },
                ON: { border: '#c62828', background: 'rgba(198, 40, 40, 0.14)' }
            };

            const fuelCodes = Object.keys(fuelStyles).filter((fuel) =>
                recentItems.some((item) => item.prices && item.prices[fuel] !== undefined && item.prices[fuel] !== null)
            );

            const storedHiddenFuels = getStoredHiddenFuels();

            window.recentChartInstance = new window.Chart(recentCtx, {
                type: 'line',
                data: {
                    labels: recentItems.map((item) => item.label),
                    datasets: fuelCodes.map((fuel) => ({
                        label: fuel,
                        data: recentItems.map((item) => item.prices?.[fuel] ?? null),
                        borderColor: fuelStyles[fuel].border,
                        backgroundColor: fuelStyles[fuel].background,
                        pointBackgroundColor: fuelStyles[fuel].border,
                        hidden: storedHiddenFuels[fuel] === true,
                        tension: 0.32,
                        spanGaps: true,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        fill: false
                    }))
                },
                options: {
                    animation: false,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 10,
                                color: palette.ink,
                                font: { family: bodyFontFamily, weight: '600' }
                            },
                            onClick: (event, legendItem, legend) => {
                                const chart = legend.chart;
                                window.Chart.defaults.plugins.legend.onClick.call(chart.legend, event, legendItem, legend);

                                const state = {};
                                chart.data.datasets.forEach((dataset, index) => {
                                    state[dataset.label] = !chart.isDatasetVisible(index);
                                });
                                storeHiddenFuels(state);
                            }
                        },
                        tooltip: {
                            callbacks: {
                                title: (contexts) => contexts[0]?.label || '',
                                label: (context) => `${context.dataset.label}: ${formatPrice(context.raw)}`
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { color: palette.gridSoft },
                            ticks: { color: palette.muted, maxRotation: 0, autoSkip: true }
                        },
                        y: {
                            grid: { color: palette.grid },
                            ticks: {
                                color: palette.muted,
                                callback: (value) => `${Number(value).toFixed(2).replace('.', ',')} zł`
                            }
                        }
                    }
                }
            });
        }
    };

    const bootCharts = () => {
        if (!recentCtx || recentItems.length === 0) return;
        loadChartLibrary().then(renderCharts).catch(() => {});
    };

    const dashboardTabs = document.querySelector('.dashboard-tabs');
    const dashboardTabButtons = Array.from(document.querySelectorAll('[data-dashboard-tab]'));
    const dashboardTabPanels = Array.from(document.querySelectorAll('[data-dashboard-panel]'));
    let dashboardTabHoverTarget = null;

    const getStoredDashboardTab = () => {
        try {
            return localStorage.getItem('fuelDashboardActiveTab') || 'prices';
        } catch (error) {
            return 'prices';
        }
    };

    const storeDashboardTab = (tabName) => {
        try {
            localStorage.setItem('fuelDashboardActiveTab', tabName);
        } catch (error) {}
    };

    const getDashboardTabIndicatorTarget = () => (
        dashboardTabHoverTarget
        || dashboardTabButtons.find((button) => button.classList.contains('is-active'))
        || dashboardTabButtons[0]
        || null
    );

    const moveDashboardTabIndicator = (target) => {
        if (!dashboardTabs || !target) return;

        const tabsRect = dashboardTabs.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const left = targetRect.left - tabsRect.left;
        const top = targetRect.top - tabsRect.top;

        dashboardTabs.style.setProperty('--tab-indicator-left', `${left}px`);
        dashboardTabs.style.setProperty('--tab-indicator-top', `${top}px`);
        dashboardTabs.style.setProperty('--tab-indicator-width', `${targetRect.width}px`);
        dashboardTabs.style.setProperty('--tab-indicator-height', `${targetRect.height}px`);
        dashboardTabs.style.setProperty('--tab-indicator-opacity', '1');
        dashboardTabs.dataset.indicatorTone = target.classList.contains('dashboard-tab-alerts') ? 'alerts' : 'default';

        dashboardTabButtons.forEach((button) => {
            button.classList.toggle('is-indicator-target', button === target);
        });
    };

    const syncDashboardTabIndicator = () => {
        window.requestAnimationFrame(() => moveDashboardTabIndicator(getDashboardTabIndicatorTarget()));
    };

    const refreshPromoToggles = () => {
        document.querySelectorAll('.promo-toggle').forEach((toggle) => {
            const body = toggle.previousElementSibling;

            if (!body || !body.classList.contains('promo-collapsible')) {
                return;
            }

            if (!toggle.dataset.bound) {
                toggle.dataset.bound = '1';

                const handler = (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const expanded = body.classList.toggle('is-expanded');
                    toggle.textContent = expanded ? 'Pokaż mniej' : 'Pokaż więcej';
                    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                };

                toggle.addEventListener('click', handler);
                toggle.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                        handler(event);
                    }
                });
            }

            if (body.classList.contains('is-expanded')) {
                toggle.hidden = false;
                return;
            }

            if (body.clientHeight === 0) {
                return;
            }

            toggle.hidden = body.scrollHeight <= body.clientHeight + 2;
        });
    };

    let promoResizeRaf = null;
    window.addEventListener('resize', () => {
        if (promoResizeRaf) {
            window.cancelAnimationFrame(promoResizeRaf);
        }

        promoResizeRaf = window.requestAnimationFrame(() => {
            const panel = document.querySelector('[data-dashboard-panel="promotions"]');

            if (panel && !panel.hidden) {
                refreshPromoToggles();
            }
        });
    });

    const activateDashboardTab = (tabName, shouldStore = true) => {
        const selectedTab = dashboardTabButtons.some((button) => button.dataset.dashboardTab === tabName) ? tabName : 'prices';

        dashboardTabButtons.forEach((button) => {
            const isActive = button.dataset.dashboardTab === selectedTab;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        dashboardTabPanels.forEach((panel) => {
            panel.hidden = panel.dataset.dashboardPanel !== selectedTab;
        });

        if (shouldStore) {
            storeDashboardTab(selectedTab);
        }

        syncDashboardTabIndicator();

        if (selectedTab === 'prices') {
            bootCharts();

            if (window.recentChartInstance) {
                window.recentChartInstance.resize();
                applyChartTheme(window.recentChartInstance);
            }
        }

        if (selectedTab === 'promotions') {
            window.requestAnimationFrame(refreshPromoToggles);
        }
    };

    dashboardTabButtons.forEach((button) => {
        button.addEventListener('mouseenter', () => {
            dashboardTabHoverTarget = button;
            syncDashboardTabIndicator();
        });

        button.addEventListener('focus', () => {
            dashboardTabHoverTarget = button;
            syncDashboardTabIndicator();
        });

        button.addEventListener('blur', () => {
            window.requestAnimationFrame(() => {
                if (!dashboardTabs || !dashboardTabs.contains(document.activeElement)) {
                    dashboardTabHoverTarget = null;
                    syncDashboardTabIndicator();
                }
            });
        });

        button.addEventListener('click', () => {
            dashboardTabHoverTarget = null;
            activateDashboardTab(button.dataset.dashboardTab || 'prices');
        });
    });

    if (dashboardTabs) {
        dashboardTabs.addEventListener('mouseleave', () => {
            dashboardTabHoverTarget = null;
            syncDashboardTabIndicator();
        });
    }

    window.addEventListener('resize', syncDashboardTabIndicator);

    activateDashboardTab(getStoredDashboardTab(), false);

    const tomorrowPriceLoader = document.getElementById('tomorrowPriceLoader');
    const autoRefreshBlockAlert = document.getElementById('autoRefreshBlockAlert');
    let tomorrowPriceLoaderHideTimer = null;
    let tomorrowRetryTimeout = null;
    let isAutoRefreshUpdating = false;

    const autoRefreshBlockedTooltip = 'Trwa ładowanie cen na jutro';

    const clearTomorrowRetryTimers = () => {
        window.clearTimeout(tomorrowRetryTimeout);
        tomorrowRetryTimeout = null;
    };

    const setManualRefreshBlocked = (blocked) => {
        isAutoRefreshUpdating = blocked;

        const button = document.getElementById('manualRefreshButton');

        if (button) {
            button.classList.toggle('is-auto-blocked', blocked);
            button.removeAttribute('title');

            if (blocked) {
                button.setAttribute('aria-disabled', 'true');
                button.setAttribute('aria-label', autoRefreshBlockedTooltip);
                button.setAttribute('data-tooltip', autoRefreshBlockedTooltip);
            } else {
                button.removeAttribute('aria-disabled');
                    button.removeAttribute('aria-label');
                button.removeAttribute('data-tooltip');
            }
        }

        if (autoRefreshBlockAlert) {
            autoRefreshBlockAlert.hidden = true;
        }
    };

    const showTomorrowPriceLoader = () => {
        window.clearTimeout(tomorrowPriceLoaderHideTimer);

        if (tomorrowPriceLoader) {
            const titleElement = tomorrowPriceLoader.querySelector('[data-tomorrow-loader-title]');
            const textElement = tomorrowPriceLoader.querySelector('[data-tomorrow-loader-text]');

            if (titleElement) titleElement.textContent = 'Wykryto ceny na jutro';
            if (textElement) textElement.textContent = 'Trwa wczytywanie najnowszej aktualizacji';

            tomorrowPriceLoader.hidden = false;

            window.requestAnimationFrame(() => {
                tomorrowPriceLoader.classList.add('is-visible');
            });
        }

        const button = document.getElementById('manualRefreshButton');

        if (button) {
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');

            const label = button.querySelector('[data-refresh-label]');
            if (label) label.textContent = 'Ładowanie cen';
        }

        setManualRefreshBlocked(true);
    };

    const hideTomorrowPriceLoader = () => {
        clearTomorrowRetryTimers();

        if (tomorrowPriceLoader) {
            tomorrowPriceLoader.classList.remove('is-visible');

            tomorrowPriceLoaderHideTimer = window.setTimeout(() => {
                tomorrowPriceLoader.hidden = true;
            }, 240);
        }

        const button = document.getElementById('manualRefreshButton');

        if (button) {
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');

            const label = button.querySelector('[data-refresh-label]');
            if (label) label.textContent = 'Odśwież dane';
        }

        setManualRefreshBlocked(false);
    };

    const reloadDashboardAfterAutoRefresh = () => {
        const url = new URL(window.location.href);

        url.searchParams.delete('refreshed');
        url.searchParams.delete('status');
        url.searchParams.set('auto_prices_loaded', '1');
        url.searchParams.set('t', String(Date.now()));

        window.location.replace(url.toString());
    };

    const scheduleTomorrowRetry = (delaySeconds, targetLabel, callback, options = {}) => {
        clearTomorrowRetryTimers();

        const remainingSeconds = Math.max(1, Math.ceil(Number(delaySeconds) || 1));
        const pollIntervalSeconds = Math.max(0, Math.ceil(Number(options.pollIntervalSeconds || 0)));
        const nextCheckSeconds = pollIntervalSeconds > 0
            ? Math.min(remainingSeconds, pollIntervalSeconds)
            : remainingSeconds;

        if (options.showLoader !== false) {
            showTomorrowPriceLoader();
        }

        tomorrowRetryTimeout = window.setTimeout(() => {
            clearTomorrowRetryTimers();
            callback();
        }, nextCheckSeconds * 1000);
    };

    const bootAutoRefresh = () => {
        if (!autoRefreshConfig || !autoRefreshConfig.enabled || !autoRefreshConfig.url) {
            return;
        }

        let attempts = 0;
        const maxAttempts = 12;
        const targetLabel = autoRefreshConfig.targetDateLabel ? ` (${autoRefreshConfig.targetDateLabel})` : '';

        const retryableStatuses = new Set([
            'refresh_busy',
            'target_missing_existing_kept',
            'target_missing_saved',
            'target_missing_save_failed',
            'failed_existing_kept',
            'failed_empty_saved',
            'failed_empty_save_failed'
        ]);

        const retryDelaySeconds = (payload) => {
            const retryAfterSeconds = Number(payload?.retryAfterSeconds || 0);

            if (retryAfterSeconds > 0) {
                return Math.max(5, retryAfterSeconds);
            }

            return attempts <= 2 ? 8 : 20;
        };

        const requestRefresh = () => {
            clearTomorrowRetryTimers();
            attempts += 1;

            showTomorrowPriceLoader();

            fetch(autoRefreshConfig.url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            })
                .then((response) => response.ok ? response.json() : null)
                .then((payload) => {
                    if (!payload) {
                        if (attempts < 3) {
                            scheduleTomorrowRetry(8, targetLabel, requestRefresh);
                            return;
                        }

                        hideTomorrowPriceLoader();
                        return;
                    }

                    const status = String(payload.status || '');

                    if (status === 'auto_throttled') {
                        hideTomorrowPriceLoader();
                        return;
                    }

                    if (payload.reload) {
                        showTomorrowPriceLoader();
                        window.setTimeout(reloadDashboardAfterAutoRefresh, 450);
                        return;
                    }

                    const canRetry = retryableStatuses.has(status) && attempts < maxAttempts;

                    if (canRetry) {
                        const delay = retryDelaySeconds(payload);
                        const shouldProbeInstead = status === 'refresh_busy';

                        scheduleTomorrowRetry(delay, targetLabel, shouldProbeInstead ? probeForUpdate : requestRefresh, {
                            pollIntervalSeconds: shouldProbeInstead ? 4 : 8
                        });
                        return;
                    }

                    hideTomorrowPriceLoader();
                })
                .catch(() => {
                    if (attempts < 3) {
                        scheduleTomorrowRetry(8, targetLabel, requestRefresh);
                        return;
                    }

                    hideTomorrowPriceLoader();
                });
        };

        const probeForUpdate = () => {
            if (autoRefreshConfig.loading) {
                requestRefresh();
                return;
            }

            if (!autoRefreshConfig.probeUrl) {
                hideTomorrowPriceLoader();
                return;
            }

            const scheduleProbeRetry = (payload, fallbackDelay = 6, options = {}) => {
                const delay = Math.max(1, Number(payload?.retryAfterSeconds || fallbackDelay));

                scheduleTomorrowRetry(delay, targetLabel, probeForUpdate, {
                    pollIntervalSeconds: 4,
                    showLoader: options.showLoader === true
                });
            };

            fetch(autoRefreshConfig.probeUrl, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            })
                .then((response) => response.ok ? response.json() : null)
                .then((payload) => {
                    if (!payload) {
                        if (isAutoRefreshUpdating) {
                            scheduleTomorrowRetry(6, targetLabel, probeForUpdate, {
                                pollIntervalSeconds: 4
                            });
                        }

                        return;
                    }

                    const status = String(payload.status || '');

                    if (status === 'auto_throttled') {
                        hideTomorrowPriceLoader();
                        return;
                    }

                    if (payload.reload) {
                        reloadDashboardAfterAutoRefresh();
                        return;
                    }

                    if (payload.updateAvailable && !payload.loading) {
                        requestRefresh();
                        return;
                    }

                    if (status === 'probe_busy') {
                        scheduleProbeRetry(payload, 6, { showLoader: false });
                        return;
                    }

                    if (status === 'refresh_busy') {
                        scheduleProbeRetry(payload, 8, { showLoader: true });
                        return;
                    }

                    if (payload.loading) {
                        scheduleProbeRetry(payload, 8, { showLoader: true });
                        return;
                    }

                    if (status === 'no_update') {
                        hideTomorrowPriceLoader();
                    }
                })
                .catch(() => {
                    if (isAutoRefreshUpdating) {
                        scheduleTomorrowRetry(6, targetLabel, probeForUpdate, {
                            pollIntervalSeconds: 4
                        });
                    }
                });
        };

        probeForUpdate();
    };

    bootAutoRefresh();

    const manualRefreshButton = document.getElementById('manualRefreshButton');

    if (manualRefreshButton) {
        manualRefreshButton.addEventListener('click', (event) => {
            if (isAutoRefreshUpdating || manualRefreshButton.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
                setManualRefreshBlocked(true);
                return;
            }

            manualRefreshButton.classList.add('is-loading');
            manualRefreshButton.setAttribute('aria-busy', 'true');

            const label = manualRefreshButton.querySelector('[data-refresh-label]');
            const sublabel = manualRefreshButton.querySelector('[data-refresh-sublabel]');

            if (label) {
                label.textContent = 'Odświeżanie...';
            }

            if (sublabel) {
                sublabel.textContent = '';
            }
        });
    }

    const manualRefreshAlert = document.getElementById('manualRefreshAlert');

    if (manualRefreshAlert) {
        window.setTimeout(() => {
            manualRefreshAlert.classList.add('is-hiding');
            window.setTimeout(() => manualRefreshAlert.remove(), 350);
        }, 6500);
    }

    (() => {
        const url = new URL(window.location.href);

        if (url.searchParams.get('refreshed') === '1' || url.searchParams.get('auto_prices_loaded') === '1') {
            url.searchParams.delete('refreshed');
            url.searchParams.delete('status');
            url.searchParams.delete('auto_prices_loaded');
            url.searchParams.delete('t');

            const cleanSearch = url.searchParams.toString();
            const cleanUrl = url.pathname + (cleanSearch ? `?${cleanSearch}` : '') + url.hash;

            window.history.replaceState({}, document.title, cleanUrl);
        }
    })();

    scheduleAfterLoad(() => {
        if (!document.querySelector('[data-dashboard-panel="prices"]')?.hidden) {
            bootCharts();
        }
    }, 120);
</script>
</body>
</html>
