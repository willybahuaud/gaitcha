/**
 * Orchestre Gaitcha pour un formulaire donné.
 *
 * Enchaîne : détection premier signal → fetch token → injection DOM →
 * collecte events → sérialisation au submit.
 */

import { listenForFirstInteraction } from './InteractionDetector.js';
import { createEventLogger } from './EventLogger.js';
import { createAjaxFetcher } from './AjaxFetcher.js';
import { createDOMInjector } from './DOMInjector.js';
import { attachSubmitSerializer } from './LogSerializer.js';

/**
 * Initialise Gaitcha sur un formulaire.
 *
 * @param {HTMLFormElement} form     Formulaire à protéger.
 * @param {string}          endpoint URL de l'endpoint /captcha/init.
 * @param {object}          options  Options optionnelles.
 * @param {string}          options.label Label de la checkbox.
 * @return {object} Instance avec destroy().
 */
function initGaitchaForm(form, endpoint, options) {
    const label = (options && options.label) || 'Je ne suis pas un robot';

    const logger = createEventLogger(form);
    const fetcher = createAjaxFetcher(endpoint);
    const injector = createDOMInjector(form);

    let detectorCleanup = null;
    let serializerCleanup = null;

    /**
     * Appelé au premier signal d'interaction.
     * Fetch le token, injecte les champs, démarre la collecte.
     */
    function handleFirstInteraction() {
        fetcher.fetch()
            .then(function onFetchSuccess(data) {
                // Injecter les champs.
                injector.inject(data.field_name, data.token, data.token_field_name, label);

                // Démarrer la collecte d'événements.
                logger.start();

                // Écouter le check de la checkbox.
                const checkbox = injector.getCheckbox();
                if (checkbox) {
                    attachCheckboxListeners(checkbox);
                }

                // Sérialiser le log au submit.
                serializerCleanup = attachSubmitSerializer(
                    form,
                    function payloadGetter() { return logger.getPayload(); },
                    function logInputGetter() { return injector.getLogInput(); }
                );

                // Auto-refresh : mettre à jour les champs DOM.
                fetcher.onRefresh(function handleRefresh(newFieldName, newToken, newTokenFieldName) {
                    injector.update(newFieldName, newToken, newTokenFieldName);
                });
            })
            .catch(function onFetchError(error) {
                // eslint-disable-next-line no-console
                console.warn('Gaitcha: initialization failed.');
            });
    }

    /**
     * Attache les listeners de check sur la checkbox.
     *
     * @param {HTMLInputElement} checkbox Checkbox Gaitcha.
     */
    function attachCheckboxListeners(checkbox) {
        /**
         * Handler de clic sur la checkbox.
         *
         * @param {MouseEvent} event Événement click.
         */
        function handleClick(event) {
            if (event.isTrusted === false) {
                return;
            }
            if (checkbox.checked) {
                logger.recordCheck(checkbox, event);
            }
        }

        /**
         * Handler de touch sur la checkbox.
         *
         * @param {TouchEvent} event Événement touchend.
         */
        function handleTouchEnd(event) {
            if (event.isTrusted === false) {
                return;
            }
            if (checkbox.checked) {
                logger.recordCheck(checkbox, event);
            }
        }

        /**
         * Handler clavier : espace ou entrée coche la checkbox.
         *
         * @param {KeyboardEvent} event Événement keydown.
         */
        function handleKeyDown(event) {
            if (event.isTrusted === false) {
                return;
            }
            if ((event.key === ' ' || event.key === 'Enter') && checkbox.checked) {
                logger.recordCheck(checkbox, event);
            }
        }

        checkbox.addEventListener('click', handleClick);
        checkbox.addEventListener('touchend', handleTouchEnd);
        checkbox.addEventListener('keydown', handleKeyDown);
    }

    // Démarrer la détection du premier signal.
    detectorCleanup = listenForFirstInteraction(form, handleFirstInteraction);

    /**
     * Nettoie toutes les ressources.
     */
    function destroy() {
        if (detectorCleanup) {
            detectorCleanup();
        }
        if (serializerCleanup) {
            serializerCleanup();
        }
        logger.destroy();
        fetcher.destroy();
        injector.destroy();
    }

    return {
        destroy: destroy,
    };
}

export { initGaitchaForm };
