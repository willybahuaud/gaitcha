/**
 * Récupère le token et le field name depuis l'endpoint Ajax.
 *
 * Gère l'auto-refresh avant expiration du TTL.
 */

/**
 * Crée un fetcher pour un endpoint Gaitcha.
 *
 * @param {string} endpoint URL de l'endpoint /captcha/init.
 * @return {object} Fetcher avec fetch(), getFieldName(), getToken(), destroy().
 */
function createAjaxFetcher(endpoint) {
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
     * Effectue le fetch initial vers l'endpoint.
     *
     * @return {Promise<{field_name: string, token: string, ttl: number, token_field_name: string}>}
     */
    function fetchInit() {
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        })
        .then(function handleResponse(response) {
            if (!response.ok) {
                throw new Error('Gaitcha: endpoint returned ' + response.status);
            }
            return response.json();
        })
        .then(function handleData(data) {
            fieldName = data.field_name;
            token = data.token;
            tokenFieldName = data.token_field_name || '_ct';
            ttl = data.ttl || 120;

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
