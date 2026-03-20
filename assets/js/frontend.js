document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('.kiwi-form');

    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const button = form.querySelector('.kiwi-submit-button');
            const loading = form.querySelector('.kiwi-loading');

            if (button) {
                // optional wieder aktivieren:
                // button.disabled = true;

                if (!button.dataset.originalText) {
                    button.dataset.originalText = button.textContent;
                }

                button.textContent = 'Working...';
            }

            if (loading) {
                loading.style.display = 'inline-block';
            }
        });
    });
});