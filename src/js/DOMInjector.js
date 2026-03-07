/**
 * Injecte les champs Gaitcha dans le formulaire.
 *
 * - Checkbox visible avec label
 * - Input hidden pour le token (_ct)
 * - Input hidden pour le log comportemental
 */

/**
 * Crée un injecteur DOM pour un formulaire.
 *
 * @param {HTMLFormElement}  form            Formulaire cible.
 * @param {HTMLElement|null} targetContainer Conteneur cible optionnel pour l'injection.
 * @return {object} Injecteur avec inject(), update(), getCheckbox(), getLogInput(), destroy().
 */
function createDOMInjector(form, targetContainer) {
    /** @type {HTMLDivElement|null} */
    let container = null;

    /** @type {HTMLInputElement|null} */
    let checkbox = null;

    /** @type {HTMLInputElement|null} */
    let tokenInput = null;

    /** @type {HTMLInputElement|null} */
    let logInput = null;

    /**
     * Injecte les champs dans le formulaire.
     *
     * @param {string} fieldName      Nom du champ aléatoire.
     * @param {string} token          Token HMAC signé.
     * @param {string} tokenFieldName Nom du champ hidden pour le token.
     * @param {string} label          Label de la checkbox.
     */
    function inject(fieldName, token, tokenFieldName, label) {
        // Container pour la checkbox + label.
        container = document.createElement('div');
        container.className = 'gaitcha-field';

        // Checkbox visible.
        checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = fieldName;
        checkbox.id = 'gaitcha-' + fieldName;
        checkbox.required = true;
        checkbox.className = 'gaitcha-checkbox';

        // Label.
        const labelEl = document.createElement('label');
        labelEl.htmlFor = checkbox.id;
        labelEl.textContent = label || 'Je ne suis pas un robot';
        labelEl.className = 'gaitcha-label';

        container.appendChild(checkbox);
        container.appendChild(labelEl);

        // Hidden : token signé.
        tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = tokenFieldName;
        tokenInput.value = token;

        // Hidden : log comportemental.
        logInput = document.createElement('input');
        logInput.type = 'hidden';
        logInput.name = fieldName + '_log';
        logInput.value = '';

        // Injecter dans le conteneur cible, ou avant le bouton submit, ou à la fin du form.
        if (targetContainer) {
            targetContainer.appendChild(container);
            targetContainer.appendChild(tokenInput);
            targetContainer.appendChild(logInput);
        } else {
            var submitBtn = form.querySelector('[type="submit"], button:not([type])');
            if (submitBtn) {
                form.insertBefore(container, submitBtn);
                form.insertBefore(tokenInput, submitBtn);
                form.insertBefore(logInput, submitBtn);
            } else {
                form.appendChild(container);
                form.appendChild(tokenInput);
                form.appendChild(logInput);
            }
        }
    }

    /**
     * Met à jour les champs après un refresh de token.
     *
     * @param {string} newFieldName      Nouveau nom de champ.
     * @param {string} newToken          Nouveau token.
     * @param {string} newTokenFieldName Nouveau nom de champ token.
     */
    function update(newFieldName, newToken, newTokenFieldName) {
        if (checkbox) {
            checkbox.name = newFieldName;
            checkbox.id = 'gaitcha-' + newFieldName;
        }

        if (tokenInput) {
            tokenInput.name = newTokenFieldName;
            tokenInput.value = newToken;
        }

        if (logInput) {
            logInput.name = newFieldName + '_log';
        }

        // Mettre à jour le label.
        if (container) {
            const labelEl = container.querySelector('label');
            if (labelEl) {
                labelEl.htmlFor = 'gaitcha-' + newFieldName;
            }
        }
    }

    /**
     * @return {HTMLInputElement|null} La checkbox Gaitcha.
     */
    function getCheckbox() {
        return checkbox;
    }

    /**
     * @return {HTMLInputElement|null} L'input hidden du log.
     */
    function getLogInput() {
        return logInput;
    }

    /**
     * Supprime les champs injectés.
     */
    function destroy() {
        if (container && container.parentNode) {
            container.parentNode.removeChild(container);
        }
        if (tokenInput && tokenInput.parentNode) {
            tokenInput.parentNode.removeChild(tokenInput);
        }
        if (logInput && logInput.parentNode) {
            logInput.parentNode.removeChild(logInput);
        }
        container = null;
        checkbox = null;
        tokenInput = null;
        logInput = null;
    }

    return {
        inject: inject,
        update: update,
        getCheckbox: getCheckbox,
        getLogInput: getLogInput,
        destroy: destroy,
    };
}

export { createDOMInjector };
