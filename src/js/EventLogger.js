/**
 * Collecte les événements d'interaction avec la checkbox Gaitcha.
 *
 * - Buffer circulaire de 30 mousemove max (throttle 50ms)
 * - Compteur total de moves (pré-throttle) pour le serveur
 * - Coalesced events count par mousemove (détection CDP)
 * - Enregistre les focus changes (tabs) et keydown/keyup (dwell time)
 * - Enregistre le check event (clic, clavier ou touch) avec screenDx/screenDy
 * - Se gèle automatiquement quand la checkbox est cochée
 */

const MAX_MOVES = 30;
const MAX_TABS = 50;
const THROTTLE_MS = 50;

/**
 * Crée un logger d'événements pour un formulaire.
 *
 * @param {HTMLFormElement} form Formulaire surveillé.
 * @return {object} Logger avec start(), freeze(), reset(), recordCheck(), getPayload(), destroy().
 */
function createEventLogger(form) {
    /** @type {Array<{t: number, x: number, y: number}>} */
    let moves = [];

    /** @type {Array<{t: number, key: string, dur: number}>} */
    let tabs = [];

    /** @type {Map<string, number>} Touche active → index dans tabs[] (pour calculer dur au keyup). */
    let activeKeys = new Map();

    /** @type {object|null} */
    let checkEvent = null;

    /** @type {number} Timestamp du premier event détecté. */
    let firstEventTime = 0;

    /** @type {number} Dernier timestamp de mousemove enregistré. */
    let lastMoveTime = 0;

    /** @type {number} Nombre total de mousemove reçus (avant throttle). */
    let totalMoveCount = 0;

    /** @type {number} Somme des coalesced events sur les mousemove. */
    let coalescedTotal = 0;

    /** @type {number} Nombre de mousemove ayant eu des coalesced events. */
    let coalescedMoveCount = 0;

    /** @type {boolean} */
    let frozen = false;

    /** @type {Array<Function>} Fonctions de cleanup des listeners. */
    let cleanupFns = [];

    /**
     * Enregistre un mousemove dans le buffer circulaire (throttle 50ms).
     *
     * @param {MouseEvent} event Événement mousemove.
     */
    function handleMouseMove(event) {
        if (frozen || event.isTrusted === false) {
            return;
        }

        // Compteur total AVANT le throttle check — permet au serveur de
        // distinguer le nombre réel de moves vs les 30 du buffer circulaire.
        totalMoveCount++;

        // Coalesced events : un vrai navigateur regroupe plusieurs positions
        // hardware entre chaque frame (moyenne > 1). CDP dispatche un event
        // par appel mouse.move → toujours 1.0.
        if (typeof event.getCoalescedEvents === 'function') {
            coalescedTotal += event.getCoalescedEvents().length;
            coalescedMoveCount++;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        if (now - lastMoveTime < THROTTLE_MS) {
            return;
        }

        lastMoveTime = now;

        moves.push({
            t: Math.round(now - firstEventTime),
            x: Math.round(event.clientX),
            y: Math.round(event.clientY),
        });

        // Buffer circulaire : on garde les N derniers.
        if (moves.length > MAX_MOVES) {
            moves.shift();
        }
    }

    /**
     * Enregistre un touchmove dans le buffer circulaire.
     *
     * @param {TouchEvent} event Événement touchmove.
     */
    function handleTouchMove(event) {
        if (frozen || event.isTrusted === false || !event.touches.length) {
            return;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        if (now - lastMoveTime < THROTTLE_MS) {
            return;
        }

        lastMoveTime = now;

        const touch = event.touches[0];
        moves.push({
            t: Math.round(now - firstEventTime),
            x: Math.round(touch.clientX),
            y: Math.round(touch.clientY),
        });

        if (moves.length > MAX_MOVES) {
            moves.shift();
        }
    }

    /**
     * Enregistre un changement de focus (navigation clavier).
     *
     * @param {FocusEvent} event Événement focusin.
     */
    function handleFocusIn(event) {
        if (frozen || event.isTrusted === false) {
            return;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        if (tabs.length < MAX_TABS) {
            tabs.push({
                t: Math.round(now - firstEventTime),
                key: 'focus',
            });
        }
    }

    /**
     * Enregistre un keydown pertinent (Tab, Shift+Tab, Space, Enter).
     *
     * Ignore les auto-repeat (event.repeat) et stocke l'index dans activeKeys
     * pour calculer la durée d'appui au keyup.
     *
     * @param {KeyboardEvent} event Événement keydown.
     */
    function handleKeyDown(event) {
        if (frozen || event.isTrusted === false || event.repeat) {
            return;
        }

        var key = event.shiftKey && event.key === 'Tab' ? 'Shift+Tab' : event.key;
        if (key !== 'Tab' && key !== 'Shift+Tab' && key !== ' ' && key !== 'Enter') {
            return;
        }

        var now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        if (tabs.length < MAX_TABS) {
            activeKeys.set(event.key, tabs.length);
            tabs.push({
                t: Math.round(now - firstEventTime),
                key: key,
                dur: 0,
            });
        }
    }

    /**
     * Enregistre le keyup pour calculer la durée d'appui (dwell time).
     *
     * Écouté sur document en capture phase pour capter les keyup même si
     * le focus a bougé entre le keydown et le keyup.
     *
     * @param {KeyboardEvent} event Événement keyup.
     */
    function handleKeyUp(event) {
        if (frozen || !activeKeys.has(event.key)) {
            return;
        }

        var now = performance.now();
        var index = activeKeys.get(event.key);
        if (index < tabs.length) {
            tabs[index].dur = Math.round(now - firstEventTime) - tabs[index].t;
        }
        activeKeys.delete(event.key);
    }

    /**
     * Enregistre le check event quand la checkbox est cochée.
     *
     * @param {HTMLInputElement} checkbox Checkbox Gaitcha.
     * @param {Event} event Événement d'origine (click ou keydown).
     */
    function recordCheck(checkbox, event) {
        if (frozen) {
            return;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        const rect = checkbox.getBoundingClientRect();
        const centerX = rect.left + rect.width / 2;
        const centerY = rect.top + rect.height / 2;

        const isTouch = event.type === 'touchend';
        // Un clic clavier (Space/Enter) a event.detail === 0.
        const isRealClick = event.type === 'click' && event.detail > 0;
        const isKeyboardClick = event.type === 'click' && event.detail === 0;

        let clientX = 0;
        let clientY = 0;

        if (isTouch && event.changedTouches && event.changedTouches.length) {
            clientX = event.changedTouches[0].clientX;
            clientY = event.changedTouches[0].clientY;
        } else if (isRealClick && event.clientX !== undefined) {
            clientX = event.clientX;
            clientY = event.clientY;
        }

        let type = 'key';
        if (isTouch) {
            type = 'touch';
        } else if (isRealClick) {
            type = 'click';
        }

        checkEvent = {
            type: type,
            t: Math.round(now - firstEventTime),
        };

        if (type === 'click' || type === 'touch') {
            checkEvent.x = Math.round(clientX);
            checkEvent.y = Math.round(clientY);
            checkEvent.offset = {
                x: Math.round(clientX - centerX),
                y: Math.round(clientY - centerY),
            };

            // CDP leak : un vrai navigateur desktop a screenX > clientX
            // (position fenêtre + chrome). CDP Playwright : screenX === clientX → delta = 0.
            if (event.screenX !== undefined) {
                checkEvent.screenDx = Math.round(event.screenX - event.clientX);
                checkEvent.screenDy = Math.round(event.screenY - event.clientY);
            }
        }

        frozen = true;
    }

    /**
     * Démarre la collecte d'événements sur le formulaire.
     */
    function start() {
        form.addEventListener('mousemove', handleMouseMove, { passive: true });
        form.addEventListener('touchmove', handleTouchMove, { passive: true });
        form.addEventListener('focusin', handleFocusIn, { passive: true });
        form.addEventListener('keydown', handleKeyDown, { passive: true });
        document.addEventListener('keyup', handleKeyUp, true);

        cleanupFns.push(
            function cleanupMouseMove() { form.removeEventListener('mousemove', handleMouseMove); },
            function cleanupTouchMove() { form.removeEventListener('touchmove', handleTouchMove); },
            function cleanupFocusIn() { form.removeEventListener('focusin', handleFocusIn); },
            function cleanupKeyDown() { form.removeEventListener('keydown', handleKeyDown); },
            function cleanupKeyUp() { document.removeEventListener('keyup', handleKeyUp, true); }
        );
    }

    /**
     * Gèle le logger manuellement (si nécessaire en plus du freeze auto au check).
     */
    function freeze() {
        frozen = true;
    }

    /**
     * Retourne le payload compact à envoyer au serveur.
     *
     * @return {object} Payload sérialisable en JSON.
     */
    function getPayload() {
        const dt = checkEvent ? checkEvent.t : (firstEventTime ? Math.round(performance.now() - firstEventTime) : 0);

        var payload = {
            moves: moves,
            check: checkEvent || { type: 'click', t: 0 },
            tabs: tabs,
            dt: dt,
            moveCount: totalMoveCount,
        };

        // Moyenne des coalesced events par mousemove.
        // Vrai navigateur : > 1 (hardware envoie plusieurs positions par frame).
        // CDP : toujours 1.0 (un event dispatché par appel).
        if (coalescedMoveCount > 0) {
            payload.coalescedAvg = Math.round((coalescedTotal / coalescedMoveCount) * 100) / 100;
        }

        return payload;
    }

    /**
     * Remet le logger a zero sans supprimer les listeners.
     *
     * Vide toutes les donnees collectees et degele le logger
     * pour permettre un nouveau cycle de collecte.
     */
    function reset() {
        moves = [];
        tabs = [];
        activeKeys = new Map();
        checkEvent = null;
        firstEventTime = 0;
        lastMoveTime = 0;
        totalMoveCount = 0;
        coalescedTotal = 0;
        coalescedMoveCount = 0;
        frozen = false;
    }

    /**
     * Nettoie tous les listeners.
     */
    function destroy() {
        cleanupFns.forEach(function runCleanup(fn) { fn(); });
        cleanupFns = [];
    }

    return {
        start: start,
        freeze: freeze,
        reset: reset,
        recordCheck: recordCheck,
        getPayload: getPayload,
        destroy: destroy,
    };
}

export { createEventLogger };
