<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'America/Lima');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('REGISTRO_SOPORTE_SESION');
    $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    // PHP 7.2 no admite el arreglo de opciones para session_set_cookie_params().
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secureCookie, true);
    }
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'");

function upper_es(string $s): string {
    if (function_exists('mb_strtoupper')) return mb_strtoupper($s, 'UTF-8');
    return strtr(strtoupper($s), ['á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ñ'=>'Ñ']);
}
function text_length(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function text_slice(string $s, int $start, int $length): string { return function_exists('mb_substr') ? mb_substr($s, $start, $length, 'UTF-8') : substr($s, $start, $length); }
function csv_utf8(string $s): string {
    if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')) return $s;
    if (!function_exists('mb_check_encoding') && preg_match('//u', $s)) return $s;
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    return function_exists('iconv') ? (iconv('Windows-1252', 'UTF-8//IGNORE', $s) ?: '') : $s;
}
function cfg(): array { global $config; return $config; }
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $destination): void { header('Location: ' . $destination, true, 303); exit; }
function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Sesión no válida. Actualiza la página e inténtalo nuevamente.');
    }
}
function flash(string $kind, string $message): void { $_SESSION['flash'][] = [$kind, $message]; }
function get_flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function logged_in(): bool { return isset($_SESSION['uid']) && is_int($_SESSION['uid']); }
function ensure_login(): void { if (!logged_in()) go('index.php?view=login'); }
function db(bool $selectDb = true): PDO {
    static $connections = [];
    $key = $selectDb ? 'database' : 'server';
    if (isset($connections[$key])) return $connections[$key];
    $c = cfg();
    $dsn = 'mysql:host=' . $c['db_host'] . ';port=' . $c['db_port'] . ';' . ($selectDb ? 'dbname=' . $c['db_name'] . ';' : '') . 'charset=utf8mb4';
    $connections[$key] = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $connections[$key];
}
function installed(): bool {
    try {
        return (int)db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function meses(): array {
    return ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
}
function estados(): array { return ['PRODUCCION', 'DEMO', 'INTERNO']; }
function short_date(?string $date): string {
    if (!$date || $date === '0000-00-00') return '—';
    $time = strtotime($date);
    return $time ? date('d/m/Y', $time) : '—';
}
function parse_date(string $date): ?string {
    $date = trim($date);
    if ($date === '') return null;
    foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!Y/m/d'] as $pattern) {
        $dt = DateTimeImmutable::createFromFormat($pattern, $date);
        if ($dt && $dt->format(substr($pattern, 1)) === $date) return $dt->format('Y-m-d');
    }
    return null;
}
function normalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    return $url;
}
function registro_fields(): array {
    return ['f_produccion','mes','fecha','ruc','razon_social','url','estado','soporte','vendedor','celular',
            'proyecto','plan','monto','pago','emite','observacion','serie_comprobante','numero_comprobante','imagen_voucher','informacion_soporte'];
}
function registro_filters(array $input): array {
    $q = trim((string)($input['q'] ?? ''));
    $estado = (string)($input['estado'] ?? '');
    $mes = (string)($input['mes'] ?? '');
    $soporte = trim((string)($input['soporte'] ?? ''));
    $parts=[]; $params=[];
    if ($q !== '') {
        $parts[] = '(razon_social LIKE :q1 OR ruc LIKE :q2 OR celular LIKE :q3 OR url LIKE :q4 OR vendedor LIKE :q5 OR soporte LIKE :q6 OR proyecto LIKE :q7 OR plan LIKE :q8 OR observacion LIKE :q9 OR informacion_soporte LIKE :q10)';
        for ($i=1; $i<=10; $i++) $params[':q'.$i] = '%'.text_slice($q,0,100).'%';
    }
    if (in_array($estado, estados(), true)) { $parts[]='estado=:estado'; $params[':estado']=$estado; }
    if (in_array($mes, meses(), true)) { $parts[]='mes=:mes'; $params[':mes']=$mes; }
    if ($soporte !== '') { $parts[]='soporte LIKE :soporte'; $params[':soporte']='%'.text_slice($soporte,0,80).'%'; }
    // Permite exportar y filtrar el directorio por la fecha real del registro.
    foreach (['desde'=>'>=', 'hasta'=>'<='] as $field=>$operator) {
        $date = trim((string)($input[$field] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && parse_date($date) === $date) {
            $parts[] = 'fecha '.$operator.' :'.$field;
            $params[':'.$field] = $date;
        }
    }
    return [$parts ? ' WHERE '.implode(' AND ', $parts) : '', $params];
}
function bind_filters(PDOStatement $st, array $params): void {
    foreach ($params as $k=>$v) $st->bindValue($k,$v,PDO::PARAM_STR);
}
function form_values(array $data, bool $isCsv = false): array {
    $fields = registro_fields();
    $values = [];
    foreach ($fields as $field) $values[$field] = trim((string)($data[$field] ?? ''));
    $errors = [];
    foreach (['f_produccion','fecha'] as $field) {
        $val = $values[$field];
        $values[$field] = parse_date($val);
        if ($val !== '' && $values[$field] === null) $errors[] = 'Formato inválido en ' . $field;
    }
    $amountRaw = str_replace(' ', '', $values['monto']);
    if ($amountRaw === '') $values['monto'] = null;
    else {
        $amount = str_replace(',', '.', $amountRaw);
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) $errors[] = 'MONTO debe ser un número positivo con hasta dos decimales (ej. 99 o 159.90).';
        else $values['monto'] = number_format((float)$amount, 2, '.', '');
    }
    foreach (['pago','emite'] as $field) {
        $v = strtoupper($values[$field]);
        $values[$field] = $v === 'SÍ' ? 'SI' : $v;
        if (!in_array($values[$field], ['', 'SI', 'NO'], true)) $errors[] = 'Selecciona SI o NO en '.$field;
    }
    $values['mes'] = upper_es($values['mes']);
    $values['estado'] = str_replace('Ó', 'O', upper_es($values['estado'] ?: 'PRODUCCION'));
    if (!in_array($values['mes'], meses(), true)) $errors[] = 'Selecciona un mes válido.';
    if (!in_array($values['estado'], estados(), true)) $errors[] = 'Selecciona un estado válido.';
    if ($values['razon_social'] === '') $errors[] = 'La razón social es obligatoria.';
    foreach (['ruc'=>20, 'razon_social'=>255, 'url'=>500, 'soporte'=>100, 'vendedor'=>100, 'celular'=>35,'proyecto'=>100,'plan'=>100,'observacion'=>65000,'serie_comprobante'=>30,'numero_comprobante'=>40,'imagen_voucher'=>255,'informacion_soporte'=>65000] as $field=>$max) {
        if (text_length($values[$field]) > $max) $errors[] = 'El campo ' . $field . ' excede ' . $max . ' caracteres.';
    }
    $urlRaw = $values['url'];
    $values['url'] = normalize_url($urlRaw);
    if ($urlRaw !== '' && $values['url'] === '') $errors[] = 'La URL no es válida.';
    // Solo se guardan nombres de archivos generados internamente, nunca rutas aportadas por el navegador.
    if ($values['imagen_voucher'] !== '' && !preg_match('/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/D', $values['imagen_voucher'])) {
        $errors[] = 'Imagen de voucher no válida.';
    }
    return [$values, $errors];
}
function save_registro(PDO $pdo, array $values, ?int $id = null): int {
    $fields = registro_fields();
    $params = [];
    foreach ($fields as $field) $params[':' . $field] = $values[$field];
    if ($id === null) {
        $sql = 'INSERT INTO registros (' . implode(',', $fields) . ') VALUES (' . implode(',', array_keys($params)) . ')';
    } else {
        $sql = 'UPDATE registros SET ' . implode(', ', array_map(function ($f) {
            return "$f=:$f";
        }, $fields)) . ' WHERE id=:id';
        $params[':id'] = $id;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $id ?? (int)$pdo->lastInsertId();
}
function query_params(array $add = []): string {
    $p = array_merge($_GET, $add);
    foreach ($p as $key => $val) if ($val === '' || $val === null) unset($p[$key]);
    return 'index.php?' . http_build_query($p);
}
function csv_column_map(array $cols): array {
    // Orden A..J antiguo, seguido de los 10 campos nuevos (K..T).
    return [
        'f_produccion' => $cols[0] ?? '', 'mes' => $cols[1] ?? '',
        'fecha' => $cols[2] ?? '', 'ruc' => $cols[3] ?? '',
        'razon_social' => $cols[4] ?? '', 'url' => $cols[5] ?? '',
        'estado' => $cols[6] ?? '', 'soporte' => $cols[7] ?? '',
        'vendedor' => $cols[8] ?? '', 'celular' => $cols[9] ?? '',
        'proyecto' => $cols[10] ?? '', 'plan' => $cols[11] ?? '',
        'monto' => $cols[12] ?? '', 'pago' => $cols[13] ?? '',
        'emite' => $cols[14] ?? '', 'observacion' => $cols[15] ?? '',
        'serie_comprobante' => $cols[16] ?? '', 'numero_comprobante' => $cols[17] ?? '',
        'imagen_voucher' => '', 'informacion_soporte' => $cols[19] ?? '',
    ];
}

function normalize_csv_header(string $header): string {
    $header=upper_es(trim(ltrim($header, "\xEF\xBB\xBF")));
    $header=strtr($header,['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', $header));
}
function csv_header_map(array $cols): array {
    $result=[];
    foreach ($cols as $i=>$header) {
        $title=normalize_csv_header((string)$header);
        $lookup=[
            'F PRODUCCION'=>'f_produccion', 'MES'=>'mes', 'FECHA'=>'fecha',
            'RUC'=>'ruc', 'RAZON SOCIAL'=>'razon_social', 'URL'=>'url',
            'ESTADO'=>'estado','N SOPORTE'=>'soporte', 'N VENDEDOR'=>'vendedor',
            'N CELULAR'=>'celular','NRO CELULAR'=>'celular','PROYECTO'=>'proyecto',
            'PLAN'=>'plan', 'MONTO'=>'monto', 'PAGO'=>'pago', 'EMITE'=>'emite',
            'OBSERVACION'=>'observacion', 'SERIE COMPROBANTE'=>'serie_comprobante',
            'SERIE COM'=>'serie_comprobante', 'NUMERO COMPROBANTE'=>'numero_comprobante',
            'NUMERO COF'=>'numero_comprobante','IMAGEN DE VOUCHER'=>'imagen_voucher',
            'INFORMACION SOPORTE'=>'informacion_soporte',
        ];
        if (isset($lookup[$title])) $result[$lookup[$title]]=(int)$i;
    }
    return count($result)>=5 && isset($result['razon_social']) ? $result : [];
}
function csv_column_map_headers(array $cols, array $headers): array {
    $result=[];
    foreach (registro_fields() as $f) $result[$f]=isset($headers[$f]) ? (string)($cols[$headers[$f]] ?? '') : '';
    // Las imágenes que estaban en Sheets deben adjuntarse desde el formulario web.
    $result['imagen_voucher']='';
    return $result;
}
function format_csv_fields(array $cols): array {
    foreach ($cols as &$s) {
        $s = (string)$s;
        $s = csv_utf8($s);
        $s = trim($s);
    }
    unset($s);
    return $cols;
}
