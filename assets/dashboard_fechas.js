/* Filtro de fechas: vista inicial sin restricción, accesos rápidos y calendario de dos meses.
   El backend PHP procesa el rango; esta interfaz no modifica ni almacena datos. */
(function () {
    'use strict';
    var form = document.getElementById('db-period-form');
    var period = document.getElementById('db-periodo');
    var from = document.getElementById('db-fecha-desde');
    var to = document.getElementById('db-fecha-hasta');
    var open = document.getElementById('db-open-range');
    var panel = document.getElementById('db-range-popover');
    var months = document.getElementById('db-cal-months');
    var title = document.getElementById('db-cal-title');
    var summary = document.getElementById('db-range-summary');
    var shortcuts = document.getElementById('db-range-shortcuts');
    if (!form || !period || !from || !to || !open || !panel) return;

    var names = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var weekdays = ['LU','MA','MI','JU','VI','SA','DO'];
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12);
    var anchor = new Date(today.getFullYear(), today.getMonth() - 1, 1, 12);
    var snapshot = null;

    function iso(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0');
    }
    function dateFromIso(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
        var x = value.split('-').map(Number);
        var d = new Date(x[0],x[1]-1,x[2],12);
        return iso(d) === value ? d : null;
    }
    function addDays(d, days) {
        return new Date(d.getFullYear(), d.getMonth(), d.getDate() + days, 12);
    }
    function pretty(value) {
        var d = dateFromIso(value);
        return d ? String(d.getDate()).padStart(2,'0') + '/' + String(d.getMonth()+1).padStart(2,'0') + '/' + d.getFullYear() : '—';
    }
    function setRange(mode) {
        period.value = mode;
        if (mode === 'todos') { from.value = ''; to.value = ''; }
        else if (mode === 'hoy') { from.value = iso(today); to.value = iso(today); }
        else if (mode === 'ayer') { from.value = iso(addDays(today,-1)); to.value = from.value; }
        else if (mode === '7d') { from.value = iso(addDays(today,-6)); to.value = iso(today); }
        else if (mode === '30d') { from.value = iso(addDays(today,-29)); to.value = iso(today); }
        else if (mode === 'mes') { from.value = iso(new Date(today.getFullYear(),today.getMonth(),1,12)); to.value = iso(today); }
        else if (mode === '3m' || mode === '6m' || mode === '12m') {
            var count = {'3m':3,'6m':6,'12m':12}[mode];
            from.value = iso(new Date(today.getFullYear(),today.getMonth()-count+1,1,12));
            to.value = iso(today);
        }
        refresh();
    }
    function refresh() {
        shortcuts.querySelectorAll('[data-period]').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-period') === period.value);
        });
        summary.textContent = period.value === 'todos' ? 'Todos los registros (incluye sin fecha)' :
            (from.value || to.value ? pretty(from.value) + ' – ' + pretty(to.value) : 'Selecciona Desde y Hasta');
        render();
    }
    function renderMonth(month) {
        var wrapper = document.createElement('div'); wrapper.className = 'db-cal-month';
        var heading = document.createElement('div'); heading.className = 'db-cal-month-title';
        heading.textContent = names[month.getMonth()] + ' ' + month.getFullYear();
        wrapper.appendChild(heading);
        var grid = document.createElement('div'); grid.className = 'db-cal-grid';
        weekdays.forEach(function(name) {
            var w = document.createElement('div'); w.className = 'db-cal-weekday'; w.textContent = name; grid.appendChild(w);
        });
        var first = new Date(month.getFullYear(),month.getMonth(),1,12);
        var start = addDays(first,-((first.getDay()+6)%7));
        var selectionFrom = from.value, selectionTo = to.value;
        for (var i=0; i<42; i++) {
            var date = addDays(start,i), value = iso(date);
            var b = document.createElement('button'); b.type = 'button';
            b.className = 'db-cal-day'; b.textContent = date.getDate();
            b.setAttribute('aria-label',pretty(value));
            if (date.getMonth() !== month.getMonth()) b.classList.add('outside');
            if (value === iso(today)) b.classList.add('today');
            if (selectionFrom && selectionTo && selectionFrom < value && value < selectionTo) b.classList.add('in-range');
            if (value === selectionFrom || value === selectionTo) b.classList.add('selected');
            (function(day) { b.addEventListener('click', function(){ pickDay(day); }); })(value);
            grid.appendChild(b);
        }
        wrapper.appendChild(grid); return wrapper;
    }
    function render() {
        months.replaceChildren(renderMonth(anchor), renderMonth(new Date(anchor.getFullYear(),anchor.getMonth()+1,1,12)));
        title.textContent = 'Elige el inicio y el final';
    }
    function pickDay(day) {
        // Primera selección: inicio; segunda: final, sin importar en cuál se hizo clic primero.
        if (period.value !== 'personalizado' || !from.value || to.value) {
            from.value = day; to.value = '';
        } else if (day < from.value) {
            to.value = from.value; from.value = day;
        } else { to.value = day; }
        period.value = 'personalizado'; refresh();
    }
    function close(restore) {
        if (restore && snapshot) {
            period.value = snapshot.period; from.value = snapshot.from; to.value = snapshot.to;
        }
        panel.hidden = true; open.setAttribute('aria-expanded','false');
    }
    open.addEventListener('click', function () {
        if (!panel.hidden) { close(true); return; }
        snapshot = {period:period.value, from:from.value, to:to.value};
        var focused = dateFromIso(from.value) || dateFromIso(to.value) || today;
        anchor = new Date(focused.getFullYear(), focused.getMonth()-1,1,12);
        panel.hidden = false; open.setAttribute('aria-expanded','true'); refresh();
    });
    shortcuts.querySelectorAll('button[data-period]').forEach(function(b) {
        b.addEventListener('click',function () {
            var mode = b.getAttribute('data-period');
            if (mode === 'personalizado') { period.value = mode; from.value=''; to.value=''; refresh(); }
            else setRange(mode);
        });
    });
    document.getElementById('db-month-prev').addEventListener('click',function() {
        anchor = new Date(anchor.getFullYear(),anchor.getMonth()-1,1,12); render();
    });
    document.getElementById('db-month-next').addEventListener('click',function() {
        anchor = new Date(anchor.getFullYear(),anchor.getMonth()+1,1,12); render();
    });
    document.getElementById('db-range-cancel').addEventListener('click',function(){close(true);});
    document.getElementById('db-range-apply').addEventListener('click',function() {
        if (period.value === 'personalizado' && (!from.value || !to.value || from.value > to.value)) {
            alert('Selecciona un inicio y un final válidos.'); return;
        }
        close(false);
        form.requestSubmit ? form.requestSubmit() : form.submit();
    });
    from.addEventListener('change', function() {period.value='personalizado'; refresh();});
    to.addEventListener('change', function() {period.value='personalizado'; refresh();});
    form.addEventListener('submit', function(ev) {
        if (period.value === 'personalizado' && (!from.value || !to.value || from.value > to.value)) {
            ev.preventDefault(); alert('Selecciona fechas válidas en Desde y Hasta.');
        }
    });
    document.addEventListener('click',function(ev) {
        if (!panel.hidden && !panel.contains(ev.target) && ev.target !== open && !open.contains(ev.target)) close(true);
    });
    document.addEventListener('keydown',function(ev) { if(ev.key==='Escape' && !panel.hidden) close(true); });
})();
