<?php
declare(strict_types=1);
require __DIR__.'/usuarios_lib.php';
require_once __DIR__.'/catalogos_lib.php';
require_once __DIR__.'/ui_shared.php';
ensure_login();
$pdo=db();
if (!usuarios_listos($pdo)) go('actualizar_usuarios.php');
// Antes de habilitar las cuentas vinculadas, mostrar su instalador en lugar de fallar.
if (!$pdo->query("SHOW COLUMNS FROM usuarios LIKE 'vendedor_catalogo_id'")->fetch()) go('vendedoras_instalar.php');
exigir_permiso($pdo,'usuarios');
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['id']??0);
    if ($action==='guardar_usuario') {
        $nombre=trim((string)($_POST['nombre']??''));
        $usuario=trim((string)($_POST['usuario']??''));
        $correo=trim((string)($_POST['correo']??''));
        $clave=(string)($_POST['clave']??'');
        $perfil=(string)($_POST['perfil']??'VENDEDOR');
        $catId=$perfil==='VENDEDOR' ? (int)($_POST['vendedor_catalogo_id']??0) : 0;
        $permisos=array_values(array_intersect(array_keys(permisos_disponibles()),(array)($_POST['permisos']??[])));
        if ($nombre===''||text_length($nombre)>100) $errors[]='Nombre obligatorio (máximo 100 caracteres).';
        if (!preg_match('/^[a-zA-Z0-9_.@-]{3,80}$/D',$usuario)) $errors[]='Usuario: entre 3 y 80 caracteres, sin espacios.';
        if (!filter_var($correo,FILTER_VALIDATE_EMAIL)||strlen($correo)>190) $errors[]='Indica un correo válido.';
        if (!in_array($perfil,['ADMINISTRADOR','VENDEDOR'],true)) $errors[]='Perfil inválido.';
        if ($catId>0) {
            $q=$pdo->prepare("SELECT COUNT(*) FROM opciones_catalogo WHERE id=:id AND tipo='vendedor' AND activo=1");
            $q->execute([':id'=>$catId]);
            if (!(int)$q->fetchColumn()) $errors[]='Selecciona un miembro activo del equipo de ventas.';
        }
        if ($id===0 && strlen($clave)<10) $errors[]='La contraseña inicial debe tener al menos 10 caracteres.';
        if ($id>0 && $clave!=='' && strlen($clave)<10) $errors[]='La nueva contraseña debe tener al menos 10 caracteres.';
        if (strlen($clave)>200) $errors[]='Contraseña demasiado larga.';
        if ($clave !== '' && $clave !== (string)($_POST['confirmar_clave'] ?? '')) $errors[]='Las contraseñas no coinciden.';
        $old=null;
        if ($id>0) {
            $st=$pdo->prepare('SELECT id,perfil,activo FROM usuarios WHERE id=:id');
            $st->execute([':id'=>$id]);$old=$st->fetch();
            if (!$old) $errors[]='El usuario no existe.';
            if ($id===(int)$_SESSION['uid'] && $perfil!=='ADMINISTRADOR') $errors[]='No puedes quitarte a ti mismo el perfil Administrador.';
            if ($old && $old['perfil']==='ADMINISTRADOR' && $perfil!=='ADMINISTRADOR') {
                $activeAdmins=(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil='ADMINISTRADOR' AND activo=1")->fetchColumn();
                if ($activeAdmins<=1 && (int)$old['activo']===1) $errors[]='Debe quedar al menos un administrador activo.';
            }
        }
        if (!$errors) {
            try {
                // Ningún permiso individual otorga acceso a otros clientes: ese aislamiento siempre se aplica por propietario.
                if ($id===0) {
                    $st=$pdo->prepare('INSERT INTO usuarios (usuario,nombre,correo,clave_hash,perfil,activo,permisos,auth_version,vendedor_catalogo_id,debe_cambiar_clave) VALUES (:usuario,:nombre,:correo,:hash,:perfil,1,:permisos,1,:cat_id,0)');
                    $st->execute([':usuario'=>$usuario,':nombre'=>$nombre,':correo'=>$correo,':hash'=>password_hash($clave,PASSWORD_DEFAULT),':perfil'=>$perfil,':permisos'=>json_encode($permisos),':cat_id'=>$catId>0?$catId:null]);
                    flash('success','Usuario creado correctamente.');
                } else {
                    $sets=['usuario=:usuario','nombre=:nombre','correo=:correo','perfil=:perfil','permisos=:permisos','vendedor_catalogo_id=:cat_id','auth_version=auth_version+1'];
                    $params=[':usuario'=>$usuario,':nombre'=>$nombre,':correo'=>$correo,':perfil'=>$perfil,':permisos'=>json_encode($permisos),':cat_id'=>$catId>0?$catId:null,':id'=>$id];
                    if ($clave!=='') { $sets[]='clave_hash=:hash';$params[':hash']=password_hash($clave,PASSWORD_DEFAULT); if ($id!==(int)$_SESSION['uid']) $sets[]='debe_cambiar_clave=0'; }
                    $st=$pdo->prepare('UPDATE usuarios SET '.implode(',',$sets).' WHERE id=:id');
                    $st->execute($params);
                    if ($id===(int)$_SESSION['uid']) {
                        $st2=$pdo->prepare('SELECT auth_version FROM usuarios WHERE id=:id');$st2->execute([':id'=>$id]);
                        $_SESSION['auth_version']=(int)$st2->fetchColumn();
                        $_SESSION['username']=$usuario;
                        session_regenerate_id(true);
                    }
                    flash('success','Cambios guardados. Si modificaste el acceso de otro usuario, su sesión anterior se cerrará al actualizar.');
                }
                go('usuarios.php');
            } catch(PDOException $e) {
                if ($e->getCode()==='23000') $errors[]='El usuario o correo ya está registrado.';
                else {error_log('Usuarios: '.$e->getMessage());$errors[]='No fue posible guardar el usuario.';}
            }
        }
    } elseif ($action==='asignar_historial') {
        if ($id<1) {http_response_code(422);exit('Usuario inválido.');}
        $st=$pdo->prepare("SELECT nombre,perfil,activo,vendedor_catalogo_id FROM usuarios WHERE id=:id");
        $st->execute([':id'=>$id]);$target=$st->fetch();
        if (!$target || $target['perfil']!=='VENDEDOR' || (int)$target['activo']!==1) {flash('error','Solo se pueden asignar registros a vendedores activos.');go('usuarios.php');}
        $nombreVinculado=nombre_vendedor_vinculado($pdo,$target);
        // Solo filas aún sin dueño y con el nombre del vendedor exactamente igual.
        $st=$pdo->prepare("UPDATE registros SET creado_por=:uid WHERE creado_por IS NULL AND UPPER(TRIM(vendedor))=UPPER(TRIM(:nombre)) AND TRIM(vendedor)<>''");
        $st->execute([':uid'=>$id,':nombre'=>$nombreVinculado]);
        flash('success',$st->rowCount().' registros anteriores asignados a '.(string)$nombreVinculado.'. No se alteraron registros ya asignados.');
        go('usuarios.php');
    } elseif ($action==='cambiar_estado') {
        $activar=(string)($_POST['activar']??'');
        if ($id<1||!in_array($activar,['0','1'],true)) { http_response_code(422);exit('Acción inválida.'); }
        if ($id===(int)$_SESSION['uid'] && $activar==='0') { flash('error','No puedes inactivar tu propia cuenta.');go('usuarios.php'); }
        $st=$pdo->prepare('SELECT perfil,activo FROM usuarios WHERE id=:id');$st->execute([':id'=>$id]);$target=$st->fetch();
        if (!$target) {flash('error','Usuario inexistente.');go('usuarios.php');}
        if ($activar==='0' && $target['perfil']==='ADMINISTRADOR' && (int)$target['activo']===1) {
            $admins=(int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil='ADMINISTRADOR' AND activo=1")->fetchColumn();
            if ($admins<=1) {flash('error','No puedes inactivar al último administrador.');go('usuarios.php');}
        }
        $st=$pdo->prepare('UPDATE usuarios SET activo=:a,auth_version=auth_version+1 WHERE id=:id');
        $st->execute([':a'=>(int)$activar,':id'=>$id]);
        flash('success',$activar==='1'?'Cuenta habilitada.':'Cuenta inactivada; ya no podrá ingresar.');
        go('usuarios.php');
    }
}
$editId=(int)($_GET['editar']??0);
$edit=null;
if ($editId>0) { $st=$pdo->prepare('SELECT id,usuario,nombre,correo,perfil,activo,permisos,vendedor_catalogo_id FROM usuarios WHERE id=:id');$st->execute([':id'=>$editId]);$edit=$st->fetch(); }
if ($errors) {
    $edit=['id'=>$id,'usuario'=>$usuario,'nombre'=>$nombre,'correo'=>$correo,'perfil'=>$perfil,'activo'=>1,'permisos'=>json_encode($permisos),'vendedor_catalogo_id'=>$catId];
}
$users=$pdo->query("SELECT u.id,u.usuario,u.nombre,u.correo,u.perfil,u.activo,u.creado_en,u.permisos,u.vendedor_catalogo_id,c.nombre AS equipo_ventas FROM usuarios u LEFT JOIN opciones_catalogo c ON c.id=u.vendedor_catalogo_id AND c.tipo='vendedor' ORDER BY u.id ASC")->fetchAll();
$listaCatalogo=$pdo->query("SELECT id,nombre FROM opciones_catalogo WHERE tipo='vendedor' AND activo=1 ORDER BY nombre ASC")->fetchAll();
$permsSelected=$edit ? (json_decode((string)$edit['permisos'],true)?:[]) : permisos_vendedor();
$modalOpen = $edit !== null || !empty($errors);
$modalData = $edit ? [
  'id'=>(int)$edit['id'], 'nombre'=>(string)$edit['nombre'], 'correo'=>(string)$edit['correo'],
  'usuario'=>(string)$edit['usuario'], 'perfil'=>(string)$edit['perfil'],
  'vendedor_catalogo_id'=>(int)($edit['vendedor_catalogo_id']??0),
  'permisos'=>json_decode((string)($edit['permisos']??'[]'),true)?:[]
] : null;
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios y permisos · Registro Soporte</title><link rel="stylesheet" href="assets/usuarios.css"><link rel="stylesheet" href="assets/ui_shared.css?v=20261014a"><link rel="stylesheet" href="assets/usuarios_modal.css?v=20261014a"></head><body class="users-page">
<div class="users-layout"><?php ui_sidebar($pdo,'usuarios'); ?>
<main class="users-main"><?php ui_topbar($pdo,'Usuarios y permisos','Gestiona cuentas, contraseñas y permisos por usuario.'); ?>
<div class="users-content"><div class="users-heading"><div><small>ADMINISTRACIÓN DE ACCESO</small><h1>Listado de usuarios</h1><p>Crea, edita o inactiva las cuentas desde un mismo panel.</p></div><button type="button" class="users-button" data-action="nuevo-usuario">＋ Nuevo usuario</button></div>
<?php foreach(get_flashes() as $f):?><div class="<?= $f[0]==='success'?'users-ok':'users-error' ?>"><?=h($f[1])?></div><?php endforeach;?>
<?php foreach($errors as $e):?><div class="users-error"><?=h($e)?></div><?php endforeach;?>
<section class="users-card"><div class="users-card-title"><h2>Usuarios registrados</h2><span><?=count($users)?> cuentas</span></div>
<div class="users-scroll"><table><thead><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Correo</th><th>Perfil</th><th>Equipo de ventas</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($users as $u):
    $payload=['id'=>(int)$u['id'],'nombre'=>(string)$u['nombre'],'usuario'=>(string)$u['usuario'],'correo'=>(string)$u['correo'], 'perfil'=>(string)$u['perfil'], 'vendedor_catalogo_id'=>(int)($u['vendedor_catalogo_id']??0), 'permisos'=>json_decode((string)($u['permisos']??'[]'),true)?:[]];
    $json=json_encode($payload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
?><tr class="<?=!$u['activo']?'user-inactive':''?>"><td>#<?=(int)$u['id']?></td><td><?=h($u['nombre'])?></td><td><?=h($u['usuario'])?></td><td><?=h($u['correo'])?></td><td><span class="tag <?= $u['perfil']==='ADMINISTRADOR'?'admin':'seller' ?>"><?=h($u['perfil'])?></span></td><td><?=h($u['equipo_ventas']??'—')?></td><td><span class="<?=$u['activo']?'users-active':'users-disabled'?>"><?=$u['activo']?'Activo':'Inactivo'?></span></td><td><div class="users-actions"><button type="button" class="users-edit" data-action="editar-usuario" data-usuario="<?=h((string)$json)?>">Editar</button>
<?php if((int)$u['id']!==(int)$_SESSION['uid']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="cambiar_estado"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input type="hidden" name="activar" value="<?=$u['activo']?'0':'1'?>"><button type="submit" class="<?=$u['activo']?'users-disable':'users-enable'?>"><?=$u['activo']?'Inactivar':'Habilitar'?></button></form><?php endif;?>
<?php if($u['perfil']==='VENDEDOR' && $u['activo']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="asignar_historial"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><button class="users-assign" type="submit">Asignar ventas</button></form><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="users-card users-help"><h2>Asignación de ventas existentes</h2><p>El botón <b>Asignar ventas</b> relaciona los registros antiguos que todavía no tienen propietario con el vendedor correspondiente. Los usuarios nuevos mantienen los permisos que establezcas.</p></section>
</div></main></div>

<div id="modalUsuario" class="user-modal" hidden aria-hidden="true">
  <div class="user-modal-backdrop" data-action="cerrar-usuario"></div>
  <section class="user-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="usuarioModalTitulo" tabindex="-1">
    <div class="user-modal-header"><div><h2 id="usuarioModalTitulo">Nuevo usuario</h2><p>Configura los datos y permisos de acceso.</p></div><button type="button" class="user-modal-close" data-action="cerrar-usuario" aria-label="Cerrar ventana">×</button></div>
    <form method="post" id="formUsuario" autocomplete="off"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="guardar_usuario"><input type="hidden" name="id" id="usuarioId" value="0">
      <div class="user-modal-tabs" role="tablist"><button type="button" role="tab" class="is-active" id="tabDatos" aria-selected="true" aria-controls="panelDatos" data-tab="datos">Datos de usuario</button><button type="button" role="tab" id="tabPermisos" aria-selected="false" aria-controls="panelPermisos" data-tab="permisos">Permisos</button></div>
      <div class="user-modal-scroll"><div class="user-modal-panel" role="tabpanel" id="panelDatos" aria-labelledby="tabDatos">
        <div class="users-fields">
          <label>Nombre *<input type="text" name="nombre" id="usuarioNombre" maxlength="100" required placeholder="Nombre completo"></label>
          <label>Correo electrónico *<input type="email" name="correo" id="usuarioCorreo" maxlength="190" required placeholder="usuario@empresa.com"></label>
          <label>Usuario de acceso *<input type="text" name="usuario" id="usuarioLogin" maxlength="80" required placeholder="nombre.usuario"></label>
          <label>Perfil *<select name="perfil" id="usuarioPerfil"><option value="VENDEDOR">Vendedor</option><option value="ADMINISTRADOR">Administrador</option></select></label>
          <label id="catalogoVendedorLabel">Equipo de ventas (solo Vendedor)<select name="vendedor_catalogo_id" id="usuarioCatalogo"><option value="0">Sin vincular</option><?php foreach($listaCatalogo as $v):?><option value="<?=(int)$v['id']?>"><?=h($v['nombre'])?></option><?php endforeach;?></select></label>
          <label>Contraseña <span id="claveIndicacion">*</span><input type="password" name="clave" id="usuarioClave" minlength="10" maxlength="200" autocomplete="new-password" placeholder="Mínimo 10 caracteres" required></label>
          <label>Confirmar contraseña <input type="password" name="confirmar_clave" id="usuarioClaveConfirmar" autocomplete="new-password" placeholder="Repite la contraseña"></label>
        </div>
        <p class="users-hint">En edición deja la contraseña vacía para conservar la actual. Nunca se muestra una contraseña existente.</p>
      </div>
      <div class="user-modal-panel" role="tabpanel" id="panelPermisos" aria-labelledby="tabPermisos" hidden>
        <h3>Permisos del vendedor</h3><p class="users-hint">Un vendedor solamente accede a sus propios registros. El administrador siempre tiene acceso completo.</p>
        <div class="users-permission-grid"><?php foreach(permisos_disponibles() as $code=>$label):?><label class="users-check"><input type="checkbox" name="permisos[]" value="<?=h($code)?>" data-permission="<?=h($code)?>"><span><?=h($label)?></span></label><?php endforeach;?></div>
        <p class="users-hint">Comisiones generales, administrar usuarios y modificar catálogos siguen siendo exclusivos del administrador.</p>
      </div></div>
      <div class="user-modal-footer"><button class="user-modal-cancel" data-action="cerrar-usuario" type="button">Cancelar</button><button class="users-button" type="submit">Guardar usuario</button></div>
    </form>
  </section>
</div>
<script src="assets/usuarios_modal.js?v=20261014a" defer></script>
<?php if($modalOpen && $modalData): ?><script type="application/json" id="datosModalIniciales"><?=json_encode($modalData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><?php endif; ?>
<?php if($modalOpen): ?><script>document.documentElement.dataset.abrirModalUsuarios = '1';</script><?php endif; ?>
</body></html>
