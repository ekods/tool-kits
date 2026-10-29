(function () {
    'use strict';
    var Picker = window.ToolKitsLanguagePicker;
    if (!Picker) { return; }
    document.querySelectorAll('.tk-post-faq').forEach(function (root) {
        var value = root.querySelector('input[type="hidden"]');
        var dialog = root.querySelector('dialog');
        var rows = root.querySelector('.tk-faq-rows');
        var error = root.querySelector('.tk-faq-error');
        var opener = root.querySelector('.tk-faq-open');
        var add = root.querySelector('.tk-faq-add');
        var sequence = 0;
        var preferredLanguages;
        try { preferredLanguages = JSON.parse(root.dataset.languageOrder || '[]'); } catch (e) { preferredLanguages = []; }
        preferredLanguages = (Array.isArray(preferredLanguages) ? preferredLanguages : []).map(Picker.normalize).filter(function (tag, index, items) {
            return Picker.pattern.test(tag) && items.indexOf(tag) === index;
        });
        function rowLanguage(row) { return row.querySelector('.tk-faq-language').value; }
        function showError(message) {
            error.textContent = message;
            error.hidden = !message;
        }
        var picker = Picker.create({
            defaultLanguage: root.dataset.defaultLanguage || 'id',
            preferred: preferredLanguages,
            label: 'FAQ language',
            className: 'tk-faq-languages',
            onError: showError,
            onActivate: function (tag) {
                rows.querySelectorAll('.tk-faq-row').forEach(function (row) { row.hidden = rowLanguage(row) !== tag; });
                var hasRows = Array.prototype.some.call(rows.querySelectorAll('.tk-faq-row'), function (row) { return rowLanguage(row) === tag; });
                if (!hasRows && rows.children.length < 50) { addRow({ language: tag }); }
                numberRows();
            },
            onRemove: function (tag) {
                var languageRows = Array.prototype.filter.call(rows.querySelectorAll('.tk-faq-row'), function (row) { return rowLanguage(row) === tag; });
                var filled = languageRows.filter(function (row) {
                    return row.querySelector('.tk-faq-question').value.trim() || row.querySelector('.tk-faq-answer').value.trim();
                }).length;
                if (filled && !window.confirm('Remove language ' + tag.toUpperCase() + ' and ' + filled + ' FAQ items?')) { return false; }
                languageRows.forEach(function (row) { row.remove(); });
                updateLimit();
                return true;
            }
        });
        var defaultLanguage = picker.defaultLanguage;
        rows.parentNode.prepend(picker.element);

        function numberRows() {
            var position = 0;
            rows.querySelectorAll('.tk-faq-row').forEach(function (row) {
                if (rowLanguage(row) === picker.active()) {
                    row.querySelector('.tk-faq-number').textContent = 'FAQ ' + (++position);
                }
            });
        }
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
            picker.add(language.value);
            var questionLabel = document.createElement('label');
            questionLabel.textContent = 'Question';
            questionLabel.htmlFor = id + '-question';
            var question = document.createElement('input');
            question.type = 'text';
            question.id = questionLabel.htmlFor;
            question.className = 'tk-faq-question';
            question.value = item.question || '';
            var answerLabel = document.createElement('label');
            answerLabel.textContent = 'Answer';
            answerLabel.htmlFor = id + '-answer';
            var answer = document.createElement('textarea');
            answer.id = answerLabel.htmlFor;
            answer.className = 'tk-faq-answer';
            answer.rows = 4;
            answer.value = item.answer || '';
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button tk-faq-remove';
            remove.title = 'Remove FAQ';
            remove.setAttribute('aria-label', 'Remove FAQ');
            var icon = document.createElement('span');
            icon.className = 'dashicons dashicons-trash';
            icon.setAttribute('aria-hidden', 'true');
            remove.appendChild(icon);
            remove.addEventListener('click', function () {
                row.remove();
                updateLimit();
                numberRows();
                add.focus();
            });
            var head = document.createElement('div');
            head.className = 'tk-faq-row-head';
            var number = document.createElement('span');
            number.className = 'tk-faq-number';
            head.append(number, remove);
            row.append(language, head, questionLabel, question, answerLabel, answer);
            rows.appendChild(row);
            updateLimit();
            row.hidden = language.value !== picker.active();
            numberRows();
            return question;
        }
        opener.addEventListener('click', function () {
            var items;
            try { items = JSON.parse(value.value); } catch (e) { items = []; }
            rows.replaceChildren();
            picker.reset();
            showError('');
            items = Array.isArray(items) ? items : [];
            var knownLanguages = [defaultLanguage].concat(items.map(function (item) {
                return Picker.normalize(item && item.language ? item.language : defaultLanguage);
            })).filter(function (tag, index, all) {
                return Picker.pattern.test(tag) && all.indexOf(tag) === index;
            });
            function preferredRank(tag) {
                var exact = preferredLanguages.indexOf(tag);
                if (exact !== -1) { return exact; }
                var base = tag.split('-')[0];
                for (var index = 0; index < preferredLanguages.length; index++) {
                    if (preferredLanguages[index].split('-')[0] === base) { return index; }
                }
                return preferredLanguages.length + 100;
            }
            knownLanguages.sort(function (left, right) { return preferredRank(left) - preferredRank(right); });
            knownLanguages.forEach(picker.add);
            items.forEach(addRow);
            picker.activate(defaultLanguage);
            updateLimit();
            dialog.showModal();
        });
        add.addEventListener('click', function () {
            var question = addRow({ language: picker.active() });
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
                var tag = Picker.normalize(rowLanguage(row));
                if (!question.value.trim() && !answer.value.trim()) { return; }
                if (!question.value.trim() || !answer.value.trim()) {
                    invalid = invalid || (!question.value.trim() ? question : answer);
                }
                items.push({ question: question.value.trim(), answer: answer.value.trim(), language: tag });
            });
            if (invalid) {
                showError('Enter both a question and an answer.');
                picker.activate(rowLanguage(invalid.closest('.tk-faq-row')));
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
