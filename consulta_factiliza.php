<?php
// Endpoint autenticado para consultar a Factiliza sin exponer el token.
// PHP >= 7.2, compatible con XAMPP. No se requieren cambios a MariaDB.
declare(strict_types=1);
require __DIR__ . '/usuarios_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');

function api_responder(int $codigo, array $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_responder(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}
if (!logged_in() || !installed()) {
    api_responder(401, ['ok' => false, 'mensaje' => 'Inicia sesión nuevamente.']);
}
if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 7200) {
    unset($_SESSION['uid'], $_SESSION['username'], $_SESSION['last_seen']);
    api_responder(401, ['ok' => false, 'mensaje' => 'Tu sesión expiró. Inicia sesión de nuevo.']);
}
$_SESSION['last_seen'] = time();
if (!permitido(db(),'registros_crear') && !permitido(db(),'registros_editar')) {
    api_responder(403, ['ok'=>false,'mensaje'=>'No tienes permiso para consultar documentos.']);
}
if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
    api_responder(403, ['ok' => false, 'mensaje' => 'Sesión no válida. Actualiza la página.']);
}
$documento = trim((string)($_POST['documento'] ?? ''));
if (!preg_match('/^(?:[0-9]{8}|[0-9]{11})$/D', $documento)) {
    api_responder(422, ['ok' => false, 'mensaje' => 'El DNI requiere 8 dígitos y el RUC 11 dígitos.']);
}
$tipo = strlen($documento) === 8 ? 'DNI' : 'RUC';
$archivoConfig = __DIR__ . '/factiliza_config.php';
if (!is_file($archivoConfig)) {
    api_responder(503, ['ok' => false, 'mensaje' => 'Falta configurar factiliza_config.php.']);
}
$conf = require $archivoConfig;
$token = is_array($conf) ? trim((string)($conf['token'] ?? '')) : '';
if ($token === '' || $token === 'PEGA_AQUI_TU_TOKEN_NUEVO') {
    api_responder(503, ['ok' => false, 'mensaje' => 'Configura el token de Factiliza en factiliza_config.php.']);
}
if (!function_exists('curl_init')) {
    api_responder(503, ['ok' => false, 'mensaje' => 'PHP cURL no está habilitado en XAMPP.']);
}

// Evita consultas repetidas y reduce el consumo del cupo de Factiliza.
$cacheKey = 'factiliza:' . $documento;
if (isset($_SESSION['factiliza_cache'][$cacheKey])) {
    $guardado = $_SESSION['factiliza_cache'][$cacheKey];
    if (is_array($guardado) && isset($guardado['fecha'], $guardado['nombre']) && time() - (int)$guardado['fecha'] < 600) {
        api_responder(200, ['ok' => true, 'nombre' => $guardado['nombre'], 'tipo' => $tipo, 'cache' => true]);
    }
}
$ahora = time();
$intentos = array_values(array_filter($_SESSION['factiliza_intentos'] ?? [], function ($t) use ($ahora) {
    return (int)$t > $ahora - 600;
}));
if (count($intentos) >= 30) {
    api_responder(429, ['ok' => false, 'mensaje' => 'Límite de consultas temporales alcanzado. Intenta más tarde.']);
}
$intentos[] = $ahora;
$_SESSION['factiliza_intentos'] = $intentos;

$url = 'https://api.factiliza.com/v1/' . strtolower($tipo) . '/info/' . rawurlencode($documento);
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $url,
    CURLOPT_HTTPGET => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
    CURLOPT_USERAGENT => 'RegistroSoporte-Factiliza/1.0',
]);
$respuesta = curl_exec($curl);
$codigo = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);
if ($respuesta === false) {
    error_log('Factiliza: error de conexion / TLS (sin registrar token): ' . $error);
    api_responder(502, ['ok' => false, 'mensaje' => 'No se pudo conectar a Factiliza. Verifica internet y SSL de PHP.']);
}
if ($codigo === 401 || $codigo === 403) {
    api_responder(502, ['ok' => false, 'mensaje' => 'Factiliza rechazó el token. Genera uno nuevo y actualiza la configuración.']);
}
if ($codigo === 429) {
    api_responder(429, ['ok' => false, 'mensaje' => 'Factiliza limitó las consultas. Intenta más tarde.']);
}
if ($codigo < 200 || $codigo >= 300) {
    api_responder(502, ['ok' => false, 'mensaje' => 'Factiliza devolvió HTTP ' . $codigo . '. Revisa el documento y tu plan.']);
}
$datos = json_decode((string)$respuesta, true);
if (!is_array($datos) || empty($datos['success']) || !isset($datos['data']) || !is_array($datos['data'])) {
    api_responder(404, ['ok' => false, 'mensaje' => 'No se encontró información para este documento.']);
}
$persona = $datos['data'];
if ($tipo === 'RUC') {
    $nombre = trim((string)($persona['nombre_o_razon_social'] ?? ''));
} else {
    $nombre = trim((string)($persona['nombre_completo'] ?? ''));
    if ($nombre === '') {
        $nombre = trim(implode(' ', [
            (string)($persona['apellido_paterno'] ?? ''),
            (string)($persona['apellido_materno'] ?? ''),
            (string)($persona['nombres'] ?? ''),
        ]));
    }
}
if ($nombre === '') {
    api_responder(404, ['ok' => false, 'mensaje' => 'La respuesta no incluyó nombre o razón social.']);
}
// Máximo 255 caracteres, como la columna en registros.
$nombre = text_slice($nombre, 0, 255);
if (!isset($_SESSION['factiliza_cache']) || !is_array($_SESSION['factiliza_cache'])) $_SESSION['factiliza_cache'] = [];
$_SESSION['factiliza_cache'][$cacheKey] = ['fecha' => time(), 'nombre' => $nombre];
// Evita que una sesión acumule entradas indefinidamente.
if (count($_SESSION['factiliza_cache']) > 70) $_SESSION['factiliza_cache'] = [$cacheKey => $_SESSION['factiliza_cache'][$cacheKey]];
api_responder(200, ['ok' => true, 'nombre' => $nombre, 'tipo' => $tipo, 'cache' => false]);
