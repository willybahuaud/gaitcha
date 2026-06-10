/**
 * Récupère le token et le field name depuis l'endpoint Ajax.
 *
 * Gère l'auto-refresh avant expiration du TTL, ainsi que la preuve
 * d'effort (PoW) si le serveur l'exige : quand la réponse contient
 * un pow_challenge, le challenge est résolu puis la requête est
 * rejouée avec la solution. Chaque refresh résout un nouveau challenge.
 */

/** Pattern attendu pour les field names (préfixe + 8 hex). */
var FIELD_NAME_PATTERN = /^[a-zA-Z0-9_-]+$/;

/**
 * Vérifie que la réponse de l'endpoint a le bon format.
 *
 * @param {*} data Réponse parsée.
 * @return {boolean} True si le format est valide.
 */
function isValidResponse(data) {
    return (
        data !== null &&
        typeof data === 'object' &&
        typeof data.field_name === 'string' &&
        FIELD_NAME_PATTERN.test(data.field_name) &&
        typeof data.token === 'string' &&
        data.token.length > 0
    );
}

/** @type {number} Nombre maximum de challenges PoW résolus pour un même init. */
var MAX_POW_ATTEMPTS = 3;

/**
 * Crée un fetcher pour un endpoint Gaitcha.
 *
 * @param {string}      endpoint  URL de l'endpoint /captcha/init.
 * @param {object|null} powSolver Solveur PoW (optionnel, requis si le serveur exige une preuve).
 * @return {object} Fetcher avec fetch(), onRefresh(), getFieldName(), getToken(), getTokenFieldName(), destroy().
 */
function createAjaxFetcher(endpoint, powSolver) {
    /** @type {string|null} */
    let fieldName = null;

    /** @type {string|null} */
    let token = null;

    /** @type {string|null} */
    let tokenFieldName = null;

    /** @type {number} TTL en secondes. */
    let ttl = 120;

    /** @type {number|null} Timer ID pour l'auto-refresh. */
    let refreshTimer = null;

    /** @type {Function|null} Callback à appeler quand les données changent. */
    let onRefreshCallback = null;

    /**
     * Envoie une requête init à l'endpoint.
     *
     * @param {object|null} powPayload Preuve d'effort (challenge + solution), ou null au premier appel.
     * @return {Promise<object>} Réponse JSON brute de l'endpoint.
     */
    function requestInit(powPayload) {
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(powPayload ? { pow: powPayload } : {}),
        })
        .then(function handleResponse(response) {
            if (!response.ok) {
                throw new Error('Gaitcha: endpoint returned ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * Effectue le fetch vers l'endpoint, en résolvant les challenges
     * PoW renvoyés par le serveur si nécessaire.
     *
     * @param {number} attempt Nombre de challenges déjà résolus pour cet init.
     * @return {Promise<{field_name: string, token: string, ttl: number, token_field_name: string}>}
     */
    function fetchWithPow(attempt) {
        return requestInit(null).then(function handleInitData(data) {
            if (data && data.pow_challenge) {
                return solveAndRetry(data.pow_challenge, attempt);
            }
            return data;
        });
    }

    /**
     * Résout un challenge PoW puis rejoue la requête init avec la solution.
     *
     * Si le serveur renvoie encore un challenge (expiré, replay…),
     * réessaie jusqu'à MAX_POW_ATTEMPTS.
     *
     * @param {object} challenge Challenge { nonce, difficulty, expires, signature }.
     * @param {number} attempt   Nombre de challenges déjà résolus.
     * @return {Promise<object>} Réponse init du serveur.
     */
    function solveAndRetry(challenge, attempt) {
        if (!powSolver) {
            return Promise.reject(new Error('Gaitcha: server requires proof-of-work but no solver is available.'));
        }
        if (attempt >= MAX_POW_ATTEMPTS) {
            return Promise.reject(new Error('Gaitcha: proof-of-work rejected after ' + attempt + ' attempts.'));
        }

        return powSolver.solve(challenge).then(function submitSolution(solution) {
            return requestInit({
                nonce: challenge.nonce,
                difficulty: challenge.difficulty,
                expires: challenge.expires,
                signature: challenge.signature,
                solution: solution,
            });
        }).then(function handleRetryData(data) {
            if (data && data.pow_challenge) {
                return solveAndRetry(data.pow_challenge, attempt + 1);
            }
            return data;
        });
    }

    /**
     * Effectue le fetch initial vers l'endpoint (PoW comprise si exigée).
     *
     * @return {Promise<{field_name: string, token: string, ttl: number, token_field_name: string}>}
     */
    function fetchInit() {
        return fetchWithPow(0).then(function handleData(data) {
            if (!isValidResponse(data)) {
                throw new Error('Gaitcha: invalid endpoint response.');
            }

            fieldName = data.field_name;
            token = data.token;
            tokenFieldName = data.token_field_name || '_ct';
            // Borner le TTL entre 30s et 600s.
            ttl = Math.max(30, Math.min(data.ttl || 120, 600));

            scheduleRefresh();

            return data;
        });
    }

    /**
     * Programme l'auto-refresh du token avant l'expiration du TTL.
     * Refresh à 75% du TTL pour garder une marge.
     */
    function scheduleRefresh() {
        clearRefreshTimer();

        const refreshDelay = Math.floor(ttl * 0.75) * 1000;

        refreshTimer = setTimeout(function doRefresh() {
            fetchInit().then(function notifyRefresh() {
                if (onRefreshCallback) {
                    onRefreshCallback(fieldName, token, tokenFieldName);
                }
            }).catch(function handleRefreshError() {
                // eslint-disable-next-line no-console
                console.warn('Gaitcha: token refresh failed.');
            });
        }, refreshDelay);
    }

    /**
     * Annule le timer de refresh.
     */
    function clearRefreshTimer() {
        if (refreshTimer !== null) {
            clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    /**
     * Enregistre un callback appelé à chaque refresh de token.
     *
     * @param {Function} callback Reçoit (fieldName, token, tokenFieldName).
     */
    function onRefresh(callback) {
        onRefreshCallback = callback;
    }

    /**
     * @return {string|null} Nom du champ aléatoire.
     */
    function getFieldName() {
        return fieldName;
    }

    /**
     * @return {string|null} Token HMAC signé.
     */
    function getToken() {
        return token;
    }

    /**
     * @return {string|null} Nom du champ hidden pour le token.
     */
    function getTokenFieldName() {
        return tokenFieldName;
    }

    /**
     * Nettoie le timer de refresh.
     */
    function destroy() {
        clearRefreshTimer();
    }

    return {
        fetch: fetchInit,
        onRefresh: onRefresh,
        getFieldName: getFieldName,
        getToken: getToken,
        getTokenFieldName: getTokenFieldName,
        destroy: destroy,
    };
}

export { createAjaxFetcher };
