// Atajo de teclado Ctrl+K / Cmd+K: enfoca la búsqueda sin descargar toda la tabla.
document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        const search = document.querySelector('input[name="q"]');
        if (search) {
            event.preventDefault();
            search.focus();
        }
    }
});

// Confirmación de borrado compatible con Content-Security-Policy (sin JS inline).
document.addEventListener('submit', function(event) {
    const form = event.target;
    if (!form || !form.classList || !form.classList.contains('delete-record-form')) return;
    const id = form.getAttribute('data-registro-id') || '';
    if (!window.confirm('¿Eliminar definitivamente el registro #' + id + '? Esta acción no se puede deshacer.')) {
        event.preventDefault();
    }
});


// Consulta RUC / DNI por medio del servidor PHP. El token NUNCA se envía al navegador.
(function () {
    'use strict';
    var documento = document.getElementById('factiliza-documento');
    var nombre = document.getElementById('factiliza-razon-social');
    var boton = document.getElementById('factiliza-consultar');
    var mensaje = document.getElementById('factiliza-mensaje');
    if (!documento || !nombre || !boton || !mensaje) return;

    var formulario = documento.closest('form');
    var csrfCampo = formulario ? formulario.querySelector('input[name="csrf"]') : null;
    var demora = null;
    var controlador = null;
    var ultimaConsulta = '';
    var ultimoNombreAutomatico = '';
    var secuencia = 0;

    function mensajeEstado(texto, estado) {
        mensaje.textContent = texto;
        mensaje.className = 'factiliza-mensaje factiliza-' + (estado || 'info');
    }
    function numeroDocumento() {
        return documento.value.replace(/\D/g, '');
    }
    function valido(valor) {
        return /^\d{8}$|^\d{11}$/.test(valor);
    }
    function consultar(manual) {
        var numero = numeroDocumento();
        clearTimeout(demora);
        if (!valido(numero)) {
            mensajeEstado('Ingresa 8 dígitos para DNI u 11 para RUC.', 'error');
            return;
        }
        if (!csrfCampo) {
            mensajeEstado('Sesión no disponible. Actualiza la página.', 'error');
            return;
        }
        if (!manual && ultimaConsulta === numero) return;
        ultimaConsulta = numero;
        var solicitud = ++secuencia;
        if (controlador) controlador.abort();
        controlador = typeof AbortController !== 'undefined' ? new AbortController() : null;
        boton.disabled = true;
        mensajeEstado('Consultando Factiliza...', 'info');
        var cuerpo = new URLSearchParams();
        cuerpo.append('csrf', csrfCampo.value);
        cuerpo.append('documento', numero);
        var opciones = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
            body: cuerpo.toString()
        };
        if (controlador) opciones.signal = controlador.signal;
        fetch('consulta_factiliza.php', opciones)
            .then(function (respuesta) {
                return respuesta.json().then(function (resultado) {
                    if (!respuesta.ok || !resultado.ok) throw new Error(resultado.mensaje || 'No se pudo consultar el documento.');
                    return resultado;
                });
            })
            .then(function (resultado) {
                if (solicitud !== secuencia || numeroDocumento() !== numero) return;
                // Conserva el nombre escrito manualmente salvo consulta iniciada con el botón.
                var actual = nombre.value.trim();
                if (manual || !actual || actual === ultimoNombreAutomatico) {
                    nombre.value = resultado.nombre;
                    ultimoNombreAutomatico = resultado.nombre;
                    nombre.dispatchEvent(new Event('change', { bubbles: true }));
                    mensajeEstado('✓ ' + resultado.tipo + ' encontrado. Nombre completado.', 'ok');
                } else {
                    mensajeEstado('✓ Encontrado: ' + resultado.nombre + '. El nombre escrito no se reemplazó. Pulsa Consultar para hacerlo.', 'info');
                }
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') return;
                if (solicitud !== secuencia) return;
                ultimaConsulta = '';
                mensajeEstado(error.message || 'Error de red al consultar Factiliza.', 'error');
            })
            .finally(function () {
                if (solicitud === secuencia) boton.disabled = false;
            });
    }

    documento.addEventListener('input', function () {
        documento.value = numeroDocumento().slice(0, 11);
        clearTimeout(demora);
        secuencia++;
        if (controlador) controlador.abort();
        boton.disabled = false;
        if (!documento.value) {
            mensajeEstado('', 'info');
            ultimaConsulta = '';
        } else if (valido(documento.value)) {
            mensajeEstado('Documento completo. Consultando en un momento...', 'info');
            demora = setTimeout(function () { consultar(false); }, 750);
        } else {
            mensajeEstado('Ingresa DNI (8 dígitos) o RUC (11 dígitos).', 'info');
        }
    });
    boton.addEventListener('click', function () { consultar(true); });
})();


// Tarifas configuradas en Roles > Finanzas. Al editar un registro,
// nunca sustituye el monto guardado durante la carga inicial.
(function () {
    'use strict';
    var plan = document.querySelector('select[name="plan"]');
    var monto = document.querySelector('input[name="monto"]');
    if (!plan || !monto) return;
    var ultimoAutomatico = null;
    plan.addEventListener('change', function () {
        var selected = plan.options[plan.selectedIndex];
        var precio = selected ? selected.getAttribute('data-precio') : null;
        if (precio !== null && /^\d+(?:\.\d{1,2})?$/.test(precio)) {
            monto.value = Number(precio).toFixed(2);
            ultimoAutomatico = monto.value;
            monto.dispatchEvent(new Event('change', { bubbles: true }));
        } else if (ultimoAutomatico !== null && monto.value === ultimoAutomatico) {
            // Si el nuevo plan no tiene precio, evita dejar el monto automático anterior.
            monto.value = '';
            ultimoAutomatico = null;
        }
    });
    monto.addEventListener('input', function () {
        ultimoAutomatico = null;
    });
})();

// Filtros de fechas administrados en dashboard_fechas.js.
