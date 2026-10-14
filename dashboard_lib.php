<?php
// Métricas reales del dashboard, basadas en la columna FECHA de registros.
// No usa valores de demostración ni modifica la base de datos.

declare(strict_types=1);

function dashboard_period(array $input): array {
    $mode = (string)($input['periodo'] ?? 'todos');
    if (!in_array($mode, ['hoy','ayer','7d','30d','mes','3m','6m','12m','todos','personalizado'], true)) $mode = 'todos';
    $today = new DateTimeImmutable('today');
    $end = $today->format('Y-m-d');
    $first = $today->modify('first day of this month');
    $from = null;
    $error = '';
    switch ($mode) {
        case 'hoy': $from = $end; break;
        case 'ayer': $from = $today->modify('-1 day')->format('Y-m-d'); $end=$from; break;
        case '7d': $from = $today->modify('-6 days')->format('Y-m-d'); break;
        case '30d': $from = $today->modify('-29 days')->format('Y-m-d'); break;
        case 'mes': $from = $first->format('Y-m-d'); break;
        case '3m': $from = $first->modify('-2 months')->format('Y-m-d'); break;
        case '6m': $from = $first->modify('-5 months')->format('Y-m-d'); break;
        case '12m': $from = $first->modify('-11 months')->format('Y-m-d'); break;
        case 'todos': $end = null; break;
        case 'personalizado':
            $rFrom = trim((string)($input['desde'] ?? ''));
            $rTo = trim((string)($input['hasta'] ?? ''));
            $from = ($rFrom !== '' && dashboard_valid_iso_date($rFrom)) ? $rFrom : null;
            $end = ($rTo !== '' && dashboard_valid_iso_date($rTo)) ? $rTo : null;
            if (!$from || !$end) {
                $error = 'Selecciona las fechas Desde y Hasta para aplicar un rango personalizado.';
            }
            if ($from !== null && $end !== null && $from > $end) {
                $error = 'La fecha Desde no puede ser posterior a Hasta.';
            }
            // Nunca ejecutar consultas con un rango inesperado.
            if ($error !== '') { $mode = 'todos'; $from = null; $end = null; }
            break;
    }
    $labels = ['hoy'=>'Hoy','ayer'=>'Ayer','7d'=>'Últimos 7 días','30d'=>'Últimos 30 días','mes'=>'Este mes','3m'=>'Últimos 3 meses','6m'=>'Últimos 6 meses',
        '12m'=>'Últimos 12 meses','todos'=>'Todos los registros','personalizado'=>'Personalizado'];
    return ['modo'=>$mode, 'desde'=>$from, 'hasta'=>$end, 'etiqueta'=>$labels[$mode], 'error'=>$error];
}

function dashboard_valid_iso_date(string $v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $v;
}

function dashboard_sql_dates(?string $desde, ?string $hasta): array {
    $clauses = []; $params = [];
    if ($desde !== null) { $clauses[]='fecha >= :dash_desde'; $params[':dash_desde']=$desde; }
    if ($hasta !== null) { $clauses[]='fecha <= :dash_hasta'; $params[':dash_hasta']=$hasta; }
    return [$clauses ? ' WHERE '.implode(' AND ', $clauses) : '', $params];
}

function dashboard_counts(PDO $pdo, ?string $desde, ?string $hasta): array {
    list($where, $params) = dashboard_sql_dates($desde, $hasta);
    $st = $pdo->prepare('SELECT estado, COUNT(*) AS cantidad FROM registros'.$where.' GROUP BY estado');
    $st->execute($params);
    $out = ['total'=>0,'PRODUCCION'=>0,'DEMO'=>0,'INTERNO'=>0,'OTROS'=>0];
    foreach ($st->fetchAll() as $r) {
        $n = (int)$r['cantidad'];
        $out['total'] += $n;
        // Unificar variantes con espacios y/o tilde sin modificar los registros.
        $state = strtoupper(trim((string)$r['estado']));
        $state = str_replace(['Ó', 'ó'], 'O', $state);
        if (in_array($state, ['PRODUCCION','DEMO','INTERNO'], true)) $out[$state] += $n;
        else $out['OTROS'] += $n;
    }
    return $out;
}

function dashboard_previous_counts(PDO $pdo, ?string $desde, ?string $hasta): ?array {
    if (!$desde || !$hasta || $desde > $hasta) return null;
    $start = new DateTimeImmutable($desde);
    $end = new DateTimeImmutable($hasta);
    $span = (int)$start->diff($end)->days + 1;
    if ($span < 1) return null;
    $priorEnd = $start->modify('-1 day');
    $priorStart = $priorEnd->modify('-'.($span - 1).' days');
    return dashboard_counts($pdo, $priorStart->format('Y-m-d'), $priorEnd->format('Y-m-d'));
}

function dashboard_delta(int $now, ?int $prev): string {
    if ($prev === null) return '';
    if ($prev === 0) return $now ? 'Nuevo' : '0%';
    $value = (int)round(100 * ($now-$prev) / $prev);
    return ($value > 0 ? '+' : '').$value.'%';
}

function dashboard_vendors(PDO $pdo, ?string $desde, ?string $hasta): array {
    list($where, $params) = dashboard_sql_dates($desde, $hasta);
    $where .= ($where ? ' AND ' : ' WHERE ')."TRIM(COALESCE(vendedor, '')) <> ''";
    // Mayúsculas y espacios homogéneos para no duplicar un mismo vendedor.
    $st = $pdo->prepare('SELECT UPPER(TRIM(vendedor)) AS nombre, COUNT(*) AS cantidad FROM registros'.$where
        .' GROUP BY UPPER(TRIM(vendedor)) ORDER BY cantidad DESC, nombre ASC LIMIT 5');
    $st->execute($params);
    return $st->fetchAll();
}

function dashboard_months(PDO $pdo, ?string $desde, ?string $hasta): array {
    $monthNames = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO',
        'JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    $shortNames = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

    if ($desde === null && $hasta === null) {
        // Vista "Todos": el campo MES es la referencia, especialmente para CSV sin FECHA.
        // MES no tiene año: nunca atribuir un año inventado a registros importados.
        $result = [];
        foreach ($monthNames as $i => $month) {
            $result[$month] = ['clave'=>$month, 'etiqueta'=>$shortNames[$i], 'total'=>0];
        }
        $noMonth = 0;
        $st = $pdo->query("SELECT mes, MONTH(fecha) AS mes_fecha, COUNT(*) AS cantidad FROM registros GROUP BY mes, MONTH(fecha)");
        foreach ($st->fetchAll() as $r) {
            $name = strtoupper(trim((string)$r['mes']));
            // Variantes con acento y espacios alrededor.
            $name = str_replace(['Á','É','Í','Ó','Ú'], ['A','E','I','O','U'], $name);
            if (!isset($result[$name])) {
                $m = (int)$r['mes_fecha'];
                $name = ($m >= 1 && $m <= 12) ? $monthNames[$m-1] : '';
            }
            if ($name !== '' && isset($result[$name])) $result[$name]['total'] += (int)$r['cantidad'];
            else $noMonth += (int)$r['cantidad'];
        }
        if ($noMonth) $result['sin-mes'] = ['clave'=>'sin-mes','etiqueta'=>'Sin mes','total'=>$noMonth];
        return array_values($result);
    }

    // Cuando el usuario filtra fechas se utiliza FECHA, no MES; así el periodo es real.
    $anchorEnd = $hasta ?: date('Y-m-d');
    $endMonth = (new DateTimeImmutable($anchorEnd))->modify('first day of this month');
    $firstMonth = $desde ? (new DateTimeImmutable($desde))->modify('first day of this month') : $endMonth->modify('-11 months');
    $earliestShown = $endMonth->modify('-11 months');
    if ($firstMonth < $earliestShown) $firstMonth = $earliestShown;
    $result = [];
    for ($d = $firstMonth; $d <= $endMonth; $d = $d->modify('+1 month')) {
        $key = $d->format('Y-m');
        $result[$key] = ['clave'=>$key,'etiqueta'=>$shortNames[(int)$d->format('n')-1].' '.$d->format('Y'),'total'=>0];
    }
    list($where, $params) = dashboard_sql_dates($desde, $hasta);
    $st = $pdo->prepare("SELECT DATE_FORMAT(fecha,'%Y-%m') AS mes, COUNT(*) AS cantidad FROM registros".$where
        ." GROUP BY DATE_FORMAT(fecha,'%Y-%m')");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        if (isset($result[$r['mes']])) $result[$r['mes']]['total'] = (int)$r['cantidad'];
    }
    return array_values($result);
}

function dashboard_fraction(int $value, int $total): int {
    return $total > 0 ? min(100, (int)round($value*100/$total)) : 0;
}

function dashboard_without_date(PDO $pdo, ?string $desde, ?string $hasta): int {
    if ($desde !== null || $hasta !== null) return 0;
    $st = $pdo->query('SELECT COUNT(*) FROM registros WHERE fecha IS NULL');
    return (int)$st->fetchColumn();
}
