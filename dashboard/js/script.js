const rangeSelector = document.getElementById('range');
const timezoneSelector = document.getElementById('timezone');
const themeToggle = document.getElementById('theme-toggle');
const excludeBots = document.getElementById('exclude-bots');
const customRange = document.getElementById('custom-range');
const rangeFrom = document.getElementById('range-from');
const rangeUntil = document.getElementById('range-until');

let isDarkMode = localStorage.getItem('darkMode') === 'true';
let selectedTimezone = 'UTC';
let currentPage = 1;
let totalPages = 1;
let activeFilters = {};
let lastDashboard = null;
let latencyChart;
let responseCodeChart;
let availableEventKeys = [];

if (isDarkMode) {
    document.documentElement.setAttribute('data-theme', 'dark');
    themeToggle.innerHTML = '<i class="bi bi-sun"></i> Light mode';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    }[char]));
}

function pad(number) {
    return String(number).padStart(2, '0');
}

function parseUtc(utcTimestamp) {
    if (!utcTimestamp) {
        return null;
    }
    const text = String(utcTimestamp);
    if (text.includes('T')) {
        return new Date(text);
    }
    const parts = text.split(/[- :]/);
    if (parts.length >= 6) {
        return new Date(Date.UTC(
            parseInt(parts[0], 10),
            parseInt(parts[1], 10) - 1,
            parseInt(parts[2], 10),
            parseInt(parts[3], 10),
            parseInt(parts[4], 10),
            parseInt(parts[5], 10)
        ));
    }
    return new Date(text + 'Z');
}

function formatTimestamp(utcTimestamp, withSeconds = true) {
    const date = parseUtc(utcTimestamp);
    if (!date || Number.isNaN(date.getTime())) {
        return String(utcTimestamp ?? '');
    }
    const options = {
        timeZone: selectedTimezone,
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    };
    if (withSeconds) {
        options.second = '2-digit';
    }
    return date.toLocaleString(navigator.language || 'en-US', options);
}

function utcStamp(date) {
    return date.getUTCFullYear() + '-' + pad(date.getUTCMonth() + 1) + '-' + pad(date.getUTCDate())
        + ' ' + pad(date.getUTCHours()) + ':' + pad(date.getUTCMinutes()) + ':' + pad(date.getUTCSeconds());
}

function localInputToUtc(value) {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return utcStamp(date);
}

function utcToLocalInput(value) {
    const date = parseUtc(value);
    if (!date || Number.isNaN(date.getTime())) {
        return '';
    }
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate())
        + 'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
}

function formatBytes(bytes) {
    const size = Number(bytes) || 0;
    if (size < 1024) {
        return size + ' B';
    }
    if (size < 1048576) {
        return (size / 1024).toFixed(1) + ' KB';
    }
    return (size / 1048576).toFixed(1) + ' MB';
}

function formatMs(seconds) {
    const ms = (Number(seconds) || 0) * 1000;
    return ms.toFixed(ms >= 100 ? 0 : 1) + ' ms';
}

function latencyClass(ms) {
    if (ms >= 1000) {
        return 'text-danger';
    }
    if (ms >= 300) {
        return 'text-warning';
    }
    return '';
}

function statusBadge(code) {
    const status = Number(code);
    let cls = 'bg-secondary';
    if (status >= 500) {
        cls = 'bg-danger';
    } else if (status >= 400) {
        cls = 'bg-warning text-dark';
    } else if (status >= 300) {
        cls = 'bg-info text-dark';
    } else if (status >= 200) {
        cls = 'bg-success';
    }
    return '<span class="badge ' + cls + '">' + escapeHtml(code) + '</span>';
}

function chartTheme() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
        text: dark ? '#e0e0e0' : '#333333',
        grid: dark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.08)'
    };
}

function showError(message) {
    const box = document.getElementById('load-error');
    box.textContent = message;
    box.classList.remove('d-none');
}

function hideError() {
    document.getElementById('load-error').classList.add('d-none');
}

function readUrl() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('range')) {
        rangeSelector.value = params.get('range');
    }
    selectedTimezone = params.get('tz') || localStorage.getItem('selectedTimezone') || 'UTC';
    timezoneSelector.value = selectedTimezone;
    excludeBots.checked = params.get('exclude_bots') === '1';
    currentPage = Math.max(1, parseInt(params.get('page') || '1', 10));
    if (params.get('from')) {
        rangeFrom.value = utcToLocalInput(params.get('from').replace('+', ' '));
    }
    if (params.get('until')) {
        rangeUntil.value = utcToLocalInput(params.get('until').replace('+', ' '));
    }
    const fields = {
        url: 'filter-url',
        request_id: 'filter-request-id',
        is_bot: 'filter-bot',
        min_time: 'filter-min-time',
        route: 'filter-route',
        ip: 'filter-ip',
        host: 'filter-host',
        session_id: 'filter-session-id',
        user_agent: 'filter-user-agent',
        custom_event_type: 'filter-custom-event',
        error_message: 'filter-error'
    };
    Object.entries(fields).forEach(([key, id]) => {
        const node = document.getElementById(id);
        if (node && params.get(key) !== null) {
            node.value = params.get(key);
        }
    });
    const code = document.getElementById('filter-response-code');
    if (params.get('response_code')) {
        code.value = 'exact';
        document.getElementById('filter-exact-code').value = params.get('response_code');
        document.getElementById('exact-code-container').classList.remove('d-none');
    } else if (params.get('response_code_prefix')) {
        code.value = params.get('response_code_prefix');
    }
    if (params.get('event_keys')) {
        try {
            activeFilters.event_keys = JSON.parse(params.get('event_keys'));
        } catch (error) {
            activeFilters.event_keys = [];
        }
    }
    captureFilters(false);
    toggleCustomRange();
}

function captureFilters(fromForm = true) {
    if (!fromForm) {
        ['url', 'request_id', 'is_bot', 'min_time', 'route', 'ip', 'host', 'session_id', 'user_agent', 'custom_event_type', 'error_message'].forEach((key) => {
            const map = {
                url: 'filter-url',
                request_id: 'filter-request-id',
                is_bot: 'filter-bot',
                min_time: 'filter-min-time',
                route: 'filter-route',
                ip: 'filter-ip',
                host: 'filter-host',
                session_id: 'filter-session-id',
                user_agent: 'filter-user-agent',
                custom_event_type: 'filter-custom-event',
                error_message: 'filter-error'
            };
            const value = document.getElementById(map[key]).value.trim();
            if (value !== '') {
                activeFilters[key] = value;
            }
        });
    }
    const next = {};
    const take = (key, id) => {
        const value = document.getElementById(id).value.trim();
        if (value !== '') {
            next[key] = value;
        }
    };
    take('url', 'filter-url');
    take('request_id', 'filter-request-id');
    take('is_bot', 'filter-bot');
    take('min_time', 'filter-min-time');
    take('route', 'filter-route');
    take('ip', 'filter-ip');
    take('host', 'filter-host');
    take('session_id', 'filter-session-id');
    take('user_agent', 'filter-user-agent');
    take('custom_event_type', 'filter-custom-event');
    take('error_message', 'filter-error');
    const code = document.getElementById('filter-response-code').value;
    const exact = document.getElementById('filter-exact-code').value.trim();
    if (code === 'exact' && exact !== '') {
        next.response_code = exact;
    } else if (code && code !== 'exact') {
        next.response_code_prefix = code;
    }
    const eventKeys = [];
    document.querySelectorAll('.event-filter-row').forEach((row) => {
        const key = row.querySelector('.event-key-select').value;
        const operator = row.querySelector('.event-operator-select').value;
        const value = row.querySelector('.event-value-input').value.trim();
        if (key !== '' || value !== '') {
            eventKeys.push({ key, operator, value });
        }
    });
    if (eventKeys.length) {
        next.event_keys = eventKeys;
    } else if (!fromForm && Array.isArray(activeFilters.event_keys)) {
        next.event_keys = activeFilters.event_keys;
    }
    activeFilters = next;
}

function queryParams() {
    const params = new URLSearchParams();
    params.set('range', rangeSelector.value);
    params.set('tz', selectedTimezone);
    if (excludeBots.checked) {
        params.set('exclude_bots', '1');
    }
    if (rangeSelector.value === 'custom') {
        const from = localInputToUtc(rangeFrom.value);
        const until = localInputToUtc(rangeUntil.value);
        if (from) {
            params.set('from', from);
        }
        if (until) {
            params.set('until', until);
        }
    }
    if (currentPage > 1) {
        params.set('page', String(currentPage));
    }
    Object.entries(activeFilters).forEach(([key, value]) => {
        if (value === '' || value === null) {
            return;
        }
        params.set(key, key === 'event_keys' ? JSON.stringify(value) : value);
    });
    return params;
}

function syncUrl() {
    const params = queryParams();
    const next = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState(null, '', next);
}

function toggleCustomRange() {
    customRange.classList.toggle('d-none', rangeSelector.value !== 'custom');
}

async function loadData() {
    hideError();
    syncUrl();
    const params = queryParams();
    params.set('details', '0');
    try {
        const [dashboardResponse, requestResponse] = await Promise.all([
            fetch('/apm/data/dashboard?' + queryParams().toString()),
            fetch('/apm/data/requests?' + params.toString())
        ]);
        if (!dashboardResponse.ok || !requestResponse.ok) {
            throw new Error('The dashboard data request failed.');
        }
        lastDashboard = await dashboardResponse.json();
        const requests = await requestResponse.json();
        populateDashboard(lastDashboard);
        drawCharts(lastDashboard);
        populateRequestLog(requests.requests || []);
        updatePagination(requests.pagination || {});
        renderActiveFilters();
    } catch (error) {
        showError(error.message || 'Could not load the dashboard.');
    }
}

function setStat(id, text, className) {
    const node = document.getElementById(id);
    node.textContent = text;
    node.className = 'stat-value ' + (className || '');
}

function populateDashboard(data) {
    const count = Number(data.allRequestsCount) || 0;
    setStat('all-requests-count', String(count));
    const errorPct = (Number(data.errorRate) || 0) * 100;
    setStat('error-rate', errorPct.toFixed(2) + '%', errorPct >= 5 ? 'text-danger' : (errorPct >= 1 ? 'text-warning' : ''));
    const p95 = (Number(data.p95) || 0) * 1000;
    const p99 = (Number(data.p99) || 0) * 1000;
    const avg = (Number(data.averageLatency) || 0) * 1000;
    setStat('p95', p95.toFixed(0) + ' ms', latencyClass(p95));
    setStat('p99', p99.toFixed(0) + ' ms', latencyClass(p99));
    setStat('avg-latency', avg.toFixed(0) + ' ms', latencyClass(avg));
    setStat('throughput', (Number(data.throughputPerMinute) || 0).toFixed(1));
    document.getElementById('cache-hit-rate').textContent = ((Number(data.cacheHitRate) || 0) * 100).toFixed(1) + '%';
    const backlogWrap = document.getElementById('backlog-wrap');
    if (data.workerBacklog === null || data.workerBacklog === undefined) {
        backlogWrap.classList.add('d-none');
    } else {
        backlogWrap.classList.remove('d-none');
        document.getElementById('worker-backlog').textContent = String(data.workerBacklog);
    }
    document.getElementById('empty-window').classList.toggle('d-none', count !== 0);
    renderList('slow-routes', data.slowRoutes, (row) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        button.innerHTML = '<span>' + escapeHtml(row.route_pattern) + '</span><span class="badge bg-info">'
            + escapeHtml(formatMs(row.avg_time)) + ' · ' + escapeHtml(row.hit_count) + '</span>';
        button.addEventListener('click', () => {
            document.getElementById('filter-route').value = row.route_pattern;
            currentPage = 1;
            captureFilters();
            loadData();
        });
        return button;
    }, 'No route samples.');
    renderList('slow-middleware', data.slowMiddleware, (row) => itemHtml(
        row.middleware_name,
        formatMs(row.execution_time) + ' · ' + row.hit_count
    ), 'No middleware samples.');
    renderList('long-queries', data.longQueries, (row) => {
        const item = document.createElement('li');
        item.className = 'list-group-item';
        item.title = row.query || '';
        item.innerHTML = '<div class="d-flex justify-content-between gap-2"><span>'
            + escapeHtml(row.normalized || row.query) + '</span><span class="badge bg-warning text-dark">'
            + escapeHtml(formatMs(row.avg_time || row.execution_time)) + ' · ' + escapeHtml(row.hit_count || 1)
            + '</span></div>';
        return item;
    }, 'No query samples.');
    renderList('slow-requests', data.slowRequests, (row) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        button.innerHTML = '<span>' + escapeHtml(row.request_method || '') + ' ' + escapeHtml(row.request_url)
            + '</span><span class="badge bg-primary">' + escapeHtml(formatMs(row.total_time)) + '</span>';
        button.addEventListener('click', () => {
            document.getElementById('filter-request-id').value = row.request_token || '';
            currentPage = 1;
            captureFilters();
            loadData();
        });
        return button;
    }, 'No requests.');
    const errors = data.errorGroups || [];
    renderList('error-groups', errors, (row) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
        button.innerHTML = '<span>' + escapeHtml(row.error_message) + '</span><span class="badge bg-danger">'
            + escapeHtml(row.hit_count) + '</span>';
        button.addEventListener('click', () => {
            document.getElementById('filter-error').value = row.error_message || '';
            currentPage = 1;
            captureFilters();
            loadData();
        });
        return button;
    }, 'No errors in this window.');
}

function itemHtml(label, badge) {
    const item = document.createElement('li');
    item.className = 'list-group-item d-flex justify-content-between align-items-center gap-2';
    item.innerHTML = '<span>' + escapeHtml(label) + '</span><span class="badge bg-primary">' + escapeHtml(badge) + '</span>';
    return item;
}

function renderList(id, rows, render, emptyText) {
    const list = document.getElementById(id);
    list.innerHTML = '';
    if (!rows || rows.length === 0) {
        const item = document.createElement('li');
        item.className = 'list-group-item text-muted';
        item.textContent = emptyText;
        list.appendChild(item);
        return;
    }
    rows.forEach((row) => list.appendChild(render(row)));
}

function drawCharts(data) {
    const theme = chartTheme();
    const scale = {
        ticks: { color: theme.text, maxRotation: 0, autoSkip: true, maxTicksLimit: 6 },
        grid: { color: theme.grid },
        title: { color: theme.text }
    };
    const latency = data.chartData || [];
    const ctxLatency = document.getElementById('latencyChart');
    if (latencyChart) {
        latencyChart.destroy();
    }
    latencyChart = new Chart(ctxLatency, {
        data: {
            labels: latency.map((row) => row.request_dt),
            datasets: [
                {
                    type: 'line',
                    label: 'Average latency (ms)',
                    data: latency.map((row) => (Number(row.average_time) || 0) * 1000),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.15)',
                    yAxisID: 'y',
                    tension: 0.2,
                    pointRadius: 0,
                    spanGaps: true
                },
                {
                    type: 'bar',
                    label: 'Requests',
                    data: latency.map((row) => Number(row.request_count) || 0),
                    backgroundColor: 'rgba(25, 135, 84, 0.35)',
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: Object.assign({}, scale, {
                    ticks: Object.assign({}, scale.ticks, {
                        callback(value) {
                            return formatTimestamp(this.getLabelForValue(value), false);
                        }
                    })
                }),
                y: Object.assign({}, scale, { position: 'left', beginAtZero: true, title: { display: true, text: 'ms', color: theme.text } }),
                y1: Object.assign({}, scale, { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Requests', color: theme.text } })
            },
            plugins: {
                legend: { labels: { color: theme.text } },
                tooltip: {
                    callbacks: {
                        title(items) {
                            return formatTimestamp(items[0].label);
                        }
                    }
                }
            }
        }
    });

    const status = data.statusClassesOverTime || [];
    const ctxStatus = document.getElementById('responseCodeChart');
    if (responseCodeChart) {
        responseCodeChart.destroy();
    }
    const series = [
        ['2xx', 'code_2xx', '#198754'],
        ['3xx', 'code_3xx', '#0dcaf0'],
        ['4xx', 'code_4xx', '#ffc107'],
        ['5xx', 'code_5xx', '#dc3545']
    ];
    responseCodeChart = new Chart(ctxStatus, {
        type: 'bar',
        data: {
            labels: status.map((row) => row.request_dt),
            datasets: series.map(([label, key, color]) => ({
                label,
                data: status.map((row) => Number(row[key]) || 0),
                backgroundColor: color
            }))
        },
        options: {
            maintainAspectRatio: false,
            scales: {
                x: Object.assign({}, scale, {
                    stacked: true,
                    ticks: Object.assign({}, scale.ticks, {
                        callback(value) {
                            return formatTimestamp(this.getLabelForValue(value), false);
                        }
                    })
                }),
                y: Object.assign({}, scale, { stacked: true, beginAtZero: true, title: { display: true, text: 'Requests', color: theme.text } })
            },
            plugins: {
                legend: { labels: { color: theme.text } },
                tooltip: {
                    callbacks: {
                        title(items) {
                            return formatTimestamp(items[0].label);
                        }
                    }
                }
            },
            onClick(event, elements) {
                if (!elements.length) {
                    return;
                }
                const label = responseCodeChart.data.datasets[elements[0].datasetIndex].label;
                document.getElementById('filter-response-code').value = label.charAt(0);
                document.getElementById('exact-code-container').classList.add('d-none');
                currentPage = 1;
                captureFilters();
                loadData();
            }
        }
    });
}

function formatJson(json) {
    if (json === null || json === undefined || json === '') {
        return 'No data';
    }
    try {
        const obj = typeof json === 'string' ? JSON.parse(json) : json;
        return JSON.stringify(obj, null, 2)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, (match) => {
                let cls = 'json-number';
                if (/^"/.test(match)) {
                    cls = /:$/.test(match) ? 'json-key' : 'json-string';
                } else if (/true|false/.test(match)) {
                    cls = 'json-boolean';
                } else if (/null/.test(match)) {
                    cls = 'json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
    } catch (error) {
        return escapeHtml(json);
    }
}

function waterfall(items) {
    const max = Math.max(1, ...items.map((item) => item.ms));
    return items.map((item) => {
        const width = Math.max(2, Math.round((item.ms / max) * 100));
        return '<div class="mb-2"><div class="d-flex justify-content-between small"><span>'
            + escapeHtml(item.label) + '</span><span>' + escapeHtml(item.ms.toFixed(1)) + ' ms</span></div>'
            + '<div class="waterfall-track"><div class="waterfall-bar" style="width:' + width + '%"></div></div></div>';
    }).join('');
}

function detailHtml(row) {
    const bars = [];
    (row.routes || []).forEach((item) => bars.push({ label: 'Route ' + item.route_pattern, ms: (Number(item.execution_time) || 0) * 1000 }));
    (row.middleware || []).forEach((item) => bars.push({ label: item.middleware_name, ms: (Number(item.execution_time) || 0) * 1000 }));
    (row.views || []).forEach((item) => bars.push({ label: 'View ' + item.view_file, ms: (Number(item.render_time) || 0) * 1000 }));
    (row.queries || []).forEach((item) => bars.push({ label: item.query, ms: (Number(item.execution_time) || 0) * 1000 }));
    let html = '';
    if (bars.length) {
        html += '<h6>Timing</h6>' + waterfall(bars);
    }
    if ((row.db_connections || []).length) {
        html += '<h6 class="mt-3">Database</h6><ul>' + row.db_connections.map((item) =>
            '<li>' + escapeHtml(item.engine) + ' ' + escapeHtml(item.host) + ' / ' + escapeHtml(item.database_name) + '</li>'
        ).join('') + '</ul>';
    }
    if ((row.queries || []).length) {
        html += '<h6 class="mt-3">Queries</h6><div class="table-responsive"><table class="table table-sm"><tbody>'
            + row.queries.map((item) => '<tr><td><code>' + escapeHtml(item.query) + '</code>'
                + (item.params ? '<div class="small text-muted">' + escapeHtml(item.params) + '</div>' : '')
                + '</td><td>' + escapeHtml(formatMs(item.execution_time)) + '</td><td>'
                + escapeHtml(item.row_count) + ' rows</td></tr>').join('')
            + '</tbody></table></div>';
    }
    if ((row.errors || []).length) {
        html += '<h6 class="mt-3 text-danger">Errors</h6>' + row.errors.map((item) =>
            '<div class="mb-2"><strong>' + escapeHtml(item.error_code) + '</strong> ' + escapeHtml(item.error_message)
            + (item.error_trace ? '<pre class="trace-block mt-1">' + escapeHtml(item.error_trace) + '</pre>' : '')
            + '</div>'
        ).join('');
    }
    if ((row.cache || []).length) {
        html += '<h6 class="mt-3">Cache</h6><ul>' + row.cache.map((item) =>
            '<li>' + escapeHtml(item.cache_key) + ' ' + (Number(item.hit) === 1 ? 'hit' : 'miss')
            + ' · ' + escapeHtml(formatMs(item.execution_time)) + '</li>'
        ).join('') + '</ul>';
    }
    if ((row.custom_events || []).length) {
        html += '<h6 class="mt-3">Custom events</h6>' + row.custom_events.map((item) =>
            '<div class="mb-2"><span class="badge bg-info me-2">' + escapeHtml(item.type) + '</span>'
            + '<small>' + escapeHtml(formatTimestamp(item.event_dt)) + '</small>'
            + '<pre class="json-formatter">' + formatJson(item.data) + '</pre></div>'
        ).join('');
    }
    if (!html) {
        html = '<div class="text-muted">No detail rows for this request.</div>';
    }
    html += '<h6 class="mt-3">Raw</h6><pre class="json-formatter">' + formatJson(row) + '</pre>';
    return html;
}

function populateRequestLog(requests) {
    const body = document.getElementById('request-log');
    if (!requests.length) {
        body.innerHTML = '<tr><td colspan="9" class="text-muted">No requests match these filters.</td></tr>';
        return;
    }
    body.innerHTML = requests.map((row, index) => {
        const id = 'request-details-' + index;
        const memory = formatBytes(row.peak_memory);
        return '<tr>'
            + '<td>' + escapeHtml(formatTimestamp(row.request_dt)) + '</td>'
            + '<td>' + escapeHtml(row.request_method || '') + '</td>'
            + '<td class="truncated-url" title="' + escapeHtml(row.request_url) + '">' + escapeHtml(row.request_url) + '</td>'
            + '<td>' + statusBadge(row.response_code) + '</td>'
            + '<td class="' + latencyClass((Number(row.total_time) || 0) * 1000) + '">' + escapeHtml(formatMs(row.total_time)) + '</td>'
            + '<td>' + escapeHtml(memory) + '</td>'
            + '<td>' + escapeHtml(row.host || '') + '</td>'
            + '<td>' + (Number(row.is_bot) === 1 ? '<span class="badge bg-warning text-dark">Yes</span>' : '<span class="badge bg-secondary">No</span>') + '</td>'
            + '<td><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#' + id + '" data-request-id="' + escapeHtml(row.id) + '">Details</button></td>'
            + '</tr><tr class="collapse details-row" id="' + id + '"><td colspan="9"><div class="p-3 detail-panel">Loading…</div></td></tr>';
    }).join('');
    body.querySelectorAll('button[data-request-id]').forEach((button) => {
        button.addEventListener('click', () => loadDetails(button));
    });
}

async function loadDetails(button) {
    const row = document.querySelector(button.dataset.bsTarget);
    const panel = row.querySelector('.detail-panel');
    if (panel.dataset.loaded === '1') {
        return;
    }
    panel.textContent = 'Loading…';
    try {
        const response = await fetch('/apm/data/requests/' + button.dataset.requestId);
        if (!response.ok) {
            throw new Error('Could not load this request.');
        }
        const data = await response.json();
        panel.innerHTML = detailHtml(data);
        panel.dataset.loaded = '1';
    } catch (error) {
        panel.textContent = error.message;
    }
}

function updatePagination(pagination) {
    currentPage = pagination.currentPage || 1;
    totalPages = pagination.totalPages || 1;
    const total = pagination.totalRequests || 0;
    document.getElementById('pagination-info').textContent = total
        ? 'Page ' + currentPage + ' of ' + totalPages + ' · ' + total + ' requests'
        : '';
    document.getElementById('prev-page').classList.toggle('disabled', currentPage <= 1);
    document.getElementById('next-page').classList.toggle('disabled', currentPage >= totalPages);
}

function renderActiveFilters() {
    const wrap = document.getElementById('active-filters');
    const list = document.getElementById('active-filters-list');
    const entries = Object.entries(activeFilters);
    wrap.classList.toggle('d-none', entries.length === 0);
    list.innerHTML = '';
    entries.forEach(([key, value]) => {
        const badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'badge bg-info text-dark border-0 me-1';
        const label = key === 'event_keys' ? 'event data' : key + ': ' + (typeof value === 'string' ? value : 'set');
        badge.textContent = label + ' ×';
        badge.addEventListener('click', () => {
            delete activeFilters[key];
            const field = {
                url: 'filter-url',
                request_id: 'filter-request-id',
                is_bot: 'filter-bot',
                min_time: 'filter-min-time',
                route: 'filter-route',
                ip: 'filter-ip',
                host: 'filter-host',
                session_id: 'filter-session-id',
                user_agent: 'filter-user-agent',
                custom_event_type: 'filter-custom-event',
                error_message: 'filter-error',
                response_code: 'filter-exact-code',
                response_code_prefix: 'filter-response-code'
            }[key];
            if (field) {
                document.getElementById(field).value = '';
            }
            if (key === 'response_code' || key === 'response_code_prefix') {
                document.getElementById('filter-response-code').value = '';
            }
            if (key === 'event_keys') {
                document.getElementById('event-filters-container').innerHTML = '';
            }
            currentPage = 1;
            captureFilters();
            loadData();
        });
        list.appendChild(badge);
    });
}

function addEventFilterRow(key = '', operator = 'exact', value = '') {
    const template = document.getElementById('event-filter-template');
    const row = template.content.firstElementChild.cloneNode(true);
    const select = row.querySelector('.event-key-select');
    availableEventKeys.forEach((eventKey) => {
        const option = document.createElement('option');
        option.value = eventKey;
        option.textContent = eventKey;
        select.appendChild(option);
    });
    select.value = key;
    row.querySelector('.event-operator-select').value = operator;
    row.querySelector('.event-value-input').value = value;
    row.querySelector('.remove-event-filter').addEventListener('click', () => {
        row.remove();
        document.getElementById('no-event-filters-msg').classList.toggle(
            'd-none',
            document.querySelectorAll('.event-filter-row').length > 0
        );
    });
    document.getElementById('event-filters-container').appendChild(row);
    document.getElementById('no-event-filters-msg').classList.add('d-none');
}

async function loadEventKeys() {
    const response = await fetch('/apm/data/event-keys?' + queryParams().toString());
    if (!response.ok) {
        return;
    }
    const data = await response.json();
    availableEventKeys = data.event_keys || [];
    if (Array.isArray(activeFilters.event_keys)) {
        activeFilters.event_keys.forEach((filter) => addEventFilterRow(filter.key, filter.operator, filter.value));
    }
}

themeToggle.addEventListener('click', () => {
    isDarkMode = !isDarkMode;
    document.documentElement.setAttribute('data-theme', isDarkMode ? 'dark' : '');
    themeToggle.innerHTML = isDarkMode
        ? '<i class="bi bi-sun"></i> Light mode'
        : '<i class="bi bi-moon-stars"></i> Dark mode';
    localStorage.setItem('darkMode', isDarkMode);
    if (lastDashboard) {
        drawCharts(lastDashboard);
    }
});

rangeSelector.addEventListener('change', () => {
    toggleCustomRange();
    if (rangeSelector.value !== 'custom') {
        currentPage = 1;
        loadData();
    }
});
rangeFrom.addEventListener('change', () => {
    if (rangeSelector.value === 'custom') {
        currentPage = 1;
        loadData();
    }
});
rangeUntil.addEventListener('change', () => {
    if (rangeSelector.value === 'custom') {
        currentPage = 1;
        loadData();
    }
});
timezoneSelector.addEventListener('change', () => {
    selectedTimezone = timezoneSelector.value;
    localStorage.setItem('selectedTimezone', selectedTimezone);
    syncUrl();
    if (lastDashboard) {
        drawCharts(lastDashboard);
        loadData();
    }
});
excludeBots.addEventListener('change', () => {
    currentPage = 1;
    loadData();
});

document.getElementById('filter-response-code').addEventListener('change', (event) => {
    document.getElementById('exact-code-container').classList.toggle('d-none', event.target.value !== 'exact');
});
document.getElementById('apply-filters').addEventListener('click', () => {
    currentPage = 1;
    captureFilters();
    loadData();
});
document.getElementById('clear-filters').addEventListener('click', () => {
    ['filter-url', 'filter-request-id', 'filter-min-time', 'filter-route', 'filter-ip', 'filter-host', 'filter-session-id', 'filter-user-agent', 'filter-custom-event', 'filter-error', 'filter-exact-code'].forEach((id) => {
        document.getElementById(id).value = '';
    });
    document.getElementById('filter-bot').value = '';
    document.getElementById('filter-response-code').value = '';
    document.getElementById('exact-code-container').classList.add('d-none');
    document.getElementById('event-filters-container').innerHTML = '';
    document.getElementById('no-event-filters-msg').classList.remove('d-none');
    activeFilters = {};
    currentPage = 1;
    loadData();
});
document.getElementById('add-event-filter').addEventListener('click', () => addEventFilterRow());
document.getElementById('prev-page').addEventListener('click', (event) => {
    event.preventDefault();
    if (currentPage > 1) {
        currentPage -= 1;
        loadData();
    }
});
document.getElementById('next-page').addEventListener('click', (event) => {
    event.preventDefault();
    if (currentPage < totalPages) {
        currentPage += 1;
        loadData();
    }
});

readUrl();
loadEventKeys();
loadData();
