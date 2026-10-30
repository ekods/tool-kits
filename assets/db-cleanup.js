(function () {
    'use strict';
    const form = document.getElementById('tk-db-cleanup-form');
    const panel = document.getElementById('tk-db-cleanup-progress');
    if (!form || !panel) return;
    let job = JSON.parse(panel.dataset.job || '{}');
    let busy = false;
    let running = false;
    let pending = '';
    const status = panel.querySelector('.tk-cleanup-status');
    const counts = panel.querySelector('.tk-cleanup-counts');
    const submit = form.querySelector('button[type=submit], button.button-primary');
    function render() {
        const active = ['running', 'paused'].includes(job.status);
        const own = Number(job.owner) === Number(panel.dataset.owner);
        status.textContent = job.status ? 'Cleanup ' + job.status + '. Stage ' + Math.min(job.index + 1, job.tasks.length) + '/' + job.tasks.length +
            (job.tasks[job.index] ? ': ' + job.tasks[job.index].replaceAll('_', ' ') : '') +
            (job.error ? '. ' + job.error : '') + (active && !own ? '. Managed by another administrator.' : '') : '';
        counts.textContent = Object.entries(job.counts || {}).map(([key, value]) => key.replaceAll('_', ' ') + ': ' + value).join('\n');
        submit.disabled = busy || active;
        panel.querySelectorAll('[data-command]').forEach(button => {
            button.hidden = !active || !own || (button.dataset.command === 'pause' ? !running : button.dataset.command === 'resume' ? running : false);
            button.style.display = button.hidden ? 'none' : '';
            button.disabled = !!pending;
        });
    }
    async function request(command) {
        const data = command === 'start' ? new FormData(form) : new FormData();
        data.set('action', 'tk_db_cleanup_job');
        data.set('_tk_nonce', form.elements._tk_nonce.value);
        data.set('command', command);
        data.set('job_id', job.id || '');
        const response = await fetch(panel.dataset.url, {method: 'POST', credentials: 'same-origin', body: data});
        const result = await response.json();
        if (!result.success) throw new Error(result.data && result.data.message || 'Cleanup request failed. Reload to check its saved state.');
        job = result.data || {};
    }
    async function process(command) {
        if (busy) { pending = command; running = false; render(); return; }
        busy = true;
        let failure = '';
        try {
            await request(command);
            running = job.status === 'running' && !pending;
        } catch (error) {
            running = false;
            failure = error.message + ' No further batches will be requested. Reload to resume from saved progress.';
        } finally {
            busy = false;
            if (pending) { const next = pending; pending = ''; process(next); }
            else if (running) { render(); setTimeout(() => { if (running && !busy) process('step'); }, 150); }
            else {
                render();
                if (failure) status.textContent = failure;
            }
        }
    }
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (busy || !window.confirm('Database pruning permanently deletes selected data. Have you backed up the database?')) return;
        running = true;
        process('start');
    });
    panel.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
        if (button.dataset.command === 'cancel' && !window.confirm('Cancel remaining cleanup work? Data already deleted cannot be restored here.')) return;
        running = false;
        process(button.dataset.command);
    }));
    render();
}());
