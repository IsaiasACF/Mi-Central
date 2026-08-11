(function () {
    'use strict';

    var toggle = document.querySelector('.sidebar-toggle');
    var backdrop = document.querySelector('[data-sidebar-close]');
    var sidebar = document.getElementById('app-sidebar');

    if (!toggle || !backdrop || !sidebar) {
        return;
    }

    var navToggles = Array.prototype.slice.call(sidebar.querySelectorAll('[data-nav-toggle]'));

    function setSidebar(open) {
        document.body.classList.toggle('sidebar-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Cerrar navegacion' : 'Abrir navegacion');
        backdrop.hidden = !open;
    }

    function panelFor(button) {
        var panelId = button.getAttribute('aria-controls');

        return panelId ? document.getElementById(panelId) : null;
    }

    function setNavGroup(button, open) {
        var panel = panelFor(button);

        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.classList.toggle('is-open', open);

        if (panel) {
            panel.hidden = !open;
        }
    }

    navToggles.forEach(function (button) {
        button.addEventListener('click', function () {
            var shouldOpen = button.getAttribute('aria-expanded') !== 'true';

            navToggles.forEach(function (otherButton) {
                setNavGroup(otherButton, otherButton === button && shouldOpen);
            });
        });
    });

    toggle.addEventListener('click', function () {
        setSidebar(!document.body.classList.contains('sidebar-open'));
    });

    backdrop.addEventListener('click', function () {
        setSidebar(false);
    });

    sidebar.addEventListener('click', function (event) {
        if (event.target instanceof HTMLAnchorElement) {
            setSidebar(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setSidebar(false);
        }
    });
}());

(function () {
    'use strict';

    var editor = document.querySelector('[data-video-editor]');

    if (!editor || editor.dataset.videoEditorInitialized === 'true') {
        return;
    }

    editor.dataset.videoEditorInitialized = 'true';

    var video = editor.querySelector('[data-video-editor-player]');
    var timeline = editor.querySelector('[data-video-timeline]');
    var playhead = editor.querySelector('[data-video-playhead]');
    var markers = editor.querySelector('[data-video-cut-markers]');
    var ticks = editor.querySelector('[data-video-ticks]');
    var currentTimeLabel = editor.querySelector('[data-video-current-time]');
    var durationTimeLabel = editor.querySelector('[data-video-duration-time]');
    var playButton = editor.querySelector('[data-video-editor-play]');
    var addCutButton = editor.querySelector('[data-video-editor-add-cut]');
    var cutList = editor.querySelector('[data-video-cut-list]');
    var cutCount = editor.querySelector('[data-video-cut-count]');
    var message = editor.querySelector('[data-video-editor-message]');
    var videoId = editor.getAttribute('data-video-id') || '';
    var apiUrl = editor.getAttribute('data-api-url') || '/api/video/cuts.php';
    var segmentsApiUrl = editor.getAttribute('data-segments-api-url') || '/api/video/segments.php';
    var exportsApiUrl = editor.getAttribute('data-exports-api-url') || '/api/video/exports.php';
    var csrfToken = editor.getAttribute('data-csrf-token') || '';
    var duration = Number.parseFloat(editor.getAttribute('data-duration-seconds') || '0') || 0;
    var cutPoints = [];
    var segments = [];
    var selectedCutId = null;
    var selectedSegmentId = null;
    var actionPending = false;
    var segmentPlaybackEnd = null;
    var draggedSegmentId = null;
    var segmentList = editor.querySelector('[data-video-segment-list]');
    var segmentStrip = editor.querySelector('[data-video-segment-strip]');
    var segmentCount = editor.querySelector('[data-video-segment-count]');
    var resultSummary = editor.querySelector('[data-video-result-summary]');
    var exportConfirm = editor.querySelector('[data-video-export-confirm]');
    var exportOpenButton = editor.querySelector('[data-video-export-open]');
    var exportCancelButton = editor.querySelector('[data-video-export-cancel]');
    var exportCreateButton = editor.querySelector('[data-video-export-create]');
    var exportNameInput = editor.querySelector('[data-video-export-name]');
    var exportList = editor.querySelector('[data-video-export-list]');
    var exportCount = editor.querySelector('[data-video-export-count]');
    var exportPollingTimer = null;

    if (!(video instanceof HTMLVideoElement) || !(timeline instanceof HTMLElement) || !(playhead instanceof HTMLElement) || !(markers instanceof HTMLElement) || !(cutList instanceof HTMLElement) || duration <= 0) {
        return;
    }

    function secondsToTimecode(seconds) {
        var milliseconds = Math.max(0, Math.round((Number(seconds) || 0) * 1000));
        var hours = Math.floor(milliseconds / 3600000);
        var minutes = Math.floor((milliseconds % 3600000) / 60000);
        var secs = Math.floor((milliseconds % 60000) / 1000);
        var ms = milliseconds % 1000;

        return String(hours).padStart(2, '0') + ':'
            + String(minutes).padStart(2, '0') + ':'
            + String(secs).padStart(2, '0') + '.'
            + String(ms).padStart(3, '0');
    }

    function timecodeToSeconds(value) {
        value = String(value || '').trim();

        if (/^\d+(\.\d+)?$/.test(value)) {
            return Number.parseFloat(value);
        }

        var match = value.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.(\d{1,3}))?$/);

        if (!match) {
            return null;
        }

        var hasHours = match[3] !== undefined;
        var hours = hasHours ? Number(match[1]) : 0;
        var minutes = hasHours ? Number(match[2]) : Number(match[1]);
        var seconds = hasHours ? Number(match[3]) : Number(match[2]);
        var millis = Number(String(match[4] || '0').padEnd(3, '0'));

        if (minutes >= 60 || seconds >= 60) {
            return null;
        }

        return hours * 3600 + minutes * 60 + seconds + millis / 1000;
    }

    function durationLabel(seconds) {
        var total = Math.max(0, Math.round(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainingSeconds = total % 60;
        var mm = String(minutes).padStart(2, '0');
        var ss = String(remainingSeconds).padStart(2, '0');

        return hours > 0 ? String(hours) + ':' + mm + ':' + ss : mm + ':' + ss;
    }

    function sizeLabel(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var size = Math.max(0, Number(bytes || 0));
        var unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }

        return unit === 0 ? String(Math.round(size)) + ' ' + units[unit] : String(Math.round(size * 10) / 10) + ' ' + units[unit];
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function clampTime(seconds) {
        return Math.min(duration, Math.max(0, Number(seconds) || 0));
    }

    function timelineInset() {
        return Number.parseFloat(window.getComputedStyle(document.documentElement).fontSize) || 16;
    }

    function setVideoTime(seconds) {
        video.currentTime = clampTime(seconds);
        updatePlayhead();
    }

    function updatePlayhead() {
        var current = clampTime(video.currentTime);
        var percent = duration > 0 ? (current / duration) * 100 : 0;
        var inset = timelineInset();
        var available = Math.max(1, timeline.clientWidth - inset * 2);

        playhead.style.left = String(inset + (available * percent / 100)) + 'px';
        timeline.setAttribute('aria-valuenow', String(Math.round(current * 1000) / 1000));
        timeline.setAttribute('aria-valuetext', secondsToTimecode(current));

        if (currentTimeLabel) {
            currentTimeLabel.textContent = secondsToTimecode(current);
        }
    }

    function updatePlayState() {
        if (playButton) {
            playButton.textContent = video.paused ? 'Play' : 'Pausa';
        }
    }

    function timelinePosition(event) {
        var rect = timeline.getBoundingClientRect();
        var clientX = event.clientX;
        var inset = timelineInset();
        var available = Math.max(1, rect.width - inset * 2);

        if (event.touches && event.touches[0]) {
            clientX = event.touches[0].clientX;
        }

        return clampTime((Math.min(available, Math.max(0, clientX - rect.left - inset)) / available) * duration);
    }

    function api(action, payload, query) {
        var url = new URL(apiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function segmentApi(action, payload, query) {
        var url = new URL(segmentsApiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function exportApi(action, payload, query) {
        var url = new URL(exportsApiUrl, window.location.origin);
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            Object.keys(query).forEach(function (key) {
                url.searchParams.set(key, query[key]);
            });
        }

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return fetch(url.toString(), options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function loadCuts() {
        return api('list', null, {video_id: videoId}).then(function (data) {
            cutPoints = Array.isArray(data) ? data : [];
            renderCuts();
        });
    }

    function loadSegments() {
        if (!(segmentList instanceof HTMLElement) || !(segmentStrip instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return segmentApi('list', null, {video_id: videoId}).then(function (data) {
            setSegments(data);
            return data;
        });
    }

    function loadExports() {
        if (!(exportList instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return exportApi('list', null, {video_id: videoId}).then(function (data) {
            renderExports(Array.isArray(data) ? data : []);
        });
    }

    function setCuts(data) {
        cutPoints = Array.isArray(data.cut_points) ? data.cut_points : cutPoints;

        if (data.cut_point && data.cut_point.id) {
            selectedCutId = Number(data.cut_point.id);
        }

        renderCuts();

        if (data.segments) {
            setSegments(data.segments);
        } else {
            loadSegments().catch(function (error) {
                showMessage(error.message, true);
            });
        }
    }

    function setSegments(data) {
        segments = Array.isArray(data && data.segments) ? data.segments : segments;
        renderSegments(data && data.summary ? data.summary : null);
    }

    function renderTicks() {
        if (!ticks) {
            return;
        }

        var width = timeline.clientWidth || 600;
        var maxLabels = Math.max(2, Math.min(8, Math.floor(width / 95)));
        var roughStep = duration / (maxLabels - 1);
        var steps = [1, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];
        var step = steps.find(function (candidate) {
            return candidate >= roughStep;
        }) || 3600;
        var values = [0];
        var current = step;

        while (current < duration) {
            values.push(current);
            current += step;
        }

        values.push(duration);
        ticks.replaceChildren();
        values.forEach(function (value) {
            var tick = document.createElement('span');
            tick.style.left = String((value / duration) * 100) + '%';
            tick.textContent = secondsToTimecode(value).replace(/^00:/, '').replace(/\.\d{3}$/, '');
            ticks.appendChild(tick);
        });
    }

    function renderCuts() {
        markers.replaceChildren();
        cutList.replaceChildren();
        cutPoints.sort(function (a, b) {
            return Number(a.position_seconds) - Number(b.position_seconds);
        });

        if (cutCount) {
            cutCount.textContent = String(cutPoints.length) + (cutPoints.length === 1 ? ' corte' : ' cortes');
        }

        if (cutPoints.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'video-cut-list__empty';
            empty.textContent = 'Aun no has agregado puntos de corte.';
            cutList.appendChild(empty);
        }

        cutPoints.forEach(function (cutPoint) {
            var id = Number(cutPoint.id);
            var position = Number(cutPoint.position_seconds);
            var marker = document.createElement('button');
            var item = document.createElement('li');
            var time = document.createElement('strong');
            var input = document.createElement('input');
            var go = document.createElement('button');
            var save = document.createElement('button');
            var remove = document.createElement('button');

            marker.type = 'button';
            marker.className = 'video-editor__cut-marker';
            marker.style.left = String((position / duration) * 100) + '%';
            marker.dataset.cutId = String(id);
            marker.dataset.positionSeconds = String(position);
            marker.setAttribute('aria-label', 'Corte ' + secondsToTimecode(position));
            marker.classList.toggle('is-selected', selectedCutId === id);
            markers.appendChild(marker);

            item.className = 'video-cut-item';
            item.dataset.cutId = String(id);
            item.dataset.positionSeconds = String(position);
            item.classList.toggle('is-selected', selectedCutId === id);
            time.textContent = secondsToTimecode(position);
            input.type = 'text';
            input.value = secondsToTimecode(position);
            input.setAttribute('aria-label', 'Editar tiempo del corte');
            input.dataset.cutTimeInput = '';

            go.type = 'button';
            go.className = 'button button--secondary';
            go.dataset.cutAction = 'go';
            go.textContent = 'Ir';
            save.type = 'button';
            save.className = 'button button--secondary';
            save.dataset.cutAction = 'save';
            save.textContent = 'Guardar';
            remove.type = 'button';
            remove.className = 'button button--danger';
            remove.dataset.cutAction = 'delete';
            remove.textContent = 'Eliminar';

            item.appendChild(time);
            item.appendChild(input);
            item.appendChild(go);
            item.appendChild(save);
            item.appendChild(remove);
            cutList.appendChild(item);
        });
    }

    function segmentById(segmentId) {
        var id = Number(segmentId);

        return segments.find(function (segment) {
            return Number(segment.id) === id;
        }) || null;
    }

    function includedSegments() {
        return segments.filter(function (segment) {
            return Boolean(segment.is_included);
        }).sort(function (a, b) {
            return Number(a.sort_order) - Number(b.sort_order);
        });
    }

    function updateResultSummary(summary) {
        if (!(resultSummary instanceof HTMLElement)) {
            return;
        }

        var total = resultSummary.querySelector('[data-result-total]');
        var included = resultSummary.querySelector('[data-result-included]');
        var original = resultSummary.querySelector('[data-result-original]');
        var finalDuration = resultSummary.querySelector('[data-result-final]');
        var sequence = resultSummary.querySelector('[data-result-sequence]');
        var includedItems = includedSegments();
        var finalSeconds = includedItems.reduce(function (carry, segment) {
            return carry + Number(segment.duration_seconds || 0);
        }, 0);
        var sourceSequence = includedItems.map(function (segment) {
            return String(segment.source_index || '');
        }).filter(Boolean).join(' -> ');

        if (total) {
            total.textContent = String(summary && summary.total_segments !== undefined ? summary.total_segments : segments.length) + ' segmentos totales';
        }

        if (included) {
            included.textContent = String(summary && summary.included_segments !== undefined ? summary.included_segments : includedItems.length) + ' incluidos';
        }

        if (original) {
            original.textContent = durationLabel(summary && summary.original_duration_seconds !== undefined ? summary.original_duration_seconds : duration);
        }

        if (finalDuration) {
            finalDuration.textContent = durationLabel(summary && summary.final_duration_seconds !== undefined ? summary.final_duration_seconds : finalSeconds);
        }

        if (sequence) {
            sequence.textContent = sourceSequence || 'Sin segmentos incluidos';
        }

        updateExportConfirm();
    }

    function updateExportConfirm() {
        if (!(exportConfirm instanceof HTMLElement)) {
            return;
        }

        var includedItems = includedSegments();
        var finalSeconds = includedItems.reduce(function (carry, segment) {
            return carry + Number(segment.duration_seconds || 0);
        }, 0);
        var segmentsTarget = exportConfirm.querySelector('[data-export-confirm-segments]');
        var durationTarget = exportConfirm.querySelector('[data-export-confirm-duration]');

        if (segmentsTarget) {
            segmentsTarget.textContent = String(includedItems.length);
        }

        if (durationTarget) {
            durationTarget.textContent = durationLabel(finalSeconds);
        }
    }

    function exportStatusLabel(status) {
        if (status === 'pending') {
            return 'En cola';
        }

        if (status === 'processing') {
            return 'Procesando';
        }

        if (status === 'completed') {
            return 'Completada';
        }

        if (status === 'failed') {
            return 'Fallida';
        }

        return 'Pendiente';
    }

    function renderExports(jobs) {
        if (!(exportList instanceof HTMLElement)) {
            return;
        }

        exportList.replaceChildren();

        if (exportCount) {
            exportCount.textContent = String(jobs.length);
        }

        if (jobs.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'video-cut-list__empty';
            empty.textContent = 'Aun no has creado exportaciones.';
            exportList.appendChild(empty);
            return;
        }

        jobs.forEach(function (job) {
            var item = document.createElement('article');
            var main = document.createElement('div');
            var title = document.createElement('strong');
            var status = document.createElement('span');
            var progress = document.createElement('div');
            var progressFill = document.createElement('span');
            var details = document.createElement('small');
            var extra = document.createElement('small');
            var actions = document.createElement('div');
            var statusText = exportStatusLabel(String(job.status || 'pending'));
            var percent = Math.max(0, Math.min(100, Number(job.progress_percent || (job.status === 'completed' ? 100 : 0))));

            if (job.status === 'completed' && job.output_size_bytes !== null && job.output_size_bytes !== undefined) {
                statusText += ' · ' + sizeLabel(job.output_size_bytes);
            }

            item.className = 'video-export-item';
            item.dataset.exportJobId = String(job.id || '');
            title.textContent = String(job.output_name || '');
            status.textContent = statusText;
            progress.className = 'video-export-progress';
            progress.setAttribute('aria-label', 'Progreso ' + String(Math.round(percent)) + '%');
            progressFill.style.width = String(percent) + '%';
            progress.appendChild(progressFill);
            details.textContent = String(Math.round(percent)) + '%'
                + (job.processed_seconds !== null && job.processed_seconds !== undefined ? ' · ' + durationLabel(job.processed_seconds) + ' procesados de ' + durationLabel(job.estimated_duration_seconds) : '')
                + (job.speed ? ' · Velocidad: ' + String(job.speed) : '');
            actions.className = 'task-actions';

            if (job.status === 'completed' && job.expires_at) {
                extra.textContent = 'Expira: ' + String(job.expires_at).slice(0, 16);
            } else if (job.status === 'failed') {
                extra.textContent = 'No se pudo completar la exportacion.';
            }

            if (job.status === 'completed') {
                var download = document.createElement('a');
                var transcribe = document.createElement('button');
                var remove = document.createElement('button');
                download.className = 'button button--secondary';
                download.href = '/video/export/download.php?id=' + encodeURIComponent(String(job.id || ''));
                download.textContent = 'Descargar';
                transcribe.type = 'button';
                transcribe.className = 'button button--secondary';
                transcribe.dataset.videoTranscriptionOpen = '';
                transcribe.dataset.transcriptionSourceType = 'export';
                transcribe.dataset.transcriptionSourceId = String(job.id || '');
                transcribe.dataset.transcriptionSourceName = String(job.output_name || 'Exportacion');
                transcribe.textContent = 'Transcribir';
                remove.type = 'button';
                remove.className = 'button button--danger';
                remove.dataset.exportAction = 'delete';
                remove.textContent = 'Eliminar exportacion';
                actions.appendChild(download);
                actions.appendChild(transcribe);
                actions.appendChild(remove);
            } else if (job.status === 'failed') {
                var retry = document.createElement('button');
                var deleteFailed = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button button--secondary';
                retry.dataset.exportAction = 'retry';
                retry.dataset.outputName = String(job.output_name || '');
                retry.textContent = 'Reintentar';
                deleteFailed.type = 'button';
                deleteFailed.className = 'button button--danger';
                deleteFailed.dataset.exportAction = 'delete';
                deleteFailed.textContent = 'Eliminar exportacion';
                actions.appendChild(retry);
                actions.appendChild(deleteFailed);
            }

            main.appendChild(title);
            main.appendChild(status);
            main.appendChild(progress);
            main.appendChild(details);

            if (extra.textContent !== '') {
                main.appendChild(extra);
            }

            item.appendChild(main);
            item.appendChild(actions);
            exportList.appendChild(item);
        });

        if (jobs.some(function (job) { return job.status === 'pending' || job.status === 'processing'; })) {
            startExportPolling();
        } else {
            stopExportPolling();
        }
    }

    function startExportPolling() {
        if (exportPollingTimer !== null) {
            return;
        }

        exportPollingTimer = window.setTimeout(function pollExports() {
            exportPollingTimer = null;
            loadExports().catch(function (error) {
                showMessage(error.message, true);
                startExportPolling();
            });
        }, 2500);
    }

    function stopExportPolling() {
        if (exportPollingTimer !== null) {
            window.clearTimeout(exportPollingTimer);
            exportPollingTimer = null;
        }
    }

    function renderSegments(summary) {
        if (!(segmentList instanceof HTMLElement) || !(segmentStrip instanceof HTMLElement)) {
            return;
        }

        segmentList.replaceChildren();
        segmentStrip.replaceChildren();
        updateResultSummary(summary);

        if (segmentCount) {
            segmentCount.textContent = String(segments.length) + (segments.length === 1 ? ' segmento' : ' segmentos');
        }

        segments.forEach(function (segment) {
            var id = Number(segment.id);
            var included = Boolean(segment.is_included);
            var start = Number(segment.source_start_seconds);
            var end = Number(segment.source_end_seconds);
            var item = document.createElement('article');
            var content = document.createElement('div');
            var number = document.createElement('span');
            var range = document.createElement('strong');
            var length = document.createElement('span');
            var actions = document.createElement('div');
            var go = document.createElement('button');
            var play = document.createElement('button');
            var stripItem = document.createElement('button');

            stripItem.type = 'button';
            stripItem.className = 'video-segment-strip__item';
            stripItem.classList.toggle('is-excluded', !included);
            stripItem.dataset.segmentId = String(id);
            stripItem.style.flexBasis = String(Math.max(8, Math.min(100, (Number(segment.duration_seconds || 0) / duration) * 100))) + '%';
            stripItem.textContent = String(segment.source_index || '');
            stripItem.setAttribute('aria-label', 'Segmento fuente ' + String(segment.source_index || ''));
            stripItem.classList.toggle('is-selected', selectedSegmentId === id);
            segmentStrip.appendChild(stripItem);

            item.className = 'video-segment-card';
            item.classList.toggle('is-excluded', !included);
            item.classList.toggle('is-selected', selectedSegmentId === id);
            item.dataset.segmentId = String(id);
            item.dataset.sourceIndex = String(segment.source_index || '');
            item.dataset.sourceStart = String(start);
            item.dataset.sourceEnd = String(end);
            item.dataset.isIncluded = included ? '1' : '0';
            item.draggable = included;

            number.className = 'video-segment-card__number';
            number.textContent = included ? 'Segmento ' + String(segment.sort_order || '') : 'Segmento descartado';
            range.textContent = secondsToTimecode(start).replace(/^00:/, '') + ' -> ' + secondsToTimecode(end).replace(/^00:/, '');
            length.textContent = durationLabel(segment.duration_seconds);
            content.appendChild(number);
            content.appendChild(range);
            content.appendChild(length);

            actions.className = 'task-actions';
            go.type = 'button';
            go.className = 'button button--secondary';
            go.dataset.segmentAction = 'go';
            go.textContent = 'Ir';
            play.type = 'button';
            play.className = 'button button--secondary';
            play.dataset.segmentAction = 'play';
            play.textContent = 'Reproducir segmento';
            actions.appendChild(go);
            actions.appendChild(play);

            if (included) {
                ['move-left', 'move-right'].forEach(function (action) {
                    var move = document.createElement('button');
                    move.type = 'button';
                    move.className = 'button button--secondary';
                    move.dataset.segmentAction = action;
                    move.setAttribute('aria-label', action === 'move-left' ? 'Mover segmento antes' : 'Mover segmento despues');
                    move.textContent = action === 'move-left' ? '<-' : '->';
                    actions.appendChild(move);
                });

                var exclude = document.createElement('button');
                exclude.type = 'button';
                exclude.className = 'button button--danger';
                exclude.dataset.segmentAction = 'exclude';
                exclude.textContent = 'Excluir del resultado';
                actions.appendChild(exclude);
            } else {
                var restore = document.createElement('button');
                restore.type = 'button';
                restore.className = 'button button--secondary';
                restore.dataset.segmentAction = 'restore';
                restore.textContent = 'Restaurar';
                actions.appendChild(restore);
            }

            item.appendChild(content);
            item.appendChild(actions);
            segmentList.appendChild(item);
        });
    }

    function selectCut(cutId, seek) {
        var id = Number(cutId);
        var cutPoint = cutPoints.find(function (item) {
            return Number(item.id) === id;
        });

        if (!cutPoint) {
            selectedCutId = null;
            renderCuts();
            return;
        }

        selectedCutId = id;

        if (seek) {
            setVideoTime(Number(cutPoint.position_seconds));
        }

        renderCuts();
    }

    function addCut() {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('create', {position_seconds: Math.round(video.currentTime * 1000) / 1000}, {video_id: videoId})
            .then(setCuts)
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function updateCut(cutId, position) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('update', {position_seconds: position}, {id: String(cutId)})
            .then(setCuts)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadCuts();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function deleteCut(cutId) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        api('delete', {}, {id: String(cutId), video_id: videoId})
            .then(function (data) {
                if (selectedCutId === Number(cutId)) {
                    selectedCutId = null;
                }

                setCuts(data);
            })
            .catch(function (error) {
                showMessage(error.message, true);
                return loadCuts();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function selectSegment(segmentId, seek) {
        var segment = segmentById(segmentId);

        if (!segment) {
            selectedSegmentId = null;
            renderSegments(null);
            return;
        }

        selectedSegmentId = Number(segment.id);

        if (seek) {
            setVideoTime(Number(segment.source_start_seconds));
        }

        renderSegments(null);
    }

    function playSegment(segmentId) {
        var segment = segmentById(segmentId);

        if (!segment) {
            return;
        }

        selectedSegmentId = Number(segment.id);
        segmentPlaybackEnd = Number(segment.source_end_seconds);
        setVideoTime(Number(segment.source_start_seconds));
        renderSegments(null);
        video.play();
    }

    function setSegmentIncluded(segmentId, include) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        segmentApi(include ? 'restore' : 'exclude', {}, {id: String(segmentId)})
            .then(setSegments)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadSegments();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function persistSegmentOrder(orderedIds) {
        if (actionPending) {
            return;
        }

        actionPending = true;
        clearMessage();
        segmentApi('reorder', {segment_ids: orderedIds}, {video_id: videoId})
            .then(setSegments)
            .catch(function (error) {
                showMessage(error.message, true);
                return loadSegments();
            })
            .finally(function () {
                actionPending = false;
            });
    }

    function moveSegment(segmentId, direction) {
        var ids = includedSegments().map(function (segment) {
            return Number(segment.id);
        });
        var index = ids.indexOf(Number(segmentId));
        var target = index + direction;

        if (index === -1 || target < 0 || target >= ids.length) {
            return;
        }

        var swap = ids[index];
        ids[index] = ids[target];
        ids[target] = swap;
        persistSegmentOrder(ids);
    }

    function reorderDraggedSegment(dragId, targetId) {
        var ids = includedSegments().map(function (segment) {
            return Number(segment.id);
        });
        var from = ids.indexOf(Number(dragId));
        var to = ids.indexOf(Number(targetId));

        if (from === -1 || to === -1 || from === to) {
            return;
        }

        var moved = ids.splice(from, 1)[0];
        ids.splice(to, 0, moved);
        persistSegmentOrder(ids);
    }

    function targetIsInput(target) {
        return target instanceof HTMLElement && Boolean(target.closest('input, textarea, select, button, a, [contenteditable="true"]'));
    }

    timeline.addEventListener('click', function (event) {
        if (event.target instanceof HTMLElement && event.target.closest('[data-cut-id]')) {
            return;
        }

        setVideoTime(timelinePosition(event));
    });

    timeline.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            setVideoTime(video.currentTime - 5);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            setVideoTime(video.currentTime + 5);
        }
    });

    markers.addEventListener('click', function (event) {
        var marker = event.target instanceof HTMLElement ? event.target.closest('[data-cut-id]') : null;

        if (marker instanceof HTMLElement) {
            selectCut(marker.dataset.cutId || '', true);
        }
    });

    cutList.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-cut-action]') : null;
        var item = event.target instanceof HTMLElement ? event.target.closest('[data-cut-id]') : null;

        if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement)) {
            return;
        }

        var cutId = Number(item.dataset.cutId || 0);
        var position = Number(item.dataset.positionSeconds || 0);

        if (button.dataset.cutAction === 'go') {
            selectCut(cutId, true);
        } else if (button.dataset.cutAction === 'save') {
            var input = item.querySelector('[data-cut-time-input]');
            var parsed = input instanceof HTMLInputElement ? timecodeToSeconds(input.value) : null;

            if (parsed === null) {
                showMessage('El punto de corte esta fuera del video.', true);
                return;
            }

            updateCut(cutId, parsed);
        } else if (button.dataset.cutAction === 'delete') {
            deleteCut(cutId);
        }

        if (position > 0) {
            selectedCutId = cutId;
            renderCuts();
        }
    });

    if (segmentList instanceof HTMLElement) {
        segmentList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-segment-action]') : null;
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement)) {
                return;
            }

            var segmentId = Number(item.dataset.segmentId || 0);

            if (button.dataset.segmentAction === 'go') {
                selectSegment(segmentId, true);
            } else if (button.dataset.segmentAction === 'play') {
                playSegment(segmentId);
            } else if (button.dataset.segmentAction === 'exclude') {
                setSegmentIncluded(segmentId, false);
            } else if (button.dataset.segmentAction === 'restore') {
                setSegmentIncluded(segmentId, true);
            } else if (button.dataset.segmentAction === 'move-left') {
                moveSegment(segmentId, -1);
            } else if (button.dataset.segmentAction === 'move-right') {
                moveSegment(segmentId, 1);
            }
        });

        segmentList.addEventListener('dragstart', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (!(item instanceof HTMLElement) || item.dataset.isIncluded !== '1') {
                event.preventDefault();
                return;
            }

            draggedSegmentId = Number(item.dataset.segmentId || 0);
            item.classList.add('is-dragging');

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
            }
        });

        segmentList.addEventListener('dragover', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (draggedSegmentId !== null && item instanceof HTMLElement && item.dataset.isIncluded === '1') {
                event.preventDefault();
                item.classList.add('is-drop-target');
            }
        });

        segmentList.addEventListener('dragleave', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (item instanceof HTMLElement) {
                item.classList.remove('is-drop-target');
            }
        });

        segmentList.addEventListener('drop', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (draggedSegmentId !== null && item instanceof HTMLElement && item.dataset.isIncluded === '1') {
                event.preventDefault();
                reorderDraggedSegment(draggedSegmentId, Number(item.dataset.segmentId || 0));
            }

            segmentList.querySelectorAll('.is-drop-target, .is-dragging').forEach(function (node) {
                node.classList.remove('is-drop-target', 'is-dragging');
            });
            draggedSegmentId = null;
        });

        segmentList.addEventListener('dragend', function () {
            segmentList.querySelectorAll('.is-drop-target, .is-dragging').forEach(function (node) {
                node.classList.remove('is-drop-target', 'is-dragging');
            });
            draggedSegmentId = null;
        });
    }

    if (segmentStrip instanceof HTMLElement) {
        segmentStrip.addEventListener('click', function (event) {
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-segment-id]') : null;

            if (item instanceof HTMLElement) {
                selectSegment(item.dataset.segmentId || '', true);
            }
        });
    }

    if (exportOpenButton instanceof HTMLButtonElement && exportConfirm instanceof HTMLElement) {
        exportOpenButton.addEventListener('click', function () {
            updateExportConfirm();
            exportConfirm.hidden = false;
        });
    }

    if (exportCancelButton instanceof HTMLButtonElement && exportConfirm instanceof HTMLElement) {
        exportCancelButton.addEventListener('click', function () {
            exportConfirm.hidden = true;
        });
    }

    if (exportCreateButton instanceof HTMLButtonElement) {
        exportCreateButton.addEventListener('click', function () {
            if (actionPending) {
                return;
            }

            actionPending = true;
            exportCreateButton.disabled = true;
            clearMessage();
            exportApi('create', {
                output_name: exportNameInput instanceof HTMLInputElement ? exportNameInput.value : ''
            }, {video_id: videoId}).then(function () {
                showMessage('Exportacion creada.', false);

                if (exportConfirm instanceof HTMLElement) {
                    exportConfirm.hidden = true;
                }

                return loadExports();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                actionPending = false;
                exportCreateButton.disabled = false;
            });
        });
    }

    if (exportList instanceof HTMLElement) {
        exportList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-export-action]') : null;
            var item = event.target instanceof HTMLElement ? event.target.closest('[data-export-job-id]') : null;

            if (!(button instanceof HTMLButtonElement) || !(item instanceof HTMLElement) || actionPending) {
                return;
            }

            var jobId = item.dataset.exportJobId || '';

            if (button.dataset.exportAction === 'delete') {
                if (!window.confirm('Eliminar esta exportacion?')) {
                    return;
                }

                actionPending = true;
                button.disabled = true;
                clearMessage();
                exportApi('delete', {}, {id: jobId})
                    .then(function () {
                        showMessage('Exportacion eliminada.', false);
                        return loadExports();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
            } else if (button.dataset.exportAction === 'retry') {
                actionPending = true;
                button.disabled = true;
                clearMessage();
                exportApi('create', {output_name: button.dataset.outputName || ''}, {video_id: videoId})
                    .then(function () {
                        showMessage('Exportacion creada.', false);
                        return loadExports();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    })
                    .finally(function () {
                        actionPending = false;
                        button.disabled = false;
                    });
            }
        });
    }

    window.addEventListener('pagehide', stopExportPolling);

    if (playButton) {
        playButton.addEventListener('click', function () {
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        });
    }

    editor.querySelectorAll('[data-video-editor-skip]').forEach(function (button) {
        button.addEventListener('click', function () {
            setVideoTime(video.currentTime + (Number(button.getAttribute('data-video-editor-skip')) || 0));
        });
    });

    if (addCutButton) {
        addCutButton.addEventListener('click', addCut);
    }

    document.addEventListener('keydown', function (event) {
        if (targetIsInput(event.target)) {
            return;
        }

        if (event.key === ' ') {
            event.preventDefault();

            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            setVideoTime(video.currentTime - 5);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            setVideoTime(video.currentTime + 5);
        } else if (event.key.toLowerCase() === 'c') {
            addCut();
        } else if ((event.key === 'Delete' || event.key === 'Backspace') && selectedCutId !== null) {
            event.preventDefault();
            deleteCut(selectedCutId);
        }
    });

    video.addEventListener('timeupdate', function () {
        updatePlayhead();

        if (segmentPlaybackEnd !== null && video.currentTime >= segmentPlaybackEnd - 0.025) {
            video.pause();
            setVideoTime(segmentPlaybackEnd);
            segmentPlaybackEnd = null;
        }
    });
    video.addEventListener('seeking', updatePlayhead);
    video.addEventListener('loadedmetadata', function () {
        updatePlayhead();
        updatePlayState();
    });
    video.addEventListener('durationchange', function () {
        updatePlayhead();
        renderTicks();
    });
    video.addEventListener('play', updatePlayState);
    video.addEventListener('pause', updatePlayState);
    window.addEventListener('resize', function () {
        renderTicks();
        updatePlayhead();
    });

    if (durationTimeLabel) {
        durationTimeLabel.textContent = secondsToTimecode(duration);
    }

    updatePlayhead();
    updatePlayState();
    renderTicks();
    loadCuts().catch(function (error) {
        showMessage(error.message, true);
    });
    loadSegments().catch(function (error) {
        showMessage(error.message, true);
    });
    loadExports().catch(function (error) {
        showMessage(error.message, true);
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-video-page]');

    if (!page || page.dataset.videoInitialized === 'true') {
        return;
    }

    page.dataset.videoInitialized = 'true';

    var form = page.querySelector('[data-video-upload-form]');
    var fileInput = page.querySelector('[data-video-file-input]');
    var fileSummary = page.querySelector('[data-video-file-summary]');
    var message = page.querySelector('[data-video-message]');
    var list = page.querySelector('[data-video-list]');
    var count = page.querySelector('[data-video-count]');
    var apiUrl = page.getAttribute('data-api-url') || '/api/video/files.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var maxUploadMb = Number.parseInt(page.getAttribute('data-max-upload-mb') || '1024', 10) || 1024;
    var uploadPending = false;
    var actionPending = false;
    var detailId = page.getAttribute('data-video-detail-id') || '';
    var pollingTimer = null;
    var transcriptionsApiUrl = page.getAttribute('data-transcriptions-api-url') || '/api/video/transcriptions.php';
    var transcriptionConfirm = page.querySelector('[data-video-transcription-confirm]');
    var transcriptionCancelButton = page.querySelector('[data-video-transcription-cancel]');
    var transcriptionCreateButton = page.querySelector('[data-video-transcription-create]');
    var transcriptionLanguage = page.querySelector('[data-video-transcription-language]');
    var transcriptionList = page.querySelector('[data-video-transcription-list]');
    var transcriptionCount = page.querySelector('[data-video-transcription-count]');
    var transcriptionModel = page.getAttribute('data-transcription-model') || '';
    var transcriptionPollingTimer = null;
    var transcriptionActionPending = false;
    var pendingTranscriptionSource = null;
    var latestTranscriptions = [];

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function setUploadPending(pending) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input'));

        uploadPending = pending;
        form.setAttribute('aria-busy', pending ? 'true' : 'false');
        controls.forEach(function (control) {
            control.disabled = pending;
        });
    }

    function sizeLabel(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var size = Math.max(0, Number(bytes || 0));
        var unit = 0;

        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit++;
        }

        return unit === 0 ? String(Math.round(size)) + ' ' + units[unit] : String(Math.round(size * 10) / 10) + ' ' + units[unit];
    }

    function statusLabel(status) {
        if (status === 'ready') {
            return 'Listo';
        }

        if (status === 'failed') {
            return 'No se pudo analizar el video.';
        }

        return 'Analizando video...';
    }

    function durationLabel(seconds) {
        if (seconds === null || seconds === undefined || seconds === '') {
            return 'Pendiente';
        }

        var total = Math.max(0, Math.round(Number(seconds) || 0));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainingSeconds = total % 60;
        var mm = String(minutes).padStart(2, '0');
        var ss = String(remainingSeconds).padStart(2, '0');

        return hours > 0 ? String(hours) + ':' + mm + ':' + ss : mm + ':' + ss;
    }

    function fpsLabel(fps) {
        if (fps === null || fps === undefined || fps === '') {
            return 'FPS pendiente';
        }

        return String(Math.round(Number(fps) * 100) / 100).replace(/\.0$/, '') + ' fps';
    }

    function codecLabel(codec) {
        var value = String(codec || '').toLowerCase();
        var labels = {
            h264: 'H.264',
            hevc: 'H.265',
            h265: 'H.265',
            mpeg4: 'MPEG-4',
            vp8: 'VP8',
            vp9: 'VP9',
            av1: 'AV1',
            aac: 'AAC',
            mp3: 'MP3',
            opus: 'Opus',
            vorbis: 'Vorbis'
        };

        return value ? (labels[value] || value.toUpperCase()) : 'Sin datos';
    }

    function metadataCards(video) {
        var status = String(video.metadata_status || 'pending');
        var cards = [];

        if (status !== 'ready') {
            return [
                {label: 'Tamano', value: sizeLabel(video.size_bytes)}
            ];
        }

        cards.push({label: 'Duracion', value: durationLabel(video.duration_seconds)});
        cards.push({
            label: 'Resolucion',
            value: video.width && video.height ? String(video.width) + ' x ' + String(video.height) : 'Sin datos'
        });
        cards.push({label: 'FPS', value: fpsLabel(video.fps)});
        cards.push({label: 'Video', value: codecLabel(video.video_codec)});
        cards.push({label: 'Audio', value: codecLabel(video.audio_codec)});
        cards.push({label: 'Tamano', value: sizeLabel(video.size_bytes)});

        if (video.container_format) {
            cards.push({label: 'Formato', value: String(video.container_format)});
        }

        if (video.bitrate !== null && video.bitrate !== undefined) {
            cards.push({label: 'Bitrate', value: sizeLabel(video.bitrate) + '/s'});
        }

        return cards;
    }

    function metadataGrid(video) {
        var dl = document.createElement('dl');

        dl.className = 'video-metadata';
        dl.dataset.videoMetadataState = 'ready';
        metadataCards(video).forEach(function (card) {
            var wrapper = document.createElement('div');
            var label = document.createElement('dt');
            var value = document.createElement('dd');

            label.textContent = card.label;
            value.textContent = card.value;
            wrapper.appendChild(label);
            wrapper.appendChild(value);
            dl.appendChild(wrapper);
        });

        return dl;
    }

    function metadataLoading(video) {
        var wrapper = document.createElement('div');
        var title = document.createElement('strong');
        var size = document.createElement('span');

        wrapper.className = 'video-metadata-state';
        wrapper.dataset.videoMetadataState = 'loading';
        title.textContent = 'Analizando informacion del video...';
        size.textContent = 'Tamano: ' + sizeLabel(video.size_bytes);
        wrapper.appendChild(title);
        wrapper.appendChild(size);

        return wrapper;
    }

    function metadataFailed(video) {
        var wrapper = document.createElement('div');
        var title = document.createElement('strong');
        var retry = document.createElement('button');

        wrapper.className = 'video-metadata-state';
        wrapper.dataset.videoMetadataState = 'failed';
        title.textContent = 'No se pudo obtener la informacion tecnica del video.';
        retry.type = 'button';
        retry.className = 'button button--secondary';
        retry.dataset.videoAction = 'retry-metadata';
        retry.dataset.videoId = String(video.id || detailId || '');
        retry.textContent = 'Reintentar analisis';
        wrapper.appendChild(title);
        wrapper.appendChild(retry);

        return wrapper;
    }

    function renderMetadataPanel(video) {
        var panel = page.querySelector('[data-video-metadata-panel]');
        var status = String(video.metadata_status || 'pending');

        if (!panel) {
            return;
        }

        panel.replaceChildren();

        if (status === 'ready') {
            panel.appendChild(metadataGrid(video));
            stopMetadataPolling();
            return;
        }

        if (status === 'failed') {
            panel.appendChild(metadataFailed(video));
            stopMetadataPolling();
            return;
        }

        panel.appendChild(metadataLoading(video));
        startMetadataPolling();
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'Aun no has subido videos.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function videoArticle(video) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var titleLink = document.createElement('a');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var openLink = document.createElement('a');
        var deleteButton = document.createElement('button');
        var metadataStatus = String(video.metadata_status || 'pending');

        article.className = 'video-item';
        article.dataset.videoId = String(video.id || '');
        main.className = 'video-item__main';
        titleLink.href = '/index.php?section=video-editor&id=' + encodeURIComponent(String(video.id || ''));
        titleLink.textContent = String(video.original_name || '');
        title.appendChild(titleLink);
        meta.className = 'task-meta';
        [sizeLabel(video.size_bytes)].concat(metadataStatus === 'ready' ? [
            durationLabel(video.duration_seconds) + ' · ' + (video.width && video.height ? String(video.width) + 'x' + String(video.height) : 'Resolucion pendiente') + ' · ' + fpsLabel(video.fps),
            codecLabel(video.video_codec) + ' / ' + codecLabel(video.audio_codec)
        ] : []).concat([statusLabel(metadataStatus)]).forEach(function (text) {
            var span = document.createElement('span');
            span.textContent = text;
            meta.appendChild(span);
        });

        actions.className = 'task-actions';

        if (metadataStatus === 'failed') {
            var retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.className = 'button button--secondary';
            retryButton.dataset.videoAction = 'retry-metadata';
            retryButton.textContent = 'Reintentar analisis';
            actions.appendChild(retryButton);
        }

        openLink.className = 'button button--secondary';
        openLink.href = '/index.php?section=video-editor&id=' + encodeURIComponent(String(video.id || ''));
        openLink.textContent = 'Abrir';
        deleteButton.type = 'button';
        deleteButton.className = 'button button--danger';
        deleteButton.dataset.videoAction = 'delete';
        deleteButton.textContent = 'Eliminar';
        actions.appendChild(openLink);
        actions.appendChild(deleteButton);
        main.appendChild(title);
        main.appendChild(meta);
        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function renderVideos(videos) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(videos.length);
        }

        if (videos.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        videos.forEach(function (video) {
            list.appendChild(videoArticle(video));
        });
    }

    function jsonApi(url, options) {
        options.credentials = 'same-origin';
        options.headers = Object.assign({Accept: 'application/json'}, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function refreshVideos() {
        return jsonApi(apiUrl, {method: 'GET'}).then(function (videos) {
            renderVideos(Array.isArray(videos) ? videos : []);
        });
    }

    function videoUrl(videoId) {
        var url = new URL(apiUrl, window.location.origin);

        url.searchParams.set('id', videoId);

        return url;
    }

    function fetchVideo(videoId) {
        return jsonApi(videoUrl(videoId).toString(), {method: 'GET'});
    }

    function transcriptionUrl(query) {
        var url = new URL(transcriptionsApiUrl, window.location.origin);

        Object.keys(query || {}).forEach(function (key) {
            url.searchParams.set(key, query[key]);
        });

        return url;
    }

    function transcriptionDownloadUrl(transcriptionId, format) {
        var url = new URL('/video/transcription/download.php', window.location.origin);

        url.searchParams.set('id', transcriptionId);
        url.searchParams.set('format', format);

        return url.toString();
    }

    function transcriptionApi(action, payload, query) {
        var url = transcriptionUrl(query || {});
        var options = {
            method: action === 'list' ? 'GET' : 'POST',
            headers: {
                Accept: 'application/json'
            }
        };

        if (action !== 'list') {
            url.searchParams.set('action', action);
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(Object.assign({action: action}, payload || {}));
        }

        return jsonApi(url.toString(), options);
    }

    function transcriptionStatusLabel(status) {
        if (status === 'pending') {
            return 'En cola';
        }

        if (status === 'processing') {
            return 'Procesando';
        }

        if (status === 'completed') {
            return 'Completada';
        }

        if (status === 'failed') {
            return 'Fallida';
        }

        return 'Pendiente';
    }

    function transcriptionLanguageLabel(language) {
        if (language === 'es') {
            return 'Espanol';
        }

        if (language === 'en') {
            return 'Ingles';
        }

        return 'Detectar automaticamente';
    }

    function transcriptionSourceId(item) {
        if (String(item.source_type || 'video') === 'export') {
            return item.export_job_id === null || item.export_job_id === undefined ? '' : String(item.export_job_id);
        }

        return String(item.video_id || '');
    }

    function sameTranscriptionSource(item, source) {
        if (!source) {
            return false;
        }

        return String(item.source_type || 'video') === source.type && transcriptionSourceId(item) === source.id;
    }

    function segmentCountLabel(count) {
        var amount = Math.max(0, Number(count || 0));

        return new Intl.NumberFormat('es-CL').format(amount) + (amount === 1 ? ' segmento' : ' segmentos');
    }

    function renderTranscriptions(items, model) {
        if (!(transcriptionList instanceof HTMLElement)) {
            return;
        }

        latestTranscriptions = Array.isArray(items) ? items : [];
        transcriptionList.replaceChildren();

        if (transcriptionCount) {
            transcriptionCount.textContent = String(latestTranscriptions.length);
        }

        if (transcriptionOpenButton) {
            transcriptionOpenButton.textContent = latestTranscriptions.length === 0 ? 'Transcribir' : 'Volver a transcribir';
        }

        if (model) {
            transcriptionModel = String(model);
            var modelTarget = page.querySelector('[data-video-transcription-model]');

            if (modelTarget) {
                modelTarget.textContent = transcriptionModel;
            }
        }

        if (latestTranscriptions.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'video-cut-list__empty';
            empty.textContent = 'Aun no has creado transcripciones.';
            transcriptionList.appendChild(empty);
            stopTranscriptionPolling();
            return;
        }

        latestTranscriptions.forEach(function (transcription) {
            var item = document.createElement('article');
            var main = document.createElement('div');
            var title = document.createElement('strong');
            var status = document.createElement('span');
            var progress = document.createElement('div');
            var progressFill = document.createElement('span');
            var details = document.createElement('small');
            var extra = document.createElement('small');
            var actions = document.createElement('div');
            var transcriptionStatus = String(transcription.status || 'pending');
            var percent = Math.max(0, Math.min(100, Number(transcription.progress_percent || (transcriptionStatus === 'completed' ? 100 : 0))));

            item.className = 'video-export-item';
            item.dataset.transcriptionId = String(transcription.id || '');
            title.textContent = String(transcription.source_display_name || '') || transcriptionLanguageLabel(String(transcription.requested_language || 'auto'));
            status.textContent = transcriptionStatusLabel(transcriptionStatus) + ' · ' + transcriptionLanguageLabel(String(transcription.requested_language || 'auto'));
            progress.className = 'video-export-progress';
            progress.setAttribute('aria-label', 'Progreso ' + String(Math.round(percent)) + '%');
            progressFill.style.width = String(percent) + '%';
            progress.appendChild(progressFill);
            details.textContent = transcriptionStatus === 'processing'
                ? 'Procesando...' + (percent > 1 && percent < 100 ? ' ' + String(Math.round(percent)) + '%' : '')
                : String(Math.round(percent)) + '%';

            if (transcriptionStatus === 'completed') {
                details.textContent += ' · ' + segmentCountLabel(transcription.segments_count);
                extra.textContent = 'Transcripcion completada correctamente.';
            } else if (transcriptionStatus === 'failed') {
                extra.textContent = 'No se pudo completar la transcripcion.';
            }

            actions.className = 'task-actions';

            if (transcriptionStatus === 'completed') {
                var txt = document.createElement('a');
                var timedTxt = document.createElement('a');
                var srt = document.createElement('a');
                var vtt = document.createElement('a');
                var copy = document.createElement('button');
                var remove = document.createElement('button');
                txt.className = 'button button--secondary';
                txt.href = transcriptionDownloadUrl(String(transcription.id || ''), 'txt');
                txt.textContent = 'Descargar TXT';
                timedTxt.className = 'button button--secondary';
                timedTxt.href = transcriptionDownloadUrl(String(transcription.id || ''), 'txt_timestamps');
                timedTxt.textContent = 'TXT con tiempos';
                srt.className = 'button button--secondary';
                srt.href = transcriptionDownloadUrl(String(transcription.id || ''), 'srt');
                srt.textContent = 'Descargar SRT';
                vtt.className = 'button button--secondary';
                vtt.href = transcriptionDownloadUrl(String(transcription.id || ''), 'vtt');
                vtt.textContent = 'Descargar VTT';
                copy.type = 'button';
                copy.className = 'button button--secondary';
                copy.dataset.transcriptionAction = 'copy';
                copy.textContent = 'Copiar texto';
                remove.type = 'button';
                remove.className = 'button button--danger';
                remove.dataset.transcriptionAction = 'delete';
                remove.textContent = 'Eliminar transcripcion';
                actions.appendChild(txt);
                actions.appendChild(timedTxt);
                actions.appendChild(srt);
                actions.appendChild(vtt);
                actions.appendChild(copy);
                actions.appendChild(remove);
            } else if (transcriptionStatus === 'failed') {
                var retry = document.createElement('button');
                var deleteFailed = document.createElement('button');
                retry.type = 'button';
                retry.className = 'button button--secondary';
                retry.dataset.transcriptionAction = 'retry';
                retry.dataset.language = String(transcription.requested_language || 'auto');
                retry.dataset.sourceType = String(transcription.source_type || 'video');
                retry.dataset.sourceId = transcriptionSourceId(transcription);
                retry.dataset.sourceName = String(transcription.source_display_name || '');
                retry.textContent = 'Reintentar';
                deleteFailed.type = 'button';
                deleteFailed.className = 'button button--danger';
                deleteFailed.dataset.transcriptionAction = 'delete';
                deleteFailed.textContent = 'Eliminar transcripcion';
                actions.appendChild(retry);
                actions.appendChild(deleteFailed);
            }

            main.appendChild(title);
            main.appendChild(status);
            main.appendChild(progress);
            main.appendChild(details);

            if (extra.textContent !== '') {
                main.appendChild(extra);
            }

            item.appendChild(main);
            item.appendChild(actions);
            transcriptionList.appendChild(item);
        });

        if (latestTranscriptions.some(function (item) { return item.status === 'pending' || item.status === 'processing'; })) {
            startTranscriptionPolling();
        } else {
            stopTranscriptionPolling();
        }
    }

    function loadTranscriptions() {
        if (!detailId || !(transcriptionList instanceof HTMLElement)) {
            return Promise.resolve(null);
        }

        return transcriptionApi('list', null, {video_id: detailId}).then(function (data) {
            var items = Array.isArray(data && data.items) ? data.items : [];
            renderTranscriptions(items, data && data.model ? data.model : transcriptionModel);
            return data;
        });
    }

    function stopMetadataPolling() {
        if (pollingTimer !== null) {
            window.clearTimeout(pollingTimer);
            pollingTimer = null;
        }
    }

    function startMetadataPolling() {
        if (!detailId || pollingTimer !== null) {
            return;
        }

        pollingTimer = window.setTimeout(function poll() {
            pollingTimer = null;
            fetchVideo(detailId).then(function (video) {
                renderMetadataPanel(video);
            }).catch(function () {
                startMetadataPolling();
            });
        }, 4000);
    }

    window.addEventListener('pagehide', stopMetadataPolling);

    function stopTranscriptionPolling() {
        if (transcriptionPollingTimer !== null) {
            window.clearTimeout(transcriptionPollingTimer);
            transcriptionPollingTimer = null;
        }
    }

    function startTranscriptionPolling() {
        if (!detailId || !(transcriptionList instanceof HTMLElement) || transcriptionPollingTimer !== null) {
            return;
        }

        transcriptionPollingTimer = window.setTimeout(function pollTranscriptions() {
            transcriptionPollingTimer = null;
            loadTranscriptions().catch(function () {
                startTranscriptionPolling();
            });
        }, 3000);
    }

    function activeTranscriptionExists(source) {
        return latestTranscriptions.some(function (item) {
            return sameTranscriptionSource(item, source) && (item.status === 'pending' || item.status === 'processing');
        });
    }

    function completedTranscriptionExists(source) {
        return latestTranscriptions.some(function (item) {
            return sameTranscriptionSource(item, source) && item.status === 'completed';
        });
    }

    function setTranscriptionConfirmVisible(visible) {
        if (transcriptionConfirm instanceof HTMLElement) {
            transcriptionConfirm.hidden = !visible;
        }
    }

    function sourceFromButton(button) {
        var type = button.dataset.transcriptionSourceType || button.dataset.sourceType || 'video';
        var id = button.dataset.transcriptionSourceId || button.dataset.sourceId || detailId;

        if (type !== 'export') {
            type = 'video';
        }

        return {
            type: type,
            id: String(id || ''),
            name: button.dataset.transcriptionSourceName || button.dataset.sourceName || (type === 'video' ? 'Video' : 'Exportacion')
        };
    }

    function setPendingTranscriptionSource(source) {
        pendingTranscriptionSource = source;

        var label = page.querySelector('[data-video-transcription-source]');

        if (label) {
            label.textContent = source && source.name ? source.name : 'Video';
        }
    }

    function createTranscription(language, button) {
        if (!detailId || transcriptionActionPending || !pendingTranscriptionSource || !pendingTranscriptionSource.id) {
            return;
        }

        transcriptionActionPending = true;

        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }

        clearMessage();
        var query = pendingTranscriptionSource.type === 'export'
            ? {export_job_id: pendingTranscriptionSource.id}
            : {video_id: pendingTranscriptionSource.id};

        transcriptionApi('create', {requested_language: language || 'auto'}, query).then(function () {
            setTranscriptionConfirmVisible(false);
            showMessage('Transcripcion creada.', false);
            return loadTranscriptions();
        }).catch(function (error) {
            showMessage(error.message, true);
        }).finally(function () {
            transcriptionActionPending = false;

            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
            }
        });
    }

    function copyTranscriptionText(transcriptionId, button) {
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            showMessage('No se pudo acceder al portapapeles.', true);
            return;
        }

        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
        }

        clearMessage();
        fetch(transcriptionDownloadUrl(transcriptionId, 'txt'), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'text/plain'
            }
        }).then(function (response) {
            return response.text().then(function (text) {
                if (!response.ok) {
                    throw new Error(text || 'No se pudo obtener la transcripcion.');
                }

                return navigator.clipboard.writeText(text);
            });
        }).then(function () {
            showMessage('Transcripcion copiada.', false);
        }).catch(function () {
            showMessage('No se pudo copiar la transcripcion.', true);
        }).finally(function () {
            if (button instanceof HTMLButtonElement) {
                button.disabled = false;
            }
        });
    }

    window.addEventListener('pagehide', stopTranscriptionPolling);

    if (fileInput instanceof HTMLInputElement) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

            if (!file || !fileSummary) {
                return;
            }

            fileSummary.textContent = file.name + ' · ' + sizeLabel(file.size) + ' · limite ' + (maxUploadMb >= 1024 ? String(maxUploadMb / 1024) + ' GB' : String(maxUploadMb) + ' MB');
        });
    }

    if (form instanceof HTMLFormElement && fileInput instanceof HTMLInputElement) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (uploadPending) {
                return;
            }

            if (!fileInput.files || !fileInput.files[0]) {
                showMessage('Selecciona un video para subir.', true);
                return;
            }

            var formData = new FormData(form);

            clearMessage();
            setUploadPending(true);
            jsonApi(apiUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            }).then(function () {
                form.reset();
                window.location.href = '/index.php?section=video-editor';
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                setUploadPending(false);
            });
        });
    }

    page.addEventListener('click', function (event) {
        var openButton = event.target instanceof HTMLElement ? event.target.closest('[data-video-transcription-open]') : null;

        if (!(openButton instanceof HTMLButtonElement)) {
            return;
        }

        var source = sourceFromButton(openButton);

        if (!source.id) {
            showMessage('Fuente de transcripcion invalida.', true);
            return;
        }

        if (activeTranscriptionExists(source)) {
            showMessage('Este video ya se esta transcribiendo.', true);
            return;
        }

        if (completedTranscriptionExists(source) && !window.confirm('Crear una nueva transcripcion para este video?')) {
            return;
        }

        setPendingTranscriptionSource(source);
        setTranscriptionConfirmVisible(true);
        clearMessage();

        if (transcriptionLanguage instanceof HTMLSelectElement) {
            transcriptionLanguage.focus();
        }
    });

    if (transcriptionCancelButton instanceof HTMLButtonElement) {
        transcriptionCancelButton.addEventListener('click', function () {
            setTranscriptionConfirmVisible(false);
        });
    }

    if (transcriptionCreateButton instanceof HTMLButtonElement) {
        transcriptionCreateButton.addEventListener('click', function () {
            var language = transcriptionLanguage instanceof HTMLSelectElement ? transcriptionLanguage.value : 'auto';

            createTranscription(language, transcriptionCreateButton);
        });
    }

    if (transcriptionList instanceof HTMLElement) {
        transcriptionList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-transcription-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-transcription-id]');
            var transcriptionId = item instanceof HTMLElement ? item.dataset.transcriptionId || '' : '';
            var action = button.dataset.transcriptionAction || '';

            if (!transcriptionId || transcriptionActionPending) {
                return;
            }

            if (action === 'copy') {
                copyTranscriptionText(transcriptionId, button);
                return;
            }

            if (action === 'retry') {
                var retrySource = sourceFromButton(button);

                if (activeTranscriptionExists(retrySource)) {
                    showMessage('Este video ya se esta transcribiendo.', true);
                    return;
                }

                setPendingTranscriptionSource(retrySource);
                createTranscription(button.dataset.language || 'auto', button);
                return;
            }

            if (action !== 'delete') {
                return;
            }

            if (!window.confirm('Eliminar esta transcripcion?')) {
                return;
            }

            transcriptionActionPending = true;
            button.disabled = true;
            clearMessage();
            transcriptionApi('delete', {id: transcriptionId}, {id: transcriptionId}).then(function () {
                showMessage('Transcripcion eliminada.', false);
                return loadTranscriptions();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                transcriptionActionPending = false;
                button.disabled = false;
            });
        });
    }

    page.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-video-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-video-id]');
            var videoId = button.getAttribute('data-video-id') || (item instanceof HTMLElement ? item.dataset.videoId || '' : '');

            if (!videoId) {
                return;
            }

            if (actionPending) {
                return;
            }

            if (button.dataset.videoAction === 'delete' && !window.confirm('Eliminar este video?')) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = videoUrl(videoId);
            var action = button.dataset.videoAction || '';
            var payload = {};

            if (action === 'retry-metadata') {
                url.searchParams.set('action', 'retry-metadata');
                payload.action = 'retry-metadata';
            } else if (action === 'delete') {
                url.searchParams.set('action', 'delete');
                payload.action = 'delete';
            } else {
                return;
            }

            jsonApi(url.toString(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            }).then(function (data) {
                if (action === 'retry-metadata') {
                    showMessage('Analisis reprogramado.', false);

                    if (detailId) {
                        renderMetadataPanel(data);
                    } else {
                        return refreshVideos();
                    }

                    return null;
                }

                if (!list || detailId) {
                    window.location.href = '/index.php?section=video-editor&video_message=deleted';
                    return null;
                }

                showMessage(data && data.message ? data.message : 'Video eliminado correctamente.', false);
                return refreshVideos();
            }).catch(function (error) {
                showMessage(error.message, true);
            }).finally(function () {
                actionPending = false;
                button.disabled = false;
            });
        });

    if (detailId && ['pending', 'processing'].indexOf(page.getAttribute('data-metadata-status') || '') !== -1) {
        startMetadataPolling();
    }

    loadTranscriptions().catch(function (error) {
        showMessage(error.message, true);
    });
}());

(function () {
    'use strict';

    var week = document.querySelector('[data-friends-week]');

    if (!week || week.dataset.weekInitialized === 'true') {
        return;
    }

    week.dataset.weekInitialized = 'true';

    function timeMinutes(value) {
        value = String(value || '08:00').slice(0, 5);
        var parts = value.split(':');

        return (Number(parts[0] || 0) * 60) + Number(parts[1] || 0);
    }

    function layoutWeekBlocks() {
        week.querySelectorAll('.schedule-block').forEach(function (block) {
            var start = Math.max(8 * 60, timeMinutes(block.dataset.startsAt));
            var end = Math.min(21 * 60, timeMinutes(block.dataset.endsAt));

            block.style.setProperty('--schedule-offset', String(Math.max(0, start - (8 * 60))));
            block.style.setProperty('--schedule-duration', String(Math.max(30, end - start)));
        });

        week.classList.add('is-laid-out');
    }

    function requestWeekLayout() {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(layoutWeekBlocks);
        });
    }

    function activateDay(date) {
        date = String(date || '');

        if (!date) {
            var first = week.querySelector('[data-week-day]');
            date = first instanceof HTMLElement ? String(first.dataset.weekDay || '') : '';
        }

        week.querySelectorAll('[data-week-day-tab]').forEach(function (tab) {
            var active = tab.getAttribute('data-week-day-tab') === date;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        week.querySelectorAll('[data-week-day]').forEach(function (column) {
            column.classList.toggle('is-mobile-active', column.getAttribute('data-week-day') === date);
        });
    }

    week.querySelectorAll('[data-week-day-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateDay(button.getAttribute('data-week-day-tab') || '');
        });
    });

    var activeTab = week.querySelector('[data-week-day-tab].is-active');
    activateDay(activeTab instanceof HTMLElement ? activeTab.getAttribute('data-week-day-tab') || '' : '');
    requestWeekLayout();
    window.addEventListener('resize', requestWeekLayout);
}());

(function () {
    'use strict';

    var containers = Array.prototype.slice.call(document.querySelectorAll('[data-notification-center], [data-notification-page]'));

    if (containers.length === 0) {
        return;
    }

    function api(container, method, payload, query) {
        var url = container.getAttribute('data-api-url') || '/api/notifications.php';
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (query) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + query;
        }

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = container.getAttribute('data-csrf-token') || '';
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function updateCounters(count) {
        Array.prototype.slice.call(document.querySelectorAll('[data-notification-count]')).forEach(function (counter) {
            counter.textContent = count > 0 ? String(count) : '';
            counter.hidden = count <= 0;
        });
    }

    function currentStatus() {
        var params = new URLSearchParams(window.location.search);
        var status = params.get('status') || 'all';

        return ['all', 'unread', 'read'].indexOf(status) === -1 ? 'all' : status;
    }

    function notificationArticle(notification, compact) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var isRead = Boolean(notification.is_read);
        var date = String(notification.scheduled_at_local || '').slice(0, 16);

        article.className = 'notification-item ' + (isRead ? 'is-read' : 'is-unread');
        article.dataset.notificationId = String(notification.id || '');
        main.className = 'notification-item__main';

        if (!compact) {
            var type = document.createElement('p');
            type.className = 'dashboard-card__eyebrow';
            type.textContent = String(notification.type_label || 'Notificacion');
            main.appendChild(type);
        }

        title.textContent = String(notification.title || '');
        main.appendChild(title);

        if (notification.message) {
            var message = document.createElement('p');
            message.textContent = String(notification.message);
            main.appendChild(message);
        }

        meta.className = 'notification-meta';
        [date, isRead ? 'Leida' : 'No leida', compact ? '' : String(notification.target_label || '')].forEach(function (text) {
            if (!text) {
                return;
            }

            var span = document.createElement('span');
            span.textContent = text;
            meta.appendChild(span);
        });
        main.appendChild(meta);

        actions.className = 'notification-item__actions';

        if (notification.target_url) {
            var link = document.createElement('a');
            link.className = 'button button--secondary';
            link.href = String(notification.target_url);
            link.textContent = 'Abrir';
            actions.appendChild(link);
        }

        if (!isRead) {
            var readButton = document.createElement('button');
            readButton.type = 'button';
            readButton.className = 'button button--secondary';
            readButton.dataset.notificationAction = 'read';
            readButton.textContent = compact ? 'Leida' : 'Marcar leida';
            actions.appendChild(readButton);
        }

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function renderList(container, items) {
        var list = container.querySelector('[data-notification-list]');
        var compact = container.hasAttribute('data-notification-center');

        if (!list) {
            return;
        }

        list.replaceChildren();

        if (items.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'notification-empty';
            empty.textContent = compact ? 'Sin notificaciones recientes.' : 'No hay notificaciones para este filtro.';
            list.appendChild(empty);
        } else {
            items.forEach(function (notification) {
                list.appendChild(notificationArticle(notification, compact));
            });
        }

        var pageCount = container.querySelector('[data-notification-page-count]');

        if (pageCount) {
            pageCount.textContent = String(items.length);
        }
    }

    function refresh(container) {
        var query = container.hasAttribute('data-notification-page')
            ? 'status=' + encodeURIComponent(currentStatus()) + '&limit=50'
            : 'limit=5';

        return api(container, 'GET', null, query).then(function (data) {
            updateCounters(Number(data.unread_count || 0));
            renderList(container, Array.isArray(data.items) ? data.items : []);
        });
    }

    function refreshAll() {
        containers.forEach(function (container) {
            refresh(container).catch(function () {});
        });
    }

    containers.forEach(function (container) {
        var toggle = container.querySelector('[data-notification-toggle]');
        var panel = container.querySelector('[data-notification-panel]');

        if (toggle && panel) {
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') !== 'true';

                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                panel.hidden = !open;

                if (open) {
                    refresh(container).catch(function () {});
                }
            });
        }

        container.addEventListener('click', function (event) {
            var actionTarget = event.target instanceof Element ? event.target.closest('[data-notification-action]') : null;

            if (!actionTarget) {
                return;
            }

            event.preventDefault();

            var action = actionTarget.getAttribute('data-notification-action') || '';
            var payload = { action: action };

            if (action === 'read') {
                var article = actionTarget.closest('[data-notification-id]');
                payload.id = article ? article.getAttribute('data-notification-id') : '';
            }

            api(container, 'POST', payload, '').then(refreshAll).catch(function () {});
        });
    });

    document.addEventListener('click', function (event) {
        containers.forEach(function (container) {
            var toggle = container.querySelector('[data-notification-toggle]');
            var panel = container.querySelector('[data-notification-panel]');

            if (!toggle || !panel || panel.hidden || container.contains(event.target)) {
                return;
            }

            toggle.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        containers.forEach(function (container) {
            var toggle = container.querySelector('[data-notification-toggle]');
            var panel = container.querySelector('[data-notification-panel]');

            if (toggle && panel) {
                toggle.setAttribute('aria-expanded', 'false');
                panel.hidden = true;
            }
        });
    });

    window.setInterval(refreshAll, 60000);
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');

    if (!page || page.dataset.remindersInitialized === 'true') {
        return;
    }

    var formPanel = page.querySelector('[data-reminder-form-panel]');
    var form = page.querySelector('[data-reminder-form]');

    if (!formPanel || !(form instanceof HTMLFormElement)) {
        return;
    }

    page.dataset.remindersInitialized = 'true';

    var remindersPanel = page.querySelector('[data-reminders-panel]');
    var apiUrl = remindersPanel ? remindersPanel.getAttribute('data-api-url') || '/api/organization/reminders.php' : '/api/organization/reminders.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formTitle = page.querySelector('[data-reminder-form-title]');
    var newButton = page.querySelector('[data-reminder-new]');
    var cancelButton = page.querySelector('[data-reminder-cancel]');
    var list = page.querySelector('[data-reminder-list]');
    var count = page.querySelector('[data-reminder-count]');
    var message = page.querySelector('[data-task-message]');
    var targetNote = page.querySelector('[data-reminder-target-note]');
    var pending = false;
    var actionPending = false;

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function statusLabel(status) {
        if (status === 'completed') {
            return 'Completado';
        }

        if (status === 'dismissed') {
            return 'Descartado';
        }

        return 'Pendiente';
    }

    function recurrenceLabel(type, interval) {
        interval = Math.max(1, Number(interval || 1));

        if (type === 'none') {
            return 'No repetir';
        }

        var singular = {
            daily: 'dia',
            weekly: 'semana',
            monthly: 'mes',
            yearly: 'ano'
        }[type] || '';
        var plural = {
            daily: 'dias',
            weekly: 'semanas',
            monthly: 'meses',
            yearly: 'anos'
        }[type] || '';

        return interval === 1 ? 'Cada ' + singular : 'Cada ' + interval + ' ' + plural;
    }

    function targetLabel(reminder) {
        if (reminder.task_id) {
            return 'Tarea: ' + (reminder.target_title || reminder.task_title || 'sin titulo');
        }

        if (reminder.project_id) {
            return 'Proyecto: ' + (reminder.target_title || reminder.project_title || 'sin titulo');
        }

        return 'Independiente';
    }

    function targetUrl(reminder) {
        if (reminder.task_id) {
            if (reminder.target_project_id) {
                return '/index.php?section=organization&tab=projects&project=' + encodeURIComponent(String(reminder.target_project_id)) + '&edit_task=' + encodeURIComponent(String(reminder.task_id));
            }

            return '/index.php?section=organization&tab=tasks&status=all&edit_task=' + encodeURIComponent(String(reminder.task_id));
        }

        if (reminder.project_id) {
            return '/index.php?section=organization&tab=projects&project=' + encodeURIComponent(String(reminder.project_id));
        }

        return '';
    }

    function appendMeta(parent, child) {
        parent.appendChild(child);
    }

    function metaText(text, className) {
        var span = document.createElement('span');
        span.textContent = text;

        if (className) {
            span.className = className;
        }

        return span;
    }

    function actionButton(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.reminderAction = action;
        button.textContent = label;
        return button;
    }

    function reminderArticle(reminder) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var date = String(reminder.occurrence_at_local || reminder.remind_at_local || '').slice(0, 16);
        var status = String(reminder.status || 'pending');
        var recurrenceType = String(reminder.recurrence_type || 'none');
        var recurrenceInterval = String(reminder.recurrence_interval || '1');
        var url = targetUrl(reminder);

        article.className = 'reminder-item';
        article.classList.toggle('is-overdue', Boolean(reminder.is_overdue));
        article.dataset.reminderId = String(reminder.id || '');
        article.dataset.title = String(reminder.title || '');
        article.dataset.description = String(reminder.description || '');
        article.dataset.taskId = reminder.task_id === null || reminder.task_id === undefined ? '' : String(reminder.task_id);
        article.dataset.projectId = reminder.project_id === null || reminder.project_id === undefined ? '' : String(reminder.project_id);
        article.dataset.remindDate = String(reminder.remind_date_local || '');
        article.dataset.remindTime = String(reminder.remind_time_local || '');
        article.dataset.recurrenceType = recurrenceType;
        article.dataset.recurrenceInterval = recurrenceInterval;
        article.dataset.recurrenceUntil = String(reminder.recurrence_until_local || '');
        article.dataset.status = status;

        main.className = 'task-item__main';
        title.textContent = String(reminder.title || '');
        main.appendChild(title);

        if (reminder.description) {
            var description = document.createElement('p');
            description.textContent = String(reminder.description);
            main.appendChild(description);
        }

        meta.className = 'task-meta';
        appendMeta(meta, metaText(date));

        if (url) {
            var link = document.createElement('a');
            link.href = url;
            link.textContent = targetLabel(reminder);
            appendMeta(meta, link);
        } else {
            appendMeta(meta, metaText(targetLabel(reminder)));
        }

        appendMeta(meta, metaText(statusLabel(status)));

        if (recurrenceType !== 'none') {
            appendMeta(meta, metaText(recurrenceLabel(recurrenceType, recurrenceInterval)));

            if (reminder.recurrence_until_local) {
                appendMeta(meta, metaText('Hasta ' + String(reminder.recurrence_until_local)));
            }
        }

        if (reminder.is_overdue) {
            appendMeta(meta, metaText('Atrasado', 'reminder-overdue'));
        }

        main.appendChild(meta);

        actions.className = 'task-actions';
        if (status === 'pending') {
            actions.appendChild(actionButton('complete', 'Completar', false));
            actions.appendChild(actionButton('dismiss', 'Descartar', false));
        }
        if (recurrenceType !== 'none') {
            actions.appendChild(actionButton('stop', 'Detener recurrencia', false));
        }
        actions.appendChild(actionButton('edit', 'Editar', false));
        actions.appendChild(actionButton('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay recordatorios para mostrar.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function renderReminders(reminders) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(reminders.length);
        }

        if (reminders.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        reminders.forEach(function (reminder) {
            list.appendChild(reminderArticle(reminder));
        });
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var status = remindersPanel ? remindersPanel.getAttribute('data-reminder-status-filter') || 'pending' : 'pending';

        if (status) {
            url.searchParams.set('status', status);
        }

        return url.toString();
    }

    function refreshReminders() {
        if (!list) {
            return Promise.resolve([]);
        }

        return jsonApi(filteredListUrl(), 'GET').then(function (reminders) {
            renderReminders(Array.isArray(reminders) ? reminders : []);
        });
    }

    function setPending(value) {
        pending = value;
        form.setAttribute('aria-busy', value ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = value;
        });
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;

        if (targetNote) {
            targetNote.hidden = true;
            targetNote.textContent = '';
        }
    }

    function openForm(reminder) {
        clearMessage();
        form.reset();
        form.elements.reminder_id.value = reminder ? reminder.dataset.reminderId || '' : '';
        form.elements.task_id.value = reminder ? reminder.dataset.taskId || '' : '';
        form.elements.project_id.value = reminder ? reminder.dataset.projectId || '' : '';
        form.elements.title.value = reminder ? reminder.dataset.title || '' : '';
        form.elements.description.value = reminder ? reminder.dataset.description || '' : '';
        form.elements.remind_date.value = reminder ? reminder.dataset.remindDate || '' : '';
        form.elements.remind_time.value = reminder ? reminder.dataset.remindTime || '' : '';
        form.elements.status.value = reminder ? reminder.dataset.status || 'pending' : 'pending';
        form.elements.recurrence_type.value = reminder ? reminder.dataset.recurrenceType || 'none' : 'none';
        form.elements.recurrence_interval.value = reminder ? reminder.dataset.recurrenceInterval || '1' : '1';
        form.elements.recurrence_until.value = reminder ? reminder.dataset.recurrenceUntil || '' : '';

        if (formTitle) {
            formTitle.textContent = reminder ? 'Editar recordatorio' : 'Nuevo recordatorio';
        }

        if (targetNote) {
            var target = '';

            if (form.elements.task_id.value) {
                target = 'Recordatorio asociado a una tarea.';
            } else if (form.elements.project_id.value) {
                target = 'Recordatorio asociado a un proyecto.';
            }

            targetNote.hidden = target === '';
            targetNote.textContent = target;
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    }

    window.MiCentralOpenReminder = function (context) {
        var title = context && context.title ? String(context.title) : '';
        clearMessage();
        form.reset();
        form.elements.reminder_id.value = '';
        form.elements.task_id.value = context && context.taskId ? String(context.taskId) : '';
        form.elements.project_id.value = context && context.projectId ? String(context.projectId) : '';
        form.elements.title.value = title ? 'Recordatorio: ' + title : '';
        form.elements.status.value = 'pending';
        form.elements.recurrence_type.value = 'none';
        form.elements.recurrence_interval.value = '1';
        form.elements.recurrence_until.value = '';

        if (formTitle) {
            formTitle.textContent = 'Nuevo recordatorio';
        }

        if (targetNote) {
            targetNote.textContent = context && context.targetLabel ? 'Asociado a ' + String(context.targetLabel).toLowerCase() : '';
            targetNote.hidden = targetNote.textContent === '';
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    };

    function payloadFromForm() {
        return {
            title: form.elements.title.value.trim(),
            description: form.elements.description.value.trim(),
            remind_date: form.elements.remind_date.value,
            remind_time: form.elements.remind_time.value,
            task_id: form.elements.task_id.value,
            project_id: form.elements.project_id.value,
            status: form.elements.status.value,
            recurrence_type: form.elements.recurrence_type.value,
            recurrence_interval: form.elements.recurrence_interval.value,
            recurrence_until: form.elements.recurrence_until.value
        };
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var reminderId = form.elements.reminder_id.value;
        var payload = payloadFromForm();

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        if (!payload.remind_date || !payload.remind_time) {
            showMessage('La fecha y hora son obligatorias.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = reminderId ? 'PATCH' : 'POST';

        if (reminderId) {
            url.searchParams.set('id', reminderId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(reminderId ? 'Recordatorio actualizado.' : 'Recordatorio creado.', false);
                return refreshReminders();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    if (list) {
        list.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-reminder-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-reminder-id]');
            var action = button.dataset.reminderAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit') {
                openForm(item);
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar este recordatorio?')) {
                return;
            }

            if (actionPending) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = new URL(apiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.reminderId || '');

            if (action === 'complete' || action === 'dismiss' || action === 'stop') {
                url.searchParams.set('action', action);
            }

            jsonApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Recordatorio eliminado.' : (action === 'stop' ? 'Recurrencia detenida.' : 'Recordatorio actualizado.'), false);
                    return refreshReminders();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
                });
        });
    }
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');

    if (!page) {
        return;
    }

    var apiUrl = page.getAttribute('data-api-url') || '/api/organization/tasks.php';
    var projectApiUrl = '/api/organization/projects.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var list = page.querySelector('[data-task-list]');
    var count = page.querySelector('[data-task-count]');
    var message = page.querySelector('[data-task-message]');
    var filterForm = page.querySelector('[data-task-filters]');
    var formPanel = page.querySelector('[data-task-form-panel]');
    var form = page.querySelector('[data-task-form]');
    var formTitle = page.querySelector('[data-task-form-title]');
    var newButton = page.querySelector('[data-task-new]');
    var cancelButton = page.querySelector('[data-task-cancel]');
    var pageMode = page.getAttribute('data-task-page-mode') || 'tasks';
    var projectFormPanel = page.querySelector('[data-project-form-panel]');
    var projectForm = page.querySelector('[data-project-form]');
    var projectFormTitle = page.querySelector('[data-project-form-title]');
    var projectNewButton = page.querySelector('[data-project-new]');
    var projectCancelButton = page.querySelector('[data-project-cancel]');
    var projectList = page.querySelector('[data-project-list]');
    var projectDetail = page.querySelector('[data-project-detail]');
    var projectTaskNewButton = page.querySelector('[data-project-task-new]');
    var taskSpaceField = page.querySelector('[data-task-space-field]');
    var projectSpaceNote = page.querySelector('[data-project-space-note]');

    if (!formPanel || !form) {
        return;
    }

    if (page.dataset.tasksInitialized === 'true') {
        return;
    }

    page.dataset.tasksInitialized = 'true';
    var formPending = false;
    var actionPending = false;
    var projectPending = false;
    var activeTaskPrefill = false;
    var prefillReturnUrl = '';

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function showCoincidenceCreatedMessage(task, returnUrl) {
        if (!message) {
            return;
        }

        var taskId = task && task.id ? String(task.id) : '';
        var viewTask = document.createElement('a');
        var backLink = document.createElement('a');

        viewTask.href = '/index.php?section=organization&tab=tasks&status=all' + (taskId ? '&edit_task=' + encodeURIComponent(taskId) : '');
        viewTask.textContent = 'Ver tarea';
        backLink.href = returnUrl || '/index.php?section=friends&tab=coincidences';
        backLink.textContent = 'Volver a Coincidencias';

        message.replaceChildren();
        message.appendChild(document.createTextNode('Tarea creada en Organizacion. '));
        message.appendChild(viewTask);
        message.appendChild(document.createTextNode(' · '));
        message.appendChild(backLink);
        message.hidden = false;
        message.classList.remove('task-message--error');
        message.classList.add('task-message--success');
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function selectedFilter(name) {
        if (!filterForm) {
            if (name === 'status') {
                return page.getAttribute('data-default-status-filter') || 'all';
            }

            if (name === 'space') {
                return page.getAttribute('data-default-space-filter') || 'all';
            }

            if (name === 'priority') {
                return page.getAttribute('data-default-priority-filter') || 'all';
            }
        }

        var control = filterForm.elements[name];
        return control instanceof HTMLSelectElement ? control.value : '';
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var currentProjectId = projectDetail instanceof HTMLElement ? projectDetail.dataset.projectId || '' : '';
        var status = selectedFilter('status');
        var space = selectedFilter('space');
        var priority = selectedFilter('priority');
        var dueFrom = page.getAttribute('data-default-due-from') || '';
        var dueTo = page.getAttribute('data-default-due-to') || '';
        var dueBefore = page.getAttribute('data-default-due-before') || '';

        if (currentProjectId) {
            url.searchParams.set('project_id', currentProjectId);
            return url.toString();
        }

        if (page.getAttribute('data-independent-tasks') === 'true') {
            url.searchParams.set('project_id', 'none');
            url.searchParams.set('parent_task_id', 'none');
        }

        if (status && status !== 'all') {
            url.searchParams.set('status', status);
        }

        if (space && space !== 'all') {
            url.searchParams.set('space_id', spaceValueForApi(space));
        }

        if (priority && priority !== 'all') {
            url.searchParams.set('priority', priority);
        }

        if (dueFrom) {
            url.searchParams.set('due_from', dueFrom);
        }

        if (dueTo) {
            url.searchParams.set('due_to', dueTo);
        }

        if (dueBefore) {
            url.searchParams.set('due_before', dueBefore);
        }

        return url.toString();
    }

    function spaceValueForApi(space) {
        if (space === 'inbox') {
            return 'none';
        }

        if (!filterForm) {
            return space;
        }

        var control = filterForm.elements.space;

        if (!(control instanceof HTMLSelectElement)) {
            return space;
        }

        var selectedOption = control.options[control.selectedIndex];

        return selectedOption && selectedOption.dataset.spaceId ? selectedOption.dataset.spaceId : space;
    }

    function hasActiveFilters() {
        if (!filterForm) {
            return false;
        }

        return selectedFilter('status') !== 'pending'
            || selectedFilter('space') !== 'all'
            || selectedFilter('priority') !== 'all'
            || (filterForm.elements.time && filterForm.elements.time.value !== 'all');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function taskApi(url, method, payload) {
        return jsonApi(url, method, payload);
    }

    function projectApi(url, method, payload) {
        return jsonApi(url, method, payload);
    }

    function spaceLabel(spaceId) {
        if (!spaceId) {
            return 'Sin espacio';
        }

        var options = Array.prototype.slice.call(form.elements.space_id.options);
        var found = options.find(function (option) {
            return option.value === String(spaceId);
        });

        return found ? found.textContent : 'Espacio no disponible';
    }

    function priorityLabel(priority) {
        if (priority === 'low') {
            return 'Baja';
        }

        if (priority === 'high') {
            return 'Alta';
        }

        return 'Normal';
    }

    function statusLabel(status) {
        return status === 'completed' ? 'Completada' : 'Pendiente';
    }

    function dateTimeInputValue(value) {
        if (!value) {
            return '';
        }

        return String(value).slice(0, 16).replace(' ', 'T');
    }

    function dateTimeLabel(value) {
        return value ? dateTimeInputValue(value).replace('T', ' ') : 'Sin fecha';
    }

    function appendMeta(parent, text) {
        var span = document.createElement('span');
        span.textContent = text;
        parent.appendChild(span);
    }

    function createAction(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.taskAction = action;
        button.textContent = label;
        return button;
    }

    function taskArticle(task, forceSubtask) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var status = String(task.status || 'pending');
        var spaceId = task.space_id === null || task.space_id === undefined ? '' : String(task.space_id);
        var projectId = task.project_id === null || task.project_id === undefined ? '' : String(task.project_id);
        var parentTaskId = task.parent_task_id === null || task.parent_task_id === undefined ? '' : String(task.parent_task_id);
        var startsAtLocal = task.starts_at_local || task.starts_at || '';
        var endsAtLocal = task.ends_at_local || task.ends_at || '';
        var dueAtLocal = task.due_at_local || task.due_at || '';
        var startsAt = task.starts_at_input || dateTimeInputValue(startsAtLocal);
        var endsAt = task.ends_at_input || dateTimeInputValue(endsAtLocal);
        var dueAt = task.due_at_input || dateTimeInputValue(dueAtLocal);
        var isSubtask = Boolean(forceSubtask) || parentTaskId !== '';

        article.className = 'task-item';
        article.classList.toggle('is-completed', status === 'completed');
        article.classList.toggle('task-item--subtask', isSubtask);
        article.dataset.taskId = String(task.id);
        article.dataset.title = String(task.title || '');
        article.dataset.description = String(task.description || '');
        article.dataset.spaceId = spaceId;
        article.dataset.projectId = projectId;
        article.dataset.parentTaskId = parentTaskId;
        article.dataset.priority = String(task.priority || 'normal');
        article.dataset.startsAt = startsAt;
        article.dataset.endsAt = endsAt;
        article.dataset.dueAt = dueAt;
        article.dataset.status = status;

        main.className = 'task-item__main';
        title.textContent = (isSubtask ? 'Subtarea: ' : '') + String(task.title || '');
        main.appendChild(title);

        if (task.description) {
            var description = document.createElement('p');
            description.textContent = String(task.description);
            main.appendChild(description);
        }

        meta.className = 'task-meta';
        appendMeta(meta, spaceLabel(spaceId));
        appendMeta(meta, 'Prioridad ' + priorityLabel(String(task.priority || 'normal')));
        appendMeta(meta, 'Inicio ' + dateTimeLabel(startsAtLocal));
        appendMeta(meta, 'Fin ' + dateTimeLabel(endsAtLocal));
        appendMeta(meta, 'Limite ' + dateTimeLabel(dueAtLocal));
        appendMeta(meta, statusLabel(status));
        main.appendChild(meta);

        actions.className = 'task-actions';
        actions.appendChild(createAction(status === 'completed' ? 'reopen' : 'complete', status === 'completed' ? 'Reabrir' : 'Completar', false));
        if (!isSubtask && projectId) {
            actions.appendChild(createAction('subtask', 'Agregar subtarea', false));
        }
        actions.appendChild(createAction('reminder', 'Agregar recordatorio', false));
        actions.appendChild(createAction('edit', 'Editar', false));
        if (pageMode === 'inbox') {
            actions.appendChild(createAction('organize', 'Organizar', false));
        }
        actions.appendChild(createAction('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = hasActiveFilters()
            ? page.getAttribute('data-empty-filtered') || 'No hay tareas que coincidan con estos filtros.'
            : page.getAttribute('data-empty-default') || 'No tienes tareas todavia.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function renderTasks(tasks) {
        if (!list) {
            return;
        }

        list.replaceChildren();

        if (count) {
            count.textContent = String(tasks.length);
        }

        if (tasks.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        var childrenByParent = {};
        var parentTasks = [];

        tasks.forEach(function (task) {
            var parentTaskId = task.parent_task_id === null || task.parent_task_id === undefined ? '' : String(task.parent_task_id);

            if (parentTaskId) {
                if (!childrenByParent[parentTaskId]) {
                    childrenByParent[parentTaskId] = [];
                }
                childrenByParent[parentTaskId].push(task);
                return;
            }

            parentTasks.push(task);
        });

        parentTasks.forEach(function (task) {
            list.appendChild(taskArticle(task, false));
            (childrenByParent[String(task.id)] || []).forEach(function (childTask) {
                list.appendChild(taskArticle(childTask, true));
            });
        });
    }

    function refreshTasks() {
        if (!list) {
            return Promise.resolve([]);
        }

        return taskApi(filteredListUrl(), 'GET').then(function (tasks) {
            renderTasks(Array.isArray(tasks) ? tasks : []);
        });
    }

    function openForm(task, purpose) {
        clearMessage();
        activeTaskPrefill = false;
        prefillReturnUrl = '';
        form.reset();
        form.elements.task_id.value = task ? task.dataset.taskId : '';
        form.elements.project_id.value = task ? task.dataset.projectId : '';
        form.elements.parent_task_id.value = task ? task.dataset.parentTaskId : '';
        form.elements.title.value = task ? task.dataset.title : '';
        form.elements.description.value = task ? task.dataset.description : '';
        form.elements.space_id.value = task ? task.dataset.spaceId : '';
        form.elements.priority.value = task ? task.dataset.priority : 'normal';
        form.elements.starts_at.value = task ? task.dataset.startsAt : '';
        form.elements.ends_at.value = task ? task.dataset.endsAt : '';
        form.elements.due_at.value = task ? task.dataset.dueAt : '';
        updateProjectSpaceState();

        if (formTitle) {
            formTitle.textContent = purpose === 'organize'
                ? 'Organizar tarea'
                : (purpose === 'coincidence' ? 'Nueva tarea desde coincidencia' : (task ? 'Editar tarea' : 'Nueva tarea'));
        }

        formPanel.hidden = false;
        if (purpose === 'organize') {
            form.elements.space_id.focus();
        } else {
            form.elements.title.focus();
        }
    }

    function optionExists(select, value) {
        return Array.prototype.slice.call(select.options).some(function (option) {
            return option.value === String(value);
        });
    }

    function parseInitialTaskPrefill() {
        var raw = page.getAttribute('data-task-prefill') || '';

        if (!raw) {
            return null;
        }

        try {
            var parsed = JSON.parse(raw);

            return parsed && parsed.source === 'coincidence' ? parsed : null;
        } catch (error) {
            return null;
        }
    }

    function openTaskPrefill(prefill) {
        openForm(null, 'coincidence');
        form.elements.title.value = String(prefill.title || '');
        form.elements.description.value = String(prefill.description || '');
        form.elements.priority.value = String(prefill.priority || 'normal');
        form.elements.starts_at.value = String(prefill.starts_at || '');
        form.elements.ends_at.value = String(prefill.ends_at || '');
        form.elements.due_at.value = String(prefill.due_at || '');

        if (prefill.space_id && optionExists(form.elements.space_id, prefill.space_id)) {
            form.elements.space_id.value = String(prefill.space_id);
        }

        activeTaskPrefill = true;
        prefillReturnUrl = String(prefill.return_url || '');
        showMessage('Revisa y ajusta la tarea antes de guardarla.', false);
        form.elements.title.focus();
    }

    function openProjectTaskForm() {
        if (!(projectDetail instanceof HTMLElement)) {
            return;
        }

        openForm(null);
        form.elements.project_id.value = projectDetail.dataset.projectId || '';
        form.elements.space_id.value = projectDetail.dataset.projectSpaceId || '';
        updateProjectSpaceState();
    }

    function openSubtaskForm(task) {
        openForm(null, 'subtask');
        form.elements.parent_task_id.value = task.dataset.taskId || '';
        form.elements.project_id.value = task.dataset.projectId || '';
        form.elements.space_id.value = task.dataset.spaceId || '';
        updateProjectSpaceState();

        if (formTitle) {
            formTitle.textContent = 'Nueva subtarea';
        }
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
        activeTaskPrefill = false;
        prefillReturnUrl = '';
        updateProjectSpaceState();
    }

    function payloadFromForm() {
        var projectId = form.elements.project_id.value;

        return {
            title: form.elements.title.value.trim(),
            description: form.elements.description.value.trim(),
            space_id: projectId ? '' : form.elements.space_id.value,
            project_id: projectId,
            parent_task_id: form.elements.parent_task_id.value,
            priority: form.elements.priority.value,
            starts_at: form.elements.starts_at.value,
            ends_at: form.elements.ends_at.value,
            due_at: form.elements.due_at.value
        };
    }

    function updateProjectSpaceState() {
        if (!form || !taskSpaceField || !projectSpaceNote) {
            return;
        }

        var projectId = form.elements.project_id.value;
        var inheritedLabel = projectDetail instanceof HTMLElement ? projectDetail.dataset.projectSpaceLabel || '' : '';
        var isProjectTask = projectId !== '';

        taskSpaceField.hidden = isProjectTask;
        projectSpaceNote.hidden = !isProjectTask;
        projectSpaceNote.textContent = isProjectTask
            ? 'Espacio heredado del proyecto: ' + (inheritedLabel || spaceLabel(form.elements.space_id.value))
            : '';
    }

    function setFormPending(pending) {
        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea'));

        formPending = pending;
        form.setAttribute('aria-busy', pending ? 'true' : 'false');

        controls.forEach(function (control) {
            control.disabled = pending;
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (projectTaskNewButton) {
        projectTaskNewButton.addEventListener('click', openProjectTaskForm);
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (formPending) {
            return;
        }

        clearMessage();

        var taskId = form.elements.task_id.value;
        var payload = payloadFromForm();
        var createdFromCoincidence = activeTaskPrefill && !taskId;
        var returnUrl = prefillReturnUrl;

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = taskId ? 'PATCH' : 'POST';

        if (taskId) {
            url.searchParams.set('id', taskId);
        }

        setFormPending(true);
        taskApi(url.toString(), method, payload)
            .then(function (task) {
                closeForm();
                if (createdFromCoincidence) {
                    showCoincidenceCreatedMessage(task, returnUrl);
                } else {
                    showMessage(taskId ? 'Tarea actualizada.' : 'Tarea creada.', false);
                }
                if (page.getAttribute('data-active-tab') === 'calendar') {
                    window.location.reload();
                    return null;
                }
                return refreshTasks();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setFormPending(false);
            });
    });

    var initialTaskPrefill = parseInitialTaskPrefill();

    if (initialTaskPrefill) {
        window.requestAnimationFrame(function () {
            openTaskPrefill(initialTaskPrefill);
        });
    }

    if (list) {
        list.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-task-action]') : null;

            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var item = button.closest('[data-task-id]');
            var action = button.dataset.taskAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit' || action === 'organize') {
                openForm(item, action);
                return;
            }

            if (action === 'subtask') {
                openSubtaskForm(item);
                return;
            }

            if (action === 'reminder') {
                if (typeof window.MiCentralOpenReminder === 'function') {
                    window.MiCentralOpenReminder({
                        taskId: item.dataset.taskId || '',
                        projectId: '',
                        title: item.dataset.title || '',
                        targetLabel: 'Tarea: ' + (item.dataset.title || '')
                    });
                }
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar esta tarea?')) {
                return;
            }

            if (actionPending) {
                return;
            }

            actionPending = true;
            button.disabled = true;
            clearMessage();

            var url = new URL(apiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.taskId || '');

            if (action === 'complete' || action === 'reopen') {
                url.searchParams.set('action', action);
            }

            taskApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Tarea eliminada.' : 'Tarea actualizada.', false);
                    return refreshTasks();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
                });
        });
    }

    function openProjectForm(project) {
        if (!projectFormPanel || !projectForm) {
            return;
        }

        projectForm.reset();
        projectForm.elements.project_id.value = project ? project.dataset.projectId : '';
        projectForm.elements.title.value = project ? project.dataset.title : '';
        projectForm.elements.description.value = project ? project.dataset.description : '';
        projectForm.elements.space_id.value = project ? project.dataset.spaceId : '';
        projectForm.elements.status.value = project ? project.dataset.status : 'active';
        projectForm.elements.starts_on.value = project ? project.dataset.startsOn : '';
        projectForm.elements.due_on.value = project ? project.dataset.dueOn : '';

        if (projectFormTitle) {
            projectFormTitle.textContent = project ? 'Editar proyecto' : 'Nuevo proyecto';
        }

        projectFormPanel.hidden = false;
        projectForm.elements.title.focus();
    }

    function closeProjectForm() {
        if (!projectFormPanel || !projectForm) {
            return;
        }

        projectForm.reset();
        projectFormPanel.hidden = true;
    }

    function setProjectPending(pending) {
        if (!projectForm) {
            return;
        }

        projectPending = pending;
        projectForm.setAttribute('aria-busy', pending ? 'true' : 'false');
        Array.prototype.slice.call(projectForm.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = pending;
        });
    }

    if (projectNewButton) {
        projectNewButton.addEventListener('click', function () {
            openProjectForm(null);
        });
    }

    if (projectCancelButton) {
        projectCancelButton.addEventListener('click', closeProjectForm);
    }

    if (projectForm) {
        projectForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (projectPending) {
                return;
            }

            var projectId = projectForm.elements.project_id.value;
            var payload = {
                title: projectForm.elements.title.value.trim(),
                description: projectForm.elements.description.value.trim(),
                space_id: projectForm.elements.space_id.value,
                status: projectForm.elements.status.value,
                starts_on: projectForm.elements.starts_on.value,
                due_on: projectForm.elements.due_on.value
            };

            if (!payload.title) {
                showMessage('El titulo es obligatorio.', true);
                return;
            }

            var url = new URL(projectApiUrl, window.location.origin);
            var method = projectId ? 'PATCH' : 'POST';

            if (projectId) {
                url.searchParams.set('id', projectId);
            }

            setProjectPending(true);
            projectApi(url.toString(), method, payload)
                .then(function () {
                    closeProjectForm();
                    showMessage(projectId ? 'Proyecto actualizado.' : 'Proyecto creado.', false);
                    window.location.reload();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    setProjectPending(false);
                });
        });
    }

    if (projectList) {
        projectList.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('[data-project-action]') : null;

            if (!(button instanceof HTMLButtonElement) || actionPending) {
                return;
            }

            var item = button.closest('[data-project-id]');
            var action = button.dataset.projectAction || '';

            if (!(item instanceof HTMLElement)) {
                return;
            }

            if (action === 'edit') {
                openProjectForm(item);
                return;
            }

            if (action === 'reminder') {
                if (typeof window.MiCentralOpenReminder === 'function') {
                    window.MiCentralOpenReminder({
                        taskId: '',
                        projectId: item.dataset.projectId || '',
                        title: item.dataset.title || '',
                        targetLabel: 'Proyecto: ' + (item.dataset.title || '')
                    });
                }
                return;
            }

            if (action === 'delete' && !window.confirm('Eliminar este proyecto? Sus tareas conservaran el registro sin proyecto.')) {
                return;
            }

            actionPending = true;
            button.disabled = true;

            var url = new URL(projectApiUrl, window.location.origin);
            url.searchParams.set('id', item.dataset.projectId || '');

            if (action === 'complete' || action === 'archive') {
                url.searchParams.set('action', action);
            }

            projectApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
                .then(function () {
                    showMessage(action === 'delete' ? 'Proyecto eliminado.' : 'Proyecto actualizado.', false);
                    window.location.reload();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    button.disabled = false;
            });
        });
    }

    var editTaskId = new URLSearchParams(window.location.search).get('edit_task');

    if (editTaskId && list) {
        var editableTask = Array.prototype.slice.call(list.querySelectorAll('[data-task-id]')).find(function (item) {
            return item instanceof HTMLElement && item.dataset.taskId === editTaskId;
        });

        if (editableTask instanceof HTMLElement) {
            openForm(editableTask, 'edit');
        }
    }

    var projectReminderNewButton = page.querySelector('[data-project-reminder-new]');

    if (projectReminderNewButton && projectDetail instanceof HTMLElement) {
        projectReminderNewButton.addEventListener('click', function () {
            if (typeof window.MiCentralOpenReminder === 'function') {
                window.MiCentralOpenReminder({
                    taskId: '',
                    projectId: projectDetail.dataset.projectId || '',
                    title: projectDetail.dataset.title || '',
                    targetLabel: 'Proyecto: ' + (projectDetail.dataset.title || '')
                });
            }
        });
    }
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-tasks-page]');
    var notesPanel = page ? page.querySelector('[data-notes-panel]') : null;

    if (!page || !notesPanel || notesPanel.dataset.notesInitialized === 'true') {
        return;
    }

    notesPanel.dataset.notesInitialized = 'true';

    var apiUrl = notesPanel.getAttribute('data-api-url') || '/api/organization/notes.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-note-form-panel]');
    var form = page.querySelector('[data-note-form]');
    var formTitle = page.querySelector('[data-note-form-title]');
    var newButton = page.querySelector('[data-note-new]');
    var cancelButton = page.querySelector('[data-note-cancel]');
    var list = page.querySelector('[data-note-list]');
    var count = page.querySelector('[data-note-count]');
    var message = page.querySelector('[data-task-message]');
    var filterForm = page.querySelector('[data-note-filters]');
    var pending = false;
    var actionPending = false;

    if (!formPanel || !(form instanceof HTMLFormElement) || !list) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function noteSpaceLabel(spaceId) {
        if (!spaceId) {
            return 'Sin espacio';
        }

        var options = Array.prototype.slice.call(form.elements.space_id.options);
        var found = options.find(function (option) {
            return option.value === String(spaceId);
        });

        return found ? found.textContent : 'Espacio no disponible';
    }

    function appendMeta(parent, text) {
        var span = document.createElement('span');
        span.textContent = text;
        parent.appendChild(span);
    }

    function noteArticle(note) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var edit = document.createElement('button');
        var remove = document.createElement('button');
        var spaceId = note.space_id === null || note.space_id === undefined ? '' : String(note.space_id);

        article.className = 'note-item';
        article.dataset.noteId = String(note.id);
        article.dataset.title = String(note.title || '');
        article.dataset.content = String(note.content || '');
        article.dataset.spaceId = spaceId;

        main.className = 'task-item__main';
        title.textContent = String(note.title || '');
        main.appendChild(title);

        if (note.content) {
            var content = document.createElement('p');
            content.textContent = String(note.content);
            main.appendChild(content);
        }

        meta.className = 'task-meta';
        appendMeta(meta, noteSpaceLabel(spaceId));
        main.appendChild(meta);

        actions.className = 'task-actions';
        edit.type = 'button';
        edit.className = 'button button--secondary';
        edit.dataset.noteAction = 'edit';
        edit.textContent = 'Editar';
        remove.type = 'button';
        remove.className = 'button button--danger';
        remove.dataset.noteAction = 'delete';
        remove.textContent = 'Eliminar';
        actions.appendChild(edit);
        actions.appendChild(remove);

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay notas que coincidan con este filtro.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function selectedSpaceForApi() {
        if (!filterForm || !(filterForm.elements.space instanceof HTMLSelectElement)) {
            return '';
        }

        var control = filterForm.elements.space;
        var selectedOption = control.options[control.selectedIndex];

        if (!selectedOption || control.value === 'all') {
            return '';
        }

        return selectedOption.dataset.spaceId || control.value;
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var space = selectedSpaceForApi();

        if (space) {
            url.searchParams.set('space_id', space);
        }

        return url.toString();
    }

    function renderNotes(notes) {
        list.replaceChildren();

        if (count) {
            count.textContent = String(notes.length);
        }

        if (notes.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        notes.forEach(function (note) {
            list.appendChild(noteArticle(note));
        });
    }

    function refreshNotes() {
        return jsonApi(filteredListUrl(), 'GET').then(function (notes) {
            renderNotes(Array.isArray(notes) ? notes : []);
        });
    }

    function openForm(note) {
        clearMessage();
        form.reset();
        form.elements.note_id.value = note ? note.dataset.noteId : '';
        form.elements.title.value = note ? note.dataset.title : '';
        form.elements.content.value = note ? note.dataset.content : '';
        form.elements.space_id.value = note ? note.dataset.spaceId : '';

        if (formTitle) {
            formTitle.textContent = note ? 'Editar nota' : 'Nueva nota';
        }

        formPanel.hidden = false;
        form.elements.title.focus();
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var noteId = form.elements.note_id.value;
        var payload = {
            title: form.elements.title.value.trim(),
            content: form.elements.content.value.trim(),
            space_id: form.elements.space_id.value
        };

        if (!payload.title) {
            showMessage('El titulo es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = noteId ? 'PATCH' : 'POST';

        if (noteId) {
            url.searchParams.set('id', noteId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(noteId ? 'Nota actualizada.' : 'Nota creada.', false);
                return refreshNotes();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    list.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-note-action]') : null;

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        var item = button.closest('[data-note-id]');
        var action = button.dataset.noteAction || '';

        if (!(item instanceof HTMLElement)) {
            return;
        }

        if (action === 'edit') {
            openForm(item);
            return;
        }

        if (action === 'delete' && !window.confirm('Eliminar esta nota?')) {
            return;
        }

        if (actionPending) {
            return;
        }

        actionPending = true;
        button.disabled = true;

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('id', item.dataset.noteId || '');

        jsonApi(url.toString(), 'DELETE', {})
            .then(function () {
                showMessage('Nota eliminada.', false);
                return refreshNotes();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
                button.disabled = false;
            });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-friends-page]');

    if (!page || page.dataset.friendsInitialized === 'true') {
        return;
    }

    page.dataset.friendsInitialized = 'true';

    var apiUrl = page.getAttribute('data-api-url') || '/api/friends/friends.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-friend-form-panel]');
    var form = page.querySelector('[data-friend-form]');
    var formTitle = page.querySelector('[data-friend-form-title]');
    var newButton = page.querySelector('[data-friend-new]');
    var cancelButton = page.querySelector('[data-friend-cancel]');
    var list = page.querySelector('[data-friend-list]');
    var count = page.querySelector('[data-friend-count]');
    var message = page.querySelector('[data-friend-message]');
    var pending = false;
    var actionPending = false;

    if (!formPanel || !(form instanceof HTMLFormElement) || !list) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function fieldLabel(prefix, value) {
        value = String(value || '').trim();

        return prefix + ': ' + (value || 'sin dato');
    }

    function appendMeta(parent, text) {
        var span = document.createElement('span');
        span.textContent = text;
        parent.appendChild(span);
    }

    function actionButton(action, label, danger) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = danger ? 'button button--danger' : 'button button--secondary';
        button.dataset.friendAction = action;
        button.textContent = label;
        return button;
    }

    function friendArticle(friend) {
        var article = document.createElement('article');
        var main = document.createElement('div');
        var title = document.createElement('h3');
        var meta = document.createElement('div');
        var actions = document.createElement('div');
        var active = Number(friend.is_active || 0) === 1;

        article.className = 'task-item friend-item' + (active ? '' : ' is-inactive');
        article.dataset.friendId = String(friend.id || '');
        article.dataset.name = String(friend.name || '');
        article.dataset.university = String(friend.university || '');
        article.dataset.defaultCampus = String(friend.default_campus || '');
        article.dataset.notes = String(friend.notes || '');
        article.dataset.isActive = active ? '1' : '0';
        article.dataset.scheduleCount = String(friend.schedule_entries_count || 0);
        article.dataset.exceptionCount = String(friend.schedule_exceptions_count || 0);

        main.className = 'task-item__main';
        title.textContent = String(friend.name || '');
        main.appendChild(title);

        if (friend.notes) {
            var notes = document.createElement('p');
            notes.textContent = String(friend.notes);
            main.appendChild(notes);
        }

        meta.className = 'task-meta';
        appendMeta(meta, fieldLabel('Universidad', friend.university));
        appendMeta(meta, fieldLabel('Campus', friend.default_campus));
        appendMeta(meta, active ? 'Activo' : 'Inactivo');
        main.appendChild(meta);

        actions.className = 'task-actions';
        actions.appendChild(actionButton('edit', 'Editar', false));
        actions.appendChild(actionButton(active ? 'deactivate' : 'activate', active ? 'Desactivar' : 'Activar', false));
        actions.appendChild(actionButton('delete', 'Eliminar', true));

        article.appendChild(main);
        article.appendChild(actions);

        return article;
    }

    function emptyState() {
        var wrapper = document.createElement('div');
        var marker = document.createElement('span');
        var text = document.createElement('p');

        wrapper.className = 'empty-state';
        marker.setAttribute('aria-hidden', 'true');
        text.textContent = 'No hay amigos para mostrar.';
        wrapper.appendChild(marker);
        wrapper.appendChild(text);

        return wrapper;
    }

    function filteredListUrl() {
        var url = new URL(apiUrl, window.location.origin);
        var status = page.getAttribute('data-friend-status-filter') || 'active';

        if (status && status !== 'active') {
            url.searchParams.set('status', status);
        }

        return url.toString();
    }

    function renderFriends(friends) {
        list.replaceChildren();

        if (count) {
            count.textContent = String(friends.length);
        }

        if (friends.length === 0) {
            list.appendChild(emptyState());
            return;
        }

        friends.forEach(function (friend) {
            list.appendChild(friendArticle(friend));
        });
    }

    function refreshFriends() {
        return jsonApi(filteredListUrl(), 'GET').then(function (friends) {
            renderFriends(Array.isArray(friends) ? friends : []);
        });
    }

    function openForm(friend) {
        clearMessage();
        form.reset();
        form.elements.friend_id.value = friend ? friend.dataset.friendId : '';
        form.elements.name.value = friend ? friend.dataset.name : '';
        form.elements.university.value = friend ? friend.dataset.university : '';
        form.elements.default_campus.value = friend ? friend.dataset.defaultCampus : '';
        form.elements.notes.value = friend ? friend.dataset.notes : '';
        form.elements.is_active.checked = friend ? friend.dataset.isActive === '1' : true;

        if (formTitle) {
            formTitle.textContent = friend ? 'Editar amigo' : 'Nuevo amigo';
        }

        formPanel.hidden = false;
        form.elements.name.focus();
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function setPending(nextPending) {
        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function payloadFromForm() {
        return {
            name: form.elements.name.value.trim(),
            university: form.elements.university.value.trim(),
            default_campus: form.elements.default_campus.value.trim(),
            notes: form.elements.notes.value.trim(),
            is_active: form.elements.is_active.checked ? '1' : '0'
        };
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var friendId = form.elements.friend_id.value;
        var payload = payloadFromForm();

        if (!payload.name) {
            showMessage('El nombre es obligatorio.', true);
            return;
        }

        var url = new URL(apiUrl, window.location.origin);
        var method = friendId ? 'PATCH' : 'POST';

        if (friendId) {
            url.searchParams.set('id', friendId);
        }

        setPending(true);
        jsonApi(url.toString(), method, payload)
            .then(function () {
                closeForm();
                showMessage(friendId ? 'Amigo actualizado.' : 'Amigo creado.', false);
                return refreshFriends();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    list.addEventListener('click', function (event) {
        var button = event.target instanceof HTMLElement ? event.target.closest('[data-friend-action]') : null;

        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        var item = button.closest('[data-friend-id]');
        var action = button.dataset.friendAction || '';

        if (!(item instanceof HTMLElement)) {
            return;
        }

        if (action === 'edit') {
            openForm(item);
            return;
        }

        if (action === 'delete') {
            var relatedCount = Number(item.dataset.scheduleCount || 0) + Number(item.dataset.exceptionCount || 0);
            var question = relatedCount > 0
                ? 'Eliminar este amigo tambien eliminara sus horarios y excepciones asociados. Desactivar conserva el historial. Continuar?'
                : 'Eliminar este amigo? Desactivar es recomendable si quieres conservar el historial.';

            if (!window.confirm(question)) {
                return;
            }
        }

        if (actionPending) {
            return;
        }

        actionPending = true;
        button.disabled = true;
        clearMessage();

        var url = new URL(apiUrl, window.location.origin);
        url.searchParams.set('id', item.dataset.friendId || '');

        if (action === 'activate' || action === 'deactivate') {
            url.searchParams.set('action', action);
        }

        jsonApi(url.toString(), action === 'delete' ? 'DELETE' : 'POST', {})
            .then(function () {
                var success = action === 'delete'
                    ? 'Amigo eliminado.'
                    : (action === 'activate' ? 'Amigo activado.' : 'Amigo desactivado.');

                showMessage(success, false);
                return refreshFriends();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                actionPending = false;
                button.disabled = false;
            });
    });
}());

(function () {
    'use strict';

    var page = document.querySelector('[data-friends-page]');
    var panel = page ? page.querySelector('[data-schedule-panel]') : null;

    if (!page || !panel || panel.dataset.scheduleInitialized === 'true') {
        return;
    }

    panel.dataset.scheduleInitialized = 'true';

    var apiUrl = page.getAttribute('data-schedule-api-url') || '/api/friends/schedule.php';
    var csrfToken = page.getAttribute('data-csrf-token') || '';
    var formPanel = page.querySelector('[data-schedule-form-panel]');
    var form = page.querySelector('[data-schedule-form]');
    var formTitle = page.querySelector('[data-schedule-form-title]');
    var newButton = page.querySelector('[data-schedule-new]');
    var cancelButton = page.querySelector('[data-schedule-cancel]');
    var week = page.querySelector('[data-schedule-week]');
    var count = page.querySelector('[data-schedule-count]');
    var message = page.querySelector('[data-friend-message]');
    var detail = page.querySelector('[data-schedule-detail]');
    var detailTitle = page.querySelector('[data-schedule-detail-title]');
    var detailMeta = page.querySelector('[data-schedule-detail-meta]');
    var detailWarning = page.querySelector('[data-schedule-detail-warning]');
    var detailEdit = page.querySelector('[data-schedule-detail-edit]');
    var detailDuplicate = page.querySelector('[data-schedule-detail-duplicate]');
    var detailException = page.querySelector('[data-schedule-detail-exception]');
    var detailDelete = page.querySelector('[data-schedule-detail-delete]');
    var detailClose = page.querySelector('[data-schedule-detail-close]');
    var detailExceptions = page.querySelector('[data-schedule-detail-exceptions]');
    var exceptionPanel = page.querySelector('[data-schedule-exception-panel]');
    var exceptionForm = page.querySelector('[data-schedule-exception-form]');
    var exceptionTitle = page.querySelector('[data-schedule-exception-title]');
    var exceptionCancelButtons = page.querySelectorAll('[data-schedule-exception-cancel]');
    var targetSelect = page.querySelector('[data-schedule-target]');
    var showSelect = page.querySelector('[data-schedule-show]');
    var importOpen = page.querySelector('[data-schedule-import-open]');
    var importPanel = page.querySelector('[data-schedule-import-panel]');
    var importForm = page.querySelector('[data-schedule-import-form]');
    var importCancel = page.querySelector('[data-schedule-import-cancel]');
    var importTarget = page.querySelector('[data-schedule-import-target]');
    var importFile = page.querySelector('[data-schedule-import-file]');
    var importJson = page.querySelector('[data-schedule-import-json]');
    var importPreviewButton = page.querySelector('[data-schedule-import-preview]');
    var importConfirm = page.querySelector('[data-schedule-import-confirm]');
    var importPreviewPanel = page.querySelector('[data-schedule-import-preview-panel]');
    var currentWeekday = page.getAttribute('data-schedule-current-weekday') || '1';
    var selectedEntry = null;
    var scheduleExceptions = [];
    var pending = false;
    var actionPending = false;
    var scheduleLayoutFrame = null;

    if (!formPanel || !(form instanceof HTMLFormElement) || !week) {
        return;
    }

    function showMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function clearMessage() {
        if (!message) {
            return;
        }

        message.textContent = '';
        message.hidden = true;
        message.classList.remove('task-message--error', 'task-message--success');
    }

    function targetParts() {
        var target = targetSelect instanceof HTMLSelectElement ? targetSelect.value : 'user';

        return targetPartsFromValue(target);
    }

    function targetPartsFromValue(target) {
        target = String(target || 'user');

        if (target.indexOf('friend:') === 0) {
            return {
                target_type: 'friend',
                friend_id: target.slice(7)
            };
        }

        return {
            target_type: 'user',
            friend_id: ''
        };
    }

    function targetLabel(select) {
        if (!(select instanceof HTMLSelectElement)) {
            return 'Mi horario';
        }

        var option = select.options[select.selectedIndex];

        return option ? option.textContent : 'Mi horario';
    }

    function apiWithTarget(id) {
        var url = new URL(apiUrl, window.location.origin);
        var parts = targetParts();
        var show = showSelect instanceof HTMLSelectElement ? showSelect.value : (page.getAttribute('data-schedule-show') || 'active');

        url.searchParams.set('target_type', parts.target_type);

        if (parts.friend_id) {
            url.searchParams.set('friend_id', parts.friend_id);
        }

        if (show) {
            url.searchParams.set('show', show);
        }

        if (id) {
            url.searchParams.set('id', id);
        }

        return url.toString();
    }

    function apiWithAction(action, id) {
        var url = new URL(apiWithTarget(id || ''), window.location.origin);

        url.searchParams.set('action', action);

        return url.toString();
    }

    function apiWithImportTarget(action) {
        var url = new URL(apiUrl, window.location.origin);
        var parts = targetPartsFromValue(importTarget instanceof HTMLSelectElement ? importTarget.value : 'user');

        url.searchParams.set('action', action);
        url.searchParams.set('target_type', parts.target_type);

        if (parts.friend_id) {
            url.searchParams.set('friend_id', parts.friend_id);
        }

        return url.toString();
    }

    function jsonApi(url, method, payload) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json'
            }
        };

        if (method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrfToken;
            options.body = JSON.stringify(payload || {});
        }

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo procesar la solicitud.');
                }

                return body.data;
            });
        });
    }

    function importApi(action, jsonText) {
        return fetch(apiWithImportTarget(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                json: jsonText
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!body || typeof body !== 'object') {
                    throw new Error('No se pudo procesar la solicitud.');
                }

                return {
                    ok: response.ok && body.ok,
                    status: response.status,
                    data: body.data || null,
                    error: body.error || 'No se pudo procesar la solicitud.'
                };
            });
        });
    }

    function timeInput(value) {
        value = String(value || '');

        return value ? value.slice(0, 5) : '';
    }

    function timeMinutes(value) {
        value = timeInput(value) || '08:00';
        var parts = value.split(':');

        return (Number(parts[0] || 0) * 60) + Number(parts[1] || 0);
    }

    function scheduleGridStartMinutes() {
        return 8 * 60;
    }

    function scheduleGridEndMinutes() {
        return 21 * 60;
    }

    function scheduleOffsetMinutes(value) {
        return Math.max(0, timeMinutes(value) - scheduleGridStartMinutes());
    }

    function scheduleDurationMinutes(startsAt, endsAt) {
        var start = Math.max(scheduleGridStartMinutes(), timeMinutes(startsAt));
        var end = Math.min(scheduleGridEndMinutes(), timeMinutes(endsAt));
        var duration = Math.max(30, end - start);

        return duration;
    }

    function layoutScheduleBlock(block) {
        var offset = scheduleOffsetMinutes(block.dataset.startsAt);
        var duration = scheduleDurationMinutes(block.dataset.startsAt, block.dataset.endsAt);

        block.style.setProperty('--schedule-offset', String(offset));
        block.style.setProperty('--schedule-duration', String(duration));
    }

    function layoutScheduleBlocks() {
        var blocks = Array.prototype.slice.call(week.querySelectorAll('.schedule-block'));

        blocks.forEach(function (block) {
            layoutScheduleBlock(block);
        });

        panel.classList.add('is-laid-out');

        if (selectedEntry && detail && !detail.hidden) {
            positionDetail();
        }
    }

    function requestScheduleLayout() {
        var raf = window.requestAnimationFrame || function (callback) {
            return window.setTimeout(callback, 0);
        };

        if (scheduleLayoutFrame !== null) {
            return;
        }

        scheduleLayoutFrame = raf(function () {
            raf(function () {
                scheduleLayoutFrame = null;
                layoutScheduleBlocks();
            });
        });
    }

    function entryDataset(block, entry) {
        block.setAttribute('aria-expanded', 'false');
        block.setAttribute('aria-haspopup', 'dialog');
        block.dataset.scheduleEntryId = String(entry.id || '');
        block.dataset.weekday = String(entry.weekday || '');
        block.dataset.startsAt = timeInput(entry.starts_at);
        block.dataset.endsAt = timeInput(entry.ends_at);
        block.dataset.courseName = String(entry.course_name || '');
        block.dataset.courseCode = String(entry.course_code || '');
        block.dataset.room = String(entry.room || '');
        block.dataset.campus = String(entry.campus || '');
        block.dataset.effectiveCampus = String(entry.effective_campus || '');
        block.dataset.validFrom = String(entry.valid_from || '');
        block.dataset.validUntil = String(entry.valid_until || '');
        block.dataset.warning = Array.isArray(entry.warnings) && entry.warnings.length > 0 ? String(entry.warnings[0]) : '';
        block.dataset.ownerLabel = targetLabel(targetSelect);
    }

    function scheduleBlock(entry) {
        var block = document.createElement('button');
        var title = document.createElement('strong');
        var time = document.createElement('span');

        block.type = 'button';
        block.className = 'schedule-block' + (entry.has_overlap ? ' has-warning' : '');
        entryDataset(block, entry);
        layoutScheduleBlock(block);

        title.textContent = String(entry.course_name || '');
        time.textContent = timeInput(entry.starts_at) + '-' + timeInput(entry.ends_at);
        block.appendChild(title);
        block.appendChild(time);

        if (entry.room) {
            var room = document.createElement('span');
            room.textContent = String(entry.room);
            block.appendChild(room);
        }

        if (entry.effective_campus) {
            var campus = document.createElement('span');
            campus.textContent = String(entry.effective_campus);
            block.appendChild(campus);
        }

        if (Array.isArray(entry.warnings) && entry.warnings.length > 0) {
            var warning = document.createElement('em');
            warning.textContent = String(entry.warnings[0]);
            block.appendChild(warning);
        }

        return block;
    }

    function renderSchedule(entries) {
        var columns = Array.prototype.slice.call(week.querySelectorAll('[data-schedule-day]'));

        columns.forEach(function (column) {
            column.querySelectorAll('.schedule-block').forEach(function (block) {
                block.remove();
            });
        });

        entries.slice().sort(function (left, right) {
            var weekdayDiff = Number(left.weekday || 0) - Number(right.weekday || 0);

            if (weekdayDiff !== 0) {
                return weekdayDiff;
            }

            return timeMinutes(left.starts_at) - timeMinutes(right.starts_at);
        }).forEach(function (entry) {
            var column = week.querySelector('[data-schedule-day="' + String(entry.weekday || '') + '"]');

            if (column) {
                column.appendChild(scheduleBlock(entry));
            }
        });

        if (count) {
            count.textContent = String(entries.length);
        }

        closeDetail();
        requestScheduleLayout();
    }

    function refreshSchedule() {
        return jsonApi(apiWithTarget(''), 'GET').then(function (entries) {
            renderSchedule(Array.isArray(entries) ? entries : []);
            return refreshExceptions();
        });
    }

    function refreshExceptions() {
        return jsonApi(apiWithAction('exceptions', ''), 'GET').then(function (items) {
            scheduleExceptions = Array.isArray(items) ? items : [];

            if (selectedEntry) {
                renderDetailExceptions(selectedEntry);
            }

            return scheduleExceptions;
        });
    }

    function openForm(entry) {
        clearMessage();
        form.reset();
        form.elements.entry_id.value = entry ? entry.dataset.scheduleEntryId : '';
        form.elements.weekday.value = entry ? entry.dataset.weekday : '1';
        form.elements.starts_at.value = entry ? entry.dataset.startsAt : '';
        form.elements.ends_at.value = entry ? entry.dataset.endsAt : '';
        form.elements.course_name.value = entry ? entry.dataset.courseName : '';
        form.elements.course_code.value = entry ? entry.dataset.courseCode : '';
        form.elements.room.value = entry ? entry.dataset.room : '';
        form.elements.campus.value = entry ? entry.dataset.campus : '';
        form.elements.valid_from.value = entry ? entry.dataset.validFrom : '';
        form.elements.valid_until.value = entry ? entry.datasetValidUntil || entry.dataset.validUntil : '';

        if (formTitle) {
            formTitle.textContent = entry ? 'Editar bloque' : 'Nuevo bloque';
        }

        formPanel.hidden = false;
        form.elements.weekday.focus();
    }

    function duplicateForm(entry) {
        openForm(entry);
        form.elements.entry_id.value = '';

        if (formTitle) {
            formTitle.textContent = 'Duplicar bloque';
        }
    }

    function closeForm() {
        form.reset();
        formPanel.hidden = true;
    }

    function closeExceptionForm() {
        if (exceptionForm instanceof HTMLFormElement) {
            exceptionForm.reset();
        }

        if (exceptionPanel) {
            exceptionPanel.hidden = true;
        }
    }

    function setPending(value) {
        pending = value;
        form.setAttribute('aria-busy', value ? 'true' : 'false');
        Array.prototype.slice.call(form.querySelectorAll('button, input, select, textarea')).forEach(function (control) {
            control.disabled = value;
        });
    }

    function payloadFromForm() {
        return {
            weekday: form.elements.weekday.value,
            starts_at: form.elements.starts_at.value,
            ends_at: form.elements.ends_at.value,
            course_name: form.elements.course_name.value.trim(),
            course_code: form.elements.course_code.value.trim(),
            room: form.elements.room.value.trim(),
            campus: form.elements.campus.value.trim(),
            valid_from: form.elements.valid_from.value,
            valid_until: form.elements.valid_until.value
        };
    }

    function payloadFromExceptionForm() {
        return {
            schedule_entry_id: exceptionForm.elements.schedule_entry_id.value,
            exception_date: exceptionForm.elements.exception_date.value,
            type: exceptionForm.elements.type.value,
            starts_at: exceptionForm.elements.starts_at.value,
            ends_at: exceptionForm.elements.ends_at.value,
            course_name: exceptionForm.elements.course_name.value.trim(),
            course_code: exceptionForm.elements.course_code.value.trim(),
            room: exceptionForm.elements.room.value.trim(),
            campus: exceptionForm.elements.campus.value.trim(),
            notes: exceptionForm.elements.notes.value.trim()
        };
    }

    function openExceptionForm(entry, exception) {
        if (!(exceptionForm instanceof HTMLFormElement) || !exceptionPanel || !entry) {
            return;
        }

        exceptionForm.reset();
        exceptionForm.elements.exception_id.value = exception ? String(exception.id || '') : '';
        exceptionForm.elements.schedule_entry_id.value = exception
            ? String(exception.schedule_entry_id || entry.dataset.scheduleEntryId || '')
            : String(entry.dataset.scheduleEntryId || '');
        exceptionForm.elements.exception_date.value = exception ? String(exception.exception_date || '') : '';
        exceptionForm.elements.type.value = exception ? String(exception.type || 'cancelled') : 'cancelled';
        exceptionForm.elements.starts_at.value = exception && exception.starts_at_input ? String(exception.starts_at_input) : entry.dataset.startsAt || '';
        exceptionForm.elements.ends_at.value = exception && exception.ends_at_input ? String(exception.ends_at_input) : entry.dataset.endsAt || '';
        exceptionForm.elements.course_name.value = exception && exception.course_name ? String(exception.course_name) : entry.dataset.courseName || '';
        exceptionForm.elements.course_code.value = exception && exception.course_code ? String(exception.course_code) : entry.dataset.courseCode || '';
        exceptionForm.elements.room.value = exception && exception.room ? String(exception.room) : entry.dataset.room || '';
        exceptionForm.elements.campus.value = exception && exception.campus ? String(exception.campus) : entry.dataset.campus || entry.dataset.effectiveCampus || '';
        exceptionForm.elements.notes.value = exception && exception.notes ? String(exception.notes) : '';

        if (exceptionTitle) {
            exceptionTitle.textContent = exception ? 'Editar excepcion' : 'Nueva excepcion';
        }

        exceptionPanel.hidden = false;
        exceptionForm.elements.exception_date.focus();
    }

    function exceptionForButton(button) {
        var id = button ? String(button.dataset.exceptionId || '') : '';

        return scheduleExceptions.find(function (exception) {
            return String(exception.id || '') === id;
        }) || null;
    }

    function renderDetailExceptions(block) {
        if (!detailExceptions) {
            return;
        }

        var entryId = String(block.dataset.scheduleEntryId || '');
        var related = scheduleExceptions.filter(function (exception) {
            return String(exception.schedule_entry_id || '') === entryId;
        });

        detailExceptions.replaceChildren();
        detailExceptions.hidden = related.length === 0;

        if (related.length === 0) {
            return;
        }

        var title = document.createElement('p');
        title.className = 'dashboard-card__eyebrow';
        title.textContent = 'Excepciones';
        detailExceptions.appendChild(title);

        related.slice(0, 5).forEach(function (exception) {
            var row = document.createElement('div');
            var text = document.createElement('span');
            var actions = document.createElement('span');
            var edit = document.createElement('button');
            var del = document.createElement('button');

            row.className = 'schedule-exception-row';
            actions.className = 'task-actions';
            text.textContent = [String(exception.exception_date || ''), String(exception.type_label || '')].filter(Boolean).join(' · ');
            edit.type = 'button';
            edit.className = 'button button--secondary';
            edit.textContent = 'Editar';
            edit.dataset.exceptionId = String(exception.id || '');
            del.type = 'button';
            del.className = 'button button--danger';
            del.textContent = 'Eliminar';
            del.dataset.exceptionId = String(exception.id || '');
            actions.appendChild(edit);
            actions.appendChild(del);
            row.appendChild(text);
            row.appendChild(actions);
            detailExceptions.appendChild(row);
        });
    }

    function setSelectedBlock(block) {
        if (selectedEntry && selectedEntry !== block) {
            selectedEntry.classList.remove('is-selected');
            selectedEntry.setAttribute('aria-expanded', 'false');
        }

        selectedEntry = block;

        if (selectedEntry) {
            selectedEntry.classList.add('is-selected');
            selectedEntry.setAttribute('aria-expanded', 'true');
        }
    }

    function positionDetail() {
        if (!detail || !selectedEntry || detail.hidden) {
            return;
        }

        var gap = 12;
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var isCompact = viewportWidth < 720;

        detail.classList.toggle('schedule-detail--sheet', isCompact);
        detail.style.top = '';
        detail.style.left = '';
        detail.style.right = '';
        detail.style.bottom = '';
        detail.style.maxHeight = '';

        if (isCompact) {
            return;
        }

        var anchorRect = selectedEntry.getBoundingClientRect();
        var detailRect = detail.getBoundingClientRect();
        var detailWidth = Math.min(detailRect.width || 368, Math.max(240, viewportWidth - (gap * 2)));
        var detailHeight = Math.min(detailRect.height || 240, Math.max(160, viewportHeight - (gap * 2)));
        var left = anchorRect.right + gap;
        var top = anchorRect.top;

        if (left + detailWidth > viewportWidth - gap) {
            left = anchorRect.left - detailWidth - gap;
        }

        if (left < gap) {
            left = Math.max(gap, Math.min(anchorRect.left, viewportWidth - detailWidth - gap));
        }

        if (top + detailHeight > viewportHeight - gap) {
            top = viewportHeight - detailHeight - gap;
        }

        if (top < gap) {
            top = gap;
        }

        if (detail) {
            detail.style.left = left + 'px';
            detail.style.top = top + 'px';
            detail.style.maxHeight = Math.max(160, viewportHeight - top - gap) + 'px';
        }
    }

    function closeDetail() {
        setSelectedBlock(null);

        if (detail) {
            detail.hidden = true;
            detail.classList.remove('schedule-detail--sheet');
            detail.style.top = '';
            detail.style.left = '';
            detail.style.right = '';
            detail.style.bottom = '';
            detail.style.maxHeight = '';
        }
    }

    function focusDetail() {
        if (!detail) {
            return;
        }

        try {
            detail.focus({ preventScroll: true });
        } catch (error) {
            detail.focus();
        }
    }

    function openDetail(block) {
        if (!detail || !detailTitle || !detailMeta) {
            return;
        }

        setSelectedBlock(block);

        detailTitle.textContent = block.dataset.courseName || '';
        var dayLabels = {
            '1': 'Lunes',
            '2': 'Martes',
            '3': 'Miercoles',
            '4': 'Jueves',
            '5': 'Viernes',
            '6': 'Sabado',
            '7': 'Domingo'
        };

        detailMeta.textContent = [
            'Persona: ' + (block.dataset.ownerLabel || targetLabel(targetSelect)),
            'Dia: ' + (dayLabels[block.dataset.weekday] || block.dataset.weekday || ''),
            (block.dataset.startsAt || '') + '-' + (block.dataset.endsAt || ''),
            block.dataset.courseCode ? 'Codigo ' + block.dataset.courseCode : '',
            block.dataset.room ? 'Sala ' + block.dataset.room : '',
            block.dataset.effectiveCampus ? 'Campus ' + block.dataset.effectiveCampus : '',
            block.dataset.validFrom ? 'Desde ' + block.dataset.validFrom : '',
            block.dataset.validUntil ? 'Hasta ' + block.dataset.validUntil : ''
        ].filter(Boolean).join(' · ');

        if (detailWarning) {
            detailWarning.textContent = block.dataset.warning || '';
            detailWarning.hidden = !block.dataset.warning;
        }

        renderDetailExceptions(block);
        detail.hidden = false;
        positionDetail();
        focusDetail();
    }

    if (newButton) {
        newButton.addEventListener('click', function () {
            openForm(null);
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', closeForm);
    }

    if (targetSelect) {
        targetSelect.addEventListener('change', function () {
            targetSelect.form.submit();
        });
    }

    if (showSelect) {
        showSelect.addEventListener('change', function () {
            showSelect.form.submit();
        });
    }

    function activateScheduleDay(day) {
        day = String(day || currentWeekday || '1');

        page.querySelectorAll('[data-schedule-day-tab]').forEach(function (tab) {
            var isActive = tab.getAttribute('data-schedule-day-tab') === day;

            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        page.querySelectorAll('[data-schedule-day]').forEach(function (column) {
            column.classList.toggle('is-mobile-active', column.getAttribute('data-schedule-day') === day);
        });

        requestScheduleLayout();
    }

    page.querySelectorAll('[data-schedule-day-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            activateScheduleDay(button.getAttribute('data-schedule-day-tab') || currentWeekday);
        });
    });

    activateScheduleDay(currentWeekday);
    requestScheduleLayout();

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var entryId = form.elements.entry_id.value;
        var payload = payloadFromForm();

        if (!payload.course_name) {
            showMessage('El nombre del ramo es obligatorio.', true);
            return;
        }

        if (!payload.starts_at || !payload.ends_at) {
            showMessage('Las horas de inicio y fin son obligatorias.', true);
            return;
        }

        setPending(true);
        jsonApi(apiWithTarget(entryId), entryId ? 'PATCH' : 'POST', payload)
            .then(function (entry) {
                closeForm();
                var warning = entry && Array.isArray(entry.warnings) && entry.warnings.length > 0 ? ' ' + entry.warnings[0] : '';
                showMessage((entryId ? 'Bloque actualizado.' : 'Bloque creado.') + warning, false);
                return refreshSchedule();
            })
            .catch(function (error) {
                showMessage(error.message, true);
            })
            .finally(function () {
                setPending(false);
            });
    });

    week.addEventListener('click', function (event) {
        var block = event.target instanceof HTMLElement ? event.target.closest('.schedule-block') : null;

        if (block instanceof HTMLElement) {
            openDetail(block);
        }
    });

    document.addEventListener('click', function (event) {
        if (!detail || detail.hidden) {
            return;
        }

        var target = event.target;

        if (target instanceof Node && (detail.contains(target) || (selectedEntry && selectedEntry.contains(target)))) {
            return;
        }

        closeDetail();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && detail && !detail.hidden) {
            closeDetail();
        }
    });

    window.addEventListener('resize', requestScheduleLayout);
    window.addEventListener('scroll', positionDetail, true);

    if (detailEdit) {
        detailEdit.addEventListener('click', function () {
            if (selectedEntry) {
                openForm(selectedEntry);
            }
        });
    }

    if (detailDuplicate) {
        detailDuplicate.addEventListener('click', function () {
            if (selectedEntry) {
                duplicateForm(selectedEntry);
            }
        });
    }

    if (detailException) {
        detailException.addEventListener('click', function () {
            if (selectedEntry) {
                openExceptionForm(selectedEntry, null);
            }
        });
    }

    if (detailClose) {
        detailClose.addEventListener('click', closeDetail);
    }

    if (detailExceptions) {
        detailExceptions.addEventListener('click', function (event) {
            var button = event.target instanceof HTMLElement ? event.target.closest('button') : null;

            if (!(button instanceof HTMLButtonElement) || !selectedEntry) {
                return;
            }

            var exception = exceptionForButton(button);

            if (!exception) {
                return;
            }

            if (button.classList.contains('button--danger')) {
                if (!window.confirm('Eliminar esta excepcion?')) {
                    return;
                }

                jsonApi(apiWithAction('exceptions', String(exception.id || '')), 'DELETE', {})
                    .then(function () {
                        showMessage('Excepcion eliminada.', false);
                        return refreshExceptions();
                    })
                    .catch(function (error) {
                        showMessage(error.message, true);
                    });
                return;
            }

            openExceptionForm(selectedEntry, exception);
        });
    }

    exceptionCancelButtons.forEach(function (button) {
        button.addEventListener('click', closeExceptionForm);
    });

    if (exceptionForm instanceof HTMLFormElement) {
        exceptionForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!selectedEntry) {
                showMessage('Selecciona un bloque para crear la excepcion.', true);
                return;
            }

            var exceptionId = exceptionForm.elements.exception_id.value;

            jsonApi(apiWithAction('exceptions', exceptionId), exceptionId ? 'PATCH' : 'POST', payloadFromExceptionForm())
                .then(function () {
                    closeExceptionForm();
                    showMessage(exceptionId ? 'Excepcion actualizada.' : 'Excepcion creada.', false);
                    return refreshExceptions();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    if (detailDelete) {
        detailDelete.addEventListener('click', function () {
            if (!selectedEntry || actionPending || !window.confirm('Eliminar este bloque de horario?')) {
                return;
            }

            actionPending = true;
            detailDelete.disabled = true;
            jsonApi(apiWithTarget(selectedEntry.dataset.scheduleEntryId || ''), 'DELETE', {})
                .then(function () {
                    showMessage('Bloque eliminado.', false);
                    return refreshSchedule();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                })
                .finally(function () {
                    actionPending = false;
                    detailDelete.disabled = false;
                });
        });
    }

    function openImport() {
        clearMessage();

        if (!importPanel || !importForm) {
            return;
        }

        if (importTarget instanceof HTMLSelectElement && targetSelect instanceof HTMLSelectElement) {
            importTarget.value = targetSelect.value;
        }

        importForm.reset();

        if (importTarget instanceof HTMLSelectElement && targetSelect instanceof HTMLSelectElement) {
            importTarget.value = targetSelect.value;
        }

        if (importPreviewPanel) {
            importPreviewPanel.replaceChildren();
            importPreviewPanel.hidden = true;
        }

        if (importConfirm) {
            importConfirm.disabled = true;
        }

        importPanel.hidden = false;

        if (importJson) {
            importJson.focus();
        }
    }

    function closeImport() {
        if (importForm) {
            importForm.reset();
        }

        if (importPanel) {
            importPanel.hidden = true;
        }

        if (importPreviewPanel) {
            importPreviewPanel.replaceChildren();
            importPreviewPanel.hidden = true;
        }

        if (importConfirm) {
            importConfirm.disabled = true;
        }
    }

    function importJsonText() {
        return importJson ? importJson.value.trim() : '';
    }

    function renderImportPreview(result) {
        if (!importPreviewPanel) {
            return;
        }

        importPreviewPanel.replaceChildren();
        importPreviewPanel.hidden = false;

        var heading = document.createElement('h3');
        heading.textContent = 'Previsualizacion';
        importPreviewPanel.appendChild(heading);

        if (!result || !Array.isArray(result.errors) || result.errors.length > 0) {
            var errors = document.createElement('div');
            errors.className = 'task-message task-message--error';
            (result && Array.isArray(result.errors) ? result.errors : ['Importacion invalida.']).forEach(function (error) {
                var p = document.createElement('p');
                p.textContent = String(error);
                errors.appendChild(p);
            });
            importPreviewPanel.appendChild(errors);

            if (importConfirm) {
                importConfirm.disabled = true;
            }

            return;
        }

        var summary = document.createElement('p');
        summary.textContent = 'Importar a: ' + (result.target && result.target.label ? String(result.target.label) : targetLabel(importTarget)) + ' · ' + String(result.total || 0) + ' bloques encontrados';
        importPreviewPanel.appendChild(summary);

        if (Array.isArray(result.warnings) && result.warnings.length > 0) {
            var warningBox = document.createElement('div');
            warningBox.className = 'task-message task-message--success';
            result.warnings.forEach(function (warning) {
                var p = document.createElement('p');
                p.textContent = String(warning);
                warningBox.appendChild(p);
            });
            importPreviewPanel.appendChild(warningBox);
        }

        var list = document.createElement('div');
        list.className = 'schedule-import-list';
        (Array.isArray(result.blocks) ? result.blocks : []).forEach(function (block) {
            var row = document.createElement('p');
            row.textContent = [
                String(block.weekday_name || ''),
                String(block.starts_at_input || '') + '-' + String(block.ends_at_input || ''),
                String(block.course_name || ''),
                block.room ? String(block.room) : '',
                block.campus || block.effective_campus ? String(block.campus || block.effective_campus) : '',
                block.is_duplicate ? 'Duplicado: se omitira' : ''
            ].filter(Boolean).join(' · ');
            list.appendChild(row);
        });
        importPreviewPanel.appendChild(list);

        if (importConfirm) {
            importConfirm.disabled = false;
        }
    }

    if (importOpen) {
        importOpen.addEventListener('click', openImport);
    }

    if (importCancel) {
        importCancel.addEventListener('click', closeImport);
    }

    if (importFile) {
        importFile.addEventListener('change', function () {
            var file = importFile.files && importFile.files[0] ? importFile.files[0] : null;

            if (!file) {
                return;
            }

            if (file.size > 256000) {
                showMessage('El archivo JSON supera el tamano permitido.', true);
                importFile.value = '';
                return;
            }

            file.text().then(function (text) {
                if (importJson) {
                    importJson.value = text;
                }
            }).catch(function () {
                showMessage('No se pudo leer el archivo JSON.', true);
            });
        });
    }

    if (importPreviewButton) {
        importPreviewButton.addEventListener('click', function () {
            var text = importJsonText();

            if (!text) {
                showMessage('Pega o carga un JSON antes de previsualizar.', true);
                return;
            }

            importApi('import-preview', text)
                .then(function (result) {
                    renderImportPreview(result.data);
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    if (importForm) {
        importForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var text = importJsonText();

            if (!text) {
                showMessage('Pega o carga un JSON antes de importar.', true);
                return;
            }

            importApi('import', text)
                .then(function (result) {
                    if (!result.ok) {
                        renderImportPreview(result.data);
                        return;
                    }

                    closeImport();
                    showMessage('Importados: ' + String(result.data.imported || 0) + '. Omitidos por duplicado: ' + String(result.data.skipped_duplicates || 0) + '.', false);

                    if (targetSelect instanceof HTMLSelectElement && importTarget instanceof HTMLSelectElement) {
                        targetSelect.value = importTarget.value;
                    }

                    return refreshSchedule();
                })
                .catch(function (error) {
                    showMessage(error.message, true);
                });
        });
    }

    refreshExceptions();
}());

(function () {
    'use strict';

    var quick = document.querySelector('[data-inbox-quick]');

    if (!quick || quick.dataset.inboxQuickInitialized === 'true') {
        return;
    }

    quick.dataset.inboxQuickInitialized = 'true';

    var form = quick.querySelector('[data-inbox-quick-form]');
    var input = form ? form.elements.title : null;
    var message = quick.querySelector('[data-inbox-quick-message]');
    var count = quick.querySelector('[data-inbox-quick-count]');
    var list = quick.querySelector('[data-inbox-quick-list]');
    var empty = quick.querySelector('[data-inbox-quick-empty]');
    var apiUrl = quick.getAttribute('data-api-url') || '/api/organization/tasks.php';
    var csrfToken = quick.getAttribute('data-csrf-token') || '';
    var pending = false;

    if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
        return;
    }

    function setQuickPending(nextPending) {
        var controls = Array.prototype.slice.call(form.querySelectorAll('button, input'));

        pending = nextPending;
        form.setAttribute('aria-busy', nextPending ? 'true' : 'false');
        controls.forEach(function (control) {
            control.disabled = nextPending;
        });
    }

    function showQuickMessage(text, isError) {
        if (!message) {
            return;
        }

        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('task-message--error', Boolean(isError));
        message.classList.toggle('task-message--success', !isError);
    }

    function incrementCount() {
        if (!count) {
            return;
        }

        count.textContent = String((Number.parseInt(count.textContent || '0', 10) || 0) + 1);
    }

    function appendInboxItem(title) {
        if (!list) {
            return;
        }

        var item = document.createElement('li');
        item.textContent = title;
        list.hidden = false;
        list.prepend(item);

        while (list.children.length > 3) {
            list.removeChild(list.lastElementChild);
        }

        if (empty) {
            empty.hidden = true;
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (pending) {
            return;
        }

        var title = input.value.trim();

        if (!title) {
            showQuickMessage('Escribe un pendiente para anadirlo.', true);
            input.focus();
            return;
        }

        setQuickPending(true);

        fetch(apiUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                title: title,
                priority: 'normal',
                space_id: '',
                due_at: ''
            })
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.ok) {
                    throw new Error(body && body.error ? body.error : 'No se pudo anadir el pendiente.');
                }

                return body.data;
            });
        }).then(function (task) {
            input.value = '';
            showQuickMessage('Pendiente anadido a Bandeja.', false);
            incrementCount();
            appendInboxItem(String(task.title || title));
            input.focus();
        }).catch(function (error) {
            showQuickMessage(error.message, true);
        }).finally(function () {
            setQuickPending(false);
        });
    });
}());
