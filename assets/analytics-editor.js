(function () {
    'use strict';
    function initialize() {
        if (!window.tkAnalyticsEditor || !window.tkAnalyticsEditor.settings || !window.wp || !window.wp.codeEditor) return;
        document.querySelectorAll('[data-tk-analytics-code]').forEach(function (field) {
            if (field.dataset.tkCodeInitialized) return;
            var editor = window.wp.codeEditor.initialize(field, window.tkAnalyticsEditor.settings);
            if (!editor || !editor.codemirror) return;
            field.dataset.tkCodeInitialized = '1';
            editor.codemirror.on('change', function () { editor.codemirror.save(); });
            if (field.form) field.form.addEventListener('submit', function () { editor.codemirror.save(); });
            editor.codemirror.getWrapperElement().setAttribute('aria-describedby', field.getAttribute('aria-describedby') || '');
            editor.codemirror.getInputField().setAttribute('aria-label', field.id === 'tk-google-tag-manager-head' ? 'Google Tag Manager head code' : 'Google Tag Manager body code');
            editor.codemirror.refresh();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
