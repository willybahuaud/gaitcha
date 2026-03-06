/**
 * Point d'entrée Gaitcha.
 *
 * Auto-détecte les formulaires avec l'attribut data-gaitcha
 * et initialise un GaitchaForm pour chacun.
 *
 * Usage :
 *   <form data-gaitcha data-gaitcha-endpoint="/captcha/init">
 *     ...
 *   </form>
 *   <script src="gaitcha.min.js"></script>
 *
 * Ou via API JS :
 *   Gaitcha.init(form, '/captcha/init', { label: 'Vérification' });
 */

import { initGaitchaForm } from './GaitchaForm.js';

/** @type {Array<{form: HTMLFormElement, instance: object}>} */
const instances = [];

/**
 * Initialise Gaitcha sur un formulaire spécifique.
 *
 * @param {HTMLFormElement} form     Formulaire à protéger.
 * @param {string}          endpoint URL de l'endpoint Ajax.
 * @param {object}          options  Options (label, etc.).
 * @return {object} Instance avec destroy().
 */
function init(form, endpoint, options) {
    const instance = initGaitchaForm(form, endpoint, options || {});
    instances.push({ form: form, instance: instance });
    return instance;
}

/**
 * Détruit toutes les instances Gaitcha.
 */
function destroyAll() {
    instances.forEach(function destroyInstance(entry) {
        entry.instance.destroy();
    });
    instances.length = 0;
}

/**
 * Auto-détection : initialise tous les formulaires avec data-gaitcha.
 */
function autoInit() {
    const forms = document.querySelectorAll('form[data-gaitcha]');

    forms.forEach(function initForm(form) {
        const endpoint = form.getAttribute('data-gaitcha-endpoint') || '/captcha/init';
        const label = form.getAttribute('data-gaitcha-label') || undefined;

        init(form, endpoint, { label: label });
    });
}

// Auto-init au chargement du DOM.
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        autoInit();
    }
}

export { init, destroyAll };
