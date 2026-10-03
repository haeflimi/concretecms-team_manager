/*
 * Username autocomplete for the team invite forms, fills the block's <datalist>.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.team-manager-my-teams[data-search-url]').forEach(function (block) {
        var url = block.getAttribute('data-search-url');
        var timer = null;

        block.querySelectorAll('.team-manager-invite-form input[name="user"]').forEach(function (input) {
            var list = document.getElementById(input.getAttribute('list'));
            if (!list) {
                return;
            }
            input.addEventListener('input', function () {
                clearTimeout(timer);
                var q = input.value.trim();
                if (q.length < 2) {
                    return;
                }
                timer = setTimeout(function () {
                    fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                        .then(function (response) {
                            return response.ok ? response.json() : [];
                        })
                        .then(function (names) {
                            list.innerHTML = '';
                            names.forEach(function (name) {
                                var option = document.createElement('option');
                                option.value = name;
                                list.appendChild(option);
                            });
                        });
                }, 250);
            });
        });
    });
});
