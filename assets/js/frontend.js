document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('.kiwi-form');

    function copyTextToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.setAttribute('readonly', '');
            textArea.style.position = 'fixed';
            textArea.style.left = '-9999px';
            document.body.appendChild(textArea);
            textArea.select();

            try {
                const copied = document.execCommand('copy');
                document.body.removeChild(textArea);

                if (copied) {
                    resolve();
                    return;
                }
            } catch (error) {
                document.body.removeChild(textArea);
                reject(error);
                return;
            }

            reject(new Error('Copy command failed'));
        });
    }

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

    document.addEventListener('click', function (event) {
        const copyButton = event.target.closest('.kiwi-lp-card__copy-btn');

        if (!copyButton) {
            return;
        }

        const copyText = (copyButton.getAttribute('data-copy-text') || '').trim();

        if (!copyText) {
            return;
        }

        copyTextToClipboard(copyText).then(function () {
            copyButton.classList.add('is-copied');
            copyButton.setAttribute('title', 'Copied');
            copyButton.setAttribute('aria-label', 'Copied');

            window.setTimeout(function () {
                copyButton.classList.remove('is-copied');
                copyButton.setAttribute('title', 'Copy URL');
                copyButton.setAttribute('aria-label', 'Copy URL');
            }, 1200);
        }).catch(function () {
            copyButton.setAttribute('title', 'Copy failed');

            window.setTimeout(function () {
                copyButton.setAttribute('title', 'Copy URL');
            }, 1200);
        });
    });
});
