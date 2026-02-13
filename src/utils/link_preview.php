<?php
declare(strict_types=1);

// public/link_preview.php
header('Content-Type: application/json; charset=utf-8');

function lp_respond(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function lp_normalize_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    return $url;
}

/**
 * Mitigación SSRF básica: solo http/https + bloquear localhost/redes privadas.
 */
function lp_is_private_ip(string $ip): bool {
    // IPv4
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['224.0.0.0', '239.255.255.255'],
        ];
        foreach ($ranges as [$s, $e]) {
            if ($long >= ip2long($s) && $long <= ip2long($e)) return true;
        }
        return false;
    }

    // IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $l = strtolower($ip);
        if ($l === '::1') return true;                 // loopback
        if (str_starts_with($l, 'fe80:')) return true; // link-local
        if (str_starts_with($l, 'fc') || str_starts_with($l, 'fd')) return true; // ULA
        return false;
    }

    return true; // si no valida, bloquea
}

function lp_ssrf_ok(string $url): bool {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return false;

    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) return false;

    $host = $p['host'];
    if ($host === 'localhost') return false;

    $records = dns_get_record($host, DNS_A + DNS_AAAA);
    if (!$records) return false;

    foreach ($records as $r) {
        $ip = $r['ip'] ?? ($r['ipv6'] ?? null);
        if ($ip && lp_is_private_ip($ip)) return false;
    }
    return true;
}

function lp_fetch_html(string $url, int $maxBytes = 800000): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 7,
        CURLOPT_USERAGENT => 'TodoItLinkPreview/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: es,en;q=0.8',
        ],
    ]);

    $html = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($html === false) return [null, "curl_error:$err", $finalUrl, $ct];
    if ($code < 200 || $code >= 300) return [null, "http_status:$code", $finalUrl, $ct];
    if ($ct && stripos($ct, 'text/html') === false) return [null, "not_html", $finalUrl, $ct];

    if (strlen($html) > $maxBytes) $html = substr($html, 0, $maxBytes);

    return [$html, null, $finalUrl ?? $url, $ct];
}

function lp_first_meta(DOMXPath $xpath, array $queries): ?string {
    foreach ($queries as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = trim($nodes->item(0)->getAttribute('content'));
            if ($val !== '') return $val;
        }
    }
    return null;
}

function lp_absolutize_url(?string $maybeRelative, string $baseUrl): ?string {
    if (!$maybeRelative) return null;
    $u = trim($maybeRelative);
    if ($u === '') return null;

    if (preg_match('#^https?://#i', $u)) return $u;
    if (str_starts_with($u, '//')) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $u;
    }

    $p = parse_url($baseUrl);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return $u;

    $scheme = $p['scheme'];
    $host = $p['host'];
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = $p['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);

    if (str_starts_with($u, '/')) return "{$scheme}://{$host}{$port}{$u}";
    return "{$scheme}://{$host}{$port}{$dir}{$u}";
}

// ---- handler ----
$url = lp_normalize_url((string)($_GET['url'] ?? ''));
if (!$url) lp_respond(400, ['error' => 'missing_url']);

if (!lp_ssrf_ok($url)) {
    lp_respond(400, ['error' => 'invalid_or_blocked_url']);
}

[$html, $fetchErr, $effectiveUrl] = lp_fetch_html($url);
$host = parse_url($effectiveUrl ?? $url, PHP_URL_HOST);
$domain = $host ? preg_replace('#^www\.#i', '', $host) : null;

if ($fetchErr) {
    lp_respond(200, [
        'url' => $effectiveUrl ?? $url,
        'domain' => $domain,
        'site' => $domain,
        'title' => null,
        'description' => null,
        'image' => null,
        'error' => $fetchErr,
    ]);
}

libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML($html);
$xpath = new DOMXPath($doc);

// OpenGraph / Twitter cards
$title = lp_first_meta($xpath, [
    "//meta[@property='og:title']",
    "//meta[@name='twitter:title']",
]);

$description = lp_first_meta($xpath, [
    "//meta[@property='og:description']",
    "//meta[@name='description']",
    "//meta[@name='twitter:description']",
]);

$image = lp_first_meta($xpath, [
    "//meta[@property='og:image']",
    "//meta[@property='og:image:url']",
    "//meta[@name='twitter:image']",
]);

$siteName = lp_first_meta($xpath, [
    "//meta[@property='og:site_name']",
]);

// Fallback: <title>
if (!$title) {
    $nodes = $xpath->query("//title");
    if ($nodes && $nodes->length > 0) $title = trim($nodes->item(0)->textContent);
}

$imageAbs = lp_absolutize_url($image, $effectiveUrl);

lp_respond(200, [
    'url' => $effectiveUrl,
    'domain' => $domain,
    'site' => $siteName ?: $domain,
    'title' => $title,
    'description' => $description,
    'image' => $imageAbs,
]);
