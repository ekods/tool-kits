(function () {
    'use strict';

    // Head code / Body code tabs. Progressive: without this script both fields stay visible, one after the other.
    function initializeTabs() {
        document.querySelectorAll('[data-tk-analytics-tabs]').forEach(function (wrapper) {
            if (wrapper.dataset.tkTabsReady) return;
            var tabs = Array.prototype.slice.call(wrapper.querySelectorAll('[role="tab"]'));
            if (!tabs.length) return;
            wrapper.dataset.tkTabsReady = '1';
            wrapper.classList.add('is-ready');

            function panelOf(tab) { return document.getElementById(tab.getAttribute('aria-controls')); }

            function select(tab, focus) {
                tabs.forEach(function (item) {
                    var active = item === tab;
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                    item.tabIndex = active ? 0 : -1;
                    var panel = panelOf(item);
                    if (panel) panel.hidden = !active;
                });
                if (focus) tab.focus();
                // CodeMirror cannot measure a hidden editor; content or width that changed while it was hidden is laid out only by a refresh.
                var shown = panelOf(tab);
                if (!shown) return;
                shown.querySelectorAll('[data-tk-analytics-code]').forEach(function (field) {
                    if (field.tkCodeMirror) { field.tkCodeMirror.refresh(); }
                });
            }

            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () { select(tab, false); });
                tab.addEventListener('keydown', function (event) {
                    var next = null;
                    if (event.key === 'ArrowRight') next = tabs[(index + 1) % tabs.length];
                    else if (event.key === 'ArrowLeft') next = tabs[(index - 1 + tabs.length) % tabs.length];
                    else if (event.key === 'Home') next = tabs[0];
                    else if (event.key === 'End') next = tabs[tabs.length - 1];
                    if (!next) return;
                    event.preventDefault();
                    select(next, true);
                });
            });
            select(tabs[0], false);
        });
    }

    function initializeEditors() {
        if (!window.tkAnalyticsEditor || !window.tkAnalyticsEditor.settings || !window.wp || !window.wp.codeEditor) return;
        document.querySelectorAll('[data-tk-analytics-code]').forEach(function (field) {
            if (field.dataset.tkCodeInitialized) return;
            var editor = window.wp.codeEditor.initialize(field, window.tkAnalyticsEditor.settings);
            if (!editor || !editor.codemirror) return;
            field.dataset.tkCodeInitialized = '1';
            field.tkCodeMirror = editor.codemirror;
            editor.codemirror.on('change', function () { editor.codemirror.save(); });
            if (field.form) field.form.addEventListener('submit', function () { editor.codemirror.save(); });
            editor.codemirror.getWrapperElement().setAttribute('aria-describedby', field.getAttribute('aria-describedby') || '');
            editor.codemirror.getInputField().setAttribute('aria-label', field.id === 'tk-google-tag-manager-head' ? 'Google Tag Manager head code' : 'Google Tag Manager body code');
            editor.codemirror.refresh();
        });
    }

    function initialize() {
        // Editors first, so the tab switch can refresh them; tabs work even when the code editor is unavailable.
        initializeEditors();
        initializeTabs();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
