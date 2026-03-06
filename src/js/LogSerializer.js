/**
 * Sérialise le log comportemental dans le hidden field au submit.
 */

/**
 * Attache un listener submit qui injecte le log avant l'envoi.
 *
 * @param {HTMLFormElement} form      Formulaire surveillé.
 * @param {Function}        getPayload Fonction retournant le payload du logger.
 * @param {Function}        getLogInput Fonction retournant l'input hidden du log.
 * @return {Function} Fonction de cleanup.
 */
function attachSubmitSerializer(form, getPayload, getLogInput) {
    /**
     * Handler du submit : sérialise le log dans le hidden field.
     */
    function handleSubmit() {
        const logInput = getLogInput();
        if (!logInput) {
            return;
        }

        const payload = getPayload();
        logInput.value = JSON.stringify(payload);
    }

    form.addEventListener('submit', handleSubmit);

    return function cleanup() {
        form.removeEventListener('submit', handleSubmit);
    };
}

export { attachSubmitSerializer };
