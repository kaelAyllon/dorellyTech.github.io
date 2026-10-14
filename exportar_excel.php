<?php
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
ensure_login();
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('Para exportar Excel activa extension=zip en C:\\xampp\\php\\php.ini y reinicia Apache.');
}
$pdo=db();
exigir_permiso($pdo,'exportar');
list($where,$params)=filtros_registros_usuario($pdo,$_GET);
$order=(($_GET['orden']??'desc')==='asc')?'ASC':'DESC';
$fields=['id','f_produccion','mes','fecha','ruc','razon_social','url','estado','soporte','vendedor','celular','proyecto','plan','monto','pago','emite','observacion','serie_comprobante','numero_comprobante','imagen_voucher','informacion_soporte'];
$headers=['ID','F. PRODUCCIÓN','MES','FECHA','RUC','RAZÓN SOCIAL','URL','ESTADO','N. SOPORTE','N. VENDEDOR','N° CELULAR','PROYECTO','PLAN','MONTO','¿PAGÓ?','EMITE','OBSERVACIÓN','SERIE COMPROBANTE','NÚMERO COMPROBANTE','IMAGEN DE VOUCHER','INFORMACIÓN SOPORTE'];
$st=$pdo->prepare('SELECT '.implode(',',$fields).' FROM registros'.$where.' ORDER BY id '.$order);
bind_filters($st,$params);
$st->execute();
function excel_col(int $index): string {
    $out='';
    do { $out=chr(65+($index%26)).$out; $index=intdiv($index,26)-1; } while ($index>=0);
    return $out;
}
function excel_text($value): string {
    // El tipo inlineStr impide que Excel interprete fórmulas de datos externos.
    $value=text_slice((string)$value, 0, 32767);
    $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$xmlFile=tempnam(sys_get_temp_dir(),'soporte-xml-');
$zipFile=tempnam(sys_get_temp_dir(),'soporte-xlsx-');
if (!$xmlFile || !$zipFile) { http_response_code(500); exit('No se pueden crear archivos temporales.'); }
try {
    $fp=fopen($xmlFile,'wb');
    if (!$fp) throw new RuntimeException('No se pudo crear la hoja de Excel.');
    fwrite($fp,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
    fwrite($fp,'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="16"/><cols>');
    for ($i=0;$i<count($fields);$i++) {
        $width=in_array($fields[$i],['razon_social','observacion','informacion_soporte'],true)?44:(in_array($fields[$i],['url','imagen_voucher'],true)?36:20);
        fwrite($fp,'<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>');
    }
    fwrite($fp,'</cols><sheetData><row r="1" ht="25" customHeight="1">');
    foreach ($headers as $i=>$header) fwrite($fp,'<c r="'.excel_col($i).'1" s="1" t="inlineStr"><is><t>'.excel_text($header).'</t></is></c>');
    fwrite($fp,'</row>');
    $n=1;
    while ($record=$st->fetch(PDO::FETCH_ASSOC)) {
        $n++;
        fwrite($fp,'<row r="'.$n.'">');
        foreach ($fields as $i=>$field) {
            $v=$record[$field];
            if ($field==='imagen_voucher') $v=$v ? 'Imagen adjunta en Registro Soporte (ID '.$record['id'].')' : '';
            if (($field==='fecha'||$field==='f_produccion') && $v) $v=short_date($v);
            $cell=excel_col($i).$n;
            if ($v === null || $v === '') continue;
            if ($field==='monto' && is_numeric($v)) fwrite($fp,'<c r="'.$cell.'" s="2"><v>'.(float)$v.'</v></c>');
            elseif ($field==='id') fwrite($fp,'<c r="'.$cell.'"><v>'.(int)$v.'</v></c>');
            else fwrite($fp,'<c r="'.$cell.'" t="inlineStr"><is><t>'.excel_text($v).'</t></is></c>');
        }
        fwrite($fp,'</row>');
    }
    fwrite($fp,'</sheetData><autoFilter ref="A1:U'.$n.'"/></worksheet>');
    fclose($fp);
    $archive=new ZipArchive();
    if ($archive->open($zipFile,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo empaquetar el archivo XLSX.');
    $archive->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $archive->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $archive->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Registro Soporte" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $archive->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $archive->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2166B1"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $archive->addFile($xmlFile,'xl/worksheets/sheet1.xml');
    $archive->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="REGISTRO_SOPORTE_'.date('Y-m-d').'.xlsx"');
    header('Cache-Control: no-store');
    header('Content-Length: '.filesize($zipFile));
    readfile($zipFile);
} catch (Throwable $e) {
    error_log('Exportación Excel: '.$e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo 'No se pudo exportar el Excel. Comprueba los permisos del directorio temporal.';
} finally {
    if (is_file($xmlFile)) unlink($xmlFile);
    if (is_file($zipFile)) unlink($zipFile);
}
