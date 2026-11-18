(function(){
    'use strict';

    var intervalId     = null;
    var pendingRequest = null;
    var storageKey = 'tk-active-tab:tool-kits-monitoring';
    var container      = null;
    var content        = null;
    var hasData        = false;

    function isRealtimeActive() {
        if (!content) return false;
        var panel = content.querySelector('[data-panel-id="realtime"]');
        return panel && panel.classList.contains('is-active') && !document.hidden;
    }

    function activateTab(panelId) {
        if (!validTab(panelId)) return;

        var panels = document.querySelectorAll('#tk-monitoring-tabs-content [data-panel-id]');
        var buttons = document.querySelectorAll('#tk-monitoring-tabs .tk-tabs-nav-button');

        panels.forEach(function(p){
            if (p.getAttribute('data-panel-id') === panelId) {
                p.classList.add('is-active');
                p.style.display = 'block';
            } else {
                p.classList.remove('is-active');
                p.style.display = 'none';
            }
        });

        buttons.forEach(function(b){
            if (b.getAttribute('data-panel') === panelId) {
                b.classList.add('is-active');
            } else {
                b.classList.remove('is-active');
            }
        });

        try {
            window.history.replaceState(null, '', '#' + panelId);
            window.sessionStorage.setItem(storageKey, panelId);
        } catch (e) {}
        if (panelId === 'realtime') {
            startPolling();
        } else {
            stopPolling();
        }
    }

    function validTab(id) {
        return Array.from(content.querySelectorAll('[data-panel-id]')).some(function(panel) {
            return panel.getAttribute('data-panel-id') === id;
        });
    }

    function status(message, failed) {
        var label = document.getElementById('tk-rt-status');
        var retry = document.getElementById('tk-rt-retry');
        if (label) label.textContent = message;
        if (retry) retry.hidden = !failed;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '-';
        var units = ['B','KB','MB','GB'];
        var i = 0;
        var val = bytes;
        while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
        return val.toFixed(1) + ' ' + units[i];
    }

    function formatTime(ts) {
        var d = new Date(ts);
        var h = d.getHours().toString().padStart(2,'0');
        var m = d.getMinutes().toString().padStart(2,'0');
        var s = d.getSeconds().toString().padStart(2,'0');
        return h + ':' + m + ':' + s;
    }

    function fetchHealth() {
        if (!isRealtimeActive()) { stopPolling(); return; }
        if (pendingRequest) return;
        intervalId = null;
        var request = { controller: new AbortController(), cancelled: false };
        pendingRequest = request;
        var timeout = setTimeout(function() { request.controller.abort(); }, 15000);
        status(hasData ? 'Updating metrics...' : 'Loading metrics...', false);

        var rttEl     = document.getElementById('tk-rt-rtt');
        var memEl     = document.getElementById('tk-rt-mem');
        var memBarEl  = document.getElementById('tk-rt-mem-bar');
        var errEl     = document.getElementById('tk-rt-errors');
        var objectEl  = document.getElementById('tk-rt-object-cache');
        var redisEl   = document.getElementById('tk-rt-redis');
        var pluginsEl = document.getElementById('tk-rt-plugins');
        var pulseEl   = document.getElementById('tk-rt-pulse');

        var start = Date.now();
        var data  = new URLSearchParams();
        data.append('action', 'tk_realtime_health');
        data.append('nonce', window.tkMonitoringData ? window.tkMonitoringData.nonce : '');

        if (pulseEl) {
            pulseEl.style.animation = 'none';
            requestAnimationFrame(function() {
                pulseEl.style.animation = '';
            });
        }

        fetch((window.tkMonitoringData && window.tkMonitoringData.ajaxurl) || window.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: data.toString(),
            signal: request.controller.signal
        }).then(function(resp){
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function(res){
            if (request.cancelled || !isRealtimeActive()) return;
            if (!res || !res.success || !res.data || typeof res.data !== 'object') throw new Error('Invalid metrics response');
            if (rttEl) {
                var rtt = Date.now() - start;
                rttEl.textContent = rtt + ' ms';
                rttEl.style.color = rtt > 800 ? '#e74c3c' : (rtt > 300 ? '#f39c12' : '#27ae60');
            }
            var d = res.data;

            // ── CPU Load ──────────────────────────────────────────────────────
            var cpuEl    = document.getElementById('tk-rt-cpu');
            var cpuBarEl = document.getElementById('tk-rt-cpu-bar');
            if (cpuEl) {
                if (Array.isArray(d.load) && Number.isFinite(d.load[0])) {
                    var load1m   = d.load[0];
                    var cpuCores = d.cpu_cores && d.cpu_cores > 0 ? parseInt(d.cpu_cores, 10) : 0;
                    var hasCpuCapacity = cpuCores > 0;
                    var capacityLoad = hasCpuCapacity ? Math.max(1, cpuCores) : 0;
                    var midLoad = hasCpuCapacity ? capacityLoad * 0.5 : 0;
                    var loadScale = Math.max(4, Math.ceil(load1m));
                    var cpuColor = hasCpuCapacity
                        ? (load1m >= capacityLoad ? '#e74c3c' : (load1m >= midLoad ? '#f39c12' : '#27ae60'))
                        : '#6d4aff';

                    cpuEl.textContent = hasCpuCapacity ? (load1m.toFixed(2) + ' / ' + capacityLoad.toFixed(1)) : load1m.toFixed(2);
                    cpuEl.style.color = cpuColor;
                    cpuEl.title = hasCpuCapacity
                        ? (cpuCores + ' CPU core(s). Load ' + capacityLoad.toFixed(1) + ' is full capacity.')
                        : 'CPU core count is not available from this hosting environment.';

                    if (cpuBarEl) {
                        var barMax = hasCpuCapacity ? capacityLoad : loadScale;
                        var pct = Math.min(100, Math.round((load1m / barMax) * 100));
                        cpuBarEl.style.width = pct + '%';
                        cpuBarEl.style.background = hasCpuCapacity && load1m >= capacityLoad ? '#e74c3c' : (hasCpuCapacity && load1m >= midLoad ? '#f39c12' : 'linear-gradient(90deg, #27ae60, #2ecc71)');
                    }
                } else {
                    cpuEl.textContent = 'N/A';
                    cpuEl.style.color = '#94a3b8';
                    cpuEl.title = 'sys_getloadavg() is not available';
                    if (cpuBarEl) { cpuBarEl.style.width = '0%'; }
                }
            }

            // ── Memory ────────────────────────────────────────────────────────
            if (memEl) memEl.textContent = formatBytes(d.memory ? d.memory.used : 0);
            if (memBarEl && d.memory && d.memory.percent !== undefined) {
                memBarEl.style.width = d.memory.percent + '%';
                memBarEl.style.background = d.memory.percent > 80 ? '#e74c3c' : (d.memory.percent > 50 ? '#f39c12' : 'linear-gradient(90deg, #1d4ed8, #60a5fa)');
            }

            // ── Error Rate ────────────────────────────────────────────────────
            if (errEl) {
                var err = d.errors || {};
                if (err.available === false) {
                    errEl.textContent = 'Log Off';
                } else {
                    var rate = err.per_min || 0;
                    errEl.textContent = rate + '/min';
                    errEl.style.color = rate > 5 ? '#e74c3c' : (rate > 0 ? '#f39c12' : 'inherit');
                }
            }

            // ── Cache ─────────────────────────────────────────────────────────
            if (objectEl) {
                var objS = d.cache ? d.cache.object : 'unknown';
                objectEl.textContent = objS === 'configured' ? 'Active' : (objS === 'off' ? 'Inactive' : 'Unknown');
                objectEl.style.color = objS === 'configured' ? '#27ae60' : '#94a3b8';
            }
            if (redisEl) {
                var redS = d.cache ? d.cache.redis : 'unknown';
                redisEl.textContent = redS === 'configured' ? 'Active' : (redS === 'off' ? 'Inactive' : 'Unknown');
                redisEl.style.color = redS === 'configured' ? '#27ae60' : '#94a3b8';
            }

            // ── Heaviest Plugins ──────────────────────────────────────────────
            if (pluginsEl) {
                pluginsEl.innerHTML = '';
                (d.heavy_plugins || []).forEach(function(item){
                    if (!item || !item.name) return;
                    var li = document.createElement('li');
                    li.textContent = item.name + ': ' + formatBytes(item.size);
                    pluginsEl.appendChild(li);
                });
            }
            hasData = true;
            status('Updated ' + formatTime(Date.now()), false);
        }).catch(function(){
            if (request.cancelled || !isRealtimeActive()) return;
            if (rttEl) rttEl.textContent = 'Failed';
            status('Metrics could not be updated. Retrying shortly.', true);
        }).finally(function() {
            clearTimeout(timeout);
            pendingRequest = null;
            if (isRealtimeActive()) intervalId = setTimeout(fetchHealth, request.cancelled ? 0 : 15000);
        });
    }

    function startPolling() {
        if (!isRealtimeActive() || intervalId !== null || pendingRequest) return;
        // Allow the loading state to paint before starting the first request.
        intervalId = setTimeout(fetchHealth, 80);
    }

    function stopPolling() {
        clearTimeout(intervalId);
        intervalId = null;
        if (pendingRequest) {
            pendingRequest.cancelled = true;
            pendingRequest.controller.abort();
        }
    }

    function init() {
        container = document.getElementById('tk-monitoring-tabs');
        content   = document.getElementById('tk-monitoring-tabs-content');

        if (!container || !content) return;

        var nav = container.querySelector('.tk-tabs-nav');
        if (nav) {
            nav.addEventListener('click', function(e){
                var btn = e.target.closest('.tk-tabs-nav-button');
                if (btn) {
                    e.preventDefault();
                    var panelId = btn.getAttribute('data-panel');
                    activateTab(panelId);
                }
            });
        }

        var hash = window.location.hash.replace('#', '');
        var saved = '';
        try { saved = window.sessionStorage.getItem(storageKey); } catch (e) {}
        activateTab(validTab(hash) ? hash : (validTab(saved) ? saved : 'realtime'));
        window.addEventListener('hashchange', function() {
            var id = window.location.hash.slice(1);
            activateTab(validTab(id) ? id : 'realtime');
        });
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) stopPolling(); else startPolling();
        });
        window.addEventListener('pagehide', stopPolling);
        window.addEventListener('pageshow', startPolling);
        var retry = document.getElementById('tk-rt-retry');
        if (retry) retry.addEventListener('click', function() {
            clearTimeout(intervalId);
            intervalId = null;
            startPolling();
        });

        function measureLoad() {
            var loadEl = document.getElementById('tk-rt-load');
            if (loadEl && window.performance) {
                var entry = performance.getEntriesByType('navigation')[0];
                var loadTime = entry ? entry.loadEventEnd : 0;
                if (loadTime > 0) {
                    loadEl.textContent = (loadTime / 1000).toFixed(2) + ' s';
                    loadEl.style.color = loadTime > 3000 ? '#e74c3c' : (loadTime > 1500 ? '#f39c12' : '#27ae60');
                }
            }
        }
        if (document.readyState === 'complete') setTimeout(measureLoad, 0);
        else window.addEventListener('load', function() { setTimeout(measureLoad, 0); }, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
