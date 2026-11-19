<?php

// Guardas del visor privado: no sustituyen las ACL PostgreSQL de cada cuenta autorizada.
function sysforestViewerReject(int $code = 403): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Solicitud no permitida en este visor privado.';
    exit;
}
$viewerHost = $_SERVER['HTTP_HOST'] ?? '';
if (!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?$/D', $viewerHost)) {
    sysforestViewerReject();
}
$viewerSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($viewerSite !== '' && !in_array($viewerSite, ['same-origin', 'none'], true)) {
    sysforestViewerReject();
}
$viewerOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($viewerOrigin !== '' && $viewerOrigin !== 'http://'.$viewerHost && $viewerOrigin !== 'https://'.$viewerHost) {
    sysforestViewerReject();
}
foreach ([$_GET, $_POST] as $viewerInput) {
    foreach (['server' => 'postgres:5432:disable', 'loginServer' => 'postgres:5432:disable', 'database' => 'sysforest'] as $viewerKey => $viewerExpected) {
        if (isset($viewerInput[$viewerKey]) && (!is_string($viewerInput[$viewerKey]) || $viewerInput[$viewerKey] !== $viewerExpected)) {
            sysforestViewerReject();
        }
    }
    if (isset($viewerInput['loginUsername']) && (!is_string($viewerInput['loginUsername']) || !in_array($viewerInput['loginUsername'], ['sysforest_editor', 'sysforest_viewer'], true))) {
        sysforestViewerReject();
    }
}
if (!isset($_SESSION['sysforest_csrf'])) {
    $_SESSION['sysforest_csrf'] = bin2hex(random_bytes(32));
}
$viewerMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($viewerMethod, ['GET', 'HEAD', 'POST'], true)) {
    sysforestViewerReject(405);
}
if ($viewerMethod === 'POST') {
    $viewerToken = $_POST['_sysforest_csrf'] ?? ($_SERVER['HTTP_X_SYSFOREST_CSRF'] ?? '');
    if (!is_string($viewerToken) || !hash_equals($_SESSION['sysforest_csrf'], $viewerToken)) {
        sysforestViewerReject();
    }
}
// Cada POST nativo lleva token; AJAX usa la misma sesión, sin tokens en URLs.
ob_start(static function (string $html): string {
    $isHtml = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type: text/html') === 0) {
            $isHtml = true;
        }
    }
    if (!$isHtml) {
        return $html;
    }
    $token = htmlspecialchars($_SESSION['sysforest_csrf'] ?? '', ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback('/<form\b[^>]*>/i', static function (array $match) use ($token): string {
        if (!preg_match('/\bmethod\s*=\s*(?:"post"|\'post\'|post)(?:\s|>)/i', $match[0])) {
            return $match[0];
        }
        return $match[0].'<input type="hidden" name="_sysforest_csrf" value="'.$token.'" />';
    }, $html);
    $script = '<meta name="sysforest-csrf" content="'.$token.'" /><script src="phppgadmin-security.js"></script>';
    return str_replace('<script type="text/javascript" src="libraries/js/jquery.js"></script>', '<script type="text/javascript" src="libraries/js/jquery.js"></script>'.$script, $html);
});
$GLOBALS['sysforest_viewer_buffer_level'] = ob_get_level();
// Llamar solo después de fijar el MIME de exportación: no retener el archivo completo.
function sysforestViewerStreamExport(): void
{
    $level = $GLOBALS['sysforest_viewer_buffer_level'] ?? null;
    if (is_int($level) && ob_get_level() === $level) {
        ob_end_flush();
        unset($GLOBALS['sysforest_viewer_buffer_level']);
    }
}
