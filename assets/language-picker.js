/*
 * Language tags for FAQ editors: chips switch the active language (tabs), × removes a non-default language,
 * and a search field adds one like a tag input (name or code, arrow keys + Enter, any valid code accepted).
 * The editor owns its rows; it hears about changes through onActivate and approves removals through onRemove.
 */
(function () {
    'use strict';
    var pattern = /^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/;
    var catalog = ['id', 'en', 'en-us', 'en-gb', 'en-sg', 'en-au', 'ms', 'zh', 'zh-cn', 'zh-tw', 'ja', 'ko', 'th', 'vi', 'tl',
        'hi', 'bn', 'ta', 'ar', 'he', 'fa', 'ur', 'tr', 'ru', 'uk', 'pl', 'cs', 'nl', 'de', 'fr', 'es', 'pt', 'pt-br', 'it',
        'sv', 'da', 'no', 'fi', 'el', 'ro', 'hu', 'jv', 'su'];
    var displayNames = null;
    try { displayNames = new Intl.DisplayNames([document.documentElement.lang || 'en', 'en'], { type: 'language' }); } catch (e) { displayNames = null; }
    var sequence = 0;

    function normalize(value) { return String(value || '').trim().toLowerCase().replace(/_/g, '-'); }
    function languageName(tag) {
        try { return displayNames ? (displayNames.of(tag) || '') : ''; } catch (e) { return ''; }
    }

    function create(options) {
        var defaultLanguage = normalize(options.defaultLanguage);
        if (!pattern.test(defaultLanguage)) { defaultLanguage = 'id'; }
        var known = catalog.slice();
        (options.preferred || []).map(normalize).forEach(function (tag) {
            if (pattern.test(tag) && known.indexOf(tag) === -1) { known.unshift(tag); }
        });
        var languages = [];
        var active = defaultLanguage;
        var listId = 'tk-lang-options-' + (++sequence);
        var highlighted = -1;

        var element = document.createElement('div');
        element.className = 'tk-lang-picker' + (options.className ? ' ' + options.className : '');
        var tabs = document.createElement('div');
        tabs.className = 'tk-lang-tabs';
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', options.label || 'Language');
        var search = document.createElement('div');
        search.className = 'tk-lang-search';
        var input = document.createElement('input');
        input.type = 'search';
        input.autocomplete = 'off';
        input.placeholder = 'Add language…';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);
        input.setAttribute('aria-label', 'Search a language name or code to add');
        var list = document.createElement('ul');
        list.className = 'tk-lang-options';
        list.id = listId;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        search.append(input, list);
        element.append(tabs, search);

        function error(message) { if (options.onError) { options.onError(message); } }
        function refreshRemoveButtons() {
            tabs.querySelectorAll('.tk-lang-chip-remove').forEach(function (button) {
                button.hidden = button.dataset.language === defaultLanguage || languages.length <= 1;
            });
        }
        function activate(tag) {
            active = tag;
            tabs.querySelectorAll('.tk-lang-chip').forEach(function (chip) {
                var selected = chip.dataset.language === tag;
                var button = chip.querySelector('[role="tab"]');
                chip.classList.toggle('is-active', selected);
                button.setAttribute('aria-selected', String(selected));
                button.tabIndex = selected ? 0 : -1;
            });
            refreshRemoveButtons();
            if (options.onActivate) { options.onActivate(tag); }
        }
        function remove(tag) {
            if (tag === defaultLanguage || languages.length <= 1) { return; }
            if (options.onRemove && options.onRemove(tag) === false) { return; }
            var index = languages.indexOf(tag);
            if (index === -1) { return; }
            languages.splice(index, 1);
            tabs.children[index].remove();
            if (active === tag) { activate(languages.indexOf(defaultLanguage) !== -1 ? defaultLanguage : languages[0]); }
            refreshRemoveButtons();
        }
        function add(tag) {
            if (languages.indexOf(tag) !== -1) { return; }
            languages.push(tag);
            var code = tag || defaultLanguage;
            var chip = document.createElement('span');
            chip.className = 'tk-lang-chip';
            chip.dataset.language = tag;
            var button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', 'false');
            button.tabIndex = -1;
            var codeLabel = document.createElement('strong');
            codeLabel.textContent = code.toUpperCase();
            button.appendChild(codeLabel);
            var name = languageName(code);
            if (name) {
                var nameLabel = document.createElement('span');
                nameLabel.textContent = name;
                button.appendChild(nameLabel);
            }
            button.addEventListener('click', function () { activate(tag); });
            button.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') { return; }
                event.preventDefault();
                var index = languages.indexOf(tag);
                var next = (index + (event.key === 'ArrowRight' ? 1 : -1) + languages.length) % languages.length;
                activate(languages[next]);
                tabs.children[next].querySelector('[role="tab"]').focus();
            });
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'tk-lang-chip-remove';
            close.dataset.language = tag;
            close.title = 'Remove language ' + code.toUpperCase() + ' and its FAQ items';
            close.setAttribute('aria-label', close.title);
            close.textContent = '×';
            close.addEventListener('click', function () { remove(tag); });
            chip.append(button, close);
            tabs.appendChild(chip);
            refreshRemoveButtons();
        }
        function matches(query) {
            var tag = normalize(query);
            var lower = String(query || '').trim().toLowerCase();
            var found = known.filter(function (code) {
                if (languages.indexOf(code) !== -1) { return false; }
                return !lower || code.indexOf(tag) === 0 || languageName(code).toLowerCase().indexOf(lower) !== -1;
            }).slice(0, 8);
            if (tag && pattern.test(tag) && languages.indexOf(tag) === -1 && found.indexOf(tag) === -1) { found.push(tag); }
            return found;
        }
        function closeList() {
            list.hidden = true;
            highlighted = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }
        function highlight(index) {
            var items = list.querySelectorAll('[role="option"]');
            if (!items.length) { return; }
            highlighted = (index + items.length) % items.length;
            items.forEach(function (item, position) { item.setAttribute('aria-selected', String(position === highlighted)); });
            input.setAttribute('aria-activedescendant', items[highlighted].id);
            items[highlighted].scrollIntoView({ block: 'nearest' });
        }
        function choose(tag) {
            tag = normalize(tag);
            if (!pattern.test(tag)) {
                error('Use a language code such as id, en, or en-sg.');
                input.focus();
                return;
            }
            error('');
            add(tag);
            activate(tag);
            input.value = '';
            closeList();
        }
        function renderList() {
            var found = matches(input.value);
            list.replaceChildren();
            found.forEach(function (code, index) {
                var item = document.createElement('li');
                item.id = listId + '-' + index;
                item.setAttribute('role', 'option');
                item.dataset.language = code;
                var codeLabel = document.createElement('strong');
                codeLabel.textContent = code.toUpperCase();
                var nameLabel = document.createElement('span');
                nameLabel.textContent = languageName(code) || (known.indexOf(code) === -1 ? 'Add this code' : '');
                item.append(codeLabel, nameLabel);
                item.addEventListener('mousedown', function (event) { event.preventDefault(); choose(code); });
                list.appendChild(item);
            });
            if (!found.length) {
                var empty = document.createElement('li');
                empty.className = 'tk-lang-empty';
                empty.textContent = input.value.trim() ? 'No match. Type a code such as id, en or en-sg.' : 'All listed languages are added.';
                list.appendChild(empty);
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlighted = -1;
            if (found.length && input.value.trim()) { highlight(0); }
        }
        input.addEventListener('focus', renderList);
        input.addEventListener('input', renderList);
        input.addEventListener('blur', closeList);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { renderList(); }
                highlight(highlighted + (event.key === 'ArrowDown' ? 1 : -1));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                var items = list.querySelectorAll('[role="option"]');
                if (highlighted >= 0 && items[highlighted]) { choose(items[highlighted].dataset.language); }
                else if (input.value.trim()) { choose(input.value); }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                event.stopPropagation();
                closeList();
            }
        });

        return {
            element: element,
            defaultLanguage: defaultLanguage,
            add: add,
            activate: activate,
            active: function () { return active; },
            reset: function () {
                languages = [];
                active = defaultLanguage;
                tabs.replaceChildren();
                input.value = '';
                closeList();
            }
        };
    }

    window.ToolKitsLanguagePicker = { create: create, normalize: normalize, pattern: pattern };
}());
