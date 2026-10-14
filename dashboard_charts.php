<?php
// Gráficos renderizados desde las cifras de MariaDB; sin datos ficticios.
// Compatible con PHP 7.2+. El SVG evita errores de tamaño de barras CSS.
declare(strict_types=1);

function soporte_chart_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Colorea exclusivamente la fracción real; 0 siempre queda gris. */
function soporte_metric_gradient(int $count, int $total, string $color): string {
    $allowed = ['#14b68b','#9574e8','#f0af3c','#a5aec1','#8c73e9','#3c93ee','#14bdb4','#f1ad43','#92a3be'];
    if (!in_array($color, $allowed, true)) $color = '#8c73e9';
    $percentage = dashboard_fraction(max(0, $count), max(0, $total));
    if ($percentage <= 0) return 'background-color:#edf2f8;';
    if ($percentage >= 100) return 'background-color:'.$color.';';
    return 'background:linear-gradient(to right, '.$color.' 0%, '.$color.' '.$percentage.'%, #edf2f8 '.$percentage.'%, #edf2f8 100%);';
}


/**
 * Barra SVG coloreada sin atributos style="".
 * La cabecera de seguridad existente usa style-src 'self' y bloquea estilos inline.
 * Los colores SVG se aplican como atributos fill y las longitudes como width.
 */
function soporte_metric_svg(int $count, int $total, string $color, string $label, string $class): string {
    $allowed = ['#14b68b','#9574e8','#f0af3c','#a5aec1','#8c73e9','#3c93ee','#14bdb4','#f1ad43','#92a3be'];
    if (!in_array($color, $allowed, true)) $color = '#8c73e9';
    $count = max(0, $count);
    $total = max(0, $total);
    $percentage = $total > 0 ? min(100.0, ($count * 100.0) / $total) : 0.0;
    $value = number_format($percentage, 3, '.', '');
    $a11y = soporte_chart_escape($label . ': ' . $count . ' de ' . $total . ' registros (' . round($percentage) . '%)');
    $class = in_array($class, ['db-state-track','db-vendor-track'], true) ? $class : 'db-state-track';
    $svg = '<svg class="' . $class . '" viewBox="0 0 100 16" preserveAspectRatio="none" role="meter" aria-label="' . $a11y . '" aria-valuemin="0" aria-valuemax="' . max(1, $total) . '" aria-valuenow="' . $count . '" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect x="0" y="0" width="100" height="16" rx="3" fill="#edf2f8" />';
    if ($percentage > 0) {
        $svg .= '<rect x="0" y="0" width="' . $value . '" height="16" rx="3" fill="' . $color . '" />';
    }
    return $svg . '</svg>';
}

/** SVG proporcional, con eje desde 0 y barras no vacías bien visibles. */
function soporte_month_svg(array $months): string {
    if (!$months) return '<p class="muted">No hay registros para graficar.</p>';
    $n = count($months);
    // Suficiente espacio para etiquetas, pero sin estirar la única barra a todo el ancho.
    $width = max(670, min(1040, 76 * $n + 80));
    $left = 40; $right = 16; $top = 25; $bottom = 199;
    $plotWidth = $width - $left - $right;
    $plotHeight = $bottom - $top;
    $maxData = 0;
    foreach ($months as $m) $maxData = max($maxData, (int)$m['total']);
    $axisMax = max(4, (int)ceil($maxData / 4) * 4);
    $html = '<div class="db-svg-chart-scroll"><svg class="db-month-svg" role="img" aria-label="Registros por mes con valores reales" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 246" preserveAspectRatio="xMidYMid meet">';
    $html .= '<defs><linearGradient id="soporte-bar-blue" x1="0" x2="0" y1="0" y2="1"><stop offset="0%" stop-color="#388bff"/><stop offset="100%" stop-color="#77c2ff"/></linearGradient></defs>';
    for ($i=0; $i<=4; $i++) {
        $value = $axisMax - (int)round($axisMax*$i/4);
        $y = $top + $plotHeight*$i/4;
        $html .= '<line x1="'.$left.'" y1="'.$y.'" x2="'.($width-$right).'" y2="'.$y.'" stroke="#e2eaf5" stroke-width="1"/>';
        $html .= '<text x="'.($left-9).'" y="'.($y+4).'" fill="#768ba7" text-anchor="end" font-size="12">'.$value.'</text>';
    }
    $step = $plotWidth / $n;
    $barWidth = min(46, max(16, $step*.56));
    foreach ($months as $i=>$m) {
        $count = max(0, (int)$m['total']);
        $label = soporte_chart_escape((string)$m['etiqueta']);
        $xCenter = $left + $step*($i+.5);
        if ($count > 0) {
            $barHeight = max(4, $plotHeight*$count/$axisMax);
            $y = $bottom - $barHeight;
            $x = $xCenter - $barWidth/2;
            $html .= '<rect x="'.round($x,2).'" y="'.round($y,2).'" width="'.round($barWidth,2).'" height="'.round($barHeight,2).'" rx="4" fill="url(#soporte-bar-blue)"><title>'.$label.': '.$count.' registro(s)</title></rect>';
            $html .= '<text x="'.round($xCenter,2).'" y="'.max(14,round($y-7,2)).'" font-size="12" text-anchor="middle" font-weight="700" fill="#123c75">'.$count.'</text>';
        }
        $html .= '<text x="'.round($xCenter,2).'" y="'.($bottom+19).'" text-anchor="middle" font-size="'.($n>12 ? '10' : '11').'" fill="#627b99">'.$label.'</text>';
    }
    $html .= '</svg></div>';
    return $html;
}
