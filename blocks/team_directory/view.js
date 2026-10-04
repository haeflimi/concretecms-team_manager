(function () {
    // "Random" buttons fill the name input of their form with a generated team name
    document.addEventListener('click', function (e) {
        var button = e.target.closest('.team-manager [data-team-name-url]');
        if (!button) {
            return;
        }
        var input = button.form.querySelector('[name="name"]');
        button.disabled = true;
        fetch(button.dataset.teamNameUrl, {credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : {}; })
            .then(function (data) {
                if (data.name) {
                    input.value = data.name;
                }
            })
            .finally(function () { button.disabled = false; });
    });
})();
