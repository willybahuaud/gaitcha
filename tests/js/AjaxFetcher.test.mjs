/**
 * Tests du fetcher : partage de la demande de token en cours.
 *
 * fetch() est remplacé par un faux endpoint dont le test libère les
 * réponses à la main, pour faire se chevaucher les appels.
 */

import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { createAjaxFetcher } from '../../src/js/AjaxFetcher.js';

/** @type {Array<{body: object, respond: Function}>} Requêtes reçues par le faux endpoint. */
let requests = [];

/** @type {Function|undefined} fetch global d'origine, restauré après chaque test. */
let originalFetch;

/**
 * Faux fetch : garde la requête en attente jusqu'à ce que le test réponde.
 *
 * @param {string} url     URL appelée (ignorée).
 * @param {object} options Options fetch.
 * @return {Promise<object>} Réponse minimale { ok, json() }.
 */
function fakeFetch(url, options) {
    return new Promise(function holdRequest(resolve) {
        requests.push({
            body: JSON.parse(options.body),
            respond: function respond(payload) {
                resolve({
                    ok: true,
                    json: function json() { return Promise.resolve(payload); },
                });
            },
        });
    });
}

/**
 * Construit une réponse init valide.
 *
 * @param {string} suffix Suffixe distinguant les tokens entre eux.
 * @return {object} Payload init.
 */
function makeInitPayload(suffix) {
    return { field_name: '_gc_' + suffix, token: 'token-' + suffix, token_field_name: '_ct', ttl: 120 };
}

/**
 * Laisse s'exécuter les callbacks de promesses en attente.
 *
 * @return {Promise<void>}
 */
function flush() {
    return new Promise(function waitTurn(resolve) { setImmediate(resolve); });
}

beforeEach(function installFakeFetch() {
    requests = [];
    originalFetch = globalThis.fetch;
    globalThis.fetch = fakeFetch;
});

afterEach(function restoreFetch() {
    globalThis.fetch = originalFetch;
});

test('deux fetch() qui se chevauchent partagent une seule requête', async function testOverlappingFetchesShareRequest() {
    const fetcher = createAjaxFetcher('/captcha/init', null);
    const first = fetcher.fetch();
    const second = fetcher.fetch();

    assert.equal(requests.length, 1);

    requests[0].respond(makeInitPayload('a'));

    assert.equal((await first).token, 'token-a');
    assert.equal((await second).token, 'token-a');

    fetcher.destroy();
});

test('un fetch() lancé après la réponse du précédent repart du serveur', async function testSequentialFetchesHitServer() {
    const fetcher = createAjaxFetcher('/captcha/init', null);
    const first = fetcher.fetch();
    requests[0].respond(makeInitPayload('a'));
    await first;

    const second = fetcher.fetch();
    assert.equal(requests.length, 2);
    requests[1].respond(makeInitPayload('b'));

    assert.equal((await second).token, 'token-b');
    assert.equal(fetcher.getToken(), 'token-b');

    fetcher.destroy();
});

test('un fetch() en échec ne bloque pas le suivant', async function testFailedFetchIsNotKept() {
    const fetcher = createAjaxFetcher('/captcha/init', null);
    const failed = fetcher.fetch();
    requests[0].respond({ unexpected: true });
    await assert.rejects(failed, /invalid endpoint response/);

    const retry = fetcher.fetch();
    assert.equal(requests.length, 2);
    requests[1].respond(makeInitPayload('c'));

    assert.equal((await retry).token, 'token-c');

    fetcher.destroy();
});

test('des fetch() qui se chevauchent ne résolvent qu\'un seul challenge PoW', async function testOverlappingFetchesSolveOnce() {
    let solveCalls = 0;
    const solver = {
        solve: function solve() {
            solveCalls++;
            return Promise.resolve('42');
        },
    };
    const fetcher = createAjaxFetcher('/captcha/init', solver);
    const first = fetcher.fetch();
    const second = fetcher.fetch();

    requests[0].respond({ pow_challenge: { nonce: 'a'.repeat(32), difficulty: 18, expires: 1, signature: 's' } });
    await flush();

    assert.equal(solveCalls, 1);
    assert.equal(requests.length, 2);
    assert.equal(requests[1].body.pow.solution, '42');

    requests[1].respond(makeInitPayload('d'));

    assert.equal((await first).token, 'token-d');
    assert.equal((await second).token, 'token-d');

    fetcher.destroy();
});
