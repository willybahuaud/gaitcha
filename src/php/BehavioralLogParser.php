<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Parse et valide le log comportemental envoyé par le client.
 *
 * Le log attendu est un JSON avec cette structure :
 * {
 *   "moves": [{"t": int, "x": int, "y": int, "force"?: float, "rX"?: float, "rY"?: float}, ...],
 *   "check": {"type": "click"|"key"|"touch", "t": int, "screenDx"?: int, "screenDy"?: int,
 *             "tapDuration"?: int, "tapForce"?: float, "tapRX"?: float, "tapRY"?: float, ...},
 *   "tabs":  [{"t": int, "key": string, "dur": int}, ...],
 *   "dt":    int,
 *   "coalescedAvg"?: float,
 *   "moveCount"?: int
 * }
 *
 * Champs optionnels :
 * - "dur" (tabs) : durée keydown→keyup en ms. Absent ou 0 si keyup non capté.
 * - "screenDx"/"screenDy" (check) : delta screenX-clientX / screenY-clientY.
 *   Vaut 0 en CDP Playwright (leak de détection), > 0 en vrai navigateur desktop.
 * - "force" (moves) : pression du doigt (0-1). iOS Safari, certains Android. Absent si non supporté.
 * - "rX"/"rY" (moves) : rayon de contact en px. Disponible sur la plupart des devices touch.
 * - "tapDuration" (check) : durée touchstart→touchend sur le widget en ms.
 * - "tapForce" (check) : pression au moment du touchend sur le widget.
 * - "tapRX"/"tapRY" (check) : rayon de contact au moment du touchend.
 * - "coalescedAvg" (log root) : moyenne de getCoalescedEvents().length par mousemove.
 *   > 1 en vrai navigateur (hardware buffer), = 1 en CDP. Absent si non supporté (Safari).
 * - "moveCount" (log root) : nombre total de mousemove reçus avant throttle/buffer circulaire.
 */
class BehavioralLogParser
{
    /** Taille maximale du log JSON en octets (32 Ko). */
    private const MAX_JSON_SIZE = 32768;

    /** Nombre maximum de moves acceptés. */
    private const MAX_MOVES = 100;

    /** Nombre maximum de tabs acceptés. */
    private const MAX_TABS = 50;

    /** Profondeur maximale du JSON. */
    private const MAX_JSON_DEPTH = 10;

    /**
     * Parse le log JSON et retourne un tableau structuré.
     *
     * @param string $json Log JSON brut.
     * @return array{valid: bool, data: array|null, reason: string|null}
     */
    public function parse(string $json): array
    {
        if ($json === '') {
            return $this->invalid('Log vide.');
        }

        if (strlen($json) > self::MAX_JSON_SIZE) {
            return $this->invalid('Log trop volumineux.');
        }

        $data = json_decode($json, true, self::MAX_JSON_DEPTH);

        if (!is_array($data)) {
            return $this->invalid('JSON invalide.');
        }

        if (!isset($data['check']) || !is_array($data['check'])) {
            return $this->invalid('Champ "check" manquant ou invalide.');
        }

        if (!isset($data['check']['type']) || !in_array($data['check']['type'], ['click', 'key', 'touch'], true)) {
            return $this->invalid('Type de check invalide (attendu : click, key, touch).');
        }

        if (!isset($data['check']['t']) || !is_numeric($data['check']['t'])) {
            return $this->invalid('Timestamp du check manquant.');
        }

        if (!isset($data['dt']) || !is_numeric($data['dt'])) {
            return $this->invalid('Champ "dt" manquant.');
        }

        // Normaliser, limiter, et ne garder que les champs attendus.
        $clean = [
            'moves' => array_slice($this->validateMoves($data['moves'] ?? []), 0, self::MAX_MOVES),
            'tabs'  => array_slice($this->validateTabs($data['tabs'] ?? []), 0, self::MAX_TABS),
            'check' => $data['check'],
            'dt'    => (int) $data['dt'],
        ];

        // Champs optionnels anti-CDP — présents uniquement si le client les envoie.
        if (isset($data['coalescedAvg']) && is_numeric($data['coalescedAvg'])) {
            $clean['coalescedAvg'] = (float) $data['coalescedAvg'];
        }
        if (isset($data['moveCount']) && is_numeric($data['moveCount'])) {
            $clean['moveCount'] = (int) $data['moveCount'];
        }

        return [
            'valid'  => true,
            'data'   => $clean,
            'reason' => null,
        ];
    }

    /**
     * Valide et filtre les entrées mousemove/touchmove.
     *
     * @param mixed $moves Données brutes.
     * @return array<int, array{t: int, x: int, y: int}>
     */
    private function validateMoves($moves): array
    {
        if (!is_array($moves)) {
            return [];
        }

        return array_values(array_filter($moves, [$this, 'isValidMove']));
    }

    /**
     * Vérifie qu'une entrée move a le bon format.
     *
     * @param mixed $move Entrée brute.
     * @return bool True si valide.
     */
    private function isValidMove($move): bool
    {
        return is_array($move)
            && isset($move['t'], $move['x'], $move['y'])
            && is_numeric($move['t'])
            && is_numeric($move['x'])
            && is_numeric($move['y']);
    }

    /**
     * Valide et filtre les entrées tab/focus.
     *
     * Chaque entrée contient au minimum {t, key}. Le champ optionnel "dur"
     * (durée d'appui en ms) est préservé s'il est présent.
     *
     * @param mixed $tabs Données brutes.
     * @return array<int, array{t: int, key: string, dur?: int}>
     */
    private function validateTabs($tabs): array
    {
        if (!is_array($tabs)) {
            return [];
        }

        return array_values(array_filter($tabs, [$this, 'isValidTab']));
    }

    /**
     * Vérifie qu'une entrée tab a le bon format.
     *
     * @param mixed $tab Entrée brute.
     * @return bool True si valide.
     */
    private function isValidTab($tab): bool
    {
        return is_array($tab)
            && isset($tab['t'])
            && is_numeric($tab['t']);
    }

    /**
     * Construit un résultat invalide.
     *
     * @param string $reason Description de l'erreur.
     * @return array{valid: bool, data: null, reason: string}
     */
    private function invalid(string $reason): array
    {
        return [
            'valid'  => false,
            'data'   => null,
            'reason' => $reason,
        ];
    }
}
