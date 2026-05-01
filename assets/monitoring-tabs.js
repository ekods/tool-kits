(function(){
    'use strict';

    console.log('ToolKits: Monitoring Tabs JS Loaded');

    var intervalId     = null;
    var container      = null;
    var content        = null;
    var cpuHistory     = [];   // CPU chart values: percent when capacity is known, load otherwise
    var memHistory     = [];   // memory used in MB
    var cpuTimestamps  = [];   // matching timestamps (ms)
    var memTimestamps  = [];   // matching timestamps (ms)
    var maxHistoryPoints = 30;

    // Fixed slot width across the retained history window.
    // New samples are appended left-to-right; once the buffer is full,
    // the oldest sample is dropped and the chart keeps the same scale.
    var STEP_X = 100 / (maxHistoryPoints - 1);

    function isRealtimeActive() {
        if (!content) return false;
        var panel = content.querySelector('[data-panel-id="realtime"]');
        return panel && panel.classList.contains('is-active');
    }

    function activateTab(panelId) {
        if (!panelId) return;

        console.log('ToolKits: Activating panel ->', panelId);

        var panels = document.querySelectorAll('#tk-monitoring-tabs-content [data-panel-id]');
        var buttons = document.querySelectorAll('#tk-monitoring-tabs .tk-tabs-nav-button');

        console.log('ToolKits: Found', panels.length, 'panels and', buttons.length, 'buttons');

        var found = false;
        panels.forEach(function(p){
            if (p.getAttribute('data-panel-id') === panelId) {
                p.classList.add('is-active');
                p.style.display = 'block';
                found = true;
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

        if (!found) { console.warn('ToolKits: Panel not found in DOM ->', panelId); }
        window.location.hash = panelId;
        if (panelId === 'realtime') { startPolling(); } else { stopPolling(); }
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

    function formatMb(value) {
        if (!value || value <= 0) return '0 MB';
        return Math.round(value).toLocaleString() + ' MB';
    }

    function average(values) {
        if (!values.length) return 0;
        var total = 0;
        values.forEach(function(value){ total += value; });
        return total / values.length;
    }

    function drawLineChart(config) {
        var history = config.history || [];
        var timestamps = config.timestamps || [];
        var maxValue = config.maxValue || 100;
        var n = history.length;
        if (n === 0) return;

        var chartLine = document.getElementById(config.lineId);
        if (!chartLine) return;

        var svgEl = document.getElementById(config.svgId);
        if (svgEl) { svgEl.setAttribute('viewBox', '0 0 100 100'); }

        var points = [];
        for (var i = 0; i < n; i++) {
            var px = i * STEP_X;
            var py = 100 - (history[i] / maxValue) * 100;
            py = Math.max(0, Math.min(100, py));
            points.push(px.toFixed(3) + ',' + py.toFixed(3));
        }

        chartLine.setAttribute('points', points.join(' '));
        chartLine.setAttribute('stroke', config.color || '#6d4aff');

        if (timestamps.length > 0) {
            var xOld = document.getElementById(config.xOldId);
            var xNow = document.getElementById(config.xNowId);
            if (xOld) { xOld.textContent = formatTime(timestamps[0]); }
            if (xNow) { xNow.textContent = formatTime(timestamps[timestamps.length - 1]); }
        }
    }

    function fetchHealth() {
        if (!isRealtimeActive()) { stopPolling(); return; }

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

        fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: data.toString()
        }).then(function(resp){ return resp.json(); }).then(function(res){
            if (rttEl) {
                var rtt = Date.now() - start;
                rttEl.textContent = rtt + ' ms';
                rttEl.style.color = rtt > 800 ? '#e74c3c' : (rtt > 300 ? '#f39c12' : '#27ae60');
            }
            if (!res || !res.success || !res.data) return;
            var d = res.data;

            // ── CPU Load ──────────────────────────────────────────────────────
            var cpuEl    = document.getElementById('tk-rt-cpu');
            var cpuBarEl = document.getElementById('tk-rt-cpu-bar');
            if (cpuEl) {
                if (d.load && d.load.length > 0) {
                    var load1m   = d.load[0];
                    var cpuCores = d.cpu_cores && d.cpu_cores > 0 ? parseInt(d.cpu_cores, 10) : 0;
                    var hasCpuCapacity = cpuCores > 0;
                    var capacityLoad = hasCpuCapacity ? Math.max(1, cpuCores) : 0;
                    var midLoad = hasCpuCapacity ? capacityLoad * 0.5 : 0;
                    var cpuChartValue = hasCpuCapacity ? Math.min(100, Math.round((load1m / capacityLoad) * 100)) : load1m;
                    var chartMax = hasCpuCapacity ? 100 : Math.max(4, Math.ceil(load1m));
                    var cpuColor = hasCpuCapacity
                        ? (load1m >= capacityLoad ? '#e74c3c' : (load1m >= midLoad ? '#f39c12' : '#27ae60'))
                        : '#6d4aff';

                    cpuEl.textContent = hasCpuCapacity ? (load1m.toFixed(2) + ' / ' + capacityLoad.toFixed(1)) : load1m.toFixed(2);
                    cpuEl.style.color = cpuColor;
                    cpuEl.title = hasCpuCapacity
                        ? (cpuCores + ' CPU core(s). Load ' + capacityLoad.toFixed(1) + ' is the capacity line.')
                        : 'CPU core count is not available from this hosting environment.';

                    if (cpuBarEl) {
                        var barMax = hasCpuCapacity ? capacityLoad : chartMax;
                        var pct = Math.min(100, Math.round((load1m / barMax) * 100));
                        cpuBarEl.style.width = pct + '%';
                        cpuBarEl.style.background = hasCpuCapacity && load1m >= capacityLoad ? '#e74c3c' : (hasCpuCapacity && load1m >= midLoad ? '#f39c12' : 'linear-gradient(90deg, #27ae60, #2ecc71)');
                    }

                    cpuHistory.push(cpuChartValue);
                    cpuTimestamps.push(Date.now());
                    if (cpuHistory.length > maxHistoryPoints) { cpuHistory.shift(); cpuTimestamps.shift(); }

                    var cpuAvgEl = document.getElementById('tk-rt-cpu-avg');
                    var cpuLimitEl = document.getElementById('tk-rt-cpu-limit');
                    var cpuLimitLine = document.getElementById('tk-rt-cpu-limit-line');
                    var cpuLimitLegend = document.getElementById('tk-rt-cpu-limit-legend');
                    var cpuChartMaxEl = document.getElementById('tk-rt-cpu-chart-max');
                    var cpuChartMidEl = document.getElementById('tk-rt-cpu-chart-mid');
                    var cpuChartZeroEl = document.getElementById('tk-rt-cpu-chart-zero');
                    if (hasCpuCapacity) {
                        if (cpuAvgEl) { cpuAvgEl.textContent = Math.round(average(cpuHistory)) + '%'; }
                        if (cpuLimitEl) { cpuLimitEl.textContent = '100%'; }
                        if (cpuLimitLine) { cpuLimitLine.style.display = 'block'; }
                        if (cpuLimitLegend) { cpuLimitLegend.style.display = 'flex'; }
                        if (cpuChartMaxEl) { cpuChartMaxEl.textContent = '100%'; }
                        if (cpuChartMidEl) { cpuChartMidEl.textContent = '50%'; }
                        if (cpuChartZeroEl) { cpuChartZeroEl.textContent = '0%'; }
                    } else {
                        for (var chi = 0; chi < cpuHistory.length; chi++) {
                            if (cpuHistory[chi] > chartMax) chartMax = Math.ceil(cpuHistory[chi]);
                        }
                        if (cpuAvgEl) { cpuAvgEl.textContent = average(cpuHistory).toFixed(2); }
                        if (cpuLimitEl) { cpuLimitEl.textContent = '-'; }
                        if (cpuLimitLine) { cpuLimitLine.style.display = 'none'; }
                        if (cpuLimitLegend) { cpuLimitLegend.style.display = 'none'; }
                        if (cpuChartMaxEl) { cpuChartMaxEl.textContent = chartMax.toFixed(1); }
                        if (cpuChartMidEl) { cpuChartMidEl.textContent = (chartMax / 2).toFixed(1); }
                        if (cpuChartZeroEl) { cpuChartZeroEl.textContent = '0'; }
                    }

                    drawLineChart({
                        history: cpuHistory,
                        timestamps: cpuTimestamps,
                        maxValue: chartMax,
                        svgId: 'tk-rt-cpu-chart',
                        lineId: 'tk-rt-cpu-line',
                        xOldId: 'tk-rt-cpu-x-old',
                        xNowId: 'tk-rt-cpu-x-now',
                        color: '#6d4aff'
                    });
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
            if (d.memory) {
                var memUsedMb = d.memory.used ? d.memory.used / 1048576 : 0;
                var memLimitMb = d.memory.limit ? d.memory.limit / 1048576 : 0;
                var memChartMax = memLimitMb > 0 ? Math.ceil(memLimitMb / 1000) * 1000 : Math.max(128, Math.ceil(memUsedMb / 128) * 128);
                if (memChartMax <= 0) memChartMax = 128;

                memHistory.push(memUsedMb);
                memTimestamps.push(Date.now());
                if (memHistory.length > maxHistoryPoints) { memHistory.shift(); memTimestamps.shift(); }
                for (var mhi = 0; mhi < memHistory.length; mhi++) {
                    if (memHistory[mhi] > memChartMax) memChartMax = Math.ceil(memHistory[mhi] / 128) * 128;
                }

                var memAvgEl = document.getElementById('tk-rt-mem-avg');
                var memLimitEl = document.getElementById('tk-rt-mem-limit');
                var memMaxEl = document.getElementById('tk-rt-mem-chart-max');
                var memMidEl = document.getElementById('tk-rt-mem-chart-mid');
                var memLimitLine = document.getElementById('tk-rt-mem-limit-line');
                if (memAvgEl) { memAvgEl.textContent = formatMb(average(memHistory)); }
                if (memLimitEl) { memLimitEl.textContent = memLimitMb > 0 ? formatMb(memLimitMb) : '-'; }
                if (memMaxEl) { memMaxEl.textContent = formatMb(memChartMax); }
                if (memMidEl) { memMidEl.textContent = formatMb(memChartMax / 2); }
                if (memLimitLine && memLimitMb > 0) {
                    memLimitLine.style.display = 'block';
                    memLimitLine.style.top = Math.max(0, Math.min(100, 100 - (memLimitMb / memChartMax * 100))).toFixed(2) + '%';
                } else if (memLimitLine) {
                    memLimitLine.style.display = 'none';
                }

                drawLineChart({
                    history: memHistory,
                    timestamps: memTimestamps,
                    maxValue: memChartMax,
                    svgId: 'tk-rt-mem-chart',
                    lineId: 'tk-rt-mem-line',
                    xOldId: 'tk-rt-mem-x-old',
                    xNowId: 'tk-rt-mem-x-now',
                    color: '#6d4aff'
                });
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
                    li.innerHTML = '<strong>' + item.name + '</strong>: ' + formatBytes(item.size);
                    pluginsEl.appendChild(li);
                });
            }
        }).catch(function(){
            if (rttEl) rttEl.textContent = 'Failed';
        });
    }

    function startPolling() {
        if (intervalId !== null) return;
        fetchHealth();
        intervalId = setInterval(fetchHealth, 5000);
    }

    function stopPolling() {
        if (intervalId === null) return;
        clearInterval(intervalId);
        intervalId = null;
    }

    function init() {
        container = document.getElementById('tk-monitoring-tabs');
        content   = document.getElementById('tk-monitoring-tabs-content');

        if (!container) { console.warn('ToolKits: #tk-monitoring-tabs not found'); return; }
        console.log('ToolKits: Monitoring initialized');

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
        if (hash) {
            activateTab(hash);
        } else {
            var activeBtn = container.querySelector('.tk-tabs-nav-button.is-active');
            if (activeBtn) {
                var panelId = activeBtn.getAttribute('data-panel');
                if (panelId === 'realtime') startPolling();
            }
        }

        // Measure Load Time using Performance API
        setTimeout(function() {
            var loadEl = document.getElementById('tk-rt-load');
            if (loadEl && window.performance && window.performance.timing) {
                var t = window.performance.timing;
                var loadTime = t.loadEventEnd - t.navigationStart;
                if (loadTime > 0) {
                    loadEl.textContent = (loadTime / 1000).toFixed(2) + ' s';
                    loadEl.style.color = loadTime > 3000 ? '#e74c3c' : (loadTime > 1500 ? '#f39c12' : '#27ae60');
                }
            }
        }, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
