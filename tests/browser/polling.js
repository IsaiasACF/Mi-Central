/* Open polling.html in a browser. No packages, account, database or real API required. */
(async function () {
    'use strict';
    var output = document.getElementById('results');
    var results = [];
    function assert(condition, message) { if (!condition) { throw new Error(message); } }
    async function flush() { for (var i = 0; i < 40; i++) { await Promise.resolve(); } }
    var bell = '<div data-notification-center data-csrf-token="test"><button data-notification-toggle aria-expanded="false">Bell</button><span data-notification-count>2</span><section data-notification-panel hidden><button data-notification-action="read-all">All</button><div data-notification-list><article class="notification-item is-unread" data-notification-id="1"><div class="notification-meta"><span>No leida</span></div><button data-notification-action="read">Read</button></article></div></section></div>';
    var notificationPage = '<section data-notification-page data-csrf-token="test"><button data-notification-action="read-all">All</button><a data-notification-unread-filter>No leidas (2)</a><span data-notification-page-count>1</span><div data-notification-list><article class="notification-item is-unread" data-notification-id="1"><div class="notification-meta"><span>No leida</span></div><button data-notification-action="read">Read</button></article></div></section>';
    var job = {id: 1, output_name: 'Test', status: 'processing', progress_percent: 10};
    var notices = {items: [{id: 1, title: 'New', is_read: false}, {id: 2, title: 'Other', is_read: false}], unread_count: 2};
    var frames = [];

    async function fixture(markup, options) {
        options = options || {};
        var frame = document.createElement('iframe');
        frame.src = 'fixture.html';
        await new Promise(function (resolve) {
            frame.onload = resolve;
            document.body.appendChild(frame);
        });
        frames.push(frame);
        var w = frame.contentWindow;
        var d = w.document;
        d.body.innerHTML = markup;
        // Keep real DOM/events, replace time, visibility and the network only.
        var now = 1000000, next = 1, timers = new Map(), calls = [], errors = [];
        Object.defineProperty(d, 'hidden', {value: Boolean(options.hidden), configurable: true});
        w.Date.now = function () { return now; };
        w.setTimeout = function (fn, ms) { var id = next++; timers.set(id, {fn: fn, at: now + ms}); return id; };
        w.setInterval = function (fn, ms) { var id = next++; timers.set(id, {fn: fn, at: now + ms, interval: ms}); return id; };
        w.clearTimeout = w.clearInterval = function (id) { timers.delete(id); };
        w.addEventListener('error', function (event) { errors.push(event.message); });
        w.fetch = function (url, request) {
            return new Promise(function (resolve, reject) {
                var call = {url: String(url), options: request, at: now, resolve: function (data, status) {
                    status = status || 200;
                    resolve({ok: status < 400, status: status, json: function () { return Promise.resolve({ok: status < 400, data: data, error: 'Test error'}); }});
                }, reject: reject};
                calls.push(call);
                if (request.signal) { request.signal.addEventListener('abort', function () { reject(new Error('Timeout')); }); }
                if (options.auto) { call.resolve(call.url.includes('/cuts.php') ? [] : [job]); }
            });
        };
        async function script(path) {
            await new Promise(function (resolve, reject) {
                var element = d.createElement('script');
                element.src = new URL(path, window.location.href).href;
                element.onload = resolve; element.onerror = reject;
                d.body.appendChild(element);
            });
        }
        await script('../../public/assets/js/polling.js');
        await script('../../public/assets/js/app.js');
        await flush();
        assert(errors.length === 0, errors.join('; '));
        return {w: w, d: d, calls: calls, errors: errors, timers: timers,
            tick: async function (ms) {
                var end = now + ms, safety = 0;
                while (true) {
                    var entry = Array.from(timers.entries()).filter(function (pair) { return pair[1].at <= end; }).sort(function (a, b) { return a[1].at - b[1].at; })[0];
                    if (!entry) { break; }
                    assert(++safety < 10000, 'Timer loop');
                    now = entry[1].at;
                    timers.delete(entry[0]);
                    if (entry[1].interval) { timers.set(entry[0], Object.assign({}, entry[1], {at: now + entry[1].interval})); }
                    entry[1].fn(); await flush();
                }
                now = end; await flush();
            },
            visible: async function (visible) {
                Object.defineProperty(d, 'hidden', {value: !visible, configurable: true});
                d.dispatchEvent(new w.Event('visibilitychange')); await flush();
            }
        };
    }
    async function test(name, run) {
        try { await run(); results.push('PASS ' + name); }
        catch (error) { results.push('FAIL ' + name + ': ' + error.message); }
        frames.forEach(function (frame) { frame.remove(); }); frames = [];
        output.textContent = results.join('\n');
    }

    await test('Closed bell: 0 GET / 10 min; stale visibility: 1; fresh visibility: 0', async function () {
        var f = await fixture(bell);
        assert(f.d.querySelector('[data-notification-count]').textContent === '2', 'Lost server counter');
        await f.tick(600000); assert(f.calls.length === 0, 'Background header requests');
        await f.visible(false); await f.visible(true); assert(f.calls.length === 1, 'Stale visibility');
        f.calls[0].resolve(notices); await flush();
        await f.visible(false); await f.visible(true); assert(f.calls.length === 1, 'Fresh visibility requested');
    });
    await test('Open bell: 1 GET; repeated opens while pending: 0 extra', async function () {
        var f = await fixture(bell), toggle = f.d.querySelector('[data-notification-toggle]');
        toggle.click(); toggle.click(); toggle.click(); await flush();
        assert(f.calls.length === 1 && f.calls[0].url.endsWith('limit=5'), 'Duplicate bell request');
        f.calls[0].resolve(notices); await flush();
        assert(f.d.querySelectorAll('[data-notification-id]').length === 2, 'Bell not rendered');
    });
    await test('Notifications: 2 GET / 10 min, one per cycle, limits and counter reused', async function () {
        var f = await fixture(bell + notificationPage);
        await f.tick(300000); assert(f.calls.length === 1, 'Expected single cycle request');
        assert(f.calls[0].url.includes('status=all&limit=50'), 'Lost filters/limit');
        f.calls[0].resolve(notices); await flush();
        assert(f.d.querySelector('[data-notification-count]').textContent === '2', 'Counter not reused');
        assert(f.d.querySelector('[data-notification-center] [data-notification-list]').children.length === 2, 'All page not shared with bell');
        await f.tick(300000); assert(f.calls.length === 2, 'Expected second cycle');
    });
    await test('Hidden notifications: 0 GET; return stale: 1; expired session stops all triggers', async function () {
        var f = await fixture(bell + notificationPage);
        await f.visible(false); await f.tick(600000); assert(f.calls.length === 0, 'Hidden polling');
        await f.visible(true); assert(f.calls.length === 1, 'Return refresh');
        f.calls[0].resolve(null, 401); await flush();
        await f.tick(600000); await f.visible(false); await f.visible(true);
        f.d.querySelector('[data-notification-toggle]').click(); await flush();
        assert(f.calls.length === 1, 'Continued after 401');
    });
    await test('Bell opening during unfiltered page cycle shares the pending request', async function () {
        var f = await fixture(bell + notificationPage);
        await f.tick(300000); f.d.querySelector('[data-notification-toggle]').click(); await flush();
        f.calls[0].resolve(notices); await flush();
        assert(f.calls.length === 1, 'Bell duplicated unfiltered page request');
    });
    await test('Network failure: no immediate retry storm; next visible-page cycle recovers', async function () {
        var f = await fixture(bell + notificationPage);
        await f.tick(300000); f.calls[0].reject(new Error('Offline')); await flush();
        await f.tick(299999); assert(f.calls.length === 1, 'Fast retries');
        await f.tick(1); f.calls[1].resolve(notices); await flush();
        assert(f.d.querySelector('[data-notification-page-count]').textContent === '2', 'Did not recover');
    });
    await test('Read/read-all: immediate UI + API counter, no follow-up GET; old GET cannot undo read', async function () {
        var f = await fixture(bell + notificationPage);
        await f.tick(300000); // Keep stale GET pending across a successful POST.
        f.d.querySelector('[data-notification-page] [data-notification-action="read"]').click(); await flush();
        assert(f.calls.length === 2 && f.calls[1].options.method === 'POST', 'Missing POST');
        assert(f.calls[1].options.headers['X-CSRF-Token'] === 'test', 'Lost CSRF');
        f.calls[1].resolve({unread_count: 1, updated: true}); await flush();
        f.calls[0].resolve(notices); await flush();
        assert(f.d.querySelectorAll('[data-notification-id="1"].is-unread').length === 0, 'Old GET undid read');
        assert(f.d.querySelector('[data-notification-count]').textContent === '1', 'Old count overwrote read');
        assert(f.calls.length === 2, 'Extra GET after POST');
        f.d.querySelector('[data-notification-page] [data-notification-action="read-all"]').click(); await flush();
        f.calls[2].resolve({unread_count: 0, updated: 1}); await flush();
        assert(f.d.querySelector('[data-notification-count]').hidden, 'Read-all count');
        assert(f.calls.length === 3, 'Extra GET after read-all');
    });
    await test('Changed filter: previous response ignored; unread filter removes read item', async function () {
        var f = await fixture(bell + notificationPage);
        var params = '?section=notifications&status=unread';
        // The fixture runs on HTTP, so history preserves real URLSearchParams behavior.
        f.w.history.replaceState(null, '', params);
        await f.tick(300000); assert(f.calls[0].url.includes('status=unread&limit=50'), 'Unread limit');
        f.w.history.replaceState(null, '', '?section=notifications&status=read');
        f.calls[0].resolve(notices); await flush();
        assert(f.d.querySelector('[data-notification-page-count]').textContent === '1', 'Old filter rendered');
        f.w.history.replaceState(null, '', params);
        f.d.querySelector('[data-notification-page] [data-notification-action="read"]').click(); await flush();
        f.calls[1].resolve({unread_count: 1}); await flush();
        assert(f.d.querySelector('[data-notification-page-count]').textContent === '0', 'Read item remained in unread filter');
    });

    var metadata = '<section data-video-page data-video-detail-id="1" data-metadata-status="pending"><div data-video-metadata-panel></div></section>';
    var processed = '<section data-video-page><div data-video-processed-panel><div data-video-processed-list></div></div></section>';
    var editor = '<section data-video-editor data-video-id="1" data-duration-seconds="60"><video data-video-editor-player></video><div data-video-timeline></div><div data-video-playhead></div><div data-video-cut-markers></div><div data-video-cut-list></div><div data-video-export-list></div></section>';
    await test('Active metadata: 15 GET / min, hidden: 0; resume: 1; ready/failed stop', async function () {
        var f = await fixture(metadata);
        for (var i = 0; i < 15; i++) {
            await f.tick(4000); f.calls[i].resolve({metadata_status: 'processing'}); await flush();
        }
        assert(f.calls.length === 15, 'Metadata cadence');
        await f.visible(false); await f.tick(60000); assert(f.calls.length === 15, 'Hidden metadata');
        await f.visible(true); assert(f.calls.length === 16, 'Metadata resume');
        f.calls[15].resolve({metadata_status: 'ready'}); await flush();
        await f.tick(60000); await f.visible(false); await f.visible(true); assert(f.calls.length === 16, 'Ready kept polling');
        var failed = await fixture(metadata); await failed.tick(4000); failed.calls[0].resolve({metadata_status: 'failed'}); await flush();
        await failed.tick(60000); assert(failed.calls.length === 1, 'Failed kept polling');
    });
    await test('Export lists: editor 24 GET / min; processed 20; hidden pauses; completion stops', async function () {
        for (var spec of [{html: editor, interval: 2500, count: 24}, {html: processed, interval: 3000, count: 20}]) {
            var f = await fixture(spec.html, {auto: true});
            var initial = f.calls.filter(function (call) { return call.url.includes('/exports.php'); }).length;
            await f.tick(60000);
            assert(f.calls.filter(function (call) { return call.url.includes('/exports.php'); }).length === initial + spec.count, 'Export cadence');
            await f.visible(false); var count = f.calls.length; await f.tick(60000); assert(f.calls.length === count, 'Hidden exports');
            await f.visible(true); assert(f.calls.length === count + 1, 'Export resume');
            // Switch off auto-responses by using a separate controlled fixture.
            var done = await fixture(spec.html);
            done.calls.forEach(function (call) { call.resolve(call.url.includes('/exports.php') ? [{id: 1, status: 'completed'}] : []); }); await flush();
            var doneCount = done.calls.length; await done.tick(60000); await done.visible(false); await done.visible(true);
            assert(done.calls.length === doneCount, 'Completed export kept polling');
            assert(f.errors.length === 0 && done.errors.length === 0, f.errors.concat(done.errors).join(';'));
        }
    });
    await test('Video network failures: exponential delay, 5 failures max; manual recovery; timeout', async function () {
        var f = await fixture(metadata), delays = [4000, 8000, 16000, 32000, 60000];
        for (var i = 0; i < delays.length; i++) {
            await f.tick(delays[i] - 1); assert(f.calls.length === i, 'Retry too early');
            await f.tick(1); f.calls[i].reject(new Error('Offline')); await flush();
        }
        await f.tick(600000); await f.visible(false); await f.visible(true);
        assert(f.calls.length === 5, 'Retry cap');
        var button = Array.from(f.d.querySelectorAll('button')).find(function (node) { return !node.hidden && node.textContent.includes('analisis'); });
        assert(button, 'No manual recovery'); button.click(); await flush(); assert(f.calls.length === 6, 'Manual retry');
        await f.tick(15000); await flush();
        assert(!button.hidden, 'Timed-out request did not settle');
    });
    await test('Video 401: no retries after hide/show or manual refresh; concurrent triggers join', async function () {
        var f = await fixture(processed); assert(f.calls.length === 1, 'Initial export request');
        await f.visible(false); await f.visible(true); assert(f.calls.length === 1, 'Concurrent duplicate');
        f.calls[0].resolve(null, 401); await flush();
        await f.tick(600000); await f.visible(false); await f.visible(true);
        assert(f.calls.length === 1, '401 retries');
    });
    await test('Empty export lists stop; initial hidden export page waits until visible', async function () {
        for (var html of [editor, processed]) {
            var f = await fixture(html);
            f.calls.forEach(function (call) { call.resolve([]); }); await flush();
            var initial = f.calls.length;
            await f.tick(600000); assert(f.calls.length === initial, 'Empty list still polling');
        }
        var hidden = await fixture(processed, {hidden: true});
        await hidden.tick(60000); assert(hidden.calls.length === 0, 'Hidden startup request');
        await hidden.visible(true); assert(hidden.calls.length === 1, 'No deferred initial load');
    });
    await test('Failed POST preserves UI and counter; expired video session stops notification polling too', async function () {
        var f = await fixture(bell + notificationPage);
        f.d.querySelector('[data-notification-page] [data-notification-action="read"]').click(); await flush();
        f.calls[0].resolve(null, 403); await flush();
        assert(f.d.querySelectorAll('[data-notification-id="1"].is-unread').length === 2, 'Failed POST changed UI');
        assert(f.d.querySelector('[data-notification-count]').textContent === '2', 'Failed POST changed counter');
        var both = await fixture(bell + notificationPage + processed);
        both.calls[0].resolve(null, 401); await flush();
        await both.tick(600000); await both.visible(false); await both.visible(true);
        assert(both.calls.length === 1, 'Cross-module session expiry did not stop polling');
    });
    var failed = results.filter(function (line) { return line.startsWith('FAIL'); }).length;
    output.textContent += '\n' + (failed ? 'FAILED: ' + failed : 'ALL PASSED: ' + results.length);
    document.title = failed ? 'FAIL polling checks' : 'PASS polling checks';
}());
