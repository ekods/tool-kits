(function () {
    'use strict';
    document.querySelectorAll('.tk-post-faq').forEach(function (root) {
        var value = root.querySelector('input[type="hidden"]');
        var dialog = root.querySelector('dialog');
        var rows = root.querySelector('.tk-faq-rows');
        var error = root.querySelector('.tk-faq-error');
        var opener = root.querySelector('.tk-faq-open');
        var add = root.querySelector('.tk-faq-add');
        var sequence = 0;
        var activeLanguage = 'id';
        var languages = [];
        var tabs = document.createElement('div');
        tabs.className = 'tk-faq-language-tabs';
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'Bahasa FAQ');
        var languageTools = document.createElement('div');
        languageTools.className = 'tk-faq-language-tools';
        var languageInput = document.createElement('input');
        languageInput.type = 'text';
        languageInput.placeholder = 'Kode bahasa';
        languageInput.setAttribute('aria-label', 'Kode bahasa tambahan');
        var languageAdd = document.createElement('button');
        languageAdd.type = 'button';
        languageAdd.className = 'button';
        languageAdd.textContent = 'Tambah bahasa';
        languageTools.append(languageInput, languageAdd);
        rows.before(tabs, languageTools);
        function activateLanguage(tag) {
            activeLanguage = tag;
            tabs.querySelectorAll('button').forEach(function (button) {
                var selected = button.dataset.language === tag;
                button.setAttribute('aria-selected', String(selected));
                button.tabIndex = selected ? 0 : -1;
            });
            rows.querySelectorAll('.tk-faq-row').forEach(function (row) {
                row.hidden = row.querySelector('.tk-faq-language').value !== tag;
            });
            var hasRows = Array.prototype.some.call(rows.querySelectorAll('.tk-faq-row'), function (row) {
                return row.querySelector('.tk-faq-language').value === tag;
            });
            if (!hasRows && rows.children.length < 50) { addRow({ language: tag }); }
        }
        function addLanguage(tag) {
            if (languages.indexOf(tag) !== -1) { return; }
            languages.push(tag);
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button';
            button.setAttribute('role', 'tab');
            button.dataset.language = tag;
            button.textContent = tag ? tag.toUpperCase() : 'Default';
            button.addEventListener('click', function () { activateLanguage(tag); });
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') { return; }
                event.preventDefault();
                var index = languages.indexOf(tag);
                var next = (index + (event.key === 'ArrowRight' ? 1 : -1) + languages.length) % languages.length;
                activateLanguage(languages[next]);
                tabs.children[next].focus();
            });
            tabs.appendChild(button);
        }
        languageAdd.addEventListener('click', function () {
            var tag = languageInput.value.trim().toLowerCase().replace(/_/g, '-');
            if (!/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/.test(tag)) {
                error.textContent = 'Gunakan kode bahasa seperti id, en, atau en-sg.';
                error.hidden = false;
                languageInput.focus();
                return;
            }
            error.hidden = true;
            addLanguage(tag);
            activateLanguage(tag);
            languageInput.value = '';
        });
        function updateLimit() { add.disabled = rows.children.length >= 50; }
        function addRow(item) {
            if (rows.children.length >= 50) { return; }
            var row = document.createElement('div');
            row.className = 'tk-faq-row';
            var id = 'tk-faq-' + (++sequence);
            var language = document.createElement('input');
            language.type = 'hidden';
            language.className = 'tk-faq-language';
            language.value = item.language || '';
            addLanguage(language.value);
            var questionLabel = document.createElement('label');
            questionLabel.textContent = 'Pertanyaan';
            questionLabel.htmlFor = id + '-question';
            var question = document.createElement('input');
            question.type = 'text';
            question.id = questionLabel.htmlFor;
            question.className = 'tk-faq-question';
            question.value = item.question || '';
            var answerLabel = document.createElement('label');
            answerLabel.textContent = 'Jawaban';
            answerLabel.htmlFor = id + '-answer';
            var answer = document.createElement('textarea');
            answer.id = answerLabel.htmlFor;
            answer.className = 'tk-faq-answer';
            answer.rows = 4;
            answer.value = item.answer || '';
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button tk-faq-remove';
            remove.title = 'Hapus FAQ';
            remove.setAttribute('aria-label', 'Hapus FAQ');
            var icon = document.createElement('span');
            icon.className = 'dashicons dashicons-trash';
            icon.setAttribute('aria-hidden', 'true');
            remove.appendChild(icon);
            remove.addEventListener('click', function () {
                row.remove();
                updateLimit();
                add.focus();
            });
            row.append(language, questionLabel, question, answerLabel, answer, remove);
            rows.appendChild(row);
            updateLimit();
            row.hidden = language.value !== activeLanguage;
            return question;
        }
        opener.addEventListener('click', function () {
            var items;
            try { items = JSON.parse(value.value); } catch (e) { items = []; }
            rows.replaceChildren();
            tabs.replaceChildren();
            languages = [];
            addLanguage('id');
            addLanguage('en');
            activeLanguage = 'id';
            languageInput.value = '';
            error.hidden = true;
            (Array.isArray(items) ? items : []).forEach(addRow);
            activateLanguage(activeLanguage);
            updateLimit();
            dialog.showModal();
        });
        add.addEventListener('click', function () {
            var question = addRow({ language: activeLanguage });
            if (question) { question.focus(); }
        });
        root.querySelectorAll('.tk-faq-cancel').forEach(function (button) {
            button.addEventListener('click', function () { dialog.close(); });
        });
        dialog.addEventListener('close', function () { opener.focus(); });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target.tagName === 'INPUT') { event.preventDefault(); }
        });
        root.querySelector('.tk-faq-apply').addEventListener('click', function () {
            var items = [];
            var invalid = null;
            rows.querySelectorAll('.tk-faq-row').forEach(function (row) {
                var question = row.querySelector('.tk-faq-question');
                var answer = row.querySelector('.tk-faq-answer');
                var language = row.querySelector('.tk-faq-language');
                var tag = language.value.trim().toLowerCase().replace(/_/g, '-');
                if (!question.value.trim() && !answer.value.trim()) { return; }
                if (tag && !/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/.test(tag)) { invalid = invalid || language; }
                if (!question.value.trim() || !answer.value.trim()) {
                    invalid = invalid || (!question.value.trim() ? question : answer);
                }
                items.push({ question: question.value.trim(), answer: answer.value.trim(), language: tag });
            });
            if (invalid) {
                error.textContent = 'Isi pertanyaan dan jawaban; gunakan kode bahasa seperti id atau en.';
                error.hidden = false;
                activateLanguage(invalid.closest('.tk-faq-row').querySelector('.tk-faq-language').value);
                invalid.focus();
                return;
            }
            value.value = JSON.stringify(items);
            value.disabled = false;
            value.dispatchEvent(new Event('change', { bubbles: true }));
            root.querySelector('.tk-faq-count').textContent = items.length;
            dialog.close();
        });
    });
}());
