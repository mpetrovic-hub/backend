document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('.kiwi-hlr-form');

    forms.forEach(function (form) {
        form.addEventListener('submit', function () {
            const button = form.querySelector('.kiwi-hlr-submit-button');
            const loading = form.querySelector('.kiwi-hlr-loading');

            if (button) {
                //button.disabled = true;
                button.textContent = 'Working...';
            }

            if (loading) {
                loading.style.display = 'inline-block';
            }
        });
    });
});