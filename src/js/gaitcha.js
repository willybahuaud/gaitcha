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
 * Vérifie qu'une URL est same-origin ou un chemin relatif.
 *
 * @param {string} url URL à vérifier.
 * @return {boolean} True si l'URL est safe.
 */
function isSameOrigin(url) {
    // Les chemins relatifs sont toujours safe.
    if (url.startsWith('/') && !url.startsWith('//')) {
        return true;
    }

    try {
        var parsed = new URL(url, window.location.origin);
        return parsed.origin === window.location.origin;
    } catch (e) {
        return false;
    }
}

/**
 * Initialise Gaitcha sur un formulaire spécifique.
 *
 * @param {HTMLFormElement} form     Formulaire à protéger.
 * @param {string}          endpoint URL de l'endpoint Ajax (doit être same-origin).
 * @param {object}          options            Options optionnelles.
 * @param {string}          options.label      Label de la checkbox.
 * @param {HTMLElement}     options.container  Conteneur cible pour l'injection DOM.
 * @param {string}          options.theme     Theme du widget : 'light' (defaut), 'dark', ou 'auto'.
 * @return {object|null} Instance avec destroy(), ou null si refusé.
 */
function init(form, endpoint, options) {
    // Double-init guard.
    if (form.hasAttribute('data-gaitcha-initialized')) {
        return null;
    }

    // Valider que l'endpoint est same-origin.
    if (!isSameOrigin(endpoint)) {
        console.warn('Gaitcha: endpoint must be same-origin.');
        return null;
    }

    form.setAttribute('data-gaitcha-initialized', 'true');

    var instance = initGaitchaForm(form, endpoint, options || {});
    instances.push({ form: form, instance: instance });
    return instance;
}

/**
 * Remet le captcha d'un formulaire en état initial.
 *
 * Décoche le widget, vide le log comportemental, et demande
 * un nouveau token. Utile après un rejet serveur en AJAX.
 *
 * @param {HTMLFormElement} form Formulaire à réinitialiser.
 * @return {boolean} True si l'instance a été trouvée et réinitialisée.
 */
function reset(form) {
    for (var i = 0; i < instances.length; i++) {
        if (instances[i].form === form) {
            instances[i].instance.reset();
            return true;
        }
    }
    return false;
}

/**
 * Détruit toutes les instances Gaitcha.
 */
function destroyAll() {
    instances.forEach(function destroyInstance(entry) {
        entry.form.removeAttribute('data-gaitcha-initialized');
        entry.instance.destroy();
    });
    instances.length = 0;
}

/**
 * Auto-détection : initialise tous les formulaires avec data-gaitcha.
 */
function autoInit() {
    var forms = document.querySelectorAll('form[data-gaitcha]');

    forms.forEach(function initForm(form) {
        var endpoint = form.getAttribute('data-gaitcha-endpoint') || '/captcha/init';
        var label = form.getAttribute('data-gaitcha-label') || undefined;
        var containerId = form.getAttribute('data-gaitcha-container') || undefined;
        var container = containerId ? document.getElementById(containerId) : undefined;

        init(form, endpoint, { label: label, container: container });
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

export { init, reset, destroyAll };
