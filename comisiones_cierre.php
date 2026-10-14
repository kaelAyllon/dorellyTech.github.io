<?php
declare(strict_types=1);

/**
 * Regla de cierre para comisiones del Registro Soporte.
 * No depende de JavaScript ni de parámetros GET enviados por el navegador.
 * El registro se habilita el día 1 del mes siguiente a las 00:00 (America/Lima).
 * El campo MES define el mes de comisión; FECHA aporta el año, si existe.
 * Si no hay FECHA ni F. PRODUCCIÓN, se utiliza el año de creado_en.
 */

function com_fecha_valida($valor, DateTimeZone $zona): ?DateTimeImmutable {
    $valor=trim((string)$valor);
    if ($valor==='' || $valor==='0000-00-00') return null;
    $fecha=DateTimeImmutable::createFromFormat('!Y-m-d', $valor, $zona);
    return ($fecha && $fecha->format('Y-m-d')===$valor) ? $fecha : null;
}

function com_estado_cierre(array $registro, DateTimeImmutable $ahora): array {
    $zona=new DateTimeZone('America/Lima');
    $ahora=$ahora->setTimezone($zona);
    $nombres=['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    $texto=trim((string)($registro['mes']??''));
    $texto=strtr(strtoupper($texto), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
    $pos=array_search($texto,$nombres,true);
    $fecha=com_fecha_valida($registro['fecha']??'', $zona);
    $fproduccion=com_fecha_valida($registro['f_produccion']??'', $zona);
    $creacion=com_fecha_valida(substr((string)($registro['creado_en']??''),0,10), $zona);
    if ($pos===false && $fecha===null) {
        return ['estado'=>'REVISAR MES','disponible_desde'=>'','periodo'=>''];
    }
    $mes=($pos===false) ? (int)$fecha->format('n') : ($pos+1);
    // Para registros importados sin fecha, creado_en da un año estable al cambiar de diciembre a enero.
    $origen=$fecha ?? $fproduccion ?? $creacion ?? $ahora;
    $anio=(int)$origen->format('Y');
    $periodo=DateTimeImmutable::createFromFormat('!Y-m-d',sprintf('%04d-%02d-01',$anio,$mes),$zona);
    if (!$periodo) return ['estado'=>'REVISAR MES','disponible_desde'=>'','periodo'=>''];
    $habilitacion=$periodo->modify('first day of next month')->setTime(0,0,0);
    return [
        'estado'=>($ahora < $habilitacion ? 'NO DISPONIBLE':'HABILITADO'),
        'disponible_desde'=>$habilitacion->format('d/m/Y'),
        'periodo'=>$periodo->format('Y-m')
    ];
}
