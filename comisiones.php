<?php
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
require __DIR__.'/comisiones_cierre.php';
require __DIR__.'/ui_shared.php';
ensure_login();
if (!es_admin(db())) {http_response_code(403);exit('Solo el administrador puede consultar comisiones generales.');}
$pdo=db();
// La comisión corresponde al monto registrado, no a una transferencia de pago ya liquidada.
$mes=trim((string)($_GET['mes']??''));
$desde=trim((string)($_GET['desde']??''));
$hasta=trim((string)($_GET['hasta']??''));
$vendedor=trim((string)($_GET['vendedor']??''));
$soporte=trim((string)($_GET['soporte']??''));
$pago=trim((string)($_GET['pago']??''));
$error=''; $conditions=["UPPER(TRIM(estado)) IN ('PRODUCCION','PRODUCCIÓN','INTERNO')"];
$params=[];
if ($mes!=='' && in_array($mes,meses(),true)) { $conditions[]='mes=:mes'; $params[':mes']=$mes; }
foreach (['desde'=>$desde,'hasta'=>$hasta] as $clave=>$fecha) {
    if ($fecha==='') continue;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
    if (!$d || $d->format('Y-m-d')!==$fecha) { $error='Fecha no válida. Usa el selector de calendario.'; continue; }
    $conditions[]='fecha '.($clave==='desde'?'>=':'<=').' :'.$clave;
    $params[':'.$clave]=$fecha;
}
if ($desde!=='' && $hasta!=='' && $desde>$hasta) $error='La fecha Desde no puede superar Hasta.';
if ($vendedor!=='') { $conditions[]='vendedor = :vendedor'; $params[':vendedor']=$vendedor; }
if ($soporte!=='') { $conditions[]='soporte = :soporte'; $params[':soporte']=$soporte; }
if ($pago==='SI') $conditions[]="UPPER(TRIM(pago)) IN ('SI','SÍ')";
if ($pago==='NO') $conditions[]="UPPER(TRIM(pago)) = 'NO'";
$where=' WHERE '.implode(' AND ',$conditions);
$sql='SELECT id,fecha,f_produccion,creado_en,mes,ruc,razon_social,estado,proyecto,plan,monto,pago,vendedor,soporte,serie_comprobante,numero_comprobante FROM registros'.$where.' ORDER BY COALESCE(fecha,\'0000-00-00\') DESC,id DESC';
$rows=[];
if (!$error) { $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC); }
$people=$pdo->query('SELECT DISTINCT vendedor FROM registros WHERE vendedor IS NOT NULL AND vendedor<>\'\' ORDER BY vendedor')->fetchAll(PDO::FETCH_COLUMN);
$supportPeople=$pdo->query('SELECT DISTINCT soporte FROM registros WHERE soporte IS NOT NULL AND soporte<>\'\' ORDER BY soporte')->fetchAll(PDO::FETCH_COLUMN);
// La disponibilidad se calcula en el servidor de acuerdo con la zona horaria de Perú.
$ahoraComisiones=new DateTimeImmutable('now',new DateTimeZone('America/Lima'));
$totalMonto=0.0; $totalAsesora=0.0; $totalSoporte=0.0;
$validos=0; $pendientes=0; $bloqueadas=0;
foreach ($rows as &$r) {
    $cierre=com_estado_cierre($r,$ahoraComisiones);
    $r['cierre']=$cierre['estado'];
    $r['disponible_desde']=$cierre['disponible_desde'];
    $r['comision_asesora']=null;
    $r['comision_soporte']=null;
    $r['apto']=false;
    // Nunca calcular ni acumular comisiones correspondientes a meses no cerrados.
    if ($r['cierre']==='NO DISPONIBLE') { $bloqueadas++; continue; }
    if ($r['cierre']==='REVISAR MES') { $pendientes++; continue; }
    $m=($r['monto']!==null && is_numeric($r['monto'])) ? (float)$r['monto'] : 0.0;
    $apto=($r['monto']!==null && is_numeric($r['monto']) && $m>0
        && trim((string)$r['plan'])!==''
        && trim((string)$r['vendedor'])!==''
        && trim((string)$r['soporte'])!=='');
    $r['apto']=$apto;
    if (!$apto) { $pendientes++; continue; }
    // Se calcula sobre centavos enteros y soporte recibe el saldo de la partición.
    $centavos=(int)round($m*100);
    $asesoraCentavos=(int)round($centavos*0.70);
    $soporteCentavos=$centavos-$asesoraCentavos;
    $r['comision_asesora']=$asesoraCentavos/100;
    $r['comision_soporte']=$soporteCentavos/100;
    $validos++;
    $totalMonto+=$centavos/100;
    $totalAsesora+=$asesoraCentavos/100;
    $totalSoporte+=$soporteCentavos/100;
}
unset($r);
if (isset($_GET['exportar']) && $_GET['exportar']==='excel' && !$error) {
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('Activa extension=zip en php.ini para exportar XLSX.'); }
    function com_col(int $i): string { $s=''; do {$s=chr(65+$i%26).$s;$i=intdiv($i,26)-1;} while($i>=0); return $s; }
    function com_xml($v): string { return htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    $headers=['ID','FECHA','MES','RUC / DNI','RAZÓN SOCIAL','ESTADO','PROYECTO','PLAN','MONTO (S/)','ASESORA / VENDEDOR','70% ASESORA (S/)','SOPORTE','30% SOPORTE (S/)','¿PAGÓ?','SERIE','NÚMERO','RESULTADO','DISPONIBLE DESDE'];
    $file=tempnam(sys_get_temp_dir(),'comisiones-'); if (!$file) {http_response_code(500);exit('Error de archivo temporal.');}
    $zip=new ZipArchive(); if ($zip->open($file,ZipArchive::OVERWRITE)!==true) exit('Error al crear XLSX.');
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
    foreach ($headers as $i=>$label) { $w=in_array($i,[4,9,11],true)?33:($i===7?27:18);$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>'; }
    $xml.='</cols><sheetData><row r="1">';
    foreach ($headers as $i=>$head) $xml.='<c r="'.com_col($i).'1" s="1" t="inlineStr"><is><t>'.com_xml($head).'</t></is></c>';
    $xml.='</row>'; $n=1;
    foreach ($rows as $r) {
        $n++; $cells=[(int)$r['id'], $r['fecha']?:'', $r['mes'], $r['ruc'], $r['razon_social'], $r['estado'], $r['proyecto'], $r['plan'], $r['monto']===null?'':(float)$r['monto'], $r['vendedor'], $r['apto']?$r['comision_asesora']:'', $r['soporte'], $r['apto']?$r['comision_soporte']:'', $r['pago'], $r['serie_comprobante'], $r['numero_comprobante'], $r['cierre']==='NO DISPONIBLE'?'NO DISPONIBLE':($r['cierre']==='REVISAR MES'?'REVISAR MES':($r['apto']?'CALCULADO':'REVISAR DATOS')), $r['disponible_desde']];
        $xml.='<row r="'.$n.'">'; foreach ($cells as $i=>$v) { if ($v==='') continue; $cell=com_col($i).$n; if (in_array($i,[0,8,10,12],true) && is_numeric($v)) $xml.='<c r="'.$cell.'"'.($i!==0?' s="2"':'').'><v>'.(float)$v.'</v></c>'; else $xml.='<c r="'.$cell.'" t="inlineStr"><is><t>'.com_xml($v).'</t></is></c>'; } $xml.='</row>';
    }
    $xml.='<row r="'.(++$n).'"><c r="H'.$n.'" t="inlineStr"><is><t>TOTALES (válidos)</t></is></c><c r="I'.$n.'" s="2"><v>'.round($totalMonto,2).'</v></c><c r="K'.$n.'" s="2"><v>'.round($totalAsesora,2).'</v></c><c r="M'.$n.'" s="2"><v>'.round($totalSoporte,2).'</v></c></row></sheetData><autoFilter ref="A1:R'.($n-1).'"/></worksheet>';
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Comisiones" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1769A7"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$xml); $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Comisiones_Soporte_'.date('Ymd_His').'.xlsx"'); header('Cache-Control: no-store');header('Content-Length: '.filesize($file));readfile($file);unlink($file);exit;
}
$query=$_GET;unset($query['exportar']);$query['exportar']='excel';$url='comisiones.php?'.http_build_query($query);
function soles($v): string { return 'S/ '.number_format((float)$v,2,'.',','); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comisiones · Registro Soporte</title><link rel="stylesheet" href="assets/comisiones.css"><link rel="stylesheet" href="assets/ui_shared.css?v=20261014a"></head><body>
<div class="layout"><?php ui_sidebar($pdo, 'comisiones'); ?>
<main><?php ui_topbar($pdo, 'Reporte de comisiones', 'Cálculos a partir de los planes registrados en MariaDB.'); ?>
<div class="content"><div class="notice">Se incluyen solo registros con estado <b>PRODUCCIÓN</b> o <b>INTERNO</b>. El reparto es <b>70% asesora/vendedor</b> y <b>30% soporte</b>. Las comisiones de cada mes figuran como <b>NO DISPONIBLE</b> hasta el primer día del mes siguiente (hora de Perú). Solo se suman importes de meses cerrados y con datos completos. La exportación Excel respeta la misma regla.</div>
<section class="panel"><h2>Filtros del reporte</h2><form method="get" class="filters">
<label>Mes<select name="mes"><option value="">Todos los meses</option><?php foreach(meses() as $m):?><option value="<?=h($m)?>" <?= $mes===$m?'selected':'' ?>><?=h($m)?></option><?php endforeach;?></select></label>
<label>Desde (FECHA)<input type="date" name="desde" value="<?=h($desde)?>"></label><label>Hasta (FECHA)<input type="date" name="hasta" value="<?=h($hasta)?>"></label>
<label>Asesora / Vendedor<select name="vendedor"><option value="">Todos</option><?php foreach($people as $p):?><option value="<?=h($p)?>" <?= $vendedor===$p?'selected':'' ?>><?=h($p)?></option><?php endforeach;?></select></label>
<label>Soporte<select name="soporte"><option value="">Todos</option><?php foreach($supportPeople as $p):?><option value="<?=h($p)?>" <?= $soporte===$p?'selected':'' ?>><?=h($p)?></option><?php endforeach;?></select></label>
<label>¿Pagó?<select name="pago"><option value="">Todos</option><option value="SI" <?= $pago==='SI'?'selected':'' ?>>Sí</option><option value="NO" <?= $pago==='NO'?'selected':'' ?>>No</option></select></label>
<div class="buttons"><button type="submit">⌕ Buscar</button><a class="reset" href="comisiones.php">Limpiar</a><a class="excel" href="<?=h($url)?>">▤ Descargar Excel</a></div></form>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif;?></section>
<div class="stats"><section><span>Planes habilitados</span><strong><?=number_format($validos)?></strong></section><section><span>Monto de planes</span><strong><?=soles($totalMonto)?></strong></section><section><span>70% Asesoras</span><strong><?=soles($totalAsesora)?></strong></section><section><span>30% Soporte</span><strong><?=soles($totalSoporte)?></strong></section><section><span>No disponibles (mes abierto)</span><strong class="locked-count"><?=number_format($bloqueadas)?></strong></section></div>
<section class="panel"><div class="list-title"><div><h2>Detalle por plan vendido</h2><p><?=count($rows)?> registros de producción/interno · <?= $bloqueadas ?> no disponibles por cierre mensual · <?= $pendientes ?> pendientes de revisar</p></div><a class="excel" href="<?=h($url)?>">↓ Exportar Excel</a></div><div class="scroll"><table><thead><tr><th>ID</th><th>Fecha</th><th>Cliente</th><th>Estado</th><th>Proyecto</th><th>Plan</th><th>Monto</th><th>Asesora</th><th>70%</th><th>Soporte</th><th>30%</th><th>Pago</th><th>Resultado</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td>#<?= (int)$r['id']?></td><td><?=h(short_date($r['fecha']))?></td><td><?=h($r['razon_social'])?><small><?=h($r['ruc'])?></small></td><td><span class="status"><?=h($r['estado'])?></span></td><td><?=h($r['proyecto'])?></td><td><?=h($r['plan'])?></td><td><?=soles($r['monto'])?></td><td><?=h($r['vendedor'])?></td><td class="amt"><?= $r['apto']?soles($r['comision_asesora']):'—'?></td><td><?=h($r['soporte'])?></td><td class="amt"><?= $r['apto']?soles($r['comision_soporte']):'—'?></td><td><?=h($r['pago'])?></td><td><?php if($r['cierre']==='NO DISPONIBLE'):?><span class="locked">NO DISPONIBLE</span><small>Desde <?=h($r['disponible_desde'])?></small><?php elseif($r['cierre']==='REVISAR MES'):?><span class="warn">Revisar mes</span><?php elseif($r['apto']):?><span class="ok">Calculado</span><?php else:?><span class="warn">Revisar datos</span><?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="13">No hay registros que coincidan con los filtros.</td></tr><?php endif;?></tbody></table></div></section></div></main></div></body></html>
