<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require __DIR__ . '/catalogos_lib.php';
require __DIR__ . '/usuarios_lib.php';
require __DIR__ . '/dashboard_lib.php';
require __DIR__ . '/dashboard_charts.php';
require __DIR__ . '/ui_shared.php';

if (!installed()) go('install.php');
if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 7200) {
    unset($_SESSION['uid'], $_SESSION['username'], $_SESSION['last_seen']);
}
if (logged_in()) $_SESSION['last_seen'] = time();
$pdo = db();
$accessUser = current_user($pdo);
$isAdmin = $accessUser !== null && es_admin($pdo);
$profileLabel = $isAdmin ? 'ADMINISTRADOR' : 'VENDEDOR';
$nombreVendedorAcceso = $accessUser && !$isAdmin ? nombre_vendedor_vinculado($pdo,$accessUser) : ''; 
$view = (string)($_GET['view'] ?? 'registros');
// Redirección inicial tras instalar la actualización: no deja cuentas heredadas sin control.
if (logged_in() && !usuarios_listos($pdo)) {
    if ($isAdmin) go('actualizar_usuarios.php');
    http_response_code(403); exit('El administrador debe activar primero el módulo Usuarios.');
}
if (logged_in() && !$accessUser) go('index.php?view=login');
if (logged_in() && $view==='importar') exigir_permiso($pdo,'importar');
if (logged_in() && $view==='nuevo') exigir_permiso($pdo,'registros_crear');
if (logged_in() && $view==='editar') exigir_permiso($pdo,'registros_editar');
if (logged_in() && in_array($view,['areas','finanzas'],true)) exigir_permiso($pdo,'catalogos');
if (logged_in() && $view==='registros') exigir_permiso($pdo,'registros_ver');
// Títulos compatibles con PHP 7.2; evita la expresión match() de PHP 8.
function view_title(string $view, bool $documentTitle): string {
    if ($documentTitle) {
        switch ($view) {
            case 'nuevo': return 'Nuevo registro';
            case 'editar': return 'Editar registro';
            case 'importar': return 'Importar CSV';
            case 'login': return 'Iniciar sesión';
            case 'ayuda-acceso': return 'Ayuda para acceder';
            case 'areas': return 'Roles · Áreas';
            case 'finanzas': return 'Roles · Finanzas';
            default: return 'Registros';
        }
    }
    switch ($view) {
        case 'nuevo': return 'Agregar registro';
        case 'editar': return 'Editar registro';
        case 'importar': return 'Importar registros';
        case 'areas': return 'Roles / Áreas';
        case 'finanzas': return 'Roles / Finanzas';
        default: return 'Registro de soporte';
    }
}

$errors = [];
$postedValues = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        $username = trim((string)($_POST['usuario'] ?? ''));
        $password = (string)($_POST['clave'] ?? '');
        $attempts = $_SESSION['login_attempts'] ?? [];
        $attempts = array_values(array_filter($attempts, function ($ts) {
            return $ts > time() - 300;
        }));
        if (count($attempts) >= 8) {
            $errors[] = 'Demasiados intentos. Inténtalo de nuevo en 5 minutos.';
        } else {
            $loginReady = usuarios_listos($pdo);
            // Cuentas actualizadas: acceso mediante correo electrónico.
            // Excepción transitoria: el administrador antiguo sin correo configurado
            // puede seguir entrando por usuario para no bloquear el sistema.
            $st = $pdo->prepare($loginReady
                ? "SELECT id,usuario,clave_hash,activo,auth_version FROM usuarios WHERE LOWER(correo)=LOWER(:correo) OR (perfil='ADMINISTRADOR' AND (correo IS NULL OR correo='') AND usuario=:legacy) LIMIT 1"
                : 'SELECT id,usuario,clave_hash FROM usuarios WHERE usuario=:u1 LIMIT 1');
            $st->execute($loginReady ? [':correo'=>$username, ':legacy'=>$username] : [':u1'=>$username]);
            $user = $st->fetch();
            if ($user && (!$loginReady || (int)$user['activo']===1) && password_verify($password, $user['clave_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$user['id'];
                $_SESSION['auth_version'] = $loginReady ? (int)$user['auth_version'] : 1;
                $_SESSION['username'] = $user['usuario'];
                $_SESSION['last_seen'] = time();
                unset($_SESSION['login_attempts']);
                go('index.php');
            }
            $attempts[] = time();
            $_SESSION['login_attempts'] = $attempts;
            $errors[] = 'Usuario o contraseña incorrectos.';
        }
        $view = 'login';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('index.php?view=login');
    } else {
        ensure_login();
        if ($action === 'guardar') {
            $idToSave=(int)($_POST['id']??0);
            exigir_permiso($pdo,$idToSave>0?'registros_editar':'registros_crear');
            if ($idToSave>0 && !puede_ver_registro($pdo,$idToSave)) {http_response_code(403);exit('No tienes acceso a ese registro.');}
            [$values, $errors] = form_values($_POST);
            $postedValues = array_merge($_POST, $values);
            // El vendedor únicamente puede crear ventas a su nombre.
            if (!$isAdmin) {
                $values['vendedor']=$nombreVendedorAcceso;
                $postedValues['vendedor']=$values['vendedor'];
            }

            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
            // Un archivo anterior se mantiene al editar, salvo reemplazo explícito.
            if ($id !== null) {
                $previousSt = $pdo->prepare('SELECT imagen_voucher FROM registros WHERE id=:id');
                $previousSt->execute([':id'=>$id]);
                $previousVoucher = $previousSt->fetchColumn();
                if ($previousVoucher === false) $errors[] = 'Ese registro ya no existe.';
                else $values['imagen_voucher'] = !empty($_POST['borrar_voucher']) ? '' : (string)$previousVoucher;
            } else {
                $values['imagen_voucher'] = '';
            }
            $uploadedVoucher = null;
            if (!empty($_FILES['voucher']) && $_FILES['voucher']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload = $_FILES['voucher'];
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5*1024*1024) {
                    $errors[] = 'El voucher no pudo subirse o supera los 5 MB.';
                } elseif (!is_uploaded_file($upload['tmp_name'])) {
                    $errors[] = 'Archivo de voucher no válido.';
                } else {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    if (!isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
                        $errors[] = 'El voucher debe ser imagen JPG, PNG o WEBP.';
                    } else {
                        $uploadsDir = __DIR__.'/uploads';
                        if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0700, true)) $errors[] = 'No se pudo crear la carpeta de vouchers.';
                        else {
                            $uploadedVoucher = 'uploads/'.bin2hex(random_bytes(16)).'.'.$extensions[$mime];
                            if (!move_uploaded_file($upload['tmp_name'], __DIR__.'/'.$uploadedVoucher)) {
                                $errors[] = 'No se pudo guardar el voucher en el servidor.';
                                $uploadedVoucher = null;
                            } else $values['imagen_voucher'] = $uploadedVoucher;
                        }
                    }
                }
            }
            if (!$errors) {
                if ($id !== null) {
                    $check = $pdo->prepare('SELECT id FROM registros WHERE id=:id');
                    $check->execute([':id'=>$id]);
                    if (!$check->fetch()) $errors[] = 'Ese registro ya no existe.';
                }
            }
            if ($errors && $uploadedVoucher !== null) @unlink(__DIR__.'/'.$uploadedVoucher);
            if (!$errors) {
                try {
                    if ($id !== null && $isAdmin && isset($_POST['propietario_id']) && (int)$_POST['propietario_id'] > 0) {
                        $verifyOwner=$pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id=:id AND perfil='VENDEDOR' AND activo=1");
                        $verifyOwner->execute([':id'=>(int)$_POST['propietario_id']]);
                        if (!(int)$verifyOwner->fetchColumn()) throw new RuntimeException('Propietario seleccionado inválido o inactivo.');
                    }
                    $savedId = save_registro($pdo, $values, $id);
                    if ($id === null) {
                        $stOwner=$pdo->prepare('UPDATE registros SET creado_por=:uid WHERE id=:id');
                        $stOwner->execute([':uid'=>(int)$_SESSION['uid'],':id'=>$savedId]);
                    } elseif ($isAdmin && isset($_POST['propietario_id'])) {
                        $ownerRaw=(string)$_POST['propietario_id'];
                        $ownerId=ctype_digit($ownerRaw)?(int)$ownerRaw:0;
                        // NULL permite dejar registros históricos sin asignar.
                        $ownerCheck=$pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id=:id AND perfil='VENDEDOR' AND activo=1");
                        if ($ownerId>0) {$ownerCheck->execute([':id'=>$ownerId]); if (!(int)$ownerCheck->fetchColumn()) throw new RuntimeException('El vendedor propietario no existe o está inactivo.');}
                        $setOwner=$pdo->prepare('UPDATE registros SET creado_por=:uid WHERE id=:id');
                        $setOwner->bindValue(':uid',$ownerId>0?$ownerId:null,$ownerId>0?PDO::PARAM_INT:PDO::PARAM_NULL);
                        $setOwner->bindValue(':id',$savedId,PDO::PARAM_INT);
                        $setOwner->execute();
                    }
                } catch (Throwable $e) {
                    if ($uploadedVoucher !== null) @unlink(__DIR__.'/'.$uploadedVoucher);
                    throw $e;
                }
                flash('success', $id === null ? 'Registro #'.$savedId.' agregado correctamente.' : 'Registro #'.$savedId.' actualizado correctamente.');
                go('index.php');
            }
            $view = $id === null ? 'nuevo' : 'editar';
        } elseif ($action === 'eliminar') {
            exigir_permiso($pdo,'registros_eliminar');
            // Eliminar únicamente mediante POST autenticado y CSRF válido.
            $idEliminar = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($idEliminar === false || $idEliminar === null) {
                flash('error', 'El ID del registro no es válido.');
                go('index.php');
            }
            if (!puede_ver_registro($pdo,(int)$idEliminar)) {http_response_code(403);exit('No puedes eliminar registros de otra persona.');}
            try {
                $pdo->beginTransaction();
                // Bloqueamos la fila para evitar modificaciones simultáneas.
                $buscar = $pdo->prepare('SELECT id, imagen_voucher FROM registros WHERE id = :id FOR UPDATE');
                $buscar->execute([':id' => $idEliminar]);
                $registroEliminar = $buscar->fetch();
                if (!$registroEliminar) {
                    $pdo->rollBack();
                    flash('error', 'El registro ya no existe.');
                } else {
                    $borrar = $pdo->prepare('DELETE FROM registros WHERE id = :id');
                    $borrar->execute([':id' => $idEliminar]);
                    $pdo->commit();
                    // El archivo también se elimina solo si su nombre fue generado por el sistema.
                    $rutaVoucher = (string)$registroEliminar['imagen_voucher'];
                    if (preg_match('/^uploads\/[a-f0-9]{32}\.(jpg|png|webp)$/D', $rutaVoucher)) {
                        $archivoVoucher = __DIR__ . '/' . $rutaVoucher;
                        if (is_file($archivoVoucher)) @unlink($archivoVoucher);
                    }
                    flash('success', 'Registro #'.$idEliminar.' eliminado correctamente.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Error al eliminar registro: '.$e->getMessage());
                flash('error', 'No se pudo eliminar el registro. Revisa los registros de errores del servidor.');
            }
            go('index.php');
        } elseif ($action === 'catalogo_guardar' || $action === 'catalogo_estado') {
            // Solo el administrador del primer usuario puede mantener catálogos.
            if (!permitido($pdo,'catalogos')) {
                http_response_code(403);
                exit('No tienes permisos para gestionar Áreas y Finanzas.');
            }
            if (!catalogo_disponible($pdo)) {
                flash('error', 'Primero activa el módulo de roles desde actualizar_catalogos.php.');
                go('actualizar_catalogos.php');
            }
            $tipo = (string)($_POST['tipo'] ?? '');
            if (!array_key_exists($tipo, catalogo_definiciones())) {
                flash('error', 'Categoría de catálogo inválida.');
                go('index.php');
            }
            $destino = 'index.php?view='.catalogo_vista($tipo).'#tipo-'.$tipo;
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            // Solo PLAN tiene precio. Los demás catálogos conservan su formato anterior.
            $precio = null;
            if ($action === 'catalogo_guardar' && $tipo === 'plan') {
                if (!catalogo_tiene_precios($pdo)) {
                    flash('error', 'Primero activa los montos en actualizar_precios.php.');
                    go('actualizar_precios.php');
                }
                $precioTexto = trim(str_replace(',', '.', (string)($_POST['precio'] ?? '')));
                if ($precioTexto !== '') {
                    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $precioTexto)) {
                        flash('error', 'El monto debe ser positivo, con máximo 2 decimales.');
                        go($destino);
                    }
                    $precio = $precioTexto;
                }
            }
            try {
                if ($action === 'catalogo_guardar') {
                    $nombre = trim((string)($_POST['nombre'] ?? ''));
                    if ($nombre === '' || text_length($nombre) > 100) {
                        flash('error', 'Ingresa un nombre de 1 a 100 caracteres.');
                        go($destino);
                    }
                    if ($itemId) {
                        // El renombrado y precio no modifica registros ya guardados.
                        if ($tipo === 'plan') {
                            $stmt = $pdo->prepare('UPDATE opciones_catalogo SET nombre=:n, precio=:p WHERE id=:id AND tipo=:t');
                            $stmt->execute([':n'=>$nombre, ':p'=>$precio, ':id'=>$itemId, ':t'=>$tipo]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE opciones_catalogo SET nombre=:n WHERE id=:id AND tipo=:t');
                            $stmt->execute([':n'=>$nombre, ':id'=>$itemId, ':t'=>$tipo]);
                        }
                        flash('success', 'Opción actualizada. Los registros históricos mantienen los datos anteriores.');
                    } else {
                        if ($tipo === 'plan') {
                            $stmt = $pdo->prepare('INSERT INTO opciones_catalogo (tipo,nombre,activo,precio) VALUES (:t,:n,1,:p)');
                            $stmt->execute([':t'=>$tipo, ':n'=>$nombre, ':p'=>$precio]);
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO opciones_catalogo (tipo,nombre,activo) VALUES (:t,:n,1)');
                            $stmt->execute([':t'=>$tipo, ':n'=>$nombre]);
                        }
                        flash('success', 'Nueva opción agregada. Ya aparece en el formulario de registro.');
                    }
                } else {
                    if (!$itemId) throw new RuntimeException('ID de opción inválido.');
                    $activar = (string)($_POST['activar'] ?? '');
                    if ($activar !== '0' && $activar !== '1') throw new RuntimeException('Estado inválido.');
                    $stmt = $pdo->prepare('UPDATE opciones_catalogo SET activo=:activo WHERE id=:id AND tipo=:t');
                    $stmt->execute([':activo'=>(int)$activar, ':id'=>$itemId, ':t'=>$tipo]);
                    flash('success', $activar === '1' ? 'Opción habilitada.' : 'Opción desactivada. No se han borrado registros antiguos.');
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') flash('error', 'Ya existe una opción con ese nombre en esta categoría.');
                else {
                    error_log('Error en catálogo: '.$e->getMessage());
                    flash('error', 'No se pudo guardar el catálogo. Consulta el registro del servidor.');
                }
            } catch (Throwable $e) {
                flash('error', 'No se pudo procesar la acción: '.$e->getMessage());
            }
            go($destino);
        } elseif ($action === 'importar') {
            exigir_permiso($pdo,'importar');
            if (empty($_FILES['archivo']) || !is_uploaded_file($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'No se recibió el archivo. Revisa el límite upload_max_filesize en php.ini.';
            } elseif ($_FILES['archivo']['size'] > 10 * 1024 * 1024) {
                $errors[] = 'El CSV supera el tamaño máximo de 10 MB.';
            } else {
                $path = $_FILES['archivo']['tmp_name'];
                $hash = hash_file('sha256', $path);
                $check = $pdo->prepare('SELECT id FROM importaciones WHERE hash_archivo=:h');
                $check->execute([':h'=>$hash]);
                if ($check->fetch()) {
                    $errors[] = 'Este mismo archivo ya se importó antes. No se duplicaron los datos.';
                } else {
                    try {
                        $fh = fopen($path, 'rb');
                        if (!$fh) throw new RuntimeException('No se pudo abrir el CSV.');
                        $sample = fgets($fh) ?: '';
                        rewind($fh);
                        $delimiters = [',',';',"\t"];
                        usort($delimiters, function ($a, $b) use ($sample) {
                            return substr_count($sample, $b) <=> substr_count($sample, $a);
                        });
                        $delimiter = $delimiters[0];
                        $pdo->beginTransaction();
                        $importados = 0;
                        $omitidos = 0;
                        $linea = 0;
                        $headerMap = [];
                        while (($cols = fgetcsv($fh, 1048576, $delimiter, '"', '\\')) !== false) {
                            $linea++;
                            if ($linea > 30001) throw new RuntimeException('El archivo supera el límite de 30,000 filas. Divídelo en varios CSV.');
                            $cols = format_csv_fields($cols);
                            if ($linea === 1) {
                                $headerMap = csv_header_map($cols);
                                if ($headerMap) continue;
                                $firstCell = upper_es(ltrim($cols[0] ?? '', "\xEF\xBB\xBF"));
                                if (strpos($firstCell, 'PRODUCCI') !== false || upper_es($cols[3] ?? '') === 'RUC') continue;
                            }
                            if (count(array_filter($cols, function ($v) { return trim($v) !== ''; })) === 0) continue;
                            $record = $headerMap ? csv_column_map_headers($cols, $headerMap) : csv_column_map($cols);
                            $record['mes'] = upper_es((string)$record['mes']);
                            if ($record['mes'] === '') $record['mes'] = meses()[(int)date('n') - 1];
                            [$values, $validation] = form_values($record, true);
                            if ($validation) { $omitidos++; continue; }
                            save_registro($pdo, $values);
                            $importados++;
                        }
                        fclose($fh);
                        if ($importados === 0) throw new RuntimeException('No hay filas válidas. Revisa que tu CSV tenga encabezados correctos y al menos RAZÓN SOCIAL y MES.');
                        $st = $pdo->prepare('INSERT INTO importaciones (hash_archivo, filas_importadas) VALUES (:h,:n)');
                        $st->execute([':h'=>$hash, ':n'=>$importados]);
                        $pdo->commit();
                        flash('success', 'Importación terminada: '.$importados.' registros añadidos'.($omitidos > 0 ? '; '.$omitidos.' filas omitidas por datos no válidos.' : '.'));
                        go('index.php');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $errors[] = 'Error importando: ' . $e->getMessage();
                    }
                }
            }
            $view = 'importar';
        } else {
            http_response_code(400);
            exit('Acción desconocida.');
        }
    }
}

if (!logged_in() && !in_array($view, ['login', 'ayuda-acceso'], true)) go('index.php?view=login');

// Mantiene compatible el enlace antiguo de respaldo en CSV.
if (logged_in() && $view === 'exportar') {
    exigir_permiso($pdo,'exportar');
    header('Location: exportar_excel.php?'.http_build_query(array_diff_key($_GET, ['view'=>1])), true, 302);
    exit;
}

$catalogoInstalado = logged_in() && catalogo_disponible($pdo);
$preciosInstalados = $catalogoInstalado && catalogo_tiene_precios($pdo);
$preciosPorPlan = [];
$opcionesActivas = [];
if (logged_in() && in_array($view, ['nuevo','editar'], true)) {
    foreach (array_keys(catalogo_definiciones()) as $tipo) {
        $opcionesActivas[$tipo] = catalogo_activos($pdo, $tipo);
    }
    $preciosPorPlan = catalogo_precios_activos($pdo);
}
$catalogoAdmin = logged_in() ? permitido($pdo,'catalogos') : false;
$vendedoresUsuarios = $isAdmin ? lista_vendedores($pdo) : [];

$current = null;
if (logged_in() && $view === 'editar' && $postedValues === null) {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
    if (!puede_ver_registro($pdo,$id)) {http_response_code(403);exit('No puedes acceder a este registro.');}
    $stmt = $pdo->prepare('SELECT * FROM registros WHERE id=:id');
    $stmt->execute([':id'=>$id]);
    $current = $stmt->fetch();
    if (!$current) { flash('error','Registro no encontrado.'); go('index.php'); }
}
if ($postedValues !== null) $current = $postedValues;
if ($view === 'nuevo' && $current === null) {
    $current = ['id'=>'', 'f_produccion'=>'', 'mes'=>meses()[(int)date('n') - 1],
        'fecha'=>date('Y-m-d'), 'ruc'=>'', 'razon_social'=>'', 'url'=>'', 'estado'=>'PRODUCCION',
        'soporte'=>'', 'vendedor'=>'', 'celular'=>'', 'proyecto'=>'', 'plan'=>'', 'monto'=>'', 'pago'=>'', 'emite'=>'', 'observacion'=>'', 'serie_comprobante'=>'', 'numero_comprobante'=>'', 'imagen_voucher'=>'', 'informacion_soporte'=>''];
}

$rows = [];
$pages = 0;
$filteredCount = 0;
$page = 1;
$q = trim((string)($_GET['q'] ?? ''));
$filterEstado = (string)($_GET['estado'] ?? '');
$filterMes = (string)($_GET['mes'] ?? '');
$filterSoporte = trim((string)($_GET['soporte'] ?? ''));
$order = ($_GET['orden'] ?? '') === 'asc' ? 'asc' : 'desc';
$perPage = in_array((int)($_GET['por_pagina'] ?? 25), [10,25,50,100], true) ? (int)($_GET['por_pagina'] ?? 25) : 25;
$dashboardPeriod = dashboard_period($_GET);
$dashboardCounts = ['total'=>0,'PRODUCCION'=>0,'DEMO'=>0,'INTERNO'=>0,'OTROS'=>0];
$previousCounts = null;
$dashboardMonths = [];
$dashboardVendors = [];
$dashboardMaxMonth = 1;
$dashboardMaxVendor = 1;
$dashboardSinFecha = 0;
$dashboardSinFechaTotal = 0;
$filterInput = $_GET;
if (logged_in() && $view === 'registros') {
    if ($isAdmin) $dashboardSinFechaTotal = dashboard_without_date($pdo, null, null);
    if ($dashboardPeriod['error'] === '') {
        $filterInput['desde'] = $dashboardPeriod['desde'] ?? '';
        $filterInput['hasta'] = $dashboardPeriod['hasta'] ?? '';
        if ($isAdmin) {
        $dashboardCounts = dashboard_counts($pdo,$dashboardPeriod['desde'],$dashboardPeriod['hasta']);
        $previousCounts = dashboard_previous_counts($pdo,$dashboardPeriod['desde'],$dashboardPeriod['hasta']);
        $dashboardMonths = dashboard_months($pdo,$dashboardPeriod['desde'],$dashboardPeriod['hasta']);
        $dashboardVendors = dashboard_vendors($pdo,$dashboardPeriod['desde'],$dashboardPeriod['hasta']);
        $dashboardSinFecha = dashboard_without_date($pdo,$dashboardPeriod['desde'],$dashboardPeriod['hasta']);
        foreach ($dashboardMonths as $item) $dashboardMaxMonth = max($dashboardMaxMonth, (int)$item['total']);
        foreach ($dashboardVendors as $item) $dashboardMaxVendor = max($dashboardMaxVendor, (int)$item['cantidad']);
        }
        list($where, $params) = filtros_registros_usuario($pdo,$filterInput);
        $countSt = $pdo->prepare('SELECT COUNT(*) FROM registros' . $where);
        $countSt->execute($params);
        $filteredCount = (int)$countSt->fetchColumn();
        $pages = max(1, (int)ceil($filteredCount / $perPage));
        $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $st = $pdo->prepare('SELECT * FROM registros' . $where . ' ORDER BY id ' . strtoupper($order) . ' LIMIT :lim OFFSET :off');
        bind_filters($st, $params);
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    }
}
$exportParams = array_intersect_key($filterInput, array_flip(['q','estado','mes','soporte','orden','desde','hasta']));
$exportUrl = 'exportar_excel.php?'.http_build_query($exportParams);
$todayNames = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$todayMonths = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$todayLabel = ucfirst($todayNames[(int)date('w')]).', '.date('j').' de '.$todayMonths[(int)date('n')-1].' de '.date('Y');
$flashes = get_flashes();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h(view_title($view, true)) ?> | Soporte</title><link rel="stylesheet" href="assets/app.css?v=colores20261013"><?php if (logged_in()): ?><link rel="stylesheet" href="assets/ui_shared.css?v=20261014a"><?php endif; ?><?php if (!logged_in()): ?><link rel="stylesheet" href="assets/login.css?v=20261013dorelly1"><?php endif; ?></head><body class="<?= !logged_in() ? 'auth-page' : '' ?>">
<?php if (!logged_in()): ?>
<div class="auth-shell">
    <main class="auth-card" aria-labelledby="login-heading">
        <div class="auth-brand" aria-label="DORELLYTECH">
            <svg class="auth-brand-icon" viewBox="0 0 90 82" width="73" height="68" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
                <defs><linearGradient id="db-logo" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#05e1eb"/><stop offset="1" stop-color="#0398ed"/></linearGradient><linearGradient id="db-green" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#1cf1c2"/><stop offset="1" stop-color="#08b784"/></linearGradient></defs>
                <path d="M9 24V58C9 67 22 73 39 73S69 67 69 58V24" fill="url(#db-logo)"/>
                <ellipse cx="39" cy="24" rx="30" ry="13" fill="#04d5e9"/>
                <path d="M9 37C9 46 22 52 39 52S69 46 69 37M9 52C9 61 22 67 39 67S69 61 69 52" fill="none" stroke="#052b72" stroke-width="4" opacity=".67"/>
                <rect x="53" y="49" width="33" height="29" rx="8" fill="url(#db-green)"/>
                <path d="M69.5 55V71M61.5 63H77.5" stroke="#fff" stroke-width="5" stroke-linecap="round"/>
            </svg>
            <span>DORELLY<span class="auth-brand-accent">TECH</span></span>
        </div>
        <?php if ($view === 'ayuda-acceso'): ?>
            <h1 id="login-heading">Recuperar contraseña</h1>
            <p class="auth-subtitle">Estamos aquí para ayudarte a recuperar el acceso.</p>
            <div class="auth-help-box"><strong>Solicita ayuda al administrador</strong><p>Por seguridad, las contraseñas no se muestran ni se envían desde esta página. Comunícate con el administrador de tu sistema para restablecer tu acceso. El restablecimiento automático por correo aún no está habilitado.</p></div>
            <a class="auth-submit auth-submit-link" href="index.php?view=login">Volver a iniciar sesión <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <h1 id="login-heading">Bienvenido de nuevo</h1>
            <p class="auth-subtitle">Ingresa para acceder al panel de soporte y gestionar<br class="auth-desktop-break"> todos tus registros.</p>
            <?php foreach ($errors as $e): ?><div role="alert" class="auth-error"><?= h($e) ?></div><?php endforeach; ?>
            <form method="post" action="index.php?view=login" class="auth-form" id="auth-login-form">
                <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
                <input type="hidden" name="action" value="login">
                <div class="auth-field">
                    <label for="auth-user">Correo electrónico</label>
                    <div class="auth-input-wrap">
                        <svg aria-hidden="true" class="auth-input-icon" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg>
                        <input id="auth-user" name="usuario" type="text" maxlength="80" placeholder="Ingresa tu correo electrónico" required autocomplete="username" autofocus>
                    </div>
                </div>
                <div class="auth-field">
                    <label for="auth-pass">Contraseña</label>
                    <div class="auth-input-wrap">
                        <svg aria-hidden="true" class="auth-input-icon" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15" r="1"/></svg>
                        <input id="auth-pass" name="clave" type="password" placeholder="Ingresa tu contraseña" required autocomplete="current-password">
                        <button class="auth-password-toggle" id="auth-password-toggle" type="button" aria-label="Mostrar contraseña" aria-pressed="false" aria-controls="auth-pass">
                            <svg class="auth-eye-off" aria-hidden="true" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.6 5.1A10.7 10.7 0 0 1 12 5c5 0 9 4 10 7a13.7 13.7 0 0 1-3.1 4.2M6.7 6.8C4.2 8.2 2.7 10.6 2 12c1 3 5 7 10 7 1.7 0 3.2-.5 4.5-1.2"/><path d="M10.5 10.5a2 2 0 0 0 3 3"/></svg>
                            <svg class="auth-eye-on" aria-hidden="true" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <span class="auth-caps-msg" id="auth-caps-msg" hidden>Bloq Mayús está activado</span>
                </div>
                <div class="auth-forgot-row"><a href="index.php?view=ayuda-acceso">¿Olvidaste tu contraseña?</a></div>
                <button type="submit" class="auth-submit" id="auth-submit">Iniciar sesión <span aria-hidden="true">→</span></button>
            </form>
            <div class="auth-recovery">
                <div class="auth-separator"><span>¿Necesitas ayuda para acceder?</span></div>
                <a class="auth-recover-link" href="index.php?view=ayuda-acceso">
                    <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a5 5 0 0 0-6.5 6.5L3 18v3h3l5.2-5.2a5 5 0 0 0 6.5-6.5L15 12l-3-3 2.7-2.7Z"/></svg>
                    Recuperar contraseña
                </a>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php else: ?>
<div class="app-layout">
<?php ui_sidebar($pdo, $view); ?>
<main class="main-area">
<?php ui_topbar($pdo, view_title($view, false), $view==='registros' ? 'Administra y da seguimiento a los registros reales de soporte técnico.' : ''); ?>
<section class="main-content">
<?php foreach ($flashes as [$type, $message]): ?><div class="notice <?= h($type) ?>"><?= h($message) ?></div><?php endforeach; ?>
<?php foreach ($errors as $error): ?><div class="notice error"><?= h($error) ?></div><?php endforeach; ?>
<?php if ($view === 'registros'): ?>
    <?php if ($isAdmin): ?>
    <div class="db-welcome">
      <span class="db-welcome-icon" aria-hidden="true">▥</span>
      <div class="db-welcome-copy"><h2>¡Bienvenido, <?= h((string)($_SESSION['username'] ?? 'usuario')) ?>!</h2><p>Resumen de la actividad de soporte según los registros guardados en MariaDB.</p></div>
      <div class="db-welcome-art" aria-hidden="true"><span>▣</span><span>✓</span></div>
    </div>
    <?php if ($dashboardPeriod['error'] !== ''): ?><div class="notice error"><?= h($dashboardPeriod['error']) ?> Ajusta el rango para ver los resultados.</div><?php endif; ?>
    <div class="db-overview">
      <section class="db-overview-main">
        <div class="db-section-heading db-main-heading">
          <div><h2>◉ Resumen general</h2><p class="muted">Al ingresar se muestran todos los clientes, incluidos los importados sin fecha.</p></div>
          <form method="get" action="index.php" class="db-period-form" id="db-period-form">
            <input type="hidden" name="view" value="registros">
            <input type="hidden" name="q" value="<?= h($q) ?>">
            <input type="hidden" name="estado" value="<?= h($filterEstado) ?>">
            <input type="hidden" name="mes" value="<?= h($filterMes) ?>">
            <input type="hidden" name="orden" value="<?= h($order) ?>">
            <input type="hidden" name="por_pagina" value="<?= (int)$perPage ?>">
            <input type="hidden" id="db-periodo" name="periodo" value="<?= h($dashboardPeriod['modo']) ?>">
            <input type="hidden" name="desde" id="db-fecha-desde" value="<?= h($dashboardPeriod['desde'] ?? '') ?>">
            <input type="hidden" name="hasta" id="db-fecha-hasta" value="<?= h($dashboardPeriod['hasta'] ?? '') ?>">
            <span class="db-current-period" aria-live="polite"><?= h($dashboardPeriod['etiqueta']) ?><?php if ($dashboardPeriod['desde'] && $dashboardPeriod['hasta']): ?> · <?= h(short_date($dashboardPeriod['desde'])) ?> al <?= h(short_date($dashboardPeriod['hasta'])) ?><?php endif; ?></span>
            <button type="button" class="btn btn-secondary db-open-picker" id="db-open-range" aria-controls="db-range-popover" aria-expanded="false">▦ Calendario <span aria-hidden="true">▾</span></button>
            <div class="db-range-popover" id="db-range-popover" hidden aria-label="Seleccionar rango de fechas">
              <div class="db-range-shortcuts" id="db-range-shortcuts">
                <button type="button" data-period="todos">Todos</button>
                <button type="button" data-period="hoy">Hoy</button>
                <button type="button" data-period="ayer">Ayer</button>
                <button type="button" data-period="7d">Últimos 7 días</button>
                <button type="button" data-period="30d">Últimos 30 días</button>
                <button type="button" data-period="mes">Este mes</button>
                <button type="button" data-period="personalizado">Personalizado</button>
              </div>
              <div class="db-range-content">
                <div class="db-range-cal-head">
                  <button type="button" id="db-month-prev" aria-label="Mes anterior">‹</button>
                  <span id="db-cal-title" aria-live="polite">Selecciona tus fechas</span>
                  <button type="button" id="db-month-next" aria-label="Mes siguiente">›</button>
                </div>
                <div class="db-range-months" id="db-cal-months"></div>
                <div class="db-range-bottom">
                  <span id="db-range-summary">Todas las fechas</span>
                  <button type="button" class="btn btn-secondary" id="db-range-cancel">Cancelar</button>
                  <button type="button" class="btn btn-primary" id="db-range-apply">Aplicar</button>
                </div>
              </div>
            </div>
          </form>
        </div>
        <div class="db-stat-grid">
        <?php foreach ([
            ['total','Total de registros','Clientes registrados','▦','blue'],
            ['PRODUCCION','En producción','Sistemas activos','▤','green'],
            ['DEMO','Demostraciones','Registros en demo','◈','purple'],
            ['INTERNO','Internos','Uso interno','♟','amber']
        ] as $stat): ?>
          <?php $statKey=$stat[0]; $delta=dashboard_delta((int)$dashboardCounts[$statKey], $previousCounts===null?null:(int)$previousCounts[$statKey]); ?>
          <div class="db-stat db-stat-<?= h($stat[4]) ?>">
            <span class="db-stat-icon"><?= h($stat[3]) ?></span>
            <div class="db-stat-copy"><span class="db-stat-label"><?= h($stat[1]) ?></span><div class="db-stat-number"><?= number_format((int)$dashboardCounts[$statKey]) ?><?php if ($delta !== ''): ?><span class="db-stat-delta <?= $previousCounts!==null && $dashboardCounts[$statKey] < $previousCounts[$statKey]?'negative':'positive' ?>"><?= h($delta) ?></span><?php endif; ?></div><span class="db-stat-detail"><?= h($stat[2]) ?></span></div>
          </div>
        <?php endforeach; ?>
        </div>
        <?php if ($dashboardSinFechaTotal > 0): ?>
        <div class="db-undated-alert" role="status">ℹ <strong><?= number_format($dashboardSinFechaTotal) ?> registro(s) sin FECHA.</strong>
          <?php if ($dashboardPeriod['modo'] === 'todos'): ?>Se incluyen en el resumen, directorio, Excel y en el gráfico según su MES registrado.
          <?php else: ?>No aparecen en el periodo seleccionado. <a href="index.php">Ver todos los registros</a>.<?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="db-period-foot">Periodo: <strong><?= h($dashboardPeriod['etiqueta']) ?></strong><?php if ($dashboardPeriod['desde']): ?> · Desde <?= h(short_date($dashboardPeriod['desde'])) ?><?php endif; ?><?php if ($dashboardPeriod['hasta']): ?> hasta <?= h(short_date($dashboardPeriod['hasta'])) ?><?php endif; ?><?php if ($previousCounts!==null): ?> · Variación frente al periodo anterior de igual duración<?php endif; ?></p>
      </section>
      <aside class="db-quick-actions">
        <a class="db-action-new" href="index.php?view=nuevo">＋ &nbsp; Agregar registro</a>
        <a class="db-action-excel" href="<?= h($exportUrl) ?>">▤ &nbsp; Exportar Excel</a>
      </aside>
    </div>
    <div class="db-analytics">
      <section class="db-analytic-card">
        <div class="db-section-heading"><div><h2><span class="db-heading-symbol blue">▥</span> Registros por mes</h2><p class="muted"><?php if ($dashboardPeriod['modo'] === 'todos'): ?>Por MES del registro (incluye clientes sin FECHA; sin distinguir año).<?php else: ?>Registros agrupados por FECHA dentro del rango seleccionado.<?php endif; ?></p></div></div>
        <?= soporte_month_svg($dashboardMonths) ?>
        <p class="db-metric-note">Total graficado: <strong><?= number_format(array_sum(array_column($dashboardMonths, 'total'))) ?></strong> registro(s)<?php if ($dashboardPeriod['modo'] === 'todos'): ?>, agrupados por MES (sin distinguir año).<?php else: ?> con FECHA dentro del rango seleccionado.<?php endif; ?></p>
      </section>
      <section class="db-analytic-card">
        <div class="db-section-heading"><div><h2><span class="db-heading-symbol violet">◕</span> Estados del servicio</h2><p class="muted">Distribución de registros en el periodo.</p></div></div>
        <div class="db-states">
          <?php foreach ([['PRODUCCION','Producción','production'],['DEMO','Demostración','demo'],['INTERNO','Interno','interno'],['OTROS','Otros','otros']] as $stInfo): ?>
          <?php $estadoColor = ['production'=>'#14b68b','demo'=>'#9574e8','interno'=>'#f0af3c','otros'=>'#a5aec1'][$stInfo[2]]; ?>
          <div class="db-state-row"><span class="db-state-label"><?= h($stInfo[1]) ?></span><?= soporte_metric_svg((int)$dashboardCounts[$stInfo[0]], (int)$dashboardCounts['total'], $estadoColor, $stInfo[1], 'db-state-track') ?><strong><?= (int)$dashboardCounts[$stInfo[0]] ?></strong><span class="db-state-percent"><?= dashboard_fraction((int)$dashboardCounts[$stInfo[0]],(int)$dashboardCounts['total']) ?>%</span></div>
          <?php endforeach; ?>
        </div>
        <p class="db-metric-note">Total por estado: <strong><?= (int)$dashboardCounts['PRODUCCION'] + (int)$dashboardCounts['DEMO'] + (int)$dashboardCounts['INTERNO'] + (int)$dashboardCounts['OTROS'] ?></strong> registro(s).</p>
      </section>
      <section class="db-analytic-card">
        <div class="db-section-heading"><div><h2><span class="db-heading-symbol orange">♕</span> Top vendedores / asesores</h2><p class="muted">Cantidad de registros por vendedor.</p></div></div>
        <div class="db-vendors">
          <?php if (!$dashboardVendors): ?><p class="muted">Aún no hay vendedores con registros en este periodo.</p><?php endif; ?>
          <?php foreach ($dashboardVendors as $i=>$v): ?>
          <?php $vendorColor = ['#8c73e9','#3c93ee','#14bdb4','#f1ad43','#92a3be'][$i % 5]; ?>
          <div class="db-vendor-row"><span class="db-vendor-rank"><?= $i+1 ?></span><span class="db-vendor-name" title="<?= h($v['nombre']) ?>"><?= h($v['nombre']) ?></span><?= soporte_metric_svg((int)$v['cantidad'], (int)$dashboardCounts['total'], $vendorColor, (string)$v['nombre'], 'db-vendor-track') ?><strong><?= (int)$v['cantidad'] ?></strong><span class="db-vendor-share"><?= dashboard_fraction((int)$v['cantidad'],(int)$dashboardCounts['total']) ?>%</span></div>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
    <?php else: ?>
    <div class="panel" class="users-my-sales"><div class="panel-header"><div><h2>Mis ventas</h2><p class="muted">Solo se muestran los registros que te han asignado o que has creado con tu usuario. Puedes agregar registros nuevos.</p></div><strong><?=number_format($filteredCount)?> registros</strong></div></div>
    <?php endif; ?>
    <div class="panel db-directory"><div class="panel-header db-directory-header"><div><h2>▤ &nbsp; <?= $isAdmin?'Directorio de registros':'Directorio de mis ventas' ?></h2><p class="muted">Consulta, filtra y administra los registros; los más recientes aparecen primero.</p></div><div class="db-directory-count"><?= $filteredCount ? number_format(($page-1)*$perPage+1).'–'.number_format(min($page*$perPage,$filteredCount)) : '0' ?> de <?= number_format($filteredCount) ?> registros</div></div>
    <form class="filters db-directory-filters" method="get" action="index.php">
      <input type="hidden" name="view" value="registros">
      <input type="hidden" name="periodo" value="<?= h($dashboardPeriod['modo']) ?>">
      <input type="hidden" name="desde" value="<?= h($dashboardPeriod['desde'] ?? '') ?>">
      <input type="hidden" name="hasta" value="<?= h($dashboardPeriod['hasta'] ?? '') ?>">
      <label class="search-box"><span>⌕</span><input name="q" placeholder="Buscar RUC, empresa, teléfono, vendedor..." value="<?= h($q) ?>"></label>
      <select name="estado"><option value="">Todos los estados</option><?php foreach (estados() as $item): ?><option value="<?= h($item) ?>" <?= $filterEstado===$item?'selected':'' ?>><?= h($item==='PRODUCCION'?'PRODUCCIÓN':$item) ?></option><?php endforeach; ?></select>
      <select name="mes"><option value="">Todos los meses</option><?php foreach (meses() as $item): ?><option value="<?= h($item) ?>" <?= $filterMes===$item?'selected':'' ?>><?= h($item) ?></option><?php endforeach; ?></select>
      <select name="orden"><option value="desc" <?= $order==='desc'?'selected':'' ?>>Más recientes</option><option value="asc" <?= $order==='asc'?'selected':'' ?>>Más antiguos</option></select>
      <select name="por_pagina" aria-label="Registros por página"><?php foreach ([10,25,50,100] as $n): ?><option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?> por página</option><?php endforeach; ?></select>
      <button class="btn btn-secondary" type="submit">⚑ Filtrar</button><a href="index.php" class="btn btn-ghost">↻ Limpiar</a>
    </form>
    <div class="table-meta"><span><b><?= number_format($filteredCount) ?></b> resultados · FECHA del registro: <?= h($dashboardPeriod['etiqueta']) ?></span><span><?= (int)$perPage ?> por página · Desliza horizontalmente para más columnas →</span></div>
    <div class="table-scroll"><table class="records-table"><thead><tr><th>ID</th><th>F. PRODUCCIÓN</th><th>MES</th><th>FECHA</th><th>RUC</th><th class="company-col">RAZÓN SOCIAL</th><th>URL</th><th>ESTADO</th><th>N. SOPORTE</th><th>N. VENDEDOR</th><th>N° CELULAR</th><th>PROYECTO</th><th>PLAN</th><th>MONTO</th><th>¿PAGÓ?</th><th>EMITE</th><th>OBSERVACIÓN</th><th>SERIE COMPROBANTE</th><th>NÚMERO COMPROBANTE</th><th>IMAGEN DE VOUCHER</th><th>INFORMACIÓN SOPORTE</th><th>ACCIÓN</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="22" class="empty-table"><div class="empty-icon">⌕</div><b>No hay registros</b><p>Prueba con otros filtros o selecciona «Todos los registros» para incluir clientes sin fecha.</p></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr><td class="num">#<?= (int)$r['id'] ?></td><td><?= h(short_date($r['f_produccion'])) ?></td><td><span class="month-label"><?= h($r['mes']) ?></span></td><td><?= h(short_date($r['fecha'])) ?></td><td class="ruc"><?= h($r['ruc']) ?></td><td class="company"><span title="<?= h($r['razon_social']) ?>"><?= h($r['razon_social']) ?></span></td><td><?php if ($r['url']): ?><a class="url-link" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($r['url']) ?>"><?= h(parse_url($r['url'], PHP_URL_HOST) ?: 'Visitar web') ?> ↗</a><?php else: ?><span class="muted">—</span><?php endif; ?></td><td><span class="state-pill state-<?= h(strtolower($r['estado'])) ?>"><?= h($r['estado']==='PRODUCCION'?'PRODUCCIÓN':$r['estado']) ?></span></td><td><span class="name-pill support"><?= h($r['soporte'] ?: '—') ?></span></td><td><span class="name-pill sales"><?= h($r['vendedor'] ?: '—') ?></span></td><td class="phone"><?= h($r['celular'] ?: '—') ?></td>
    <td><?= h($r['proyecto'] ?: '—') ?></td><td><?= h($r['plan'] ?: '—') ?></td>
    <td><?= $r['monto'] === null ? '—' : h(number_format((float)$r['monto'],2,'.','')) ?></td>
    <td><?= h($r['pago'] ?: '—') ?></td><td><?= h($r['emite'] ?: '—') ?></td>
    <td class="cut-text" title="<?= h((string)$r['observacion']) ?>"><?= h($r['observacion'] ?: '—') ?></td>
    <td><?= h($r['serie_comprobante'] ?: '—') ?></td><td><?= h($r['numero_comprobante'] ?: '—') ?></td>
    <td><?php if ($r['imagen_voucher']): ?><a href="voucher.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener noreferrer" class="edit-link">Ver imagen</a><?php else: ?>—<?php endif; ?></td>
    <td class="cut-text" title="<?= h((string)$r['informacion_soporte']) ?>"><?= h($r['informacion_soporte'] ?: '—') ?></td>
    <td class="action-col">
      <div class="record-actions">
        <?php if (permitido($pdo,'registros_editar')): ?><a class="edit-link" href="index.php?view=editar&amp;id=<?= (int)$r['id'] ?>">✎ Editar</a><?php endif; ?>
        <?php if (permitido($pdo,'registros_eliminar')): ?><form method="post" action="index.php" class="delete-record-form" data-registro-id="<?= (int)$r['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf()) ?>">
          <input type="hidden" name="action" value="eliminar">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button type="submit" class="delete-link" aria-label="Eliminar registro #<?= (int)$r['id'] ?>">🗑 Eliminar</button>
        </form><?php endif; ?>
      </div>
    </td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="pagination"><span>Página <?= $page ?> de <?= $pages ?></span><div class="page-actions"><a class="btn btn-secondary small <?= $page<=1?'disabled':'' ?>" href="<?= h(query_params(['page'=>max(1,$page-1)])) ?>">← Anterior</a><a class="btn btn-secondary small <?= $page >= $pages?'disabled':'' ?>" href="<?= h(query_params(['page'=>min($pages,$page+1)])) ?>">Siguiente →</a></div></div>
    </div>
<?php elseif ($view === 'nuevo' || $view === 'editar'): ?>
    <div class="page-intro"><a class="back-link" href="index.php">← Volver a los registros</a><p>Completa los mismos datos que utilizabas en tu hoja de cálculo. Los cambios se guardan inmediatamente en MySQL.</p></div>
    <div class="form-panel"><div class="form-title"><span class="form-icon">✎</span><div><h2><?= $view==='editar' ? 'Información del registro #'.h((string)$current['id']) : 'Nuevo cliente / registro' ?></h2><p class="muted">Los campos marcados con * son obligatorios.</p><?php if ($catalogoAdmin): ?><p class="tiny"><a class="back-link" href="index.php?view=areas">Administrar usuarios de soporte / ventas</a> · <a class="back-link" href="index.php?view=finanzas">Administrar proyectos / planes</a></p><?php endif; ?></div></div>
    <form method="post" action="index.php?view=<?= $view ?>" enctype="multipart/form-data" class="record-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="guardar"><input type="hidden" name="id" value="<?= h((string)($current['id'] ?? '')) ?>">
        <div class="form-grid">
            <label>F. PRODUCCIÓN <input name="f_produccion" type="date" value="<?= h((string)($current['f_produccion'] ?? '')) ?>"></label>
            <label>MES * <select name="mes" required><?php foreach (meses() as $mes): ?><option value="<?= h($mes) ?>" <?= ($current['mes'] ?? '')===$mes?'selected':'' ?>><?= h($mes) ?></option><?php endforeach; ?></select></label>
            <label>FECHA <input name="fecha" type="date" value="<?= h((string)($current['fecha'] ?? '')) ?>"></label>
            <label>RUC / DNI
                <span class="factiliza-input-row">
                    <input name="ruc" id="factiliza-documento" inputmode="numeric" maxlength="11" pattern="[0-9]{8}|[0-9]{11}" title="Ingresa 8 dígitos (DNI) u 11 dígitos (RUC)" placeholder="RUC de 11 o DNI de 8 dígitos" value="<?= h((string)($current['ruc'] ?? '')) ?>">
                    <button type="button" class="btn btn-secondary factiliza-btn" id="factiliza-consultar">Consultar</button>
                </span>
                <small class="factiliza-help">Consulta automática al completar 8 dígitos de DNI u 11 de RUC. También puedes pulsar Consultar.</small>
                <span id="factiliza-mensaje" class="factiliza-mensaje" role="status" aria-live="polite"></span>
            </label>
            <label class="span-two">RAZÓN SOCIAL / NOMBRE * <input id="factiliza-razon-social" name="razon_social" maxlength="255" required placeholder="Se completará automáticamente; también puedes escribirlo" value="<?= h((string)($current['razon_social'] ?? '')) ?>"></label>
            <label class="span-two">URL DEL SISTEMA <input name="url" type="text" maxlength="500" placeholder="https://ejemplo.rest.brainpos.pe" value="<?= h((string)($current['url'] ?? '')) ?>"></label>
            <label>ESTADO * <select name="estado" required><?php foreach (estados() as $e): ?><option value="<?= h($e) ?>" <?= ($current['estado'] ?? '')===$e?'selected':'' ?>><?= h($e==='PRODUCCION'?'PRODUCCIÓN':$e) ?></option><?php endforeach; ?></select></label>
            <label>N. SOPORTE
                <select name="soporte">
                    <option value="">Selecciona un usuario</option>
                    <?php foreach (catalogo_opcion_select($opcionesActivas['soporte'], (string)($current['soporte'] ?? '')) as $op): ?>
                    <option value="<?= h($op) ?>" <?= (string)($current['soporte'] ?? '')===$op?'selected':'' ?>><?= h($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>N. VENDEDOR
                <select name="vendedor" <?= !$isAdmin?'disabled':'' ?>>
                    <option value="">Selecciona un usuario</option>
                    <?php if (!$isAdmin): ?><option value="<?=h($nombreVendedorAcceso)?>" selected><?=h($nombreVendedorAcceso)?></option><?php endif; ?>
                    <?php foreach (catalogo_opcion_select($opcionesActivas['vendedor'], (string)($current['vendedor'] ?? '')) as $op): ?>
                    <option value="<?= h($op) ?>" <?= (string)($current['vendedor'] ?? '')===$op?'selected':'' ?>><?= h($op) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$isAdmin): ?><input type="hidden" name="vendedor" value="<?=h($nombreVendedorAcceso)?>"><?php endif; ?>
            </label>
            <label>N° CELULAR <input name="celular" maxlength="35" inputmode="tel" placeholder="Ej. 987 654 321" value="<?= h((string)($current['celular'] ?? '')) ?>"></label>
            <div class="form-section-title span-two">Facturación, planes y seguimiento</div>
            <label>PROYECTO
                <select name="proyecto">
                    <option value="">Selecciona una opción</option>
                    <?php foreach (catalogo_opcion_select($opcionesActivas['proyecto'], (string)($current['proyecto'] ?? '')) as $op): ?>
                    <option value="<?= h($op) ?>" <?= (string)($current['proyecto'] ?? '')===$op?'selected':'' ?>><?= h($op) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>PLAN
                <select name="plan">
                    <option value="">Selecciona una opción</option>
                    <?php foreach (catalogo_opcion_select($opcionesActivas['plan'], (string)($current['plan'] ?? '')) as $op): ?>
                    <option value="<?= h($op) ?>" <?= isset($preciosPorPlan[$op]) ? 'data-precio="'.h($preciosPorPlan[$op]).'"' : '' ?> <?= (string)($current['plan'] ?? '')===$op?'selected':'' ?>><?= h($op) ?><?= isset($preciosPorPlan[$op]) ? ' — S/ '.h($preciosPorPlan[$op]) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>MONTO (S/) <input name="monto" type="number" step="0.01" min="0" max="9999999999.99" list="montos-antiguos" placeholder="Selecciona un plan antiguo o ingresa monto" value="<?= h((string)($current['monto'] ?? '')) ?>"><datalist id="montos-antiguos"><option value="59.90" label="ORDENA (ANTIGUO)"></option><option value="89.90" label="CRECE (ANTIGUO)"></option><option value="159.90" label="AVANZA (ANTIGUO)"></option></datalist><small class="muted">Los planes antiguos completan el precio automáticamente. Puedes cambiarlo manualmente.</small></label>
            <label>¿PAGÓ? <select name="pago"><option value="">Sin indicar</option><?php foreach (['SI','NO'] as $o): ?><option value="<?= $o ?>" <?= ($current['pago'] ?? '')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></label>
            <label>EMITE <select name="emite"><option value="">Sin indicar</option><?php foreach (['SI','NO'] as $o): ?><option value="<?= $o ?>" <?= ($current['emite'] ?? '')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></label>
            <label>SERIE COMPROBANTE <input name="serie_comprobante" maxlength="30" placeholder="Ej. F001" value="<?= h((string)($current['serie_comprobante'] ?? '')) ?>"></label>
            <label>NÚMERO COMPROBANTE <input name="numero_comprobante" maxlength="40" placeholder="Ej. 00000123" value="<?= h((string)($current['numero_comprobante'] ?? '')) ?>"></label>
            <label>IMAGEN DE VOUCHER <input type="file" name="voucher" accept="image/png,image/jpeg,image/webp"><small class="muted">JPG, PNG o WEBP · Máximo 5 MB.</small><?php if (!empty($current['imagen_voucher']) && !empty($current['id'])): ?><a class="edit-link" target="_blank" rel="noopener noreferrer" href="voucher.php?id=<?= (int)$current['id'] ?>">Ver imagen actual</a><span class="remove-voucher"><input type="checkbox" name="borrar_voucher" value="1"> Quitar imagen anterior</span><?php endif; ?></label>
            <label class="span-two">OBSERVACIÓN <textarea name="observacion" rows="3" maxlength="65000" placeholder="Detalle del cliente o la situación"><?= h((string)($current['observacion'] ?? '')) ?></textarea></label>
            <label class="span-two">INFORMACIÓN SOPORTE <textarea name="informacion_soporte" rows="4" maxlength="65000" placeholder="Seguimiento realizado, comentarios o solución aplicada"><?= h((string)($current['informacion_soporte'] ?? '')) ?></textarea></label>
        </div>
        <?php if ($isAdmin && $view==='editar'): ?>
        <div class="users-owner" class="users-owner"><label>Propietario del registro (vendedor) <select name="propietario_id"><option value="0">Sin asignar / administración</option><?php foreach($vendedoresUsuarios as $v):?><option value="<?=(int)$v['id']?>" <?= (int)($current['creado_por']??0)===(int)$v['id']?'selected':'' ?>><?=h($v['nombre'])?> <?=!$v['activo']?'(inactivo)':''?></option><?php endforeach;?></select></label><small>Asigna los clientes importados para que cada vendedor vea únicamente sus propios registros.</small></div>
        <?php endif; ?>
        <div class="form-footer"><span class="muted">* Cambios seguros · Registro único y ordenado por ID</span><div><a href="index.php" class="btn btn-secondary">Cancelar</a><button type="submit" class="btn btn-primary"><?= $view==='editar'?'Guardar cambios':'Guardar registro' ?> →</button></div></div>
    </form></div>
<?php elseif ($view === 'areas' || $view === 'finanzas'): ?>
    <?php if (!$catalogoAdmin): ?>
    <div class="notice error">Solo el administrador puede configurar roles y listas de selección.</div>
    <?php elseif (!$catalogoInstalado): ?>
    <div class="panel catalogo-start"><h2>Activar módulo Roles</h2><p class="muted">Falta instalar el catálogo en MariaDB. Tus registros existentes se conservarán.</p><a class="btn btn-primary" href="actualizar_catalogos.php">Activar módulo →</a></div>
    <?php else: ?>
    <div class="roles-intro"><div><span class="roles-eyebrow">CONFIGURACIÓN DE LISTAS</span><h2><?= $view==='areas'?'Áreas de trabajo':'Finanzas y productos' ?></h2><p class="muted"><?= $view==='areas'?'Administra quién puede seleccionarse como responsable de Soporte o como Vendedor.':'Administra las opciones que aparecen en Proyecto y Plan al registrar clientes.' ?> Los cambios se reflejan en los nuevos formularios sin editar código.</p></div><a href="index.php?view=nuevo" class="btn btn-secondary">Ver formulario →</a></div>
    <div class="roles-tabs"><a class="<?= $view==='areas'?'active':'' ?>" href="index.php?view=areas">♧ Áreas</a><a class="<?= $view==='finanzas'?'active':'' ?>" href="index.php?view=finanzas">▤ Finanzas</a></div>
    <?php if ($view==='finanzas' && !$preciosInstalados): ?>
    <div class="notice error">Para activar precios por plan abre <a href="actualizar_precios.php">Actualizar montos de planes antiguos →</a>. Tus registros actuales no se modificarán.</div>
    <?php endif; ?>
    <div class="catalog-grid">
    <?php foreach (catalogo_definiciones() as $tipo=>$def): ?>
    <?php if ($def['grupo'] !== $view) continue; ?>
    <?php $catalogoItems = catalogo_todos($pdo, $tipo); ?>
    <section class="catalog-card" id="tipo-<?= h($tipo) ?>">
        <div class="catalog-head"><div><span class="catalog-kicker"><?= h($def['campo']) ?></span><h3><?= h($def['titulo']) ?></h3><p class="muted">Agrega, cambia o desactiva opciones para este campo.</p></div><span class="catalog-count"><?= count($catalogoItems) ?> opciones</span></div>
        <form method="post" action="index.php?view=<?= h($view) ?>" class="catalog-add">
            <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="catalogo_guardar"><input type="hidden" name="tipo" value="<?= h($tipo) ?>">
            <label>Agregar nueva opción <input type="text" name="nombre" required maxlength="100" placeholder="<?= in_array($tipo,['soporte','vendedor'],true)?'Nombre del trabajador':'Nombre de proyecto o plan' ?>"></label>
            <?php if ($tipo === 'plan' && $preciosInstalados): ?>
            <label class="catalog-price">Precio (S/) <input type="number" name="precio" min="0" max="9999999999.99" step="0.01" placeholder="Ej. 59.90"></label>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">＋ Agregar</button>
        </form>
        <?php if (!$catalogoItems): ?><p class="muted catalog-empty">Aún no hay opciones. Agrega una arriba.</p><?php endif; ?>
        <div class="catalog-items">
        <?php foreach ($catalogoItems as $item): ?>
            <div class="catalog-item <?= !$item['activo']?'inactive':'' ?>">
                <form class="catalog-edit" method="post" action="index.php?view=<?= h($view) ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="catalogo_guardar"><input type="hidden" name="tipo" value="<?= h($tipo) ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <label><span class="screen-reader-only">Editar nombre</span><input name="nombre" maxlength="100" required value="<?= h((string)$item['nombre']) ?>"></label>
                    <?php if ($tipo === 'plan' && $preciosInstalados): ?>
                    <label class="catalog-price"><span class="screen-reader-only">Precio S/</span><input name="precio" type="number" min="0" max="9999999999.99" step="0.01" value="<?= $item['precio'] === null ? '' : h(number_format((float)$item['precio'],2,'.','')) ?>" placeholder="S/ 0.00" title="Monto del plan en soles"></label>
                    <?php endif; ?>
                    <button class="btn btn-secondary small" type="submit">Guardar</button>
                </form>
                <form class="catalog-toggle" method="post" action="index.php?view=<?= h($view) ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="catalogo_estado"><input type="hidden" name="tipo" value="<?= h($tipo) ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="activar" value="<?= (int)!$item['activo'] ?>">
                    <button class="btn small <?= $item['activo']?'btn-soft-red':'btn-soft-green' ?>" type="submit"><?= $item['activo']?'Desactivar':'Activar' ?></button>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
    </div>
    <p class="roles-footnote">Los precios se aplican al elegir un plan en un registro. Cambiar el precio del catálogo no altera importes de registros anteriores. Los planes nuevos no traen precio precargado.</p>
    <p class="roles-footnote">Desactivar o renombrar una opción no elimina ni modifica registros anteriores. Estas listas sirven para seleccionar responsables y productos; no cambian los permisos de acceso de los usuarios.</p>
    <?php endif; ?>
<?php elseif ($view === 'importar'): ?>
    <div class="page-intro"><a class="back-link" href="index.php">← Volver a los registros</a><p>Pasa los datos de tu hoja de Google Sheets a la nueva base de datos, sin escribirlos uno por uno.</p></div>
    <div class="form-panel import-panel"><div class="form-title"><span class="form-icon">⇪</span><div><h2>Importar archivo CSV</h2><p class="muted">Compatible con Google Sheets y Excel (CSV). Reconoce las columnas por nombre, incluso si hay columnas ocultas o vacías.</p></div></div>
    <div class="guide"><b>Cómo preparar el archivo:</b><ol><li>Abre tu hoja en Google Sheets.</li><li>Selecciona <b>Archivo → Descargar → Valores separados por comas (.csv)</b>.</li><li>Selecciona ese archivo aquí para importar todos los clientes.</li></ol></div>
    <form method="post" enctype="multipart/form-data" action="index.php?view=importar" class="stack"><input type="hidden" name="csrf" value="<?= h(csrf()) ?>"><input type="hidden" name="action" value="importar"><label>Archivo CSV (máximo 10 MB)<input type="file" name="archivo" accept=".csv,text/csv,text/plain" required></label><p class="tiny muted">Si el archivo tiene encabezados, el orden de las columnas no importa. Reconoce las 10 columnas antiguas y las nuevas. Las imágenes de voucher se suben desde el formulario de edición. Hasta 30,000 filas por archivo. Importar el mismo archivo dos veces está bloqueado.</p><button type="submit" class="btn btn-primary">Importar registros →</button></form>
    </div>
<?php else: ?><div class="panel"><h2>Página no encontrada</h2><a href="index.php">Volver al inicio</a></div><?php endif; ?>
</section></main></div>
<?php endif; ?>
<?php if (!logged_in()): ?><script src="assets/login.js?v=20261013dorelly1" defer></script><?php endif; ?>
<script src="assets/app.js" defer></script>
<script src="assets/dashboard_fechas.js?v=20261013fix2" defer></script>
</body></html>
