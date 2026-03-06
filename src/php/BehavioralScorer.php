<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Calcule un score comportemental à partir du log d'interactions.
 *
 * Trois profils : mouse (clic), keyboard (espace/entrée), touch.
 * Le profil est déterminé automatiquement par le type du check event.
 *
 * Score final : 0.0 (bot certain) à 1.0 (humain certain).
 * Des "kill signals" mettent le score à 0.0 directement.
 */
class BehavioralScorer
{
    /** Nombre minimum de mousemove pour considérer une trajectoire. */
    private const MIN_MOVES = 5;

    /** Délai minimum entre premier event et check (ms). */
    private const MIN_DT_MS = 100;

    /** Délai minimum entre focus et keypress pour être humain (ms). */
    private const MIN_FOCUS_TO_KEY_MS = 30;

    /** Délai maximum raisonnable entre focus et keypress (ms). */
    private const MAX_FOCUS_TO_KEY_MS = 5000;

    /**
     * Calcule le score comportemental.
     *
     * @param array $log Log parsé par BehavioralLogParser.
     * @return array{score: float, profile: string, details: array<string, float>}
     */
    public function score(array $log): array
    {
        $dt = (int) $log['dt'];

        // Kill signal global : interaction trop rapide.
        if ($dt < self::MIN_DT_MS) {
            return $this->result(0.0, 'kill', ['reason' => 'dt_too_short', 'dt' => $dt]);
        }

        $checkType = $log['check']['type'];

        switch ($checkType) {
            case 'click':
                return $this->scoreMouse($log);
            case 'key':
                return $this->scoreKeyboard($log);
            case 'touch':
                return $this->scoreTouch($log);
            default:
                return $this->result(0.0, 'unknown', []);
        }
    }

    /**
     * Scoring profil souris : trajectoire, non-linéarité, offset clic, variation vitesse.
     *
     * @param array $log Log parsé.
     * @return array{score: float, profile: string, details: array<string, float>}
     */
    private function scoreMouse(array $log): array
    {
        $moves   = $log['moves'] ?? [];
        $check   = $log['check'];
        $details = [];

        // Kill signal : aucun mouvement avant le clic.
        if (count($moves) === 0) {
            return $this->result(0.0, 'mouse', ['reason' => 'no_moves']);
        }

        // Kill signal : clic exactement au centre (offset 0,0).
        if (isset($check['offset'])) {
            $ox = (float) ($check['offset']['x'] ?? 0);
            $oy = (float) ($check['offset']['y'] ?? 0);
            if ($ox === 0.0 && $oy === 0.0) {
                return $this->result(0.0, 'mouse', ['reason' => 'perfect_center_click']);
            }
        }

        // Signal 1 : trajectoire existe (poids 0.30).
        $moveCount              = count($moves);
        $trajectoryScore        = min(1.0, $moveCount / self::MIN_MOVES);
        $details['trajectory']  = $trajectoryScore;

        // Signal 2 : non-linéarité (poids 0.25).
        $nonLinearityScore          = $this->calculateNonLinearity($moves);
        $details['non_linearity']   = $nonLinearityScore;

        // Signal 3 : offset du clic (poids 0.25).
        $offsetScore          = $this->calculateClickOffset($check);
        $details['offset']    = $offsetScore;

        // Signal 4 : variation de vitesse (poids 0.20).
        $speedVariationScore      = $this->calculateSpeedVariation($moves);
        $details['speed_variation'] = $speedVariationScore;

        $score = ($trajectoryScore * 0.30)
               + ($nonLinearityScore * 0.25)
               + ($offsetScore * 0.25)
               + ($speedVariationScore * 0.20);

        return $this->result($score, 'mouse', $details);
    }

    /**
     * Scoring profil clavier : tabs, variance timing, cohérence, délai focus→check.
     *
     * @param array $log Log parsé.
     * @return array{score: float, profile: string, details: array<string, float>}
     */
    private function scoreKeyboard(array $log): array
    {
        $tabs    = $log['tabs'] ?? [];
        $check   = $log['check'];
        $details = [];

        // Kill signal : aucun event tab/focus avant le check.
        if (count($tabs) === 0) {
            return $this->result(0.0, 'keyboard', ['reason' => 'no_tabs']);
        }

        // Signal 1 : séquence de tabs existe (poids 0.35).
        $tabCount             = count($tabs);
        $tabExistScore        = min(1.0, $tabCount / 2);
        $details['tab_sequence'] = $tabExistScore;

        // Signal 2 : variance timing entre tabs (poids 0.30).
        $timingVarianceScore         = $this->calculateTimingVariance($tabs);
        $details['timing_variance']  = $timingVarianceScore;

        // Signal 3 : cohérence de navigation (poids 0.20).
        // Au moins 2 tabs = navigation logique.
        $coherenceScore          = $tabCount >= 2 ? 1.0 : 0.5;
        $details['coherence']    = $coherenceScore;

        // Signal 4 : délai focus → check (poids 0.15).
        $focusToCheckScore           = $this->calculateFocusToCheckDelay($tabs, $check);
        $details['focus_to_check']   = $focusToCheckScore;

        $score = ($tabExistScore * 0.35)
               + ($timingVarianceScore * 0.30)
               + ($coherenceScore * 0.20)
               + ($focusToCheckScore * 0.15);

        return $this->result($score, 'keyboard', $details);
    }

    /**
     * Scoring profil touch : similaire au mouse avec buffer plus court.
     *
     * @param array $log Log parsé.
     * @return array{score: float, profile: string, details: array<string, float>}
     */
    private function scoreTouch(array $log): array
    {
        $moves   = $log['moves'] ?? [];
        $check   = $log['check'];
        $details = [];

        // Kill signal : clic exactement au centre.
        if (isset($check['offset'])) {
            $ox = (float) ($check['offset']['x'] ?? 0);
            $oy = (float) ($check['offset']['y'] ?? 0);
            if ($ox === 0.0 && $oy === 0.0) {
                return $this->result(0.0, 'touch', ['reason' => 'perfect_center_touch']);
            }
        }

        // Le touch est plus court, moins de mouvements attendus.
        $minTouchMoves = 3;

        // Signal 1 : trajectoire existe (poids 0.30).
        $moveCount             = count($moves);
        $trajectoryScore       = min(1.0, $moveCount / $minTouchMoves);
        $details['trajectory'] = $trajectoryScore;

        // Signal 2 : non-linéarité (poids 0.25).
        $nonLinearityScore        = count($moves) >= 2 ? $this->calculateNonLinearity($moves) : 0.5;
        $details['non_linearity'] = $nonLinearityScore;

        // Signal 3 : offset du touch (poids 0.25).
        $offsetScore       = $this->calculateClickOffset($check);
        $details['offset'] = $offsetScore;

        // Signal 4 : variation de vitesse (poids 0.20).
        $speedVariationScore        = count($moves) >= 2 ? $this->calculateSpeedVariation($moves) : 0.5;
        $details['speed_variation'] = $speedVariationScore;

        $score = ($trajectoryScore * 0.30)
               + ($nonLinearityScore * 0.25)
               + ($offsetScore * 0.25)
               + ($speedVariationScore * 0.20);

        return $this->result($score, 'touch', $details);
    }

    /**
     * Calcule la non-linéarité d'une trajectoire.
     *
     * Ratio distance parcourue / distance en ligne droite.
     * 1.0 = parfaitement linéaire (bot), > 1.0 = trajectoire naturelle.
     *
     * @param array<int, array{x: int|float, y: int|float}> $moves Points de la trajectoire.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateNonLinearity(array $moves): float
    {
        if (count($moves) < 2) {
            return 0.0;
        }

        $totalDistance = 0.0;
        for ($i = 1; $i < count($moves); $i++) {
            $dx = (float) $moves[$i]['x'] - (float) $moves[$i - 1]['x'];
            $dy = (float) $moves[$i]['y'] - (float) $moves[$i - 1]['y'];
            $totalDistance += sqrt($dx * $dx + $dy * $dy);
        }

        $first          = $moves[0];
        $last           = $moves[count($moves) - 1];
        $dx             = (float) $last['x'] - (float) $first['x'];
        $dy             = (float) $last['y'] - (float) $first['y'];
        $straightDistance = sqrt($dx * $dx + $dy * $dy);

        // Si le point de départ et d'arrivée sont très proches,
        // il y a eu du mouvement sur place = humain.
        if ($straightDistance < 1.0) {
            return $totalDistance > 10.0 ? 1.0 : 0.5;
        }

        $ratio = $totalDistance / $straightDistance;

        // ratio ~1.0 = ligne droite (bot), > 1.1 = trajectoire naturelle.
        // On normalise : ratio 1.0 → 0.0, ratio 1.5+ → 1.0.
        return min(1.0, max(0.0, ($ratio - 1.0) * 2.0));
    }

    /**
     * Calcule le score basé sur l'offset du clic par rapport au centre.
     *
     * @param array $check Check event avec offset optionnel.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateClickOffset(array $check): float
    {
        if (!isset($check['offset'])) {
            return 0.5;
        }

        $ox       = abs((float) ($check['offset']['x'] ?? 0));
        $oy       = abs((float) ($check['offset']['y'] ?? 0));
        $distance = sqrt($ox * $ox + $oy * $oy);

        // 0px = suspect (kill signal géré en amont).
        // 1-15px = zone humaine réaliste → score élevé.
        // > 15px = possible mais de plus en plus hors cible.
        if ($distance < 0.5) {
            return 0.0;
        }
        if ($distance <= 15.0) {
            return 1.0;
        }

        // Au-delà de 15px, score décroissant.
        return max(0.3, 1.0 - (($distance - 15.0) / 30.0));
    }

    /**
     * Calcule la variation de vitesse entre points consécutifs.
     *
     * @param array<int, array{t: int|float, x: int|float, y: int|float}> $moves Points.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateSpeedVariation(array $moves): float
    {
        if (count($moves) < 3) {
            return 0.0;
        }

        $speeds = [];
        for ($i = 1; $i < count($moves); $i++) {
            $dx = (float) $moves[$i]['x'] - (float) $moves[$i - 1]['x'];
            $dy = (float) $moves[$i]['y'] - (float) $moves[$i - 1]['y'];
            $dt = (float) $moves[$i]['t'] - (float) $moves[$i - 1]['t'];

            if ($dt <= 0) {
                continue;
            }

            $speeds[] = sqrt($dx * $dx + $dy * $dy) / $dt;
        }

        if (count($speeds) < 2) {
            return 0.0;
        }

        $mean   = array_sum($speeds) / count($speeds);
        $stddev = $this->standardDeviation($speeds, $mean);

        // Coefficient de variation : stddev / mean.
        // CV = 0 → vitesse constante (bot). CV > 0.3 → variation humaine.
        if ($mean < 0.001) {
            return 0.0;
        }

        $cv = $stddev / $mean;

        return min(1.0, $cv / 0.5);
    }

    /**
     * Calcule la variance du timing entre les tabs.
     *
     * @param array<int, array{t: int|float}> $tabs Events tab/focus.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateTimingVariance(array $tabs): float
    {
        if (count($tabs) < 2) {
            return 0.5;
        }

        $intervals = [];
        for ($i = 1; $i < count($tabs); $i++) {
            $intervals[] = (float) $tabs[$i]['t'] - (float) $tabs[$i - 1]['t'];
        }

        if (count($intervals) < 2) {
            return 0.5;
        }

        $mean   = array_sum($intervals) / count($intervals);
        $stddev = $this->standardDeviation($intervals, $mean);

        if ($mean < 1.0) {
            return 0.0;
        }

        $cv = $stddev / $mean;

        // CV ~0 = intervalles réguliers (bot). CV > 0.3 = humain.
        return min(1.0, $cv / 0.4);
    }

    /**
     * Calcule le score du délai entre le dernier focus et le check.
     *
     * @param array $tabs  Events tab/focus.
     * @param array $check Check event.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateFocusToCheckDelay(array $tabs, array $check): float
    {
        if (empty($tabs)) {
            return 0.0;
        }

        $lastTab = end($tabs);
        $delay   = (float) $check['t'] - (float) $lastTab['t'];

        if ($delay < self::MIN_FOCUS_TO_KEY_MS) {
            return 0.0;
        }

        if ($delay > self::MAX_FOCUS_TO_KEY_MS) {
            return 0.5;
        }

        // Zone optimale : 50-2000ms.
        return 1.0;
    }

    /**
     * Calcule l'écart-type.
     *
     * @param float[] $values Valeurs.
     * @param float   $mean   Moyenne.
     * @return float Écart-type.
     */
    private function standardDeviation(array $values, float $mean): float
    {
        $sumSquaredDiffs = 0.0;
        foreach ($values as $value) {
            $diff = $value - $mean;
            $sumSquaredDiffs += $diff * $diff;
        }

        return sqrt($sumSquaredDiffs / count($values));
    }

    /**
     * Construit le résultat du scoring.
     *
     * @param float  $score   Score de 0.0 à 1.0.
     * @param string $profile Profil détecté.
     * @param array  $details Détails du scoring.
     * @return array{score: float, profile: string, details: array}
     */
    private function result(float $score, string $profile, array $details): array
    {
        return [
            'score'   => round($score, 4),
            'profile' => $profile,
            'details' => $details,
        ];
    }
}
