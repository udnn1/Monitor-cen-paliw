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

function redirect_after_manual_refresh(string $status, ?string $base = null): void
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

    $location = $redirectPath
        . '?refreshed=1&status=' . rawurlencode($status)
        . '&t=' . time();

    if ($base !== null && $base !== '') {
        $location .= '&base=' . rawurlencode($base);
    }

    header('Location: ' . $location, true, 303);
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

function circlek_promotions_source_url(): string
{
    return 'https://www.circlek.pl/kupony';
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

function orlen_press_promotion_start_date(string $text): ?DateTimeImmutable
{
    $months = [
        'stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4, 'maja' => 5, 'czerwca' => 6,
        'lipca' => 7, 'sierpnia' => 8, 'września' => 9, 'wrzesnia' => 9, 'października' => 10,
        'pazdziernika' => 10, 'listopada' => 11, 'grudnia' => 12,
    ];
    $monthPattern = 'stycznia|lutego|marca|kwietnia|maja|czerwca|lipca|sierpnia|września|wrzesnia|października|pazdziernika|listopada|grudnia';

    if (preg_match('/(\d{1,2})\s*[-–—]\s*\d{1,2}\s+(' . $monthPattern . ')/iu', $text, $match) !== 1
        && preg_match('/\bod\s+(\d{1,2})\s+(' . $monthPattern . ')/iu', $text, $match) !== 1) {
        return null;
    }

    $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
    $month = $months[$monthName] ?? null;
    $day = (int) $match[1];

    if ($month === null || !checkdate($month, $day, (int) (new DateTimeImmutable('today'))->format('Y'))) {
        return null;
    }

    $today = new DateTimeImmutable('today');
    $year = (int) $today->format('Y');
    $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

    if ($candidate > $today->modify('+31 days')) {
        $year -= 1;

        if (checkdate($month, $day, $year)) {
            $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        }
    }

    return $candidate;
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

function station_logo_url(string $network): ?string
{
    return match ($network) {
        'ORLEN' => 'media/logos/orlen.png',
        'BP' => 'media/logos/bp.png',
        'Shell' => 'media/logos/shell.png',
        'MOYA' => 'media/logos/moya.png',
        'MOL' => 'media/logos/mol.png',
        'Circle K' => 'media/logos/circlek.svg',
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

function station_promotion_rank_metrics(array $item): array
{
    if (isset($item['display']['fuels']['benzyna']['g']) && is_numeric($item['display']['fuels']['benzyna']['g'])) {
        $guaranteed = (int) $item['display']['fuels']['benzyna']['g'];
    } elseif (is_numeric($item['discountValueGrPerL'] ?? null)) {
        $guaranteed = (int) $item['discountValueGrPerL'];
    } else {
        $guaranteed = -1;
    }

    return [
        'value' => $guaranteed,
        'penalty' => station_promotion_discount_condition_penalty($item),
    ];
}

function station_promotions_sort(array &$items): void
{
    usort($items, static function (array $left, array $right): int {
        $leftActive = !empty($left['isActive']) ? 1 : 0;
        $rightActive = !empty($right['isActive']) ? 1 : 0;

        if ($leftActive !== $rightActive) {
            return $rightActive <=> $leftActive;
        }

        $leftRank = station_promotion_rank_metrics($left);
        $rightRank = station_promotion_rank_metrics($right);

        if ($leftRank['value'] !== $rightRank['value']) {
            return $rightRank['value'] <=> $leftRank['value'];
        }

        if ($leftRank['penalty'] !== $rightRank['penalty']) {
            return $leftRank['penalty'] <=> $rightRank['penalty'];
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

        $rank = station_promotion_rank_metrics($item);
        $score = $rank['value'];
        $penalty = $rank['penalty'];

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

function bp_official_regulamin_date_range(string $html): array
{
    $result = ['fromIso' => null, 'toIso' => null];

    if (preg_match_all('/href="([^"]*regulamin[^"]*\.pdf)"/i', $html, $hrefs) < 1) {
        return $result;
    }

    $dates = [];

    foreach ($hrefs[1] as $href) {
        $decoded = rawurldecode($href);

        if (stripos($decoded, 'taniej') === false && stripos($decoded, 'paliw') === false) {
            continue;
        }

        if (preg_match_all('/(\d{2})[_.](\d{2})[_.](\d{4})/', $decoded, $found, PREG_SET_ORDER) < 1) {
            continue;
        }

        foreach ($found as $d) {
            $day = (int) $d[1];
            $month = (int) $d[2];
            $year = (int) $d[3];

            if (checkdate($month, $day, $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }

    if ($dates === []) {
        return $result;
    }

    sort($dates);
    $result['fromIso'] = $dates[0];
    $result['toIso'] = end($dates);

    return $result;
}

function build_bp_official_fuel_promotion(string $title, string $url, ?string $detailHtml): array
{
    $description = is_string($detailHtml) && trim($detailHtml) !== ''
        ? bp_official_detail_text($detailHtml)
        : '';

    $dateRange = bp_official_date_range_from_text($title . ' ' . $description);

    if (is_string($detailHtml) && trim($detailHtml) !== '') {
        $reg = bp_official_regulamin_date_range($detailHtml);

        if ($reg['fromIso'] !== null) {
            $fromObj = new DateTimeImmutable($reg['fromIso']);
            $toObj = $reg['toIso'] !== null
                ? new DateTimeImmutable($reg['toIso'])
                : (($dateRange['toIso'] ?? null) !== null ? new DateTimeImmutable($dateRange['toIso']) : null);

            $dateRange['fromIso'] = $fromObj->format('Y-m-d');
            $dateRange['fromLabel'] = $fromObj->format('d.m.Y');

            if ($toObj instanceof DateTimeImmutable) {
                $dateRange['toIso'] = $toObj->format('Y-m-d');
                $dateRange['toLabel'] = $toObj->format('d.m.Y');
                $dateRange['rangeLabel'] = $fromObj->format('d.m.Y') . ' - ' . $toObj->format('d.m.Y');
            }
        }
    }

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
    $body = http_get($url);

    if (is_string($body) && trim($body) !== '') {
        return $body;
    }

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
            'warnings' => ['Nie udało się ustalić adresu komunikatu o promocji paliwowej ORLEN.'],
            'warning' => 'Nie udało się ustalić adresu komunikatu o promocji paliwowej ORLEN.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'orlen_press_no_url',
        ];
    }

    $html = http_get_orlen_vitay($sourceUrl);

    if (!is_string($html) || trim($html) === '') {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => ['Nie udało się pobrać komunikatu o promocji paliwowej ORLEN.'],
            'warning' => 'Nie udało się pobrać komunikatu o promocji paliwowej ORLEN.',
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

    $from = orlen_press_promotion_start_date($text);

    $dateRange = [
        'fromLabel' => $from?->format('d.m.Y'),
        'toLabel' => $to?->format('d.m.Y'),
        'fromIso' => $from instanceof DateTimeImmutable ? $from->format('Y-m-d') : (new DateTimeImmutable('today'))->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'rangeLabel' => ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable)
            ? $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y')
            : ($to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null),
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

function shell_promotions_description(int $count): string
{
    $phrase = match ($count) {
        1 => 'Aktualna promocja',
        2 => 'Dwie aktualne promocje',
        3 => 'Trzy aktualne promocje',
        4 => 'Cztery aktualne promocje',
        default => $count . ' aktualnych promocji',
    };

    return $phrase . ' na paliwo na stacjach Shell:';
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
        shell_promotions_description(count($segments)),
        $summerTeaser['url'] ?? $listingUrl,
        $listingUrl,
        $dateRange
    );

    if ($headlineDiscount !== null) {
        $item['discountLabel'] = $headlineDiscount;
        $item['discountValueGrPerL'] = $headlineValue;
    }

    if (count($segments) === 1) {
        $onlySegment = $segments[0];

        if (is_string($onlySegment['text'] ?? null) && trim($onlySegment['text']) !== '') {
            $item['description'] = $onlySegment['text'];
        }

        if (is_string($onlySegment['url'] ?? null) && trim($onlySegment['url']) !== '') {
            $item['url'] = $onlySegment['url'];
        }
    } else {
        $item['segments'] = $segments;
    }

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

function circlek_parse_validity(string $text): array
{
    if (preg_match('/od\s*(\d{1,2})[.\-](\d{1,2})(?:[.\-](\d{2,4}))?\s*do\s*(\d{1,2})[.\-](\d{1,2})[.\-](\d{2,4})/iu', $text, $m) === 1) {
        $toYear = (int) $m[6];
        if ($toYear < 100) {
            $toYear += 2000;
        }

        $fromYear = ($m[3] ?? '') !== '' ? (int) $m[3] : $toYear;
        if ($fromYear < 100) {
            $fromYear += 2000;
        }

        return [
            'fromIso' => sprintf('%04d-%02d-%02d', $fromYear, (int) $m[2], (int) $m[1]),
            'toIso' => sprintf('%04d-%02d-%02d', $toYear, (int) $m[5], (int) $m[4]),
        ];
    }

    return ['fromIso' => null, 'toIso' => null];
}

function circlek_collect_tiers(string $text): array
{
    $tiers = [];
    $seen = [];

    if (preg_match_all('/(\d{1,3})\s*gr\s*\/?\s*l\s+na\s+((?:paliwa|lpg)[^\d]*?)(?=\s*\d{1,3}\s*gr\s*\/?\s*l|\s*Rabat\b|$)/iu', $text, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $m) {
            $value = (int) $m[1];
            $fuel = trim(rtrim(clean_text($m[2]), " .,-"));

            if ($value <= 0 || $value > 99 || $fuel === '' || isset($seen[$fuel])) {
                continue;
            }

            $seen[$fuel] = true;
            $tiers[] = ['value' => $value, 'fuel' => $fuel];
        }
    }

    return $tiers;
}

function fetch_circlek_fuel_promotions(): array
{
    $fetchedAt = new DateTimeImmutable();
    $url = circlek_promotions_source_url();
    $html = http_get($url);

    if ($html === null || trim($html) === '') {
        return [
            'url' => $url,
            'items' => [],
            'warnings' => ['Nie udalo sie pobrac oficjalnej strony promocji Circle K.'],
            'warning' => 'Nie udalo sie pobrac oficjalnej strony promocji Circle K.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'circlek_failed',
        ];
    }

    $text = implode(' ', html_to_clean_lines($html));
    $tiers = circlek_collect_tiers($text);

    if ($tiers === []) {
        return [
            'url' => $url,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie znaleziono aktualnych promocji paliwowych Circle K.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'circlek_no_fuel_promo',
        ];
    }

    $validity = circlek_parse_validity($text);

    $maxValue = 0;
    foreach ($tiers as $tier) {
        if ($tier['value'] > $maxValue) {
            $maxValue = $tier['value'];
        }
    }

    $tierParts = array_map(
        static fn (array $tier): string => $tier['value'] . ' gr/l na ' . $tier['fuel'],
        $tiers
    );

    $extra = [];
    if (preg_match('/do\s*(\d{1,3})\s*litr/iu', $text, $litres) === 1) {
        $extra[] = 'rabat do ' . (int) $litres[1] . ' l na transakcję';
    }
    $extra[] = 'co tydzień nowy kupon w aplikacji Circle K extra';

    $description = 'Letnie rabaty na paliwo: ' . implode(', ', $tierParts) . '. ' . ucfirst(implode(', ', $extra)) . '.';

    $dateRange = [
        'fromLabel' => null,
        'toLabel' => $validity['toIso'],
        'fromIso' => $validity['fromIso'],
        'toIso' => $validity['toIso'],
        'rangeLabel' => null,
    ];

    $item = build_station_promotion_payload(
        'Circle K',
        'Letnie rabaty',
        $description,
        $url,
        $url,
        $dateRange
    );

    $item['discountLabel'] = 'do ' . $maxValue . ' gr/l';
    $item['discountValueGrPerL'] = $maxValue;
    $item['discountIsUpTo'] = true;
    $item['discountConditionPenalty'] = 2;
    $item['sourceMode'] = 'circlek_official';

    $items = [$item];
    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $url,
        'items' => $items,
        'warnings' => [],
        'warning' => null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'circlek_fuel_promotions',
    ];
}

function moya_promotions_source_url(): string
{
    return 'https://moyastacja.pl/aktualnosci.html';
}

function moya_known_fuel_promotion_url(): string
{
    return 'https://moyastacja.pl/aktualnosci/letnie-oszczednosci-na-stacjach-moya-nawet-do-40-gr-l-rabatu-z-aplikacja-super-moya.html';
}

function moya_absolute_url(string $url): string
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
        return 'https://moyastacja.pl' . $url;
    }

    return 'https://moyastacja.pl/' . ltrim($url, '/');
}

function moya_fuel_promotion_url(): ?string
{
    $html = http_get(moya_promotions_source_url());

    if (is_string($html) && trim($html) !== ''
        && preg_match_all('~href="(/aktualnosci/[^"]+\.html)"~i', $html, $matches) > 0) {
        foreach ($matches[1] as $href) {
            $slug = strtolower($href);

            $isOpening = strpos($slug, 'otwiera') !== false
                || strpos($slug, 'otwarcie') !== false
                || strpos($slug, 'nowa-stacj') !== false
                || strpos($slug, 'nowy-punkt') !== false
                || strpos($slug, 'na-start') !== false;

            if ($isOpening) {
                continue;
            }

            $isFuelPromo = strpos($slug, 'rabat') !== false
                || strpos($slug, 'gr-l') !== false
                || strpos($slug, 'oszczed') !== false
                || strpos($slug, 'kupon') !== false
                || strpos($slug, 'super-moya') !== false;

            if ($isFuelPromo) {
                return moya_absolute_url($href);
            }
        }
    }

    return moya_known_fuel_promotion_url();
}

function moya_genitive_months(): array
{
    return [
        'stycznia' => 1, 'lutego' => 2, 'marca' => 3, 'kwietnia' => 4, 'maja' => 5, 'czerwca' => 6,
        'lipca' => 7, 'sierpnia' => 8, 'września' => 9, 'wrzesnia' => 9, 'października' => 10,
        'pazdziernika' => 10, 'listopada' => 11, 'grudnia' => 12,
    ];
}

function moya_resolve_year(int $month, int $day): int
{
    $today = new DateTimeImmutable('today');
    $year = (int) $today->format('Y');

    if (!checkdate($month, $day, $year)) {
        return $year;
    }

    $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

    if ($candidate < $today->modify('-6 months')) {
        $year += 1;
    }

    return $year;
}

function moya_promotion_start_date(string $text): ?DateTimeImmutable
{
    $months = moya_genitive_months();
    $monthPattern = implode('|', array_keys($months));

    if (preg_match('/\bod\s+(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(\d{4}))?/iu', $text, $match) !== 1
        && preg_match('/(\d{1,2})\s*[-–—]\s*\d{1,2}\s+(' . $monthPattern . ')/iu', $text, $match) !== 1) {
        return null;
    }

    $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
    $month = $months[$monthName] ?? null;
    $day = (int) $match[1];

    if ($month === null || !checkdate($month, $day, 2000)) {
        return null;
    }

    $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : moya_resolve_year($month, $day);

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

function moya_promotion_end_date(string $text): ?DateTimeImmutable
{
    $months = moya_genitive_months();
    $monthPattern = implode('|', array_keys($months));

    if (preg_match('/do\s+końca\s+(' . $monthPattern . ')(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
        $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[1], 'UTF-8') : strtolower($match[1]);
        $month = $months[$monthName] ?? null;

        if ($month !== null) {
            $year = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : moya_resolve_year($month, 1);
            $firstDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

            return $firstDay->modify('last day of this month');
        }
    }

    if (preg_match('/do\s+(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(\d{4}))?/iu', $text, $match) === 1) {
        $monthName = function_exists('mb_strtolower') ? mb_strtolower($match[2], 'UTF-8') : strtolower($match[2]);
        $month = $months[$monthName] ?? null;
        $day = (int) $match[1];

        if ($month !== null && checkdate($month, $day, 2000)) {
            $year = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : moya_resolve_year($month, $day);

            if (checkdate($month, $day, $year)) {
                return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            }
        }
    }

    return null;
}

function fetch_moya_fuel_promotions(): array
{
    $fetchedAt = new DateTimeImmutable();
    $sourceUrl = moya_fuel_promotion_url();

    if ($sourceUrl === null || $sourceUrl === '') {
        return [
            'url' => moya_promotions_source_url(),
            'items' => [],
            'warnings' => ['Nie udało się ustalić adresu promocji paliwowej MOYA.'],
            'warning' => 'Nie udało się ustalić adresu promocji paliwowej MOYA.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'moya_no_url',
        ];
    }

    $html = http_get($sourceUrl);

    if (!is_string($html) || trim($html) === '') {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => ['Nie udało się pobrać komunikatu o promocji paliwowej MOYA.'],
            'warning' => 'Nie udało się pobrać komunikatu o promocji paliwowej MOYA.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'moya_failed',
        ];
    }

    $lines = html_to_clean_lines($html);
    $text = clean_text(implode(' ', $lines));
    $text = preg_replace('/(\d+)\s*grosz(?:y|e|a)?\s+na\s+litrze/iu', '$1 gr/l', $text) ?? $text;

    $fuelSignals = 0;
    foreach (['pb95', 'pb98', 'olej napędowy', 'olej napedowy', 'benzyn', 'paliw', 'tankuj'] as $needle) {
        if (text_contains_ci($text, $needle)) {
            $fuelSignals++;
        }
    }

    $grValues = [];
    if (preg_match_all('/([0-9]{1,3})\s*gr\s*\/\s*l/iu', $text, $grMatches) > 0) {
        $grValues = array_values(array_unique(array_map('intval', $grMatches[1])));
        sort($grValues);
    }

    $hasPromotion = text_contains_ci($text, 'promocj') || text_contains_ci($text, 'rabat');

    if (!$hasPromotion || $fuelSignals < 2 || $grValues === []) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie znaleziono aktualnej promocji paliwowej MOYA.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'moya_no_fuel_promo',
        ];
    }

    $maxGr = (int) max($grValues);
    $baseGr = $maxGr;

    if (preg_match('/podstawow\w*\s+rabat\w*\s+w\s+wysoko\w+\s+(\d{1,3})/iu', $text, $baseMatch) === 1) {
        $baseGr = (int) $baseMatch[1];
    }

    $from = moya_promotion_start_date($text);
    $to = moya_promotion_end_date($text);

    if ($to instanceof DateTimeImmutable && $to->format('Y-m-d') < (new DateTimeImmutable('today'))->format('Y-m-d')) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Promocja paliwowa MOYA już się zakończyła.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'moya_expired',
        ];
    }

    $dateRange = [
        'fromLabel' => $from?->format('d.m.Y'),
        'toLabel' => $to?->format('d.m.Y'),
        'fromIso' => $from instanceof DateTimeImmutable ? $from->format('Y-m-d') : (new DateTimeImmutable('today'))->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'rangeLabel' => ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable)
            ? $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y')
            : ($to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null),
    ];

    $description = sprintf(
        '%d gr/l na benzyny PB95 i PB98 oraz olej napędowy ON i ON MOYA Power z cotygodniowym kuponem w aplikacji Super MOYA, do %d gr/l przy jednoczesnym zakupie produktów sklepowych lub Caffe MOYA za min. 10 zł. Rabat naliczany do 50 litrów na transakcję.',
        $baseGr,
        $maxGr
    );

    $item = build_station_promotion_payload(
        'MOYA',
        'Letnie oszczędności z aplikacją Super MOYA',
        $description,
        $sourceUrl,
        moya_promotions_source_url(),
        $dateRange
    );
    $item['discountLabel'] = 'do ' . $maxGr . ' gr/l';
    $item['discountValueGrPerL'] = $maxGr;
    $item['discountIsUpTo'] = true;
    $item['sourceMode'] = 'moya_fuel_promotions';

    $items = [$item];
    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $sourceUrl,
        'items' => $items,
        'warnings' => [],
        'warning' => null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'moya_fuel_promotions',
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

function mol_promotions_source_url(): string
{
    return 'https://molpolska.pl/pl/kierowcy/aktualne-promocje';
}

function browser_scrape(): array
{
    static $cachedResult = null;

    if ($cachedResult !== null) {
        return $cachedResult;
    }

    $cachedResult = ['mol' => [], 'averages' => null];
    $script = __DIR__ . '/browser_scrape.py';

    if (is_file($script) && function_exists('shell_exec')) {
        $cmd = 'HOME=' . escapeshellarg('/var/lib/paliwo-browser')
            . ' timeout 120 python3 ' . escapeshellarg($script) . ' 2>/dev/null';
        $out = shell_exec($cmd);

        if (is_string($out) && trim($out) !== '') {
            $decoded = json_decode(trim($out), true);

            if (is_array($decoded)) {
                if (is_array($decoded['mol'] ?? null)) {
                    $cachedResult['mol'] = $decoded['mol'];
                }
                if (is_array($decoded['averages'] ?? null)) {
                    $cachedResult['averages'] = $decoded['averages'];
                }
            }
        }
    }

    return $cachedResult;
}

function mol_scrape_promotions(): array
{
    return browser_scrape()['mol'];
}

function mol_promo_gr_values(string $text): array
{
    $lpg = null;
    $nonLpg = [];

    foreach (preg_split('/[.,;]/u', $text) ?: [$text] as $clause) {
        $isLpg = stripos($clause, 'lpg') !== false || stripos($clause, 'autogas') !== false;

        if (preg_match_all('/(\d{1,3})\s*gr\s*\/\s*l/iu', $clause, $m) > 0) {
            foreach ($m[1] as $rawVal) {
                $val = (int) $rawVal;

                if ($isLpg) {
                    if ($lpg === null) {
                        $lpg = $val;
                    }
                } else {
                    $nonLpg[] = $val;
                }
            }
        }
    }

    return [
        'lpg' => $lpg,
        'max' => $nonLpg !== [] ? (int) max($nonLpg) : 0,
        'base' => $nonLpg !== [] ? (int) min($nonLpg) : 0,
    ];
}

function mol_essence_description(string $text): string
{
    $text = clean_text($text);

    if ($text === '') {
        return '';
    }

    $sentences = preg_split('/(?<=[.!])\s+/u', $text) ?: [$text];
    $keep = [];

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);

        if ($sentence === '') {
            continue;
        }

        if (preg_match('/^(wakacje zacznij|do zobaczenia|zyskaj dostęp|pobierz |dołącz |nie pozwól|sprawdź szczegóły|odblokuj ofert|promocja (trwa|obowiązuje)|promocja jest ważna)/iu', $sentence) === 1) {
            continue;
        }

        if (preg_match('~gr\s*/\s*l|litr|tankow|aplikacj|kupon|\bzł\b|weekend|dni tygodnia|dowoln\w+\s+dni|min\.|\d{1,2}\.\d{2}~iu', $sentence) === 1) {
            $keep[] = $sentence;
        }
    }

    $result = trim(implode(' ', $keep));

    return $result !== '' ? $result : $text;
}

function fetch_mol_fuel_promotions(): array
{
    $fetchedAt = new DateTimeImmutable();
    $sourceUrl = mol_promotions_source_url();
    $promos = mol_scrape_promotions();

    if ($promos === []) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => ['Nie udało się pobrać promocji paliwowych MOL.'],
            'warning' => 'Nie udało się pobrać promocji paliwowych MOL.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'mol_failed',
        ];
    }

    $fuel = [];
    foreach ($promos as $promo) {
        if (!is_array($promo)) {
            continue;
        }

        $text = (string) ($promo['text'] ?? '');

        if (preg_match('/\d{1,3}\s*gr\s*\/\s*l/iu', $text) !== 1) {
            continue;
        }

        if (preg_match('/paliw|benzyn|diesel|olej|lpg|evo|tankow/iu', $text) !== 1) {
            continue;
        }

        $fuel[] = $promo;
    }

    if ($fuel === []) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie znaleziono aktualnej promocji paliwowej MOL.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'mol_no_fuel_promo',
        ];
    }

    usort($fuel, static function (array $a, array $b): int {
        $textA = (string) ($a['text'] ?? '');
        $textB = (string) ($b['text'] ?? '');
        $nicheA = preg_match('/magenta|wybranych\s+stacj/iu', $textA) === 1 ? 1 : 0;
        $nicheB = preg_match('/magenta|wybranych\s+stacj/iu', $textB) === 1 ? 1 : 0;

        if ($nicheA !== $nicheB) {
            return $nicheA <=> $nicheB;
        }

        return mol_promo_gr_values($textB)['max'] <=> mol_promo_gr_values($textA)['max'];
    });

    $best = $fuel[0];
    $rawText = (string) ($best['text'] ?? '');
    $title = (string) ($best['title'] ?? 'Promocja paliwowa MOL');

    $description = preg_replace('/\s*(Okres obowiązywania promocji|Data rozpocz\w+ promocji).*$/iu', '', $rawText) ?? $rawText;
    $description = trim(preg_replace('/^' . preg_quote($title, '/') . '\s*/u', '', $description) ?? $description);
    $description = mol_essence_description($description);
    $description = preg_replace('/\s+(?=[−–—-]?\s*\d{1,3}\s*gr\s*\/\s*l\s*[–—-]\s*LPG)/iu', '. ', $description) ?? $description;
    $description = preg_replace('/([–—-]\s*LPG)\s+(?=\p{Lu})/u', '$1. ', $description) ?? $description;

    if ($description !== '' && function_exists('mb_substr') && mb_strlen($description, 'UTF-8') > 500) {
        $description = rtrim(mb_substr($description, 0, 497, 'UTF-8')) . '...';
    }

    $gr = mol_promo_gr_values($rawText);
    $maxGr = $gr['max'];

    if ($maxGr <= 0) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Nie udało się odczytać rabatu promocji paliwowej MOL.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'mol_no_discount',
        ];
    }

    $from = is_string($best['fromIso'] ?? null) && $best['fromIso'] !== '' ? new DateTimeImmutable($best['fromIso']) : null;
    $to = is_string($best['toIso'] ?? null) && $best['toIso'] !== '' ? new DateTimeImmutable($best['toIso']) : null;

    if ($to instanceof DateTimeImmutable && $to->format('Y-m-d') < $fetchedAt->format('Y-m-d')) {
        return [
            'url' => $sourceUrl,
            'items' => [],
            'warnings' => [],
            'warning' => 'Promocja paliwowa MOL już się zakończyła.',
            'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
            'sourceMode' => 'mol_expired',
        ];
    }

    $dateRange = [
        'fromLabel' => $from?->format('d.m.Y'),
        'toLabel' => $to?->format('d.m.Y'),
        'fromIso' => $from instanceof DateTimeImmutable ? $from->format('Y-m-d') : (new DateTimeImmutable('today'))->format('Y-m-d'),
        'toIso' => $to?->format('Y-m-d'),
        'rangeLabel' => ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable)
            ? $from->format('d.m.Y') . ' - ' . $to->format('d.m.Y')
            : ($to instanceof DateTimeImmutable ? 'do ' . $to->format('d.m.Y') : null),
    ];

    $detailUrl = is_string($best['url'] ?? null) && $best['url'] !== '' ? (string) $best['url'] : $sourceUrl;

    $item = build_station_promotion_payload(
        'MOL',
        $title,
        $description,
        $detailUrl,
        $sourceUrl,
        $dateRange
    );
    $item['discountLabel'] = $maxGr . ' gr/l';
    $item['discountValueGrPerL'] = $maxGr;

    if ($gr['base'] === $maxGr) {
        $item['discountIsUpTo'] = false;
        $item['discountConditionPenalty'] = 0;
    }

    $item['sourceMode'] = 'mol_fuel_promotions';

    $items = [$item];
    station_promotions_sort($items);
    mark_top_station_promotions($items);

    return [
        'url' => $sourceUrl,
        'items' => $items,
        'warnings' => [],
        'warning' => null,
        'fetchedAtLabel' => $fetchedAt->format('d.m.Y H:i'),
        'sourceMode' => 'mol_fuel_promotions',
    ];
}

function promo_display_fallback(string $network): array
{
    $fuelMap = [
        'BP' => ['benzyna' => [35, 35], 'diesel' => [35, 35], 'lpg' => [15, 15]],
        'Circle K' => ['benzyna' => [30, 35], 'diesel' => [30, 35], 'lpg' => [10, 10]],
        'Shell' => ['benzyna' => [20, 35], 'diesel' => [20, 35], 'lpg' => [15, 15]],
        'ORLEN' => ['benzyna' => [20, 35], 'diesel' => [20, 35]],
        'MOYA' => ['benzyna' => [30, 40], 'diesel' => [30, 40]],
        'MOL' => ['benzyna' => [36, 36], 'diesel' => [36, 36], 'lpg' => [19, 19]],
    ];
    $tierMap = [
        'BP' => ['baseCond' => 'na paliwa, z aplikacją BPme', 'maxCond' => null, 'when' => null],
        'Circle K' => ['baseCond' => 'na paliwa miles (standardowe)', 'maxCond' => 'na paliwa miles+ (premium)', 'when' => null],
        'Shell' => ['baseCond' => 'standardowo, bez dodatkowych zakupów', 'maxCond' => 'przy zakupie dowolnego produktu Shell (np. Café, myjnia)', 'when' => null],
        'ORLEN' => ['baseCond' => 'z kuponem w aplikacji ORLEN VITAY', 'maxCond' => 'przy zakupach pozapaliwowych min. 5 zł', 'when' => 'tylko w weekendy (pt.–ndz.)'],
        'MOYA' => ['baseCond' => 'z kuponem w aplikacji Super MOYA', 'maxCond' => 'przy zakupie w sklepie / Caffe MOYA min. 10 zł', 'when' => null],
        'MOL' => ['baseCond' => 'z kuponem w aplikacji MOL Move', 'maxCond' => null, 'when' => null],
    ];

    $fuels = [];
    foreach (($fuelMap[$network] ?? []) as $fuel => $spec) {
        $fuels[$fuel] = ['g' => (int) $spec[0], 'v' => (int) $spec[1], 'upto' => $spec[1] > $spec[0]];
    }

    return [
        'fuels' => $fuels,
        'tier' => $tierMap[$network] ?? ['baseCond' => '', 'maxCond' => null, 'when' => null],
    ];
}

function promo_extract_conditions(string $network, string $text, string $low, int $base, int $max): array
{
    $fallback = promo_display_fallback($network)['tier'];

    $minZl = null;
    if (preg_match('/min\.?\s*(\d+(?:[.,]\d+)?)\s*zł/iu', $text, $m) === 1) {
        $minZl = str_replace('.', ',', $m[1]);
    }

    $app = null;
    if (stripos($text, 'BPme') !== false) {
        $app = 'z aplikacją BPme';
    } elseif (stripos($text, 'VITAY') !== false) {
        $app = 'z kuponem w aplikacji ORLEN VITAY';
    } elseif (stripos($low, 'super moya') !== false) {
        $app = 'z kuponem w aplikacji Super MOYA';
    } elseif (stripos($text, 'ClubSmart') !== false) {
        $app = 'w aplikacji Shell ClubSmart';
    } elseif (stripos($low, 'mol move') !== false) {
        $app = 'z kuponem w aplikacji MOL Move';
    } elseif (stripos($text, 'Circle K extra') !== false || stripos($low, 'miles') !== false) {
        $app = 'z kuponem w aplikacji Circle K';
    }

    $when = null;
    if (preg_match('/\bw\s+weekend|obowiązuje\s+w\s+weekend|weekendow/iu', $text) === 1) {
        $when = 'tylko w weekendy (pt.–ndz.)';
    }

    $baseCond = $app ?? $fallback['baseCond'];
    $maxCond = null;

    if ($max > $base) {
        if (stripos($low, 'miles+') !== false) {
            $baseCond = 'na paliwa miles (standardowe)';
            $maxCond = 'na paliwa miles+ (premium)';
        } elseif ($minZl !== null) {
            $maxCond = 'przy zakupach pozapaliwowych min. ' . $minZl . ' zł';
        } elseif (stripos($low, 'produkt') !== false) {
            if (stripos($low, 'standardowo') !== false || stripos($low, 'bez dodatkowych') !== false) {
                $baseCond = 'standardowo, bez dodatkowych zakupów';
            }
            $maxCond = 'przy zakupie dowolnego produktu' . ($network === 'Shell' ? ' Shell' : '');
        } else {
            $maxCond = $fallback['maxCond'];
        }
    }

    if (trim((string) $baseCond) === '') {
        $baseCond = $fallback['baseCond'];
    }

    return ['baseCond' => $baseCond, 'maxCond' => $maxCond, 'when' => $when ?? $fallback['when']];
}

function promo_extract_display(array $item): array
{
    $network = (string) ($item['network'] ?? '');
    $text = clean_text(((string) ($item['title'] ?? '')) . ' ' . ((string) ($item['description'] ?? '')));
    $low = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    $max = is_numeric($item['discountValueGrPerL'] ?? null) ? (int) $item['discountValueGrPerL'] : null;
    $upto = !empty($item['discountIsUpTo']);

    $clauses = preg_split('/[.,;]/u', $text) ?: [$text];
    $lpg = null;
    $nonLpgVals = [];

    foreach ($clauses as $clause) {
        $isLpg = stripos($clause, 'lpg') !== false || stripos($clause, 'autogas') !== false;

        if (preg_match_all('/(\d{1,3})\s*gr\s*\/\s*l/iu', $clause, $mm) > 0) {
            foreach ($mm[1] as $rawVal) {
                $val = (int) $rawVal;

                if ($isLpg) {
                    if ($lpg === null) {
                        $lpg = $val;
                    }
                } else {
                    $nonLpgVals[] = $val;
                }
            }
        }
    }

    $nonLpgVals = array_values(array_unique($nonLpgVals));
    sort($nonLpgVals);

    if ($max === null && $nonLpgVals !== []) {
        $max = (int) max($nonLpgVals);
    }

    if ($max === null) {
        return promo_display_fallback($network);
    }

    $tiered = $upto || preg_match('/standardowo|bez\s+dodatkowych/iu', $text) === 1;
    $base = $max;

    if ($tiered) {
        $below = array_values(array_filter($nonLpgVals, static fn (int $v): bool => $v < $max));

        if ($below !== []) {
            $base = (int) min($below);
        }
    }

    $fuels = [
        'benzyna' => ['g' => $base, 'v' => $max, 'upto' => $max > $base],
        'diesel' => ['g' => $base, 'v' => $max, 'upto' => $max > $base],
    ];

    if ($lpg !== null) {
        $fuels['lpg'] = ['g' => $lpg, 'v' => $lpg, 'upto' => false];
    } elseif (isset(promo_display_fallback($network)['fuels']['lpg'])) {
        $fuels['lpg'] = promo_display_fallback($network)['fuels']['lpg'];
    }

    return [
        'fuels' => $fuels,
        'tier' => promo_extract_conditions($network, $text, $low, $base, $max),
    ];
}

function fetch_station_promotions(array $previousItems = []): array
{
    $bpOfficialPromotions = fetch_bp_official_fuel_promotions();
    $orlenVitayPromotions = fetch_orlen_vitay_fuel_promotions();
    $shellOfficialPromotions = fetch_shell_fuel_promotions();
    $circlekPromotions = fetch_circlek_fuel_promotions();
    $moyaPromotions = fetch_moya_fuel_promotions();
    $molPromotions = fetch_mol_fuel_promotions();

    $items = [];
    $freshNetworks = [];

    foreach ([$bpOfficialPromotions, $orlenVitayPromotions, $shellOfficialPromotions, $circlekPromotions, $moyaPromotions, $molPromotions] as $source) {
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

    foreach ($items as &$displayItem) {
        if (is_array($displayItem)) {
            $displayItem['display'] = promo_extract_display($displayItem);
        }
    }
    unset($displayItem);

    station_promotions_sort($items);
    mark_top_station_promotions($items);

    $presentNetworks = [];
    foreach ($items as $presentItem) {
        if (is_array($presentItem) && !empty($presentItem['network'])) {
            $presentNetworks[(string) $presentItem['network']] = true;
        }
    }

    $warningSources = [
        'BP' => $bpOfficialPromotions['warnings'] ?? [],
        'ORLEN' => $orlenVitayPromotions['warnings'] ?? [],
        'Shell' => $shellOfficialPromotions['warnings'] ?? [],
        'Circle K' => $circlekPromotions['warnings'] ?? [],
        'MOYA' => $moyaPromotions['warnings'] ?? [],
        'MOL' => $molPromotions['warnings'] ?? [],
    ];

    $warnings = [];
    foreach ($warningSources as $warningNetwork => $networkWarnings) {
        if (isset($presentNetworks[$warningNetwork]) || !is_array($networkWarnings)) {
            continue;
        }

        foreach ($networkWarnings as $networkWarning) {
            $warnings[] = $networkWarning;
        }
    }

    $warnings = array_values(array_unique($warnings));

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







function fuel_averages_cache_path(): string
{
    return cache_dir() . '/fuel-averages.json';
}

function fetch_paliwomapa_averages(): ?array
{
    $key = 'sb_publishable_HeTnHlo6wWxGZKk02pYO8w_UsYG0PuP';
    $base = 'https://hcxxwweqkkjspytgyool.supabase.co/rest/v1/app_docs'
        . '?select=data&collection_name=eq.prices&parent_path=is.null';

    $sums = ['pb95' => 0.0, 'pb98' => 0.0, 'on' => 0.0, 'lpg' => 0.0];
    $counts = ['pb95' => 0, 'pb98' => 0, 'on' => 0, 'lpg' => 0];
    $total = 0;

    for ($offset = 0; $offset < 20000; $offset += 1000) {
        $raw = http_get($base, [
            'apikey: ' . $key,
            'Authorization: Bearer ' . $key,
            'Range: ' . $offset . '-' . ($offset + 999),
            'Accept: application/json',
        ]);

        if (!is_string($raw) || trim($raw) === '') {
            break;
        }

        $rows = json_decode($raw, true);

        if (!is_array($rows) || $rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $data = is_array($row) ? ($row['data'] ?? null) : null;

            if (!is_array($data)) {
                continue;
            }

            $total++;

            foreach (['pb95', 'pb98', 'on', 'lpg'] as $fuel) {
                if (isset($data[$fuel]) && is_numeric($data[$fuel])) {
                    $sums[$fuel] += (float) $data[$fuel];
                    $counts[$fuel]++;
                }
            }
        }

        if (count($rows) < 1000) {
            break;
        }
    }

    if ($counts['pb95'] === 0 || $counts['on'] === 0) {
        return null;
    }

    $avg = static fn (string $fuel): ?float => $counts[$fuel] > 0 ? round($sums[$fuel] / $counts[$fuel], 2) : null;

    return [
        'benzyna' => $avg('pb95'),
        'pb98' => $avg('pb98'),
        'diesel' => $avg('on'),
        'lpg' => $avg('lpg'),
        'stations' => $total,
        'date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'fetchedAt' => time(),
        'source' => 'paliwomapa.pl',
    ];
}

function fetch_fuel_averages(): ?array
{
    $path = fuel_averages_cache_path();
    $cached = read_json_array_file($path);

    $fresh = fetch_paliwomapa_averages();

    if ($fresh !== null) {
        write_json_array_file($path, $fresh);
        fuel_averages_history_append($fresh);
        return $fresh;
    }

    return is_array($cached) && $cached !== [] ? $cached : null;
}

function fuel_averages_history_path(): string
{
    return cache_dir() . '/fuel-averages-history.json';
}

function fuel_averages_history(): array
{
    $history = read_json_array_file(fuel_averages_history_path());

    return is_array($history) ? $history : [];
}

function fuel_averages_history_append(array $fresh): void
{
    if (empty($fresh['date']) || !is_string($fresh['date'])) {
        return;
    }

    $date = $fresh['date'];
    $history = fuel_averages_history();
    $history = array_values(array_filter(
        $history,
        static fn ($entry): bool => is_array($entry) && (($entry['date'] ?? '') !== $date)
    ));

    $history[] = [
        'date' => $date,
        'benzyna' => $fresh['benzyna'] ?? null,
        'pb98' => $fresh['pb98'] ?? null,
        'diesel' => $fresh['diesel'] ?? null,
        'lpg' => $fresh['lpg'] ?? null,
    ];

    usort($history, static fn ($a, $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

    if (count($history) > 365) {
        $history = array_slice($history, -365);
    }

    write_json_array_file(fuel_averages_history_path(), $history);
}

function build_dashboard_payload(array $previousSnapshot = []): array
{
    $previousPromotionItems = is_array($previousSnapshot['stationPromotions']['items'] ?? null)
        ? $previousSnapshot['stationPromotions']['items']
        : [];

    $stationPromotions = fetch_station_promotions($previousPromotionItems);
    $fuelAverages = fetch_fuel_averages();

    $generatedAt = new DateTimeImmutable();

    return [
        'warnings' => [],
        'stationPromotions' => $stationPromotions,
        'fuelAverages' => $fuelAverages,
        'fuelAveragesHistory' => fuel_averages_history(),
        'lastDataUpdateLabel' => $generatedAt->format('d.m.Y H:i'),
        'lastDataUpdateDateLabel' => $generatedAt->format('d.m.Y'),
        'lastDataUpdateTimeLabel' => $generatedAt->format('H:i'),
        'generatedAtIso' => $generatedAt->format(DateTimeInterface::ATOM),
    ];
}

function empty_dashboard_snapshot(): array
{
    return [
        'warnings' => [],
        'stationPromotions' => [
            'url' => station_promotions_source_url(),
            'items' => [],
            'warnings' => [],
            'warning' => 'Brak zapisanego snapshotu promocji. Uruchom odświeżanie, żeby pobrać dane.',
            'fetchedAtLabel' => 'brak danych',
            'sourceMode' => 'empty_snapshot',
        ],
        'fuelAverages' => null,
        'fuelAveragesHistory' => [],
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

    $hasPromotions = !empty($freshSnapshot['stationPromotions']['items']);

    if ($hasPromotions) {
        $saveOk = save_dashboard_snapshot($freshSnapshot);

        if ($saveOk) {
            $snapshot = $freshSnapshot;
            $refreshStatus = 'fresh_saved';
        } else {
            $existingSnapshot = load_dashboard_snapshot();
            $snapshot = is_array($existingSnapshot) ? $existingSnapshot : $freshSnapshot;
            $refreshStatus = 'save_failed';
        }
    } else {
        $existingSnapshot = load_dashboard_snapshot();

        if (is_array($existingSnapshot) && !empty($existingSnapshot['stationPromotions']['items'])) {
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
        'hasTargetDate' => true,
    ];
}







if (PHP_SAPI !== 'cli' && isset($_GET['refresh_status']) && $_GET['refresh_status'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $statusSnapshot = load_dashboard_snapshot();
    echo json_encode([
        'generatedAtIso' => is_array($statusSnapshot) ? ($statusSnapshot['generatedAtIso'] ?? null) : null,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$isCronRefresh = cli_has_flag('--refresh-cache');
$isUrlRefresh = PHP_SAPI !== 'cli' && isset($_GET['refresh']) && $_GET['refresh'] === '1';
$isRefresh = $isCronRefresh || $isUrlRefresh;

if ($isUrlRefresh) {
    $manualRefreshCooldown = manual_refresh_cooldown_status();

    if (!empty($manualRefreshCooldown['active'])) {
        redirect_after_manual_refresh('refresh_cooldown');
    }

    $manualRefreshCooldown = manual_refresh_cooldown_claim();

    if (empty($manualRefreshCooldown['allowed'])) {
        redirect_after_manual_refresh('refresh_cooldown');
    }

    $preRefreshSnapshot = load_dashboard_snapshot();
    $preRefreshBase = is_array($preRefreshSnapshot) ? (string) ($preRefreshSnapshot['generatedAtIso'] ?? '') : '';

    if (function_exists('shell_exec')) {
        $phpBinary = is_file('/usr/bin/php') ? '/usr/bin/php' : 'php';
        $backgroundCmd = 'setsid ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg(__FILE__)
            . ' --refresh-cache >/dev/null 2>&1 &';
        @shell_exec($backgroundCmd);
    }

    redirect_after_manual_refresh('refresh_started', $preRefreshBase);
}

if ($isCronRefresh) {
    $refreshLock = acquire_dashboard_refresh_lock(true);

    if (!is_resource($refreshLock)) {
        $existingSnapshot = load_dashboard_snapshot();
        $snapshot = is_array($existingSnapshot) ? $existingSnapshot : empty_dashboard_snapshot();
        $refreshStatus = 'refresh_busy_existing_kept';
    } else {
        try {
            $refreshResult = refresh_dashboard_snapshot_for_target(null);
            $snapshot = is_array($refreshResult['snapshot'] ?? null)
                ? $refreshResult['snapshot']
                : empty_dashboard_snapshot();
            $refreshStatus = (string) ($refreshResult['status'] ?? 'unknown');
        } finally {
            release_dashboard_refresh_lock($refreshLock);
        }
    }
} else {
    $snapshot = load_dashboard_snapshot();

    if (!is_array($snapshot)) {
        $snapshot = empty_dashboard_snapshot();
    }
}

$manualRefreshUrl = './?refresh=1';
$warnings = [];
$fuelAveragesHistory = fuel_averages_history();

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

$promoConditions = [
    'BP' => 'bezwarunkowo (z aplikacją BPme)',
    'Shell' => 'dowolny produkt Shell',
    'ORLEN' => 'min. 5 zł zakupów',
    'Circle K' => 'na miles',
    'MOYA' => 'aplikacja Super MOYA',
    'MOL' => 'aplikacja MOL Move',
];
$promoKnownNetworks = ['BP', 'Circle K', 'Shell', 'ORLEN', 'MOYA', 'MOL'];
$promoData = [];
foreach (($stationPromotions['items'] ?? []) as $promoItem) {
    if (!is_array($promoItem)) {
        continue;
    }

    $promoNet = (string) ($promoItem['network'] ?? '');

    if (!in_array($promoNet, $promoKnownNetworks, true)) {
        continue;
    }

    $promoDisplay = isset($promoItem['display']) && is_array($promoItem['display'])
        ? $promoItem['display']
        : promo_extract_display($promoItem);

    if (empty($promoDisplay['fuels'])) {
        continue;
    }

    $promoFuels = [];
    foreach ($promoDisplay['fuels'] as $promoFuel => $promoSpec) {
        $promoFuels[$promoFuel] = [
            'v' => (int) ($promoSpec['v'] ?? 0),
            'g' => (int) ($promoSpec['g'] ?? ($promoSpec['v'] ?? 0)),
            'upto' => !empty($promoSpec['upto']),
        ];
    }

    $promoTier = $promoDisplay['tier'] ?? ['baseCond' => $promoConditions[$promoNet] ?? '', 'maxCond' => null, 'when' => null];

    $promoData[] = [
        'net' => $promoNet,
        'logo' => station_logo_url($promoNet) ?? '',
        'cond' => $promoConditions[$promoNet] ?? '',
        'disc' => [
            'baseCond' => (string) ($promoTier['baseCond'] ?? ''),
            'maxCond' => $promoTier['maxCond'] ?? null,
            'when' => $promoTier['when'] ?? null,
        ],
        'top' => !empty($promoItem['isTopPromotion']),
        'fromIso' => $promoItem['fromIso'] ?? null,
        'toIso' => $promoItem['toIso'] ?? null,
        'desc' => normalize_display_dashes((string) ($promoItem['description'] ?? '')),
        'url' => (string) ($promoItem['url'] ?? station_promotions_source_url()),
        'fuels' => $promoFuels,
    ];
}

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

    if ($status === 'refresh_started') {
        $manualRefreshMessage = 'Odświeżanie uruchomione w tle — pobieram wszystkie promocje i średnie ceny. Strona odświeży się automatycznie po zakończeniu aktualizacji (zwykle ok. 40 s).';
        $manualRefreshClass = 'alert-info';
    } elseif ($status === 'fresh_saved') {
        $manualRefreshMessage = 'Promocje zostały ręcznie odświeżone. Aktualny czas zapisu: ' . $lastDataUpdateLabel . '.';
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
        $manualRefreshMessage = 'Promocje zostały pobrane, ale nie udało się zapisać snapshotu. Sprawdź uprawnienia katalogu .paliwa-cache.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_existing_kept') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych promocji. Zostawiono poprzedni zapisany snapshot.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_empty_saved') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych promocji, ale zapisano pusty snapshot diagnostyczny.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'failed_empty_save_failed') {
        $manualRefreshMessage = 'Nie udało się pobrać świeżych promocji ani zapisać snapshotu. Sprawdź uprawnienia katalogu .paliwa-cache.';
        $manualRefreshClass = 'alert-warning';
    } elseif ($status === 'refresh_busy_existing_kept') {
        $manualRefreshMessage = 'Odświeżanie już trwa. Ponowne odświeżenie będzie możliwe po zakończeniu bieżącej próby.';
        $manualRefreshClass = 'alert-info';
    } else {
        $manualRefreshMessage = 'Próba ręcznego odświeżenia została zakończona.';
        $manualRefreshClass = 'alert-info';
    }
}

if ($isRefresh) {
    $sitemapXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
        . '  <url>' . "\n"
        . '    <loc>https://paliwo.pomo.st/</loc>' . "\n"
        . '    <lastmod>' . (new DateTimeImmutable())->format('Y-m-d') . '</lastmod>' . "\n"
        . '    <changefreq>daily</changefreq>' . "\n"
        . '    <priority>1.0</priority>' . "\n"
        . '  </url>' . "\n"
        . '</urlset>' . "\n";
    @file_put_contents(__DIR__ . '/sitemap.xml', $sitemapXml);
}

if ($isCronRefresh) {
    echo 'Cache refreshed at ' . $lastDataUpdateLabel . PHP_EOL;
    echo 'Station promotions: ' . count($stationPromotions['items'] ?? []) . PHP_EOL;
    echo 'Station promotions source mode: ' . (string) ($stationPromotions['sourceMode'] ?? 'unknown') . PHP_EOL;
    echo 'Status: ' . ($refreshStatus ?? 'unknown') . PHP_EOL;
    echo 'Snapshot: ' . dashboard_snapshot_path() . PHP_EOL;
    exit(0);
}

$seoBaseUrl = 'https://paliwo.pomo.st';
$seoCanonical = $seoBaseUrl . '/';
$seoImage = $seoBaseUrl . '/media/og-cover.svg';
$seoStations = 'BP, Orlen, Shell, Circle K, MOYA i MOL';

$seoTopOffer = null;
foreach ($promoData as $seoPromo) {
    if (!empty($seoPromo['top'])) {
        $seoTopOffer = $seoPromo;
        break;
    }
}

$seoBestSentence = '';
if (is_array($seoTopOffer)) {
    $seoBestGr = (int) ($seoTopOffer['fuels']['benzyna']['g'] ?? 0);
    if ($seoBestGr > 0) {
        $seoBestSentence = ' Najlepsza okazja dziś: ' . $seoTopOffer['net'] . ' −' . $seoBestGr . ' gr/l na benzynę i diesel.';
    }
}

$seoDateIso = null;
if (!empty($snapshot['generatedAtIso']) && is_string($snapshot['generatedAtIso'])) {
    $seoDateIso = $snapshot['generatedAtIso'];
} else {
    $seoDateIso = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
}

$seoTitle = 'Aktualne promocje paliwowe – rabaty na paliwo: BP, Orlen, Shell, Circle K, MOYA, MOL';
$seoDescription = 'Codziennie aktualizowane promocje paliwowe i rabaty na benzynę, diesel oraz LPG na stacjach ' . $seoStations
    . ', z automatycznym wykrywaniem najlepszej okazji na tańsze tankowanie.' . $seoBestSentence;
$seoKeywords = 'promocje paliwowe, rabaty na paliwo, tańsze tankowanie, rabat na benzynę, rabat na diesel, rabat LPG, ceny paliw, kupony paliwowe, BP, Orlen, Shell, Circle K, MOYA, MOL';

$seoLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $seoCanonical . '#website',
            'url' => $seoCanonical,
            'name' => 'Monitor promocji paliwowych',
            'description' => $seoDescription,
            'inLanguage' => 'pl-PL',
            'publisher' => ['@id' => $seoCanonical . '#org'],
        ],
        [
            '@type' => 'Organization',
            '@id' => $seoCanonical . '#org',
            'name' => 'Monitor promocji paliwowych',
            'url' => $seoCanonical,
            'logo' => $seoImage,
        ],
        [
            '@type' => 'WebPage',
            '@id' => $seoCanonical . '#webpage',
            'url' => $seoCanonical,
            'name' => $seoTitle,
            'description' => $seoDescription,
            'inLanguage' => 'pl-PL',
            'isPartOf' => ['@id' => $seoCanonical . '#website'],
            'dateModified' => $seoDateIso,
            'primaryImageOfPage' => $seoImage,
        ],
    ],
];

$seoListItems = [];
foreach ($promoData as $seoIndex => $seoPromo) {
    if (!is_array($seoPromo)) {
        continue;
    }

    $seoNet = (string) ($seoPromo['net'] ?? '');
    $seoGr = (int) ($seoPromo['fuels']['benzyna']['g'] ?? 0);
    $seoUpto = !empty($seoPromo['fuels']['benzyna']['upto']);
    $seoMax = (int) ($seoPromo['fuels']['benzyna']['v'] ?? $seoGr);

    $seoOfferName = 'Rabat na paliwo ' . $seoNet . ' − ' . $seoGr . ' gr/l'
        . ($seoUpto && $seoMax > $seoGr ? ' (do ' . $seoMax . ' gr/l)' : '');

    $seoOffer = [
        '@type' => 'Offer',
        'name' => $seoOfferName,
        'description' => (string) ($seoPromo['desc'] ?? ''),
        'category' => 'Paliwo',
        'url' => (string) ($seoPromo['url'] ?? $seoCanonical),
        'availability' => 'https://schema.org/InStock',
        'seller' => ['@type' => 'Organization', 'name' => $seoNet],
    ];

    if (!empty($seoPromo['fromIso'])) {
        $seoOffer['validFrom'] = (string) $seoPromo['fromIso'];
    }
    if (!empty($seoPromo['toIso'])) {
        $seoOffer['validThrough'] = (string) $seoPromo['toIso'];
    }

    $seoListItems[] = [
        '@type' => 'ListItem',
        'position' => $seoIndex + 1,
        'item' => $seoOffer,
    ];
}

if ($seoListItems !== []) {
    $seoLd['@graph'][] = [
        '@type' => 'ItemList',
        'name' => 'Aktualne promocje paliwowe na stacjach',
        'itemListElement' => $seoListItems,
    ];
}

$seoLdJson = json_encode($seoLd, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($seoTitle) ?></title>
    <meta name="description" content="<?= e($seoDescription) ?>">
    <meta name="keywords" content="<?= e($seoKeywords) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="author" content="Monitor promocji paliwowych">
    <meta name="theme-color" content="#1f8a70">
    <link rel="canonical" href="<?= e($seoCanonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Monitor promocji paliwowych">
    <meta property="og:locale" content="pl_PL">
    <meta property="og:title" content="<?= e($seoTitle) ?>">
    <meta property="og:description" content="<?= e($seoDescription) ?>">
    <meta property="og:url" content="<?= e($seoCanonical) ?>">
    <meta property="og:image" content="<?= e($seoImage) ?>">
    <meta property="og:image:type" content="image/svg+xml">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($seoTitle) ?>">
    <meta name="twitter:description" content="<?= e($seoDescription) ?>">
    <meta name="twitter:image" content="<?= e($seoImage) ?>">
    <script type="application/ld+json"><?= $seoLdJson ?></script>
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
            --green-deep: #0c5b38;
            --gold: #f4b942;
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
            position: relative;
            background:
                radial-gradient(200px 200px at 100% 0, rgba(255, 255, 255, 0.16), transparent 70%),
                linear-gradient(135deg, rgba(18, 52, 59, 0.97), rgba(31, 138, 112, 0.93)),
                linear-gradient(160deg, rgba(244, 185, 66, 0.12), transparent 42%);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18), 0 10px 28px var(--shadow);
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
        .theme-fab { position: fixed; top: 0.85rem; right: 1rem; z-index: 1000; }
        .theme-fab .theme-switch { min-width: 0; padding: 0.4rem; gap: 0; }
        .theme-fab .theme-switch-text { display: none; }

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

        .section-title { font-family: "Bahnschrift", "Aptos Display", "Segoe UI", sans-serif; letter-spacing: -0.03em; }
        .promo-empty { padding: 1.25rem; border-radius: 20px; border: 1px dashed var(--line); color: var(--muted); background: rgba(102, 112, 133, 0.06); }
        .source-link { color: var(--ink); text-decoration: none; border-bottom: 1px dashed rgba(18, 52, 59, 0.35); }
        :root[data-theme="dark"] .source-link { border-bottom-color: rgba(234, 243, 241, 0.35); }
        .source-link:hover { color: var(--mint); border-bottom-color: rgba(31, 138, 112, 0.55); }
        .page-footer { margin-top: auto; }

        @keyframes tomorrow-loader-spin {
            to {
                transform: rotate(360deg);
            }
        }
        @media (min-width: 768px) {
            .col-md-6 { width: 50%; }
        }

        @media (min-width: 992px) {
            .col-lg-4 { width: 33.333333%; }
            .col-lg-8 { width: 66.666667%; }
            .p-lg-5 { padding: 3rem; }
            .px-lg-4 { padding-inline: 1.5rem; }
            .py-lg-5 { padding-block: 3rem; }
            .g-lg-4 { --gutter-x: 1.5rem; --gutter-y: 1.5rem; }
        }

        @media (max-width: 767.98px) {
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
            .dashboard-theme-toggle .theme-switch { width: 100%; min-width: 0; justify-content: center; }
        }

        .panel { background:var(--surface); border:1px solid var(--line); border-radius:20px; padding:1.4rem 1.5rem; box-shadow:0 12px 30px var(--shadow); }
        .chart-wrap { position:relative; height:320px; }
        @media (max-width:640px){ .chart-wrap { height:260px; } }
        .chart-note { margin:.7rem 0 0; font-size:.78rem; color:var(--muted); }
        .empty { color:var(--muted); padding:1rem; }

        .spot { display:grid; grid-template-columns:1.3fr 1fr; gap:1.1rem; margin-bottom:2rem; }
        .hero { position:relative; border-radius:22px; padding:1.5rem 2rem; color:#fff; overflow:hidden; background:linear-gradient(120deg,#0c5b38,#1f8a70 52%,#35b592); box-shadow:inset 0 0 0 1px rgba(53,181,146,.55), 0 22px 50px rgba(12,91,56,.4); display:flex; align-items:center; justify-content:space-between; gap:1.5rem 2.5rem; flex-wrap:wrap; border:1px solid transparent; transition:background .25s ease, box-shadow .25s ease, border-color .25s ease; }
        :root[data-theme="dark"] .hero { background:linear-gradient(120deg,#06281a,#0c5b38 55%,#177a5f); box-shadow:0 22px 50px rgba(0,0,0,.5); border-color:rgba(53,181,146,.28); }
        .hero::after { content:"★"; position:absolute; right:2%; top:50%; transform:translateY(-50%); font-size:12rem; opacity:.1; line-height:0; }
        .hero-id { display:flex; align-items:center; gap:1.1rem; position:relative; z-index:1; }
        .hero-logo { width:64px; height:64px; flex:0 0 auto; border-radius:15px; background:rgba(255,255,255,.94); display:grid; place-items:center; }
        .hero-logo img { width:76%; height:76%; object-fit:contain; }
        .hero-kick { font-size:.74rem; text-transform:uppercase; letter-spacing:.12em; font-weight:800; opacity:.9; }
        .hero-net { font-size:1.55rem; font-weight:900; letter-spacing:-.02em; line-height:1.05; margin-top:.15rem; }
        .hero-cond { font-weight:600; opacity:.92; font-size:.9rem; margin-top:.1rem; max-width:15rem; }
        .hero-big { text-align:center; position:relative; z-index:1; }
        .hero-big .n { font-size:3.6rem; font-weight:900; letter-spacing:-.04em; line-height:1; }
        .hero-big .nu { font-size:1rem; font-weight:800; opacity:.82; margin-left:.25rem; letter-spacing:0; }
        .hero-big small { display:block; font-size:.82rem; font-weight:700; opacity:.85; margin-top:.25rem; letter-spacing:.01em; }
        .hero-foot { display:flex; flex-direction:row; flex-wrap:wrap; align-items:flex-start; gap:.5rem; position:relative; z-index:1; }
        .hero-chip { background:rgba(255,255,255,.16); border:1px solid rgba(255,255,255,.14); border-radius:14px; padding:.4rem .85rem; font-size:.84rem; font-weight:700; white-space:normal; max-width:15rem; line-height:1.3; }
        .spot-side { display:grid; grid-template-columns:1fr 1fr; gap:.8rem; align-content:start; }
        .mini { background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:.85rem 1rem; box-shadow:0 8px 20px var(--shadow); display:flex; flex-direction:column; gap:.2rem; }
        .mini-label { font-size:.72rem; color:var(--muted); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
        .mini-value { font-size:1.4rem; font-weight:900; letter-spacing:-.02em; }
        .mini-sub { font-size:.78rem; color:var(--muted); font-weight:700; }

        .promo-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 350px), 1fr)); gap:1.25rem; align-items:start; }
        .promo-item { position:relative; border-radius:20px; overflow:hidden; background:var(--surface); border:1px solid var(--line); box-shadow:0 1px 0 rgba(255,255,255,.6) inset, 0 1px 2px rgba(18,52,59,.06), 0 18px 40px -18px var(--shadow); transition:transform .2s cubic-bezier(.2,.7,.3,1), box-shadow .2s, border-color .2s; }
        :root[data-theme="dark"] .promo-item { box-shadow:0 1px 0 rgba(255,255,255,.04) inset, 0 20px 44px -20px rgba(0,0,0,.6); }
        .promo-item:hover { transform:translateY(-3px); border-color:rgba(31,138,112,.3); box-shadow:0 1px 0 rgba(255,255,255,.6) inset, 0 26px 50px -20px var(--shadow); }
        .promo-item.top { border-color:rgba(53,181,146,.55); }
        .promo-item.top::after { content:""; position:absolute; inset:0; border-radius:20px; pointer-events:none; box-shadow:0 0 0 1px rgba(53,181,146,.4) inset; }
        .pi-head { display:flex; align-items:flex-start; justify-content:space-between; gap:.8rem; padding:1rem 1.1rem .85rem; }
        .pi-id { display:flex; align-items:center; gap:.85rem; }
        .pi-logo { width:52px; height:52px; flex:0 0 auto; display:grid; place-items:center; border-radius:13px; background:#fff; border:1px solid var(--line); box-shadow:0 4px 10px rgba(18,52,59,.08); }
        :root[data-theme="dark"] .pi-logo { background:#f4f7f6; }
        .pi-logo img { width:74%; height:74%; object-fit:contain; }
        .pi-name { font-weight:850; font-size:1.15rem; letter-spacing:-.01em; line-height:1.1; }
        .pi-sub { display:flex; align-items:center; gap:.4rem; margin-top:.25rem; font-size:.82rem; color:var(--muted); font-weight:600; }
        .pi-dot { width:7px; height:7px; border-radius:50%; background:var(--green); box-shadow:0 0 0 3px rgba(31,138,112,.18); }
        .pi-disc { width:100%; box-sizing:border-box; text-align:center; padding:.45rem .7rem; border-radius:12px; background:linear-gradient(160deg, rgba(31,138,112,.12), rgba(31,138,112,.04)); border:1px solid rgba(31,138,112,.20); }
        .promo-item.top .pi-disc { background:linear-gradient(160deg, rgba(31,138,112,.20), rgba(31,138,112,.06)); border-color:rgba(31,138,112,.35); }
        .pi-disc .num { font-size:1.6rem; font-weight:900; letter-spacing:-.04em; line-height:1; color:var(--green-deep); }
        .pi-disc .num .pfx { font-size:1.25rem; font-weight:800; opacity:.9; margin-right:.08rem; }
        .pi-disc .num .nu { font-size:.72rem; font-weight:800; opacity:.72; margin-left:.18rem; letter-spacing:0; }
        :root[data-theme="dark"] .pi-disc .num { color:#8ee0b4; }
        .promo-item.top .pi-disc .num { color:var(--green-deep); }
        :root[data-theme="dark"] .promo-item.top .pi-disc .num { color:#8ee0b4; }
        .pi-disc .unit { font-size:.62rem; font-weight:700; color:var(--muted); letter-spacing:.02em; margin-top:.12rem; }
        .pi-rabaty { display:flex; flex-direction:column; align-items:stretch; gap:.4rem; width:124px; flex:0 0 auto; }
        .pi-lpg { width:100%; box-sizing:border-box; display:flex; align-items:center; justify-content:center; text-align:center; gap:.25rem; font-size:.78rem; font-weight:800; color:var(--green-deep); background:rgba(31,138,112,.10); border:1px solid rgba(31,138,112,.18); border-radius:12px; padding:.4rem .5rem; }
        :root[data-theme="dark"] .pi-lpg { color:#8ee0b4; }
        .divider { height:1px; background:linear-gradient(90deg, transparent, var(--line) 12%, var(--line) 88%, transparent); }
        .pi-meta { display:flex; flex-direction:column; gap:.7rem; padding:.9rem 1.1rem; }
        .pi-cell + .pi-cell { border-top:1px solid var(--line); padding-top:.7rem; }
        .pi-cell .lbl { font-size:.62rem; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); font-weight:800; display:block; margin-bottom:.3rem; }
        .pi-cell .val { font-weight:800; font-size:.92rem; }
        .pi-max { margin-top:.2rem; font-size:.68rem; font-weight:800; color:var(--muted); letter-spacing:.01em; }
        .val-tiers { display:flex; flex-direction:column; gap:.28rem; }
        .val-tiers .tline { font-size:.82rem; font-weight:700; color:var(--muted); line-height:1.4; }
        .val-tiers .tline b { color:var(--ink); font-weight:900; margin-right:.15rem; }
        .pi-ribbon { display:flex; align-items:center; justify-content:center; gap:.4rem; background:linear-gradient(135deg,#e3131b,#b00d14); color:#fff; font-weight:900; font-size:.74rem; text-transform:uppercase; letter-spacing:.06em; padding:.42rem .8rem; text-align:center; }
        .pi-ribbon-top { background:linear-gradient(120deg,#0c5b38,#1f8a70 52%,#35b592); color:#fff; }
        :root[data-theme="dark"] .pi-ribbon { background:linear-gradient(135deg,#b3121a,#7d0a10); color:#ffe3e3; }
        :root[data-theme="dark"] .pi-ribbon-top { background:linear-gradient(120deg,#06281a,#0c5b38 55%,#177a5f); color:#e9fff7; }
        .prog { position:relative; height:6px; margin-top:.45rem; background:rgba(120,130,140,.16); border-radius:999px; overflow:hidden; }
        .prog > div { position:absolute; inset:0 auto 0 0; border-radius:999px; background:linear-gradient(90deg,var(--green),#35b592); }
        .prog.soon > div { background:linear-gradient(90deg,#e8873a,#e3131b); }
        .days { font-size:.78rem; color:var(--muted); font-weight:700; margin-top:.35rem; }
        .days.soon { color:#c62828; }
        .save-controls { margin:0 0 1.25rem; }
        .save-toggle { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
        .save-toggle-lbl { font-size:.85rem; font-weight:700; color:var(--muted); }
        .save-btn { border:1px solid var(--line); background:var(--surface); color:var(--ink); font-weight:800; font-size:.82rem; padding:.4rem .8rem; border-radius:999px; cursor:pointer; transition:background .15s ease, border-color .15s ease, color .15s ease; }
        .save-btn:hover { border-color:rgba(31,138,112,.35); }
        .save-btn.active { background:linear-gradient(120deg,#0c5b38,#1f8a70); color:#fff; border-color:transparent; }
        .avg-note { margin:.6rem 0 0; font-size:.78rem; color:var(--muted); line-height:1.5; }
        .avg-note .source-link { font-weight:700; }
        .save-fuel { font-size:.6rem; font-weight:800; text-transform:none; letter-spacing:0; color:var(--green-deep); background:rgba(31,138,112,.12); padding:.1rem .4rem; border-radius:999px; margin-left:.3rem; }
        :root[data-theme="dark"] .save-fuel { color:#8ee0b4; }
        .save-lines .rv { display:inline-flex; gap:.55rem; align-items:baseline; }
        .save-lines .rv .cost { color:var(--muted); font-weight:700; }
        .save-price { display:block; margin-top:.35rem; font-size:.76rem; color:var(--muted); }
        .save-price b { color:var(--ink); font-weight:800; }
        .save-lines { display:flex; flex-direction:column; gap:.2rem; }
        .save-lines span { font-size:.82rem; color:var(--muted); white-space:nowrap; display:flex; justify-content:space-between; gap:1rem; }
        .save-lines span b { color:var(--ink); font-weight:800; }
        .pi-desc { padding:.9rem 1.1rem 1rem; background:rgba(120,130,140,.045); border-top:1px solid var(--line); }
        .pi-desc .lbl { font-size:.62rem; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); font-weight:800; display:block; margin-bottom:.35rem; }
        .pi-desc p { margin:0; color:var(--ink); font-size:.85rem; line-height:1.55; opacity:.92; }
        .pi-desc a { display:inline-flex; align-items:center; gap:.3rem; margin-top:.55rem; color:var(--green); font-weight:800; font-size:.82rem; text-decoration:none; }
        .pi-desc a:hover { gap:.5rem; }

        .tl { position:relative; }
        .tl-head { position:relative; height:1.2rem; margin-left:114px; margin-right:150px; }
        .tl-today-flag { position:absolute; transform:translateX(-50%); background:var(--gold); color:#3a2600; font-size:.68rem; font-weight:800; padding:.1rem .4rem; border-radius:6px; white-space:nowrap; }
        .tl-row { display:grid; grid-template-columns:104px 1fr 150px; align-items:center; gap:1rem; margin:.45rem 0; }
        .tl-name { font-weight:800; display:flex; align-items:center; gap:.45rem; font-size:.92rem; }
        .tl-name img { width:22px; height:22px; object-fit:contain; }
        .tl-lane { position:relative; height:18px; background:rgba(120,130,140,.10); border-radius:999px; }
        .tl-marker { position:absolute; top:-3px; bottom:-3px; width:2px; background:var(--gold); z-index:2; }
        .tl-bar { position:absolute; top:0; bottom:0; border-radius:999px; background:linear-gradient(90deg,rgba(31,138,112,.9),rgba(53,181,146,.9)); cursor:help; }
        .tl-bar.soon { background:linear-gradient(90deg,#e8873a,#e3131b); }
        .tl-tip { position:absolute; bottom:calc(100% + 9px); left:50%; transform:translateX(-50%); background:var(--ink); color:var(--surface); padding:.45rem .65rem; border-radius:9px; font-size:.74rem; font-weight:700; line-height:1.35; white-space:nowrap; text-align:left; opacity:0; pointer-events:none; transition:opacity .15s; box-shadow:0 10px 26px var(--shadow); z-index:6; }
        .tl-tip::after { content:""; position:absolute; top:100%; left:50%; transform:translateX(-50%); border:6px solid transparent; border-top-color:var(--ink); }
        .tl-tip b { color:var(--gold); }
        .tl-bar:hover .tl-tip, .tl-bar:focus .tl-tip { opacity:1; }
        .tl-end { font-size:.8rem; color:var(--muted); text-align:right; }
        .tl-end b { color:var(--ink); }

        @media (max-width:900px) {
            .spot{grid-template-columns:1fr;}
            .tl-head{display:none;}
            .tl-row{grid-template-columns:80px 1fr;} .tl-end{grid-column:2;text-align:left;}
        }
        @media (max-width:640px) {
            .hero{flex-direction:column; align-items:stretch; text-align:left; gap:1.1rem; padding:1.4rem 1.3rem;}
            .hero::after{display:none;}
            .hero-id{flex-direction:row; align-items:center; text-align:left; gap:.85rem;}
            .hero-big{text-align:left; display:flex; align-items:baseline; gap:.6rem;}
            .hero-big small{margin-top:0;}
            .hero-foot{flex-direction:row; flex-wrap:wrap; justify-content:flex-start; gap:.5rem;}
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
</head>
<body>
<div class="theme-fab">
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
<div class="shell">
    <main class="container-xxl page-main px-3 px-lg-4 py-4 py-lg-5 position-relative">
        <section class="card-surface hero-panel p-4 p-lg-5 mb-4">
            <div class="row g-4 align-items-start position-relative">
                <div class="col-lg-8">
                    <h1 class="hero-title font-display fw-bold display-5 mb-3">Monitor promocji paliwowych</h1>
                    <p class="hero-copy mb-0">
                        Codziennie aktualizowane <strong>promocje paliwowe i rabaty na paliwo</strong>
                        ze stacji <strong>BP, Circle K, Shell, ORLEN, MOYA i MOL</strong> —
                        z automatycznym wykrywaniem najlepszej okazji na tańsze tankowanie
                        benzyny, oleju napędowego i LPG.
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

        <div class="promotions-panel">
            <?php if (!empty($stationPromotions['warning'])): ?>
                <div class="promo-empty mb-4"><?= e((string) $stationPromotions['warning']) ?></div>
            <?php endif; ?>
            <?php if (!empty($stationPromotions['warnings']) && is_array($stationPromotions['warnings'])): ?>
                <div class="promo-empty mb-4"><?= e(implode(' ', $stationPromotions['warnings'])) ?></div>
            <?php endif; ?>

            <div id="spot" class="spot"></div>

            <h2 class="section-title h1 mb-3">Aktualne promocje paliwowe</h2>

            <div class="save-controls">
                <div class="save-toggle" role="group" aria-label="Paliwo dla szacowanej oszczędności">
                    <span class="save-toggle-lbl">Szacowana oszczędność dla:</span>
                    <button type="button" class="save-btn" data-fuel="benzyna">Benzyna (PB95)</button>
                    <button type="button" class="save-btn" data-fuel="diesel">Diesel (ON)</button>
                </div>
                <?php
                $fa = is_array($snapshot['fuelAverages'] ?? null) ? $snapshot['fuelAverages'] : null;
                if ($fa !== null && isset($fa['benzyna'], $fa['diesel'])):
                    $faPrice = static fn ($v) => is_numeric($v) ? number_format((float) $v, 2, ',', '') : '—';
                    $faDate = '';
                    if (!empty($fa['date']) && ($ts = strtotime((string) $fa['date'])) !== false) {
                        $faDate = date('d.m.Y', $ts);
                    }
                ?>
                    <p class="avg-note">
                        Średnie ceny w Polsce: PB95 <?= e($faPrice($fa['benzyna'])) ?> zł ·
                        ON <?= e($faPrice($fa['diesel'])) ?> zł<?php if (isset($fa['lpg']) && is_numeric($fa['lpg'])): ?> ·
                        LPG <?= e($faPrice($fa['lpg'])) ?> zł<?php endif; ?><?php if (!empty($fa['stations'])): ?>
                        (<?= e((string) (int) $fa['stations']) ?> stacji)<?php endif; ?><?php if ($faDate !== ''): ?> ·
                        aktualizacja <?= e($faDate) ?><?php endif; ?>.
                    </p>
                <?php endif; ?>
            </div>

            <div id="cmpBody" class="promo-list">
                <?php if ($promoData !== []): ?>
                    <?php foreach ($promoData as $seoCard):
                        $cardNet = (string) ($seoCard['net'] ?? '');
                        $cardGr = (int) ($seoCard['fuels']['benzyna']['g'] ?? 0);
                        $cardUpto = !empty($seoCard['fuels']['benzyna']['upto']);
                        $cardMax = (int) ($seoCard['fuels']['benzyna']['v'] ?? $cardGr);
                        $cardLpg = isset($seoCard['fuels']['lpg']['v']) ? (int) $seoCard['fuels']['lpg']['v'] : null;
                        $cardBase = (string) ($seoCard['disc']['baseCond'] ?? '');
                        $cardMaxCond = $seoCard['disc']['maxCond'] ?? null;
                        $cardWhen = $seoCard['disc']['when'] ?? null;
                    ?>
                        <article class="promo-item<?= !empty($seoCard['top']) ? ' top' : '' ?>">
                            <?php if (!empty($seoCard['top'])): ?><div class="pi-ribbon pi-ribbon-top">★ TOP okazja</div><?php endif; ?>
                            <?php if ($cardWhen): ?><div class="pi-ribbon">⏱ <?= e((string) $cardWhen) ?></div><?php endif; ?>
                            <div class="pi-meta">
                                <h3 class="pi-name"><?= e($cardNet) ?> — rabat na paliwo −<?= e((string) $cardGr) ?> gr/l<?= $cardUpto && $cardMax > $cardGr ? ' (do −' . e((string) $cardMax) . ' gr/l)' : '' ?></h3>
                                <p class="val">Benzyna i olej napędowy: <strong>−<?= e((string) $cardGr) ?> gr/l</strong> <?= e($cardBase) ?><?php if ($cardUpto && $cardMax > $cardGr && $cardMaxCond): ?>, do <strong>−<?= e((string) $cardMax) ?> gr/l</strong> <?= e((string) $cardMaxCond) ?><?php endif; ?><?php if ($cardLpg !== null): ?>. LPG: −<?= e((string) $cardLpg) ?> gr/l<?php endif; ?>.<?php if ($cardWhen): ?> Rabat <?= e((string) $cardWhen) ?>.<?php endif; ?></p>
                                <?php if (!empty($seoCard['toIso'])): ?><p class="days">Ważne do <time datetime="<?= e((string) $seoCard['toIso']) ?>"><?= e(date('d.m.Y', strtotime((string) $seoCard['toIso']))) ?></time>.</p><?php endif; ?>
                                <?php if (!empty($seoCard['desc'])): ?><p class="pi-desc-text"><?= e((string) $seoCard['desc']) ?></p><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="promo-empty">Brak zapisanych promocji do pokazania. Kliknij „Odśwież dane”, żeby pobrać najnowszy snapshot.</div>
                <?php endif; ?>
            </div>

            <?php if ($fuelAveragesHistory !== []): ?>
                <h2 class="section-title h3 mt-5 mb-3">Średnie ceny paliw w Polsce (ostatni miesiąc)</h2>
                <div class="panel">
                    <div class="chart-wrap"><canvas id="avgChart"></canvas></div>
                    <p class="chart-note">Średnie ceny detaliczne (PB95, PB98, ON, LPG) z ostatniego miesiąca. Źródło: <a class="source-link" href="https://paliwomapa.pl/" target="_blank" rel="noreferrer">paliwomapa.pl</a>. Wykres uzupełnia się o kolejny punkt przy każdej aktualizacji danych.</p>
                </div>
            <?php endif; ?>

            <h2 class="section-title h3 mt-5 mb-3">Oś ważności</h2>
            <div class="panel"><div id="tl" class="tl"></div></div>
        </div>

        <footer class="page-footer py-4 text-secondary small">
            Źródła:
            <a class="source-link" href="<?= e(bp_official_promotions_source_url()) ?>" target="_blank" rel="noreferrer">BP</a>,
            <a class="source-link" href="<?= e(circlek_promotions_source_url()) ?>" target="_blank" rel="noreferrer">Circle K</a>,
            <a class="source-link" href="<?= e(shell_promotions_source_url()) ?>" target="_blank" rel="noreferrer">Shell</a>,
            <a class="source-link" href="<?= e(orlen_vitay_promotions_source_url()) ?>" target="_blank" rel="noreferrer">ORLEN VITAY</a>,
            <a class="source-link" href="<?= e(moya_promotions_source_url()) ?>" target="_blank" rel="noreferrer">MOYA</a>,
            <a class="source-link" href="<?= e(mol_promotions_source_url()) ?>" target="_blank" rel="noreferrer">MOL</a>,
            <a class="source-link" href="https://paliwomapa.pl/" target="_blank" rel="noreferrer">paliwomapa.pl</a> (średnie ceny).
            <br>
            Kod źródłowy projektu:
            <a class="source-link" href="https://github.com/udnn1/monitor-promocji-paliwowych" target="_blank" rel="noreferrer">github.com/udnn1/monitor-promocji-paliwowych</a>.
            <br>
            Projekt hostowany na:
            <a class="source-link" href="https://biedahosting.pl/" target="_blank" rel="noreferrer">biedahosting.pl</a>.
        </footer>
    </main>
</div>

<script>
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
    };

    setThemeControlState();

    if (themeToggle) {
        themeToggle.addEventListener('change', () => applyTheme(themeToggle.checked ? 'dark' : 'light'));
    }

    const manualRefreshButton = document.getElementById('manualRefreshButton');

    if (manualRefreshButton) {
        manualRefreshButton.addEventListener('click', (event) => {
            if (manualRefreshButton.classList.contains('is-loading')) {
                event.preventDefault();
                return;
            }

            const target = manualRefreshButton.getAttribute('href');
            if (!target) {
                return;
            }

            event.preventDefault();
            manualRefreshButton.classList.add('is-loading');
            manualRefreshButton.setAttribute('aria-busy', 'true');

            const label = manualRefreshButton.querySelector('[data-refresh-label]');
            if (label) {
                label.textContent = 'Odświeżanie...';
            }

            requestAnimationFrame(() => requestAnimationFrame(() => {
                window.location.href = target;
            }));
        });
    }

    const manualRefreshAlert = document.getElementById('manualRefreshAlert');
    const REFRESH_STARTED = <?= (isset($_GET['refreshed'], $_GET['status']) && $_GET['status'] === 'refresh_started') ? 'true' : 'false' ?>;
    const REFRESH_BASELINE = <?= json_encode(isset($_GET['base']) && is_string($_GET['base']) && $_GET['base'] !== '' ? $_GET['base'] : ($snapshot['generatedAtIso'] ?? null), JSON_UNESCAPED_SLASHES) ?>;

    if (manualRefreshAlert && !REFRESH_STARTED) {
        window.setTimeout(() => {
            manualRefreshAlert.classList.add('is-hiding');
            window.setTimeout(() => manualRefreshAlert.remove(), 350);
        }, 6500);
    }

    if (REFRESH_STARTED) {
        let refreshTries = 0;
        let refreshPoll = null;
        const checkRefresh = () => {
            refreshTries++;
            fetch('?refresh_status=1', { cache: 'no-store' })
                .then(r => r.json())
                .then(j => {
                    if (j && j.generatedAtIso && j.generatedAtIso !== REFRESH_BASELINE) {
                        if (refreshPoll) window.clearInterval(refreshPoll);
                        window.location = window.location.pathname;
                    }
                })
                .catch(() => {});
            if (refreshTries > 90 && refreshPoll) {
                window.clearInterval(refreshPoll);
            }
        };
        checkRefresh();
        refreshPoll = window.setInterval(checkRefresh, 3000);
    }

    (() => {
        const url = new URL(window.location.href);

        if (url.searchParams.get('refreshed') === '1') {
            url.searchParams.delete('refreshed');
            url.searchParams.delete('status');
            url.searchParams.delete('t');
            url.searchParams.delete('base');

            const cleanSearch = url.searchParams.toString();
            const cleanUrl = url.pathname + (cleanSearch ? `?${cleanSearch}` : '') + url.hash;

            window.history.replaceState({}, document.title, cleanUrl);
        }
    })();

    const PROMO_DATA = <?= json_encode($promoData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const FUEL_AVG = <?= json_encode($snapshot['fuelAverages'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const FUEL_AVG_HISTORY = <?= json_encode($fuelAveragesHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let savingsFuel = 'benzyna';
    try { const sf = localStorage.getItem('fuelSavingsFuel'); if (sf === 'diesel' || sf === 'benzyna') savingsFuel = sf; } catch (e) {}
    const pToday = new Date(); pToday.setHours(0,0,0,0);
    const pFmt = (n) => n.toFixed(2).replace('.', ',');
    const pParse = (iso) => { if(!iso) return null; const [y,m,d]=iso.split('-').map(Number); return new Date(y,m-1,d); };
    const pDays = (iso) => { const d=pParse(iso); if(!d) return null; return Math.round((d-pToday)/86400000); };
    const pDmy = (iso) => { const d=pParse(iso); if(!d) return '—'; return String(d.getDate()).padStart(2,'0')+'.'+String(d.getMonth()+1).padStart(2,'0')+'.'+d.getFullYear(); };
    const pStd = (it) => it.fuels.benzyna || it.fuels.diesel || null;
    const pLpg = (it) => it.fuels.lpg || null;

    function renderPromos(){
        const rows = PROMO_DATA.map(it => ({it, off: pStd(it) || pLpg(it)})).filter(x => x.off).sort((a,b) => b.off.g - a.off.g);
        const spot = document.getElementById('spot');
        const body = document.getElementById('cmpBody');
        const tl = document.getElementById('tl');
        if (!spot || !body || !tl) return;

        if (!rows.length){
            spot.innerHTML = '';
            tl.innerHTML = '<div class="empty">Brak zapisanych promocji.</div>';
            return;
        }

        const top = rows[0];
        const dl0 = pDays(top.it.toIso);
        const save0 = pFmt(top.off.g*50/100);
        const active = rows.length;
        let maxRow = null;
        rows.forEach(r=>{ if(maxRow===null||r.off.v>maxRow.off.v){ maxRow=r; } });
        const maxV = maxRow ? maxRow.off.v : 0;
        const maxNet = maxRow ? maxRow.it.net : '';
        let nearest = null, nearestNet = '';
        rows.forEach(r=>{ const d=pDays(r.it.toIso); if(d!==null&&d>=0&&(nearest===null||d<nearest)){ nearest=d; nearestNet=r.it.net; } });
        const uncond = rows.filter(r=>!r.off.upto && r.it.cond.startsWith('bezwarunkowo')).sort((a,b)=>b.off.g-a.off.g)[0];
        spot.innerHTML = `
          <div class="hero">
            <div class="hero-id">
              <div class="hero-logo">${top.it.logo?`<img src="${top.it.logo}" alt="">`:''}</div>
              <div>
                <div class="hero-kick">★ TOP okazja</div>
                <div class="hero-net">${top.it.net}</div>
                <div class="hero-cond">${top.it.cond}</div>
              </div>
            </div>
            <div class="hero-big"><span class="n">−${top.off.g}<span class="nu">gr/l</span></span><small>benzyna i diesel</small></div>
            <div class="hero-foot">
              <span class="hero-chip">💰 ~${save0} zł przy 50 l</span>
              ${dl0!==null?`<span class="hero-chip">⏳ zostało ${dl0} dni</span>`:''}
            </div>
          </div>
          <div class="spot-side">
            <div class="mini"><span class="mini-label">Aktywne promocje</span><span class="mini-value">${active}</span></div>
            <div class="mini"><span class="mini-label">Najwyższy rabat</span><span class="mini-value">−${maxV} gr/l</span>${maxNet?`<span class="mini-sub">${maxNet}</span>`:''}</div>
            <div class="mini"><span class="mini-label">Najbliższy koniec</span><span class="mini-value">${nearest!==null?nearest+' dni':'—'}</span>${nearest!==null&&nearestNet?`<span class="mini-sub">${nearestNet}</span>`:''}</div>
            <div class="mini"><span class="mini-label">Najlepsza bezwarunkowa</span><span class="mini-value">${uncond?uncond.it.net:'—'}</span></div>
          </div>`;

        body.innerHTML = rows.map((r) => {
            const it=r.it, o=pStd(it)||r.off, lpg=pLpg(it);
            const dl=pDays(it.toIso); const soon=dl!==null&&dl<=21;
            let elapsed=50;
            if(dl!==null){ const horizon=90; elapsed=Math.max(4,Math.min(100,(horizon-dl)/horizon*100)); }
            return `
            <article class="promo-item ${it.top?'top':''}">
              ${it.top?`<div class="pi-ribbon pi-ribbon-top">★ TOP okazja</div>`:''}
              ${it.disc.when?`<div class="pi-ribbon">⏱ ${it.disc.when}</div>`:''}
              <div class="pi-head">
                <div class="pi-id">
                  ${it.logo?`<span class="pi-logo"><img src="${it.logo}" alt=""></span>`:''}
                  <div>
                    <div class="pi-name">${it.net}</div>
                    <div class="pi-sub"><span class="pi-dot"></span>Aktywna promocja</div>
                  </div>
                </div>
                <div class="pi-rabaty">
                  <div class="pi-disc"><div class="num"><span class="pfx">−</span>${o.g}<span class="nu">gr/l</span></div><div class="unit">Pb i ON</div>${o.upto&&o.v>o.g?`<div class="pi-max">maks. −${o.v} gr/l</div>`:''}</div>
                  ${lpg?`<span class="pi-lpg">LPG −${lpg.v} gr/l</span>`:''}
                </div>
              </div>
              <div class="divider"></div>
              <div class="pi-meta">
                <div class="pi-cell"><span class="lbl">Rabat i warunki</span><span class="val-tiers"><span class="tline"><b>−${o.g} gr/l</b> ${it.disc.baseCond}</span>${o.upto&&o.v>o.g?`<span class="tline"><b>−${o.v} gr/l</b> ${it.disc.maxCond?it.disc.maxCond:'w wariancie maksymalnym'}</span>`:''}</span></div>
                <div class="pi-cell"><span class="lbl">Ważność</span><span class="val">do ${pDmy(it.toIso)}</span><div class="prog ${soon?'soon':''}"><div style="width:${elapsed}%"></div></div><div class="days ${soon?'soon':''}">${dl!==null&&dl>=0?'zostało '+dl+' dni':'zakończona'}</div></div>
                <div class="pi-cell"><span class="lbl">Szacowana oszczędność${(FUEL_AVG&&FUEL_AVG[savingsFuel])?` <span class="save-fuel">${savingsFuel==='diesel'?'diesel · koszt ON':'benzyna · koszt PB95'}</span>`:''}</span><span class="save-lines">${[40,45,50].map(L=>`<span>${L} l <span class="rv"><b>~${pFmt(o.g*L/100)} zł</b>${(FUEL_AVG&&FUEL_AVG[savingsFuel])?` <span class="cost">~${pFmt((FUEL_AVG[savingsFuel]-o.g/100)*L)} zł</span>`:''}</span></span>`).join('')}</span>${(FUEL_AVG&&FUEL_AVG[savingsFuel])?`<span class="save-price">śr. ${pFmt(FUEL_AVG[savingsFuel])} zł/l → <b>~${pFmt(FUEL_AVG[savingsFuel]-o.g/100)} zł/l</b> po rabacie</span>`:''}</div>
              </div>
              <div class="pi-desc"><span class="lbl">Opis promocji</span><p>${it.desc?it.desc:'Brak dodatkowego opisu.'}</p>${it.url?`<a href="${it.url}" target="_blank" rel="noreferrer">Otwórz stronę promocji →</a>`:''}</div>
            </article>`;
        }).join('');

        const froms = rows.map(r=>pParse(r.it.fromIso)).filter(Boolean).map(d=>d.getTime());
        const tos = rows.map(r=>pParse(r.it.toIso)).filter(Boolean).map(d=>d.getTime());
        const start = Math.min(...froms), end = Math.max(...tos), span=Math.max(1,end-start);
        const todayPct = Math.max(0,Math.min(100,(pToday.getTime()-start)/span*100));
        tl.innerHTML = `<div class="tl-head"><span class="tl-today-flag" style="left:${todayPct}%">dziś</span></div>` +
            rows.map(r=>{
                const it=r.it;
                const f=pParse(it.fromIso)?.getTime()??start, t=pParse(it.toIso)?.getTime()??end;
                const left=(f-start)/span*100, width=Math.max(3,(t-f)/span*100);
                const dl=pDays(it.toIso); const soon=dl!==null&&dl<=21;
                return `<div class="tl-row">
                  <div class="tl-name">${it.logo?`<img src="${it.logo}" alt="">`:''}<span>${it.net}</span></div>
                  <div class="tl-lane"><div class="tl-marker" style="left:${todayPct}%"></div><div class="tl-bar ${soon?'soon':''}" style="left:${left}%;width:${width}%" tabindex="0"><span class="tl-tip"><b>Początek:</b> ${pDmy(it.fromIso)}<br><b>Koniec:</b> ${pDmy(it.toIso)}${dl!==null&&dl>=0?' (za '+dl+' dni)':''}</span></div></div>
                  <div class="tl-end">do <b>${pDmy(it.toIso)}</b>${dl!==null&&dl>=0?' · za '+dl+' dni':''}</div>
                </div>`;
            }).join('');
    }

    const saveButtons = document.querySelectorAll('.save-btn');
    const syncSaveButtons = () => saveButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.fuel === savingsFuel));
    saveButtons.forEach(btn => btn.addEventListener('click', () => {
        savingsFuel = btn.dataset.fuel === 'diesel' ? 'diesel' : 'benzyna';
        try { localStorage.setItem('fuelSavingsFuel', savingsFuel); } catch (e) {}
        syncSaveButtons();
        renderPromos();
    }));
    syncSaveButtons();

    function renderAvgChart() {
        const canvas = document.getElementById('avgChart');
        if (!canvas || typeof Chart === 'undefined' || !Array.isArray(FUEL_AVG_HISTORY) || FUEL_AVG_HISTORY.length === 0) {
            return;
        }
        const hist = FUEL_AVG_HISTORY.slice(-31);
        const isDark = document.documentElement.dataset.theme === 'dark';
        const gridColor = isDark ? 'rgba(255,255,255,.08)' : 'rgba(18,52,59,.08)';
        const tickColor = isDark ? 'rgba(233,243,241,.75)' : 'rgba(91,107,116,.95)';
        const labels = hist.map(e => { const d = (e.date || '').split('-'); return d.length === 3 ? d[2] + '.' + d[1] : (e.date || ''); });
        const defs = [
            { key: 'benzyna', label: 'PB95', color: '#1f8a70' },
            { key: 'pb98', label: 'PB98', color: '#e69a1a' },
            { key: 'diesel', label: 'ON', color: '#2c6fb0' },
            { key: 'lpg', label: 'LPG', color: '#c0498b' },
        ];
        const pointR = hist.length > 25 ? 0 : 3;
        const datasets = defs.map(d => ({
            label: d.label,
            data: hist.map(e => (typeof e[d.key] === 'number' ? e[d.key] : null)),
            borderColor: d.color, backgroundColor: d.color,
            tension: 0.3, spanGaps: true, pointRadius: pointR, pointHoverRadius: 4, borderWidth: 2,
        }));
        new Chart(canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { labels: { color: tickColor, usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + (c.parsed.y != null ? c.parsed.y.toFixed(2).replace('.', ',') + ' zł/l' : '—') } },
                },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { color: tickColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                    y: { grid: { color: gridColor }, ticks: { color: tickColor, callback: (v) => v.toFixed(2).replace('.', ',') } },
                },
            },
        });
    }
    document.addEventListener('DOMContentLoaded', () => { try { renderAvgChart(); } catch (e) {} });

    renderPromos();
</script>
</body>
</html>
