/* Shared only by notification/video status requests; uploads keep their own lifecycle. */
var MiCentralPolling = (function () {
    'use strict';

    var expired = false;

    function json(url, options) {
        if (expired) {
            return Promise.reject(new Error('Sesion expirada. Vuelve a iniciar sesion.'));
        }
        options = Object.assign({}, options);
        var controller = new AbortController();
        var timer = null;
        // Never abort an upload or a mutation whose outcome could be ambiguous.
        if (!options.method || options.method === 'GET') {
            options.signal = controller.signal;
            timer = window.setTimeout(function () { controller.abort(); }, 15000);
        }
        return fetch(url, options).then(function (response) {
            if (response.status === 401) {
                expired = true;
                document.dispatchEvent(new Event('mi-central-session-expired'));
                throw new Error('Sesion expirada. Vuelve a iniciar sesion.');
            }
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }
                return body.data;
            });
        }).finally(function () { window.clearTimeout(timer); });
    }

    function create(task, interval, host, label) {
        var active = false;
        var timer = null;
        var pending = null;
        var failures = 0;
        var away = false;
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'button button--secondary';
        retry.textContent = label;
        retry.hidden = true;
        host.appendChild(retry);

        function clear() {
            window.clearTimeout(timer);
            timer = null;
        }
        function schedule() {
            clear();
            if (active && !pending && !document.hidden && !away && !expired && failures < 5) {
                timer = window.setTimeout(run, Math.min(60000, interval * Math.pow(2, failures)));
            }
        }
        function run() {
            clear();
            if (pending) { return pending; }
            if (document.hidden || away || expired) { return Promise.resolve(); }
            pending = Promise.resolve().then(task).then(function (data) {
                failures = 0;
                retry.hidden = true;
                return data;
            }).catch(function (error) {
                failures++;
                retry.hidden = expired;
                retry.title = error.message;
                if (expired) {
                    retry.textContent = 'Sesion expirada. Vuelve a iniciar sesion.';
                    retry.hidden = false;
                    retry.disabled = true;
                }
            }).finally(function () {
                pending = null;
                schedule();
            });
            return pending;
        }
        function refresh() {
            if (expired) { return Promise.resolve(); }
            failures = 0;
            active = true;
            return run();
        }
        retry.addEventListener('click', refresh);
        document.addEventListener('visibilitychange', function () {
            clear();
            if (!document.hidden && active && failures < 5) { run(); }
        });
        document.addEventListener('mi-central-session-expired', clear);
        window.addEventListener('pagehide', function () { away = true; clear(); });
        window.addEventListener('pageshow', function () {
            if (!away) { return; }
            away = false;
            if (active && failures < 5) { run(); }
        });
        return {
            refresh: refresh,
            setActive: function (value) { active = value; schedule(); }
        };
    }

    return {json: json, create: create, expired: function () { return expired; }};
}());
