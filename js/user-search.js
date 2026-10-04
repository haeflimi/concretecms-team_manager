/*
 * User autocomplete for inputs with a data-user-search attribute (the search URL, see TeamManager\Team\UserSearch).
 *
 * Optional attributes on the input:
 *   data-user-search-mode        member | invite | single | captain
 *   data-user-search-team        team ID the user is added to
 *   data-user-search-pool        pool ID the user is added to
 *   data-user-search-pool-field  name of a select in the same form holding the pool ID (read on every search)
 *   data-user-search-empty       text shown when nothing matches
 *
 * Works for inputs added later (board cards), they are set up on first focus.
 */
(function () {
    'use strict';

    var counter = 0;

    function setup(input) {
        if (input.tmUserSearch) {
            return;
        }
        var id = 'tm-user-search-' + (++counter);
        var menu = document.createElement('div');
        menu.className = 'tm-user-search-menu';
        menu.id = id;
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;
        document.body.appendChild(menu);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id);
        input.setAttribute('autocomplete', 'off');

        var state = {items: [], active: -1, timer: null, controller: null, cache: {}};
        input.tmUserSearch = state;

        function buildURL(q) {
            var url = input.getAttribute('data-user-search');
            var params = {q: q, mode: input.getAttribute('data-user-search-mode') || ''};
            if (input.getAttribute('data-user-search-team')) {
                params.team = input.getAttribute('data-user-search-team');
            }
            var pool = input.getAttribute('data-user-search-pool');
            var poolField = input.getAttribute('data-user-search-pool-field');
            if (poolField && input.form && input.form.elements[poolField]) {
                pool = input.form.elements[poolField].value;
            }
            if (pool) {
                params.pool = pool;
            }
            var query = Object.keys(params).map(function (key) {
                return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            }).join('&');

            return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
        }

        function position() {
            var rect = input.getBoundingClientRect();
            menu.style.left = (rect.left + window.scrollX) + 'px';
            menu.style.top = (rect.bottom + window.scrollY + 2) + 'px';
            menu.style.minWidth = rect.width + 'px';
        }

        function close() {
            menu.hidden = true;
            state.active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function highlight(name, q) {
            var span = document.createElement('span');
            span.className = 'tm-user-search-name';
            var i = name.toLowerCase().indexOf(q.toLowerCase());
            if (i === -1 || !q) {
                span.textContent = name;
                return span;
            }
            span.appendChild(document.createTextNode(name.slice(0, i)));
            var mark = document.createElement('mark');
            mark.textContent = name.slice(i, i + q.length);
            span.appendChild(mark);
            span.appendChild(document.createTextNode(name.slice(i + q.length)));
            return span;
        }

        function setActive(index) {
            var options = menu.querySelectorAll('.tm-user-search-item');
            options.forEach(function (option) { option.classList.remove('active'); });
            state.active = index;
            if (index >= 0 && options[index]) {
                options[index].classList.add('active');
                options[index].scrollIntoView({block: 'nearest'});
                input.setAttribute('aria-activedescendant', options[index].id);
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        // next selectable item in the direction, disabled users are skipped
        function move(step) {
            var count = state.items.length;
            for (var n = 1; n <= count; n++) {
                var index = (state.active + step * n + count * n) % count;
                if (state.items[index].state !== 'disabled') {
                    setActive(index);
                    return;
                }
            }
        }

        function choose(index) {
            var item = state.items[index];
            if (!item || item.state === 'disabled') {
                return;
            }
            input.value = item.name;
            close();
            input.dispatchEvent(new Event('change', {bubbles: true}));
        }

        function render(items, q) {
            state.items = items;
            menu.innerHTML = '';
            if (!items.length) {
                var empty = document.createElement('div');
                empty.className = 'tm-user-search-empty';
                empty.textContent = input.getAttribute('data-user-search-empty') || '–';
                menu.appendChild(empty);
            }
            items.forEach(function (item, index) {
                var option = document.createElement('div');
                option.className = 'tm-user-search-item tm-user-search-' + item.state;
                option.id = id + '-' + index;
                option.setAttribute('role', 'option');
                if (item.state === 'disabled') {
                    option.setAttribute('aria-disabled', 'true');
                }
                if (item.avatar) {
                    var avatar = document.createElement('img');
                    avatar.src = item.avatar;
                    avatar.alt = '';
                    avatar.className = 'tm-user-search-avatar';
                    option.appendChild(avatar);
                }
                var text = document.createElement('span');
                text.className = 'tm-user-search-text';
                text.appendChild(highlight(item.name, q));
                if (item.email) {
                    var email = document.createElement('small');
                    email.className = 'tm-user-search-email';
                    email.textContent = item.email;
                    text.appendChild(email);
                }
                option.appendChild(text);
                if (item.hint) {
                    var hint = document.createElement('small');
                    hint.className = 'tm-user-search-hint';
                    hint.textContent = item.hint;
                    option.appendChild(hint);
                }
                // mousedown instead of click: runs before the input loses focus
                option.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    choose(index);
                });
                option.addEventListener('mousemove', function () {
                    if (item.state !== 'disabled' && state.active !== index) {
                        setActive(index);
                    }
                });
                menu.appendChild(option);
            });
            position();
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            state.active = -1;
            // preselect the first selectable user, Enter takes it
            if (items.length) {
                move(1);
            }
        }

        function search() {
            var q = input.value.trim();
            if (!q) {
                close();
                return;
            }
            var url = buildURL(q);
            if (state.cache[url]) {
                render(state.cache[url], q);
                return;
            }
            if (state.controller) {
                state.controller.abort();
            }
            state.controller = window.AbortController ? new AbortController() : null;
            fetch(url, {credentials: 'same-origin', signal: state.controller ? state.controller.signal : undefined})
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (items) {
                    state.cache[url] = items;
                    // the input may have changed while the request was running
                    if (input.value.trim() === q && document.activeElement === input) {
                        render(items, q);
                    }
                })
                .catch(function () {});
        }

        input.addEventListener('input', function () {
            clearTimeout(state.timer);
            state.timer = setTimeout(search, 150);
        });
        input.addEventListener('keydown', function (e) {
            if (menu.hidden) {
                if (e.key === 'ArrowDown' && input.value.trim()) {
                    e.preventDefault();
                    search();
                }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                move(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                move(-1);
            } else if (e.key === 'Enter') {
                if (state.active >= 0) {
                    e.preventDefault();
                    choose(state.active);
                } else {
                    close();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
            } else if (e.key === 'Tab') {
                close();
            }
        });
        input.addEventListener('blur', close);
        window.addEventListener('resize', function () {
            if (!menu.hidden) {
                position();
            }
        });
        window.addEventListener('scroll', function () {
            if (!menu.hidden) {
                position();
            }
        }, true);
    }

    document.addEventListener('focusin', function (e) {
        if (e.target.matches && e.target.matches('input[data-user-search]')) {
            setup(e.target);
        }
    });
})();
