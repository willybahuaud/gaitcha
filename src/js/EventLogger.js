/**
 * Collecte les événements d'interaction avec la checkbox Gaitcha.
 *
 * - Buffer circulaire de 30 mousemove max (throttle 50ms)
 * - Enregistre les focus changes (tabs)
 * - Enregistre le check event (clic ou clavier)
 * - Se gèle automatiquement quand la checkbox est cochée
 */

const MAX_MOVES = 30;
const THROTTLE_MS = 50;

/**
 * Crée un logger d'événements pour un formulaire.
 *
 * @param {HTMLFormElement} form Formulaire surveillé.
 * @return {object} Logger avec start(), freeze(), getPayload().
 */
function createEventLogger(form) {
    /** @type {Array<{t: number, x: number, y: number}>} */
    let moves = [];

    /** @type {Array<{t: number, key: string}>} */
    let tabs = [];

    /** @type {object|null} */
    let checkEvent = null;

    /** @type {number} Timestamp du premier event détecté. */
    let firstEventTime = 0;

    /** @type {number} Dernier timestamp de mousemove enregistré. */
    let lastMoveTime = 0;

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
        if (frozen) {
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
        if (frozen || !event.touches.length) {
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
        if (frozen) {
            return;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        tabs.push({
            t: Math.round(now - firstEventTime),
            key: 'focus',
        });
    }

    /**
     * Enregistre un keydown pertinent (Tab, Space, Enter).
     *
     * @param {KeyboardEvent} event Événement keydown.
     */
    function handleKeyDown(event) {
        if (frozen) {
            return;
        }

        const validKeys = ['Tab', ' ', 'Enter'];
        if (!validKeys.includes(event.key)) {
            return;
        }

        const now = performance.now();

        if (firstEventTime === 0) {
            firstEventTime = now;
        }

        tabs.push({
            t: Math.round(now - firstEventTime),
            key: event.key,
        });
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

        const isClick = event.type === 'click' || event.type === 'touchend';
        const isTouch = event.type === 'touchend';

        let clientX = 0;
        let clientY = 0;

        if (event.type === 'touchend' && event.changedTouches && event.changedTouches.length) {
            clientX = event.changedTouches[0].clientX;
            clientY = event.changedTouches[0].clientY;
        } else if (event.clientX !== undefined) {
            clientX = event.clientX;
            clientY = event.clientY;
        }

        let type = 'key';
        if (isTouch) {
            type = 'touch';
        } else if (isClick) {
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

        cleanupFns.push(
            function cleanupMouseMove() { form.removeEventListener('mousemove', handleMouseMove); },
            function cleanupTouchMove() { form.removeEventListener('touchmove', handleTouchMove); },
            function cleanupFocusIn() { form.removeEventListener('focusin', handleFocusIn); },
            function cleanupKeyDown() { form.removeEventListener('keydown', handleKeyDown); }
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

        return {
            moves: moves,
            check: checkEvent || { type: 'click', t: 0 },
            tabs: tabs,
            dt: dt,
        };
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
        recordCheck: recordCheck,
        getPayload: getPayload,
        destroy: destroy,
    };
}

export { createEventLogger };
