/**
 * Détecte le premier signal d'interaction humaine sur la page.
 *
 * Une fois détecté, le callback est appelé une seule fois
 * et tous les listeners sont nettoyés.
 */

const TRIGGER_EVENTS = ['mousemove', 'touchstart', 'focus', 'keydown'];

/**
 * Écoute le premier signal d'interaction sur un formulaire.
 *
 * @param {HTMLFormElement} form Formulaire à surveiller.
 * @param {Function} onFirstInteraction Callback appelé une seule fois.
 * @return {Function} Fonction de cleanup pour retirer les listeners.
 */
function listenForFirstInteraction(form, onFirstInteraction) {
    let triggered = false;

    /**
     * Handler déclenché au premier event d'interaction.
     */
    function handleFirstEvent() {
        if (triggered) {
            return;
        }
        triggered = true;
        cleanup();
        onFirstInteraction();
    }

    /**
     * Retire tous les listeners de détection.
     */
    function cleanup() {
        TRIGGER_EVENTS.forEach(function removeEvent(eventType) {
            form.removeEventListener(eventType, handleFirstEvent, { capture: true });
        });
    }

    TRIGGER_EVENTS.forEach(function addEvent(eventType) {
        form.addEventListener(eventType, handleFirstEvent, { capture: true, passive: true });
    });

    return cleanup;
}

export { listenForFirstInteraction };
