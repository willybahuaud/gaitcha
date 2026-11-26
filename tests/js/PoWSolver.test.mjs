/**
 * Tests du solveur PoW : isolation des résolutions simultanées.
 *
 * Le Web Worker est remplacé par un faux worker piloté par le test,
 * pour décider de l'ordre dans lequel les résolutions répondent.
 */

import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { createPoWSolver } from '../../src/js/PoWSolver.js';

/** @type {Array<FakeWorker>} Workers créés pendant le test courant. */
let workers = [];

/** @type {Function|undefined} Worker global d'origine, restauré après chaque test. */
let originalWorker;

/**
 * Faux Web Worker : ne calcule rien, le test répond à sa place.
 */
class FakeWorker {
    /**
     * @param {string} url URL du Blob (ignorée).
     */
    constructor(url) {
        this.url = url;
        this.terminated = false;
        this.onmessage = null;
        this.onerror = null;
        workers.push(this);
    }

    /**
     * Enregistre le challenge reçu.
     *
     * @param {object} message Message envoyé au worker.
     */
    postMessage(message) {
        this.message = message;
    }

    /**
     * Marque le worker comme terminé.
     */
    terminate() {
        this.terminated = true;
    }

    /**
     * Simule la réponse du worker.
     *
     * @param {string} solution Solution renvoyée.
     */
    answer(solution) {
        this.onmessage({ data: { solution: solution } });
    }
}

/**
 * Construit un challenge de forme valide.
 *
 * @param {string} digit Chiffre hexadécimal répété pour former le nonce.
 * @return {object} Challenge { nonce, difficulty }.
 */
function makeChallenge(digit) {
    return { nonce: digit.repeat(32), difficulty: 18 };
}

beforeEach(function installFakeWorker() {
    workers = [];
    originalWorker = globalThis.Worker;
    globalThis.Worker = FakeWorker;
});

afterEach(function restoreWorker() {
    globalThis.Worker = originalWorker;
});

test('deux solve() simultanés aboutissent quand le premier lancé répond en premier', async function testFirstAnswersFirst() {
    const solver = createPoWSolver();
    const first = solver.solve(makeChallenge('a'));
    const second = solver.solve(makeChallenge('b'));

    assert.equal(workers.length, 2);

    workers[0].answer('111');

    assert.equal(workers[0].terminated, true);
    assert.equal(workers[1].terminated, false, 'la réponse du premier worker ne doit pas couper le second');

    workers[1].answer('222');

    assert.equal(await first, '111');
    assert.equal(await second, '222');
});

test('deux solve() simultanés aboutissent quand le second lancé répond en premier', async function testSecondAnswersFirst() {
    const solver = createPoWSolver();
    const first = solver.solve(makeChallenge('a'));
    const second = solver.solve(makeChallenge('b'));

    workers[1].answer('222');

    assert.equal(workers[0].terminated, false);

    workers[0].answer('111');

    assert.equal(await first, '111');
    assert.equal(await second, '222');
});

test('chaque worker reçoit son propre challenge', function testOwnChallenge() {
    const solver = createPoWSolver();
    solver.solve(makeChallenge('a'));
    solver.solve(makeChallenge('b'));

    assert.equal(workers[0].message.nonce, 'a'.repeat(32));
    assert.equal(workers[1].message.nonce, 'b'.repeat(32));

    solver.destroy();
});

test('destroy() termine toutes les résolutions en cours', function testDestroyTerminatesAll() {
    const solver = createPoWSolver();
    solver.solve(makeChallenge('a'));
    solver.solve(makeChallenge('b'));

    solver.destroy();

    assert.equal(workers[0].terminated, true);
    assert.equal(workers[1].terminated, true);
});
