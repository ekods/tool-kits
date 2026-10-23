(function () {
    'use strict';
    function tokenEditor(wrapper) {
        var source = wrapper.querySelector('input[type="text"], textarea');
        var menu = wrapper.querySelector('.tk-seo-variable-menu');
        var trigger = wrapper.querySelector('.tk-seo-insert-variable');
        var options = Array.from(menu.querySelectorAll('[data-seo-variable]'));
        var labels = {};
        options.forEach(function (option, index) { labels[option.dataset.seoVariable] = option.textContent; option.id = menu.id + '-' + index; });
        var editor = wrapper.querySelector('[data-token-display]') || document.createElement('div');
        editor.id = source.id + '-editor'; editor.className = 'tk-seo-token-input'; editor.contentEditable = 'true';
        editor.setAttribute('role', 'textbox'); editor.setAttribute('aria-multiline', String(source.tagName === 'TEXTAREA'));
        editor.setAttribute('aria-readonly', 'false');
        editor.setAttribute('aria-describedby', source.getAttribute('aria-describedby'));
        editor.setAttribute('aria-label', wrapper.querySelector('label').textContent);
        editor.setAttribute('aria-controls', menu.id); editor.setAttribute('aria-haspopup', 'listbox');
        editor.dataset.placeholder = source.placeholder;
        if (source.tagName === 'TEXTAREA') editor.classList.add('tk-seo-token-multiline');
        if (!editor.parentNode) source.insertAdjacentElement('afterend', editor);
        source.hidden = true; trigger.hidden = false;
        wrapper.querySelector('label').htmlFor = editor.id;
        var savedRange = null, query = '', active = 0, syncing = false;
        function chip(token) {
            var node = document.createElement('span'); node.className = 'tk-seo-token'; node.contentEditable = 'false'; node.dataset.token = token;
            node.appendChild(document.createTextNode(labels[token]));
            var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'tk-seo-token-remove';
            remove.setAttribute('aria-label', 'Remove ' + labels[token] + ' variable'); remove.textContent = '×';
            node.appendChild(remove); return node;
        }
        function fragment(text) {
            var result = document.createDocumentFragment();
            text.split(/(%%[a-z_]+%%)/g).forEach(function (part) { if (part) result.appendChild(labels[part] ? chip(part) : document.createTextNode(part)); });
            return result;
        }
        function serialize(node) {
            if (node.nodeType === 3) return node.nodeValue;
            if (node.dataset && node.dataset.token) return node.dataset.token;
            if (node.nodeName === 'BR') return '\n';
            return Array.from(node.childNodes).map(serialize).join('') + (node !== editor && /^(DIV|P)$/.test(node.nodeName) ? '\n' : '');
        }
        function remember() {
            var selection = window.getSelection();
            if (selection.rangeCount && editor.contains(selection.anchorNode) && editor.contains(selection.focusNode)) savedRange = selection.getRangeAt(0).cloneRange();
        }
        function close() { menu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); editor.removeAttribute('aria-activedescendant'); query = ''; }
        function visibleOptions() { return options.filter(function (option) { return !option.hidden; }); }
        function highlight() {
            var visible = visibleOptions();
            options.forEach(function (option) { option.setAttribute('aria-selected', String(option === visible[active])); });
            if (visible[active]) editor.setAttribute('aria-activedescendant', visible[active].id);
        }
        function open(filter) {
            filter = filter.toLowerCase();
            options.forEach(function (option) { option.hidden = (option.textContent + option.dataset.seoVariable).toLowerCase().indexOf(filter) === -1; });
            if (!visibleOptions().length) { close(); return; }
            active = 0; menu.hidden = false; trigger.setAttribute('aria-expanded', 'true'); highlight();
        }
        function sync() {
            syncing = true; source.value = serialize(editor).replace(/\u00a0/g, ' '); source.dispatchEvent(new Event('input', {bubbles: true})); syncing = false;
            remember();
        }
        function rangeAtCaret() {
            var range = savedRange && editor.contains(savedRange.startContainer) ? savedRange.cloneRange() : document.createRange();
            if (!savedRange || !editor.contains(range.startContainer)) { range.selectNodeContents(editor); range.collapse(false); }
            return range;
        }
        function put(text, token) {
            var range = rangeAtCaret();
            if (token && query && range.startContainer.nodeType === 3 && range.startOffset >= query.length) range.setStart(range.startContainer, range.startOffset - query.length);
            range.deleteContents();
            var nodes = token ? document.createDocumentFragment() : fragment(text);
            if (token) { nodes.appendChild(chip(token)); nodes.appendChild(document.createTextNode(' ')); }
            var tail = nodes.lastChild;
            if (!tail) return;
            range.insertNode(nodes); range.setStartAfter(tail); range.collapse(true);
            editor.focus(); var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
            savedRange = range.cloneRange(); close(); sync();
        }
        editor.replaceChildren(fragment(source.value));
        source.addEventListener('input', function () { if (!syncing) { editor.replaceChildren(fragment(source.value)); savedRange = null; } });
        editor.addEventListener('input', function (event) {
            event.stopPropagation(); sync();
            if (event.isComposing) return;
            var range = rangeAtCaret(), before = range.cloneRange(); before.selectNodeContents(editor); before.setEnd(range.startContainer, range.startOffset);
            var match = before.toString().match(/(?:^|\s)(%{1,2}[a-z_]*)$/i);
            if (match && range.collapsed) { query = match[1]; open(query.replace(/%/g, '')); } else close();
        });
        ['keyup', 'mouseup', 'focus'].forEach(function (name) { editor.addEventListener(name, remember); });
        editor.addEventListener('keydown', function (event) {
            if (event.isComposing) return;
            if (!menu.hidden && ['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].indexOf(event.key) !== -1) {
                event.preventDefault();
                if (event.key === 'Escape') close();
                else if (event.key === 'Enter') put('', visibleOptions()[active].dataset.seoVariable);
                else { active = (active + (event.key === 'ArrowDown' ? 1 : -1) + visibleOptions().length) % visibleOptions().length; highlight(); }
                return;
            }
            if (event.key === 'Enter' && source.tagName !== 'TEXTAREA') event.preventDefault();
            if (event.key !== 'Backspace' && event.key !== 'Delete') return;
            remember(); if (!savedRange || !savedRange.collapsed) return;
            var container = savedRange.startContainer, offset = savedRange.startOffset, adjacent;
            if (container.nodeType === 3) {
                if (event.key === 'Backspace' && offset === 0) adjacent = container.previousSibling;
                if (event.key === 'Delete' && offset === container.length) adjacent = container.nextSibling;
            } else if (container === editor) adjacent = editor.childNodes[offset + (event.key === 'Backspace' ? -1 : 0)];
            if (adjacent && adjacent.dataset && adjacent.dataset.token) { event.preventDefault(); adjacent.remove(); sync(); }
        });
        editor.addEventListener('click', function (event) {
            var remove = event.target.closest('.tk-seo-token-remove');
            if (!remove) return;
            event.preventDefault(); event.stopPropagation();
            var node = remove.parentNode, range = document.createRange(); range.setStartBefore(node); range.collapse(true); node.remove();
            var selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); editor.focus(); remember(); sync();
        });
        editor.addEventListener('paste', function (event) { event.preventDefault(); remember(); put(event.clipboardData.getData('text/plain')); });
        editor.addEventListener('drop', function (event) { event.preventDefault(); remember(); put(event.dataTransfer.getData('text/plain')); });
        trigger.addEventListener('mousedown', function (event) { event.preventDefault(); });
        trigger.addEventListener('click', function () { if (!menu.hidden) { close(); return; } query = ''; open(''); editor.focus(); });
        options.forEach(function (option) {
            option.addEventListener('mousedown', function (event) { event.preventDefault(); });
            option.addEventListener('click', function () { put('', option.dataset.seoVariable); });
        });
        document.addEventListener('mousedown', function (event) { if (!wrapper.contains(event.target)) close(); });
    }

    function localeCopy(root) {
        var block = root.querySelector('.tk-seo-locale-copy');
        if (!block) return;
        var select = block.querySelector('select'), button = block.querySelector('.tk-seo-copy-locale-button');
        if (!select || !button) return;
        var replace = block.querySelector('.tk-seo-copy-replace');
        var status = block.querySelector('.tk-seo-copy-status'), link = block.querySelector('.tk-seo-copy-edit');
        var busy = false;
        function toggle() { button.disabled = busy || !select.value; select.disabled = busy; replace.disabled = busy; }
        select.addEventListener('change', function () { status.hidden = true; link.hidden = true; toggle(); });
        button.addEventListener('click', async function () {
            if (busy || !select.value) return;
            var body = new URLSearchParams({action: 'tk_seo_copy_locale', post_id: block.dataset.postId, nonce: block.dataset.copyNonce, locale: select.value, replace: replace.checked ? '1' : '0'});
            ['tk_seo_focus_keyword', 'tk_seo_title', 'tk_seo_meta_description'].forEach(function (name) {
                var input = root.querySelector('[name="' + name + '"]');
                if (input) body.set(name, input.value);
                var initial = root.querySelector('[name="tk_seo_initial[' + name + ']"]');
                if (initial) body.set('initial[' + name + ']', initial.value);
            });
            busy = true; toggle(); block.setAttribute('aria-busy', 'true');
            status.hidden = false; status.textContent = 'Copying SEO fields…'; link.hidden = true;
            var controller = new AbortController(), timeout = setTimeout(function () { controller.abort(); }, 20000);
            try {
                var response = await fetch(block.dataset.copyUrl, {method: 'POST', credentials: 'same-origin', body: body, signal: controller.signal});
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Unable to copy SEO fields.');
                status.textContent = result.data.message;
                if (result.data.edit_url) {
                    var target = new URL(result.data.edit_url, block.dataset.copyUrl);
                    if (/^https?:$/.test(target.protocol) && target.origin === new URL(block.dataset.copyUrl).origin) {
                        target.hash = 'tk-seo-content'; link.href = target.href; link.hidden = false;
                    }
                }
            } catch (error) {
                status.textContent = error.name === 'AbortError' ? 'The copy request timed out. Check the target translation before retrying.' : (error.message || 'Unable to copy SEO fields. Check the target translation before retrying.');
            } finally {
                clearTimeout(timeout); busy = false; toggle(); block.removeAttribute('aria-busy');
            }
        });
        toggle();
    }

    function init() {
        document.querySelectorAll('.tk-seo-post-editor').forEach(function (root) {
            if (root.dataset.initialized) return;
            root.dataset.initialized = '1';
            localeCopy(root);
            var title = root.querySelector('#tk-seo-title');
            var description = root.querySelector('#tk-seo-description');
            var slug = root.querySelector('#tk-seo-slug');
            var preview = root.querySelector('.tk-seo-search-preview');
            var meters = root.querySelectorAll('.tk-seo-length');
            var pageTitle = root.dataset.pageTitle;
            var initialTitle = root.querySelector('[name="tk_seo_initial[tk_seo_title]"]');
            var followingTitle = !!initialTitle;
            var automaticTitle = root.dataset.defaultTitle;
            var variables = JSON.parse(root.dataset.variables || '{}');
            var socialCard = root.querySelector('.tk-seo-social-card');
            var socialImage = root.querySelector('.tk-seo-social-image');
            var socialImageEmpty = root.querySelector('.tk-seo-social-image-empty');
            function updateLength(field, length) {
                var feedback = root.querySelector('[data-length-feedback="' + field + '"]');
                var min = Number(feedback.dataset.min), max = Number(feedback.dataset.max);
                var state = !length ? 'empty' : length < min ? 'short' : length > max ? 'long' : 'ideal';
                var badge = feedback.querySelector('.tk-seo-length-status');
                badge.dataset.state = state;
                badge.textContent = {empty: 'Not set', short: 'Too short', ideal: 'Ideal', long: 'Too long'}[state];
                feedback.querySelector('[data-' + field + '-length]').textContent = length + ' characters (suggested: ' + min + '–' + max + ').';
            }
            function setSocialImage(value, loading) {
                var url = '';
                try { if (value) { var parsed = new URL(value, window.location.href); if (/^https?:$/.test(parsed.protocol)) url = parsed.href; } } catch (error) { /* Show the empty-image state for unusable URLs. */ }
                if (url) {
                    if (socialImage.getAttribute('src') !== url) socialImage.src = url;
                    socialImage.hidden = false; socialImageEmpty.hidden = true;
                } else {
                    socialImage.removeAttribute('src'); socialImage.hidden = true; socialImageEmpty.hidden = false;
                    socialImageEmpty.textContent = loading ? 'Loading social image…' : 'No social image';
                }
            }
            socialImage.addEventListener('load', function () { socialImage.hidden = false; socialImageEmpty.hidden = true; });
            socialImage.addEventListener('error', function () { socialImage.hidden = true; socialImageEmpty.hidden = false; socialImageEmpty.textContent = 'Image unavailable'; });
            function expand(value) {
                return value.replace(/%%[a-z_]+%%/g, function (token) { return Object.prototype.hasOwnProperty.call(variables, token) ? variables[token] : token; }).replace(/\s+/g, ' ').trim();
            }
            function render() {
                var text = expand(title.value) || automaticTitle;
                var descriptionValue = expand(description.value) || root.dataset.defaultDescription || '';
                var descriptionText = descriptionValue || 'Add a meta description below. Search engines may also use relevant text from your page.';
                root.querySelector('.tk-seo-preview-title').textContent = text;
                root.querySelector('.tk-seo-preview-description').textContent = descriptionText;
                var titleLength = Array.from(text).length;
                root.querySelector('.tk-seo-social-title').textContent = text;
                root.querySelector('.tk-seo-social-description').textContent = descriptionValue;
                var descriptionLength = Array.from(descriptionValue).length;
                meters[0].value = titleLength;
                meters[1].value = descriptionLength;
                updateLength('title', titleLength); updateLength('description', descriptionLength);
                try {
                    var url = new URL(root.dataset.url, window.location.href);
                    var segments = url.pathname.split('/');
                    if (slug.value && root.dataset.slug) {
                        for (var i = segments.length - 1; i >= 0; i--) {
                            if (decodeURIComponent(segments[i]) === decodeURIComponent(root.dataset.slug)) {
                                segments[i] = encodeURIComponent(slug.value.trim().replace(/\s+/g, '-'));
                                url.pathname = segments.join('/'); break;
                            }
                        }
                    }
                    root.querySelector('.tk-seo-preview-url').textContent = url.toString();
                    root.querySelector('.tk-seo-social-url').textContent = url.toString();
                } catch (error) { root.querySelector('.tk-seo-preview-url').textContent = root.dataset.url; }
            }
            function updatePageTitle(value) {
                pageTitle = value;
                variables['%%title%%'] = value;
                if (root.dataset.homepage !== '1') automaticTitle = [pageTitle, root.dataset.siteName].filter(Boolean).join(' ' + root.dataset.separator + ' ');
                if (followingTitle && title.value.indexOf('%%') === -1) { title.value = automaticTitle; initialTitle.value = automaticTitle; title.dispatchEvent(new Event('input', {bubbles: true})); followingTitle = true; }
                render();
            }
            title.addEventListener('input', function () { followingTitle = false; render(); });
            description.addEventListener('input', render);
            slug.addEventListener('input', render);
            root.querySelectorAll('[data-template-field]').forEach(tokenEditor);
            var favicon = root.querySelector('.tk-seo-preview-favicon');
            var fallbackIcon = root.querySelector('.tk-seo-preview-icon');
            favicon.addEventListener('error', function () { favicon.hidden = true; fallbackIcon.hidden = false; });
            if (!favicon.getAttribute('src')) {
                var headIcon = document.head.querySelector('link[rel~="icon"]');
                if (headIcon) {
                    try {
                        var iconUrl = new URL(headIcon.href, window.location.href);
                        if (/^https?:$/.test(iconUrl.protocol)) { favicon.src = iconUrl.href; favicon.hidden = false; fallbackIcon.hidden = true; }
                    } catch (error) { /* Keep the site initial when there is no usable icon. */ }
                }
            }
            root.querySelectorAll('.tk-seo-device-preview .tk-seo-device-choice').forEach(function (choice) {
                choice.addEventListener('change', function () {
                    if (choice.checked) preview.dataset.device = choice.value;
                });
            });
            root.querySelectorAll('.tk-seo-social-choice').forEach(function (choice) {
                choice.addEventListener('change', function () { if (choice.checked) socialCard.dataset.network = choice.value; });
            });
            render();
            // Keep the native permalink controls and this field in sync.
            var store = window.wp && window.wp.data;
            var editorStore;
            try { editorStore = store && store.select('core/editor'); } catch (error) { editorStore = null; }
            if (editorStore && typeof editorStore.getEditedPostAttribute === 'function') {
                var lastSlug = editorStore.getEditedPostAttribute('slug');
                var featuredId = editorStore.getEditedPostAttribute('featured_media'), watchFeatured = false, featuredKey = '';
                if (typeof lastSlug === 'string') slug.value = lastSlug;
                slug.addEventListener('input', function () { store.dispatch('core/editor').editPost({slug: slug.value}); });
                store.subscribe(function () {
                    var editor = store.select('core/editor');
                    var editedTitle = editor.getEditedPostAttribute('title');
                    var editedSlug = editor.getEditedPostAttribute('slug');
                    if (typeof editedTitle === 'string' && editedTitle !== pageTitle) updatePageTitle(editedTitle);
                    if (typeof editedSlug === 'string' && editedSlug !== lastSlug) { lastSlug = editedSlug; slug.value = editedSlug; render(); }
                    var id = editor.getEditedPostAttribute('featured_media');
                    if (typeof id === 'number' && id !== featuredId) { featuredId = id; watchFeatured = true; }
                    if (watchFeatured) {
                        var imageUrl = root.dataset.socialDefaultImage || '', loading = false;
                        if (id > 0) {
                            var core;
                            try { core = store.select('core'); } catch (error) { core = null; }
                            var media = core && typeof core.getMedia === 'function' ? core.getMedia(id) : null;
                            imageUrl = media && media.source_url || ''; loading = !imageUrl;
                        }
                        var key = id + ':' + imageUrl;
                        if (key !== featuredKey) { featuredKey = key; setSocialImage(imageUrl, loading); }
                    }
                });
            } else {
                var nativeTitle = document.getElementById('title');
                if (nativeTitle) nativeTitle.addEventListener('input', function () { updatePageTitle(nativeTitle.value); });
                var nativeSlug = document.getElementById('post_name');
                if (nativeSlug) {
                    slug.addEventListener('input', function () { nativeSlug.value = slug.value; });
                    nativeSlug.addEventListener('change', function () { slug.value = nativeSlug.value; render(); });
                }
                var featuredBox = document.getElementById('postimagediv');
                if (featuredBox && window.MutationObserver) new MutationObserver(function () {
                    var image = featuredBox.querySelector('img');
                    setSocialImage(image ? image.getAttribute('src') : root.dataset.socialDefaultImage);
                }).observe(featuredBox, {childList: true, subtree: true, attributes: true, attributeFilter: ['src']});
            }
            render();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
