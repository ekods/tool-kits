(function(){
    'use strict';

    console.log('ToolKits: Monitoring Tabs JS Loaded');

    var intervalId     = null;
    var container      = null;
    var content        = null;
    var cpuHistory     = [];   // load values
    var cpuTimestamps  = [];   // matching timestamps (ms)
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

    function drawCpuChart(cpuColor, maxLoad) {
        var n = cpuHistory.length;
        if (n === 0) return;

        var chartLine = document.getElementById('tk-rt-cpu-line');
        var chartArea = document.getElementById('tk-rt-cpu-area');
        if (!chartLine || !chartArea) return;

        var svgEl = document.getElementById('tk-rt-cpu-chart');
        if (svgEl) { svgEl.setAttribute('viewBox', '0 0 100 100'); }

        var points = [];
        for (var i = 0; i < n; i++) {
            var px = i * STEP_X;
            var py = 100 - (cpuHistory[i] / maxLoad) * 100;
            py = Math.max(0, Math.min(100, py));
            points.push(px.toFixed(3) + ',' + py.toFixed(3));
        }

        var leftX  = 0;
        var rightX = (n - 1) * STEP_X;
        var ptsStr = points.join(' ');

        chartLine.setAttribute('points', ptsStr);
        chartLine.setAttribute('stroke', cpuColor);

        // Area polygon: close under the actual drawn data, not the full chart.
        chartArea.setAttribute('points', ptsStr + ' ' + rightX.toFixed(3) + ',100 ' + leftX.toFixed(3) + ',100');

        // Gradient top color
        var gradTop = document.getElementById('tk-cpu-grad-top');
        if (gradTop) { gradTop.setAttribute('stop-color', cpuColor); }

        var dotTopPct = 100 - (cpuHistory[n - 1] / maxLoad) * 100;
        dotTopPct = Math.max(2, Math.min(98, dotTopPct));
        var dotLeftPct = Math.max(2, Math.min(98, rightX));
        var chartDot  = document.getElementById('tk-rt-cpu-dot');
        if (chartDot) {
            chartDot.style.left       = dotLeftPct.toFixed(2) + '%';
            chartDot.style.top        = dotTopPct.toFixed(2) + '%';
            chartDot.style.background = cpuColor;
        }

        // Zone bands (% height from bottom)
        var pctGreenH  = (2 / maxLoad) * 100;
        var pctYellowH = (4 / maxLoad) * 100;
        var zG = document.getElementById('tk-rt-cpu-zone-green');
        var zY = document.getElementById('tk-rt-cpu-zone-yellow');
        var zR = document.getElementById('tk-rt-cpu-zone-red');
        pctGreenH = Math.max(0, Math.min(100, pctGreenH));
        pctYellowH = Math.max(pctGreenH, Math.min(100, pctYellowH));
        if (zG) { zG.style.height = pctGreenH + '%'; zG.style.bottom = '0'; }
        if (zY) { zY.style.height = (pctYellowH - pctGreenH) + '%'; zY.style.bottom = pctGreenH + '%'; }
        if (zR) { zR.style.height = (100 - pctYellowH) + '%'; zR.style.bottom = pctYellowH + '%'; }

        // Y-axis labels
        var yMax = document.getElementById('tk-rt-cpu-y-max');
        var yMid = document.getElementById('tk-rt-cpu-y-mid');
        if (yMax) { yMax.textContent = maxLoad.toFixed(1); }
        if (yMid) { yMid.textContent = (maxLoad / 2).toFixed(1); }

        // X-axis time labels: oldest / middle / newest
        var xOld = document.getElementById('tk-rt-cpu-x-old');
        var xMid = document.getElementById('tk-rt-cpu-x-mid');
        var xNow = document.getElementById('tk-rt-cpu-x-now');
        if (cpuTimestamps.length > 0) {
            if (xOld) { xOld.textContent = formatTime(cpuTimestamps[0]); }
            if (xMid) {
                var midIdx = Math.floor((cpuTimestamps.length - 1) / 2);
                xMid.textContent = formatTime(cpuTimestamps[midIdx]);
            }
            if (xNow) { xNow.textContent = formatTime(cpuTimestamps[cpuTimestamps.length - 1]); }
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
            void pulseEl.offsetHeight;
            pulseEl.style.animation = null;
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
                    var cpuColor = load1m > 4 ? '#e74c3c' : (load1m > 2 ? '#f39c12' : '#27ae60');

                    cpuEl.textContent = load1m.toFixed(2);
                    cpuEl.style.color = cpuColor;

                    if (cpuBarEl) {
                        var pct = Math.min(100, Math.round((load1m / 4) * 100));
                        cpuBarEl.style.width = pct + '%';
                        cpuBarEl.style.background = load1m > 4 ? '#e74c3c' : (load1m > 2 ? '#f39c12' : 'linear-gradient(90deg, #27ae60, #2ecc71)');
                    }

                    // Push to circular history buffer
                    cpuHistory.push(load1m);
                    cpuTimestamps.push(Date.now());
                    if (cpuHistory.length > maxHistoryPoints) { cpuHistory.shift(); cpuTimestamps.shift(); }

                    var maxLoad = 4;
                    for (var hi = 0; hi < cpuHistory.length; hi++) {
                        if (cpuHistory[hi] > maxLoad) maxLoad = Math.ceil(cpuHistory[hi]);
                    }

                    drawCpuChart(cpuColor, maxLoad);
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
