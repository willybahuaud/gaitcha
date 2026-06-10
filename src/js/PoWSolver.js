/**
 * Résout les challenges de preuve d'effort (PoW) envoyés par le serveur.
 *
 * Le client doit trouver un compteur tel que sha256(nonce + '.' + compteur)
 * commence par `difficulty` bits à zéro. La résolution tourne dans un
 * Web Worker (créé depuis un Blob, aucun fichier séparé à servir) pour
 * ne pas bloquer le main thread. Si le Worker est indisponible (CSP
 * worker-src stricte, vieux navigateur), fallback en main thread par
 * tranches via setTimeout.
 */

/** @type {number} Difficulté maximum acceptée côté client (protection contre un serveur mal configuré). */
var MAX_CLIENT_DIFFICULTY = 26;

/** @type {number} Délai maximum de résolution avant abandon (ms). */
var SOLVE_TIMEOUT_MS = 30000;

/** @type {number} Itérations par tranche en fallback main thread. */
var FALLBACK_CHUNK_SIZE = 8000;

/** @type {string} Marqueur interne pour déclencher le fallback main thread. */
var WORKER_FAILURE_MARKER = 'gaitcha-pow-worker-failure';

/**
 * Crée le moteur de hachage et de résolution PoW.
 *
 * IMPORTANT : cette fonction doit rester auto-contenue (aucune référence
 * externe) car son code source est sérialisé via toString() pour être
 * injecté dans le Web Worker.
 *
 * @return {object} Moteur avec solveRange(nonce, difficulty, start, count).
 */
function createPoWEngine() {
    /* eslint-disable no-bitwise */
    var ROUND_CONSTANTS = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];

    var messageSchedule = new Int32Array(64);

    /**
     * Calcule les 32 premiers bits du SHA-256 d'un message ASCII court.
     *
     * Optimisé pour le PoW : le message (nonce hex + '.' + compteur décimal)
     * tient toujours dans un seul bloc de 64 octets après padding (< 56 octets),
     * et seuls les 32 premiers bits du hash sont nécessaires pour compter
     * les zéros de tête (difficulté plafonnée à 26 bits).
     *
     * @param {string} message Message ASCII de moins de 56 caractères.
     * @return {number} Premier mot (32 bits) du hash SHA-256.
     */
    function sha256FirstWord(message) {
        var length = message.length;
        var i;

        for (i = 0; i < 16; i++) {
            messageSchedule[i] = 0;
        }

        for (i = 0; i < length; i++) {
            messageSchedule[i >> 2] |= message.charCodeAt(i) << ((3 - (i & 3)) << 3);
        }

        messageSchedule[length >> 2] |= 0x80 << ((3 - (length & 3)) << 3);
        messageSchedule[15] = length << 3;

        for (i = 16; i < 64; i++) {
            var word15 = messageSchedule[i - 15];
            var word2 = messageSchedule[i - 2];
            var sigma0 = ((word15 >>> 7) | (word15 << 25)) ^ ((word15 >>> 18) | (word15 << 14)) ^ (word15 >>> 3);
            var sigma1 = ((word2 >>> 17) | (word2 << 15)) ^ ((word2 >>> 19) | (word2 << 13)) ^ (word2 >>> 10);
            messageSchedule[i] = (messageSchedule[i - 16] + sigma0 + messageSchedule[i - 7] + sigma1) | 0;
        }

        var a = 0x6a09e667 | 0;
        var b = 0xbb67ae85 | 0;
        var c = 0x3c6ef372 | 0;
        var d = 0xa54ff53a | 0;
        var e = 0x510e527f | 0;
        var f = 0x9b05688c | 0;
        var g = 0x1f83d9ab | 0;
        var h = 0x5be0cd19 | 0;

        for (i = 0; i < 64; i++) {
            var upperSigma1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
            var choose = (e & f) ^ (~e & g);
            var temp1 = (h + upperSigma1 + choose + ROUND_CONSTANTS[i] + messageSchedule[i]) | 0;
            var upperSigma0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
            var majority = (a & b) ^ (a & c) ^ (b & c);
            var temp2 = (upperSigma0 + majority) | 0;

            h = g;
            g = f;
            f = e;
            e = (d + temp1) | 0;
            d = c;
            c = b;
            b = a;
            a = (temp1 + temp2) | 0;
        }

        return (0x6a09e667 + a) | 0;
    }

    /**
     * Cherche une solution dans une plage de compteurs.
     *
     * @param {string} nonce      Nonce hexadécimal du challenge.
     * @param {number} difficulty Nombre de bits à zéro attendus.
     * @param {number} start      Compteur de départ (inclus).
     * @param {number} count      Nombre d'itérations à tester.
     * @return {number} Le compteur solution, ou -1 si non trouvé dans la plage.
     */
    function solveRange(nonce, difficulty, start, count) {
        var prefix = nonce + '.';
        var end = start + count;

        for (var counter = start; counter < end; counter++) {
            if (Math.clz32(sha256FirstWord(prefix + counter)) >= difficulty) {
                return counter;
            }
        }

        return -1;
    }
    /* eslint-enable no-bitwise */

    return { solveRange: solveRange };
}

/**
 * Point d'entrée du Web Worker.
 *
 * IMPORTANT : auto-contenue elle aussi (sérialisée via toString()).
 * Reçoit { nonce, difficulty }, répond { solution } ou { error }.
 *
 * @param {Function} engineFactory Fabrique du moteur PoW (createPoWEngine).
 */
function powWorkerMain(engineFactory) {
    var engine = engineFactory();
    var CHUNK_SIZE = 25000;
    var MAX_ITERATIONS = 1073741824;

    self.onmessage = function handleSolveRequest(event) {
        var challenge = event.data;
        var counter = 0;

        while (counter < MAX_ITERATIONS) {
            var solution = engine.solveRange(challenge.nonce, challenge.difficulty, counter, CHUNK_SIZE);

            if (solution >= 0) {
                self.postMessage({ solution: String(solution) });
                return;
            }

            counter += CHUNK_SIZE;
        }

        self.postMessage({ error: 'exhausted' });
    };
}

/**
 * Vérifie qu'un challenge reçu du serveur a une forme exploitable.
 *
 * @param {*} challenge Challenge à vérifier.
 * @return {boolean} True si le challenge est exploitable.
 */
function isValidChallenge(challenge) {
    return (
        challenge !== null &&
        typeof challenge === 'object' &&
        typeof challenge.nonce === 'string' &&
        /^[a-f0-9]{32}$/.test(challenge.nonce) &&
        typeof challenge.difficulty === 'number' &&
        challenge.difficulty >= 1 &&
        challenge.difficulty <= MAX_CLIENT_DIFFICULTY
    );
}

/**
 * Crée un solveur PoW.
 *
 * @return {object} Solveur avec solve(challenge) → Promise<string> et destroy().
 */
function createPoWSolver() {
    /** @type {Worker|null} */
    var activeWorker = null;

    /** @type {string|null} */
    var blobUrl = null;

    /**
     * Termine le worker actif et libère l'URL du Blob.
     */
    function cleanupWorker() {
        if (activeWorker) {
            activeWorker.terminate();
            activeWorker = null;
        }
        if (blobUrl) {
            URL.revokeObjectURL(blobUrl);
            blobUrl = null;
        }
    }

    /**
     * Résout le challenge dans un Web Worker.
     *
     * @param {object} challenge Challenge { nonce, difficulty }.
     * @return {Promise<string>} La solution (compteur en chaîne décimale).
     */
    function solveWithWorker(challenge) {
        return new Promise(function runWorkerSolve(resolve, reject) {
            try {
                var source = '(' + powWorkerMain.toString() + ')(' + createPoWEngine.toString() + ');';
                blobUrl = URL.createObjectURL(new Blob([source], { type: 'application/javascript' }));
                activeWorker = new Worker(blobUrl);
            } catch (creationError) {
                cleanupWorker();
                reject(new Error(WORKER_FAILURE_MARKER));
                return;
            }

            var timeoutTimer = setTimeout(function handleSolveTimeout() {
                cleanupWorker();
                reject(new Error('Gaitcha: proof-of-work timed out.'));
            }, SOLVE_TIMEOUT_MS);

            activeWorker.onmessage = function handleWorkerResult(event) {
                clearTimeout(timeoutTimer);
                var data = event.data || {};
                cleanupWorker();

                if (typeof data.solution === 'string') {
                    resolve(data.solution);
                } else {
                    reject(new Error('Gaitcha: proof-of-work failed.'));
                }
            };

            activeWorker.onerror = function handleWorkerError() {
                clearTimeout(timeoutTimer);
                cleanupWorker();
                reject(new Error(WORKER_FAILURE_MARKER));
            };

            activeWorker.postMessage({ nonce: challenge.nonce, difficulty: challenge.difficulty });
        });
    }

    /**
     * Résout le challenge sur le main thread, par tranches via setTimeout
     * pour ne pas geler l'interface.
     *
     * @param {object} challenge Challenge { nonce, difficulty }.
     * @return {Promise<string>} La solution (compteur en chaîne décimale).
     */
    function solveOnMainThread(challenge) {
        return new Promise(function runMainThreadSolve(resolve, reject) {
            var engine = createPoWEngine();
            var counter = 0;
            var deadline = Date.now() + SOLVE_TIMEOUT_MS;

            /**
             * Traite une tranche d'itérations puis rend la main.
             */
            function solveNextChunk() {
                if (Date.now() > deadline) {
                    reject(new Error('Gaitcha: proof-of-work timed out.'));
                    return;
                }

                var solution = engine.solveRange(challenge.nonce, challenge.difficulty, counter, FALLBACK_CHUNK_SIZE);

                if (solution >= 0) {
                    resolve(String(solution));
                    return;
                }

                counter += FALLBACK_CHUNK_SIZE;
                setTimeout(solveNextChunk, 0);
            }

            solveNextChunk();
        });
    }

    /**
     * Résout un challenge PoW.
     *
     * Tente le Web Worker en premier, bascule en main thread si le
     * worker est indisponible (CSP, navigateur) ou échoue à se lancer.
     *
     * @param {object} challenge Challenge { nonce, difficulty } reçu du serveur.
     * @return {Promise<string>} La solution (compteur en chaîne décimale).
     */
    function solve(challenge) {
        if (!isValidChallenge(challenge)) {
            return Promise.reject(new Error('Gaitcha: invalid proof-of-work challenge.'));
        }

        if (typeof Worker === 'undefined' || typeof URL === 'undefined' || typeof Blob === 'undefined') {
            return solveOnMainThread(challenge);
        }

        return solveWithWorker(challenge).catch(function handleWorkerFailure(error) {
            if (error && error.message === WORKER_FAILURE_MARKER) {
                return solveOnMainThread(challenge);
            }
            throw error;
        });
    }

    /**
     * Annule toute résolution en cours et libère les ressources.
     */
    function destroy() {
        cleanupWorker();
    }

    return {
        solve: solve,
        destroy: destroy,
    };
}

export { createPoWSolver, createPoWEngine };
