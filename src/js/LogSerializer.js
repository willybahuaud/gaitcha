/**
 * Sérialise le log comportemental dans le hidden field au submit.
 */

/**
 * Attache un listener submit qui injecte le log avant l'envoi.
 *
 * Ne sérialise que si le checkbox du widget a été coché.
 * Cela empêche un formulaire soumis sans validation captcha
 * d'envoyer un log comportemental exploitable.
 *
 * @param {HTMLFormElement} form         Formulaire surveillé.
 * @param {Function}       getPayload   Fonction retournant le payload du logger.
 * @param {Function}       getLogInput  Fonction retournant l'input hidden du log.
 * @param {Function}       getCheckbox  Fonction retournant le checkbox du widget.
 * @return {Function} Fonction de cleanup.
 */
function attachSubmitSerializer(form, getPayload, getLogInput, getCheckbox) {
    /**
     * Handler du submit : sérialise le log dans le hidden field.
     *
     * Ignore si le checkbox n'est pas coché — le log doit être
     * sérialisé via onCheck() pour les soumissions valides.
     */
    function handleSubmit() {
        var checkbox = getCheckbox();
        if (checkbox && !checkbox.checked) {
            return;
        }

        var logInput = getLogInput();
        if (!logInput) {
            return;
        }

        var payload = getPayload();
        logInput.value = JSON.stringify(payload);
    }

    // Capture phase : garantit que le log est sérialisé AVANT
    // tout autre listener submit (qui utiliserait FormData).
    form.addEventListener('submit', handleSubmit, true);

    return function cleanup() {
        form.removeEventListener('submit', handleSubmit, true);
    };
}

export { attachSubmitSerializer };
