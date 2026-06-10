/**
 * Orchestre Gaitcha pour un formulaire donné.
 *
 * Enchaîne : placeholder au chargement → détection premier signal →
 * résolution PoW (si exigée) + fetch token → activation du widget →
 * collecte events → sérialisation au submit.
 */

import { listenForFirstInteraction } from './InteractionDetector.js';
import { createEventLogger } from './EventLogger.js';
import { createAjaxFetcher } from './AjaxFetcher.js';
import { createDOMInjector } from './DOMInjector.js';
import { createPoWSolver } from './PoWSolver.js';
import { attachSubmitSerializer } from './LogSerializer.js';

/**
 * Initialise Gaitcha sur un formulaire.
 *
 * @param {HTMLFormElement} form     Formulaire à protéger.
 * @param {string}          endpoint URL de l'endpoint /captcha/init.
 * @param {object}          options            Options optionnelles.
 * @param {string}          options.label      Label de la checkbox.
 * @param {HTMLElement}     options.container  Conteneur cible pour l'injection DOM.
 * @param {string}          options.theme      Theme du widget : 'light' (defaut), 'dark', ou 'auto'.
 * @param {string}          options.style      Style du widget : 'default' ou 'minimal'.
 * @return {object} Instance avec destroy() et reset().
 */
function initGaitchaForm(form, endpoint, options) {
    const label = (options && options.label) || 'Je ne suis pas un robot';
    const targetContainer = (options && options.container) || null;
    const theme = (options && options.theme) || 'light';
    const style = (options && options.style) || 'default';

    const logger = createEventLogger(form);
    const solver = createPoWSolver();
    const fetcher = createAjaxFetcher(endpoint, solver);
    const injector = createDOMInjector(form, targetContainer, theme, style);

    let detectorCleanup = null;
    let serializerCleanup = null;

    // Placeholder visible dès le chargement : annonce le captcha et
    // réserve l'espace (zéro layout shift à l'activation).
    injector.injectPlaceholder(label);

    /**
     * Appelé au premier signal d'interaction.
     * Démarre la collecte, résout la PoW si exigée, fetch le token,
     * puis active le widget.
     */
    function handleFirstInteraction() {
        // Démarrer la collecte immédiatement : la trajectoire pendant
        // la résolution PoW est du signal comportemental utile.
        logger.start();

        injector.setSolving(true);

        fetcher.fetch()
            .then(function onFetchSuccess(data) {
                // Activer le widget (upgrade du placeholder en place).
                injector.inject(data.field_name, data.token, data.token_field_name, label);

                // Enregistrer le check via le callback du widget.
                // Passer le widget visible (pas le checkbox caché 0×0px)
                // pour un calcul d'offset pertinent.
                injector.onCheck(function handleCheck(event, tapData) {
                    logger.recordCheck(injector.getWidget(), event, tapData);

                    // Sérialiser le log immédiatement — le logger est frozen,
                    // le payload ne changera plus. Nécessaire pour les plugins
                    // qui soumettent via AJAX sans déclencher form.submit()
                    // (Ninja Forms, etc.).
                    var logInput = injector.getLogInput();
                    if (logInput) {
                        logInput.value = JSON.stringify(logger.getPayload());
                    }
                });

                // Sérialiser le log au submit (seulement si le widget a été coché).
                serializerCleanup = attachSubmitSerializer(
                    form,
                    function payloadGetter() { return logger.getPayload(); },
                    function logInputGetter() { return injector.getLogInput(); },
                    function checkboxGetter() { return injector.getCheckbox(); }
                );

                // Auto-refresh : mettre à jour les champs DOM.
                fetcher.onRefresh(function handleRefresh(newFieldName, newToken, newTokenFieldName) {
                    injector.update(newFieldName, newToken, newTokenFieldName);
                });
            })
            .catch(function onFetchError(error) {
                injector.setSolving(false);
                // eslint-disable-next-line no-console
                console.warn('Gaitcha: initialization failed.');
            });
    }

    // Démarrer la détection du premier signal.
    detectorCleanup = listenForFirstInteraction(form, handleFirstInteraction);

    /**
     * Remet le captcha en état initial pour un nouveau cycle.
     *
     * Décoche le widget, vide le log comportemental, et demande
     * un nouveau token au serveur. Appelé après un rejet serveur
     * pour permettre à l'utilisateur de réessayer.
     */
    function reset() {
        injector.reset();
        logger.reset();

        // Demander un nouveau token et mettre à jour les champs.
        fetcher.fetch()
            .then(function onResetFetchSuccess(data) {
                injector.update(data.field_name, data.token, data.token_field_name);
            })
            .catch(function onResetFetchError() {
                // eslint-disable-next-line no-console
                console.warn('Gaitcha: token refresh after reset failed.');
            });
    }

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
        solver.destroy();
        injector.destroy();
    }

    return {
        destroy: destroy,
        reset: reset,
    };
}

export { initGaitchaForm };
