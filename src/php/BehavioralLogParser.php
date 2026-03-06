<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Parse et valide le log comportemental envoyé par le client.
 *
 * Le log attendu est un JSON avec cette structure :
 * {
 *   "moves": [{"t": int, "x": int, "y": int}, ...],
 *   "check": {"type": "click"|"key", "t": int, ...},
 *   "tabs":  [{"t": int, "key": string}, ...],
 *   "dt":    int
 * }
 */
class BehavioralLogParser
{
    /**
     * Parse le log JSON et retourne un tableau structuré.
     *
     * @param string $json Log JSON brut.
     * @return array{valid: bool, data: array|null, reason: string|null}
     */
    public function parse(string $json): array
    {
        if (empty($json)) {
            return $this->invalid('Log vide.');
        }

        $data = json_decode($json, true);

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

        // Normaliser les champs optionnels.
        $data['moves'] = $this->validateMoves($data['moves'] ?? []);
        $data['tabs']  = $this->validateTabs($data['tabs'] ?? []);
        $data['dt']    = (int) $data['dt'];

        return [
            'valid'  => true,
            'data'   => $data,
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

        return array_values(array_filter($moves, function ($move): bool {
            return is_array($move)
                && isset($move['t'], $move['x'], $move['y'])
                && is_numeric($move['t'])
                && is_numeric($move['x'])
                && is_numeric($move['y']);
        }));
    }

    /**
     * Valide et filtre les entrées tab/focus.
     *
     * @param mixed $tabs Données brutes.
     * @return array<int, array{t: int, key: string}>
     */
    private function validateTabs($tabs): array
    {
        if (!is_array($tabs)) {
            return [];
        }

        return array_values(array_filter($tabs, function ($tab): bool {
            return is_array($tab)
                && isset($tab['t'])
                && is_numeric($tab['t']);
        }));
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
