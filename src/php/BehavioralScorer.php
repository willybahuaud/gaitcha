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
     * Scoring profil souris : 7 signaux dont 3 anti-Bézier.
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

        // Signal 1 : trajectoire existe (poids 0.10).
        $moveCount              = count($moves);
        $trajectoryScore        = min(1.0, $moveCount / self::MIN_MOVES);
        $details['trajectory']  = $trajectoryScore;

        // Signal 2 : non-linéarité (poids 0.10).
        $nonLinearityScore          = $this->calculateNonLinearity($moves);
        $details['non_linearity']   = $nonLinearityScore;

        // Signal 3 : offset du clic (poids 0.10).
        $offsetScore          = $this->calculateClickOffset($check);
        $details['offset']    = $offsetScore;

        // Signal 4 : variation de vitesse (poids 0.10).
        $speedVariationScore        = $this->calculateSpeedVariation($moves);
        $details['speed_variation'] = $speedVariationScore;

        // Signal 5 : angular jitter — irrégularité des changements de direction (poids 0.10).
        // Poids réduit : une Bézier quadratique produit aussi des angles variés (CV élevé).
        $angularJitterScore        = $this->calculateAngularJitter($moves);
        $details['angular_jitter'] = $angularJitterScore;

        // Signal 6 : direction reversals — micro-corrections de trajectoire (poids 0.20).
        $directionReversalsScore        = $this->calculateDirectionReversals($moves);
        $details['direction_reversals'] = $directionReversalsScore;

        // Signal 7 : endpoint deceleration — décélération naturelle en fin de trajectoire (poids 0.20).
        $endpointDecelerationScore        = $this->calculateEndpointDeceleration($moves);
        $details['endpoint_deceleration'] = $endpointDecelerationScore;

        $score = ($trajectoryScore * 0.10)
               + ($nonLinearityScore * 0.10)
               + ($offsetScore * 0.10)
               + ($speedVariationScore * 0.10)
               + ($angularJitterScore * 0.10)
               + ($directionReversalsScore * 0.20)
               + ($endpointDecelerationScore * 0.20);

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
     * Scoring profil touch : similaire au mouse avec buffer plus court, 7 signaux.
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

        // Signal 1 : trajectoire existe (poids 0.10).
        $moveCount             = count($moves);
        $trajectoryScore       = min(1.0, $moveCount / $minTouchMoves);
        $details['trajectory'] = $trajectoryScore;

        // Signal 2 : non-linéarité (poids 0.10).
        $nonLinearityScore        = count($moves) >= 2 ? $this->calculateNonLinearity($moves) : 0.5;
        $details['non_linearity'] = $nonLinearityScore;

        // Signal 3 : offset du touch (poids 0.10).
        $offsetScore       = $this->calculateClickOffset($check);
        $details['offset'] = $offsetScore;

        // Signal 4 : variation de vitesse (poids 0.10).
        $speedVariationScore        = count($moves) >= 2 ? $this->calculateSpeedVariation($moves) : 0.5;
        $details['speed_variation'] = $speedVariationScore;

        // Signal 5 : angular jitter (poids 0.10).
        // Besoin d'au moins 4 points pour 2+ angles exploitables.
        $angularJitterScore        = count($moves) >= 4 ? $this->calculateAngularJitter($moves) : 0.5;
        $details['angular_jitter'] = $angularJitterScore;

        // Signal 6 : direction reversals (poids 0.20).
        $directionReversalsScore        = count($moves) >= 4 ? $this->calculateDirectionReversals($moves) : 0.5;
        $details['direction_reversals'] = $directionReversalsScore;

        // Signal 7 : endpoint deceleration (poids 0.20).
        $endpointDecelerationScore        = count($moves) >= 6 ? $this->calculateEndpointDeceleration($moves) : 0.5;
        $details['endpoint_deceleration'] = $endpointDecelerationScore;

        $score = ($trajectoryScore * 0.10)
               + ($nonLinearityScore * 0.10)
               + ($offsetScore * 0.10)
               + ($speedVariationScore * 0.10)
               + ($angularJitterScore * 0.10)
               + ($directionReversalsScore * 0.20)
               + ($endpointDecelerationScore * 0.20);

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
     * Mesure l'irrégularité des changements de direction (angular jitter).
     *
     * Une courbe de Bézier produit des angles très réguliers entre segments.
     * Un humain produit des tremblements → angles irréguliers → CV élevé.
     *
     * @param array<int, array{x: int|float, y: int|float}> $moves Points de la trajectoire.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateAngularJitter(array $moves): float
    {
        if (count($moves) < 3) {
            return 0.0;
        }

        $angles = [];
        for ($i = 1; $i < count($moves) - 1; $i++) {
            // Vecteur entrant (P[i-1] → P[i]).
            $dx1 = (float) $moves[$i]['x'] - (float) $moves[$i - 1]['x'];
            $dy1 = (float) $moves[$i]['y'] - (float) $moves[$i - 1]['y'];

            // Vecteur sortant (P[i] → P[i+1]).
            $dx2 = (float) $moves[$i + 1]['x'] - (float) $moves[$i]['x'];
            $dy2 = (float) $moves[$i + 1]['y'] - (float) $moves[$i]['y'];

            // Angle entre les deux vecteurs via atan2.
            $angle1 = atan2($dy1, $dx1);
            $angle2 = atan2($dy2, $dx2);

            $delta = abs($angle2 - $angle1);
            // Normalise dans [0, π].
            if ($delta > M_PI) {
                $delta = 2 * M_PI - $delta;
            }

            $angles[] = $delta;
        }

        if (count($angles) < 2) {
            return 0.0;
        }

        $mean = array_sum($angles) / count($angles);

        // Moyenne nulle = tous les segments sont colinéaires.
        if ($mean < 0.001) {
            return 0.0;
        }

        $stddev = $this->standardDeviation($angles, $mean);
        $cv     = $stddev / $mean;

        // CV / 0.8, plafonné à 1.0.
        return min(1.0, $cv / 0.8);
    }

    /**
     * Compte les inversions de direction en X et Y.
     *
     * Une trajectoire de Bézier est monotone (ou quasi-monotone).
     * Un humain fait des micro-corrections : overshoot puis retour.
     *
     * @param array<int, array{x: int|float, y: int|float}> $moves Points de la trajectoire.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateDirectionReversals(array $moves): float
    {
        if (count($moves) < 3) {
            return 0.0;
        }

        $reversals    = 0;
        $maxPossible  = 0;

        for ($i = 1; $i < count($moves) - 1; $i++) {
            $dx1 = (float) $moves[$i]['x'] - (float) $moves[$i - 1]['x'];
            $dx2 = (float) $moves[$i + 1]['x'] - (float) $moves[$i]['x'];
            $dy1 = (float) $moves[$i]['y'] - (float) $moves[$i - 1]['y'];
            $dy2 = (float) $moves[$i + 1]['y'] - (float) $moves[$i]['y'];

            // Inversion en X.
            if ($dx1 * $dx2 < 0) {
                $reversals++;
            }
            // Inversion en Y.
            if ($dy1 * $dy2 < 0) {
                $reversals++;
            }

            // 2 axes possibles par triplet.
            $maxPossible += 2;
        }

        if ($maxPossible === 0) {
            return 0.0;
        }

        $ratio = $reversals / $maxPossible;

        // ratio / 0.15, plafonné à 1.0.
        return min(1.0, $ratio / 0.15);
    }

    /**
     * Vérifie la décélération en fin de trajectoire (loi de Fitts).
     *
     * Un humain décélère naturellement en approchant la cible.
     * Un bot avec random delay n'a pas ce pattern.
     *
     * @param array<int, array{t: int|float, x: int|float, y: int|float}> $moves Points.
     * @return float Score de 0.0 à 1.0.
     */
    private function calculateEndpointDeceleration(array $moves): float
    {
        if (count($moves) < 6) {
            return 0.0;
        }

        $count  = count($moves);
        $speeds = [];

        for ($i = 1; $i < $count; $i++) {
            $dx = (float) $moves[$i]['x'] - (float) $moves[$i - 1]['x'];
            $dy = (float) $moves[$i]['y'] - (float) $moves[$i - 1]['y'];
            $dt = (float) $moves[$i]['t'] - (float) $moves[$i - 1]['t'];

            if ($dt <= 0) {
                continue;
            }

            $speeds[] = sqrt($dx * $dx + $dy * $dy) / $dt;
        }

        if (count($speeds) < 3) {
            return 0.0;
        }

        $speedCount = count($speeds);

        // Divise en 3 tiers.
        $midStart = (int) floor($speedCount * 0.3);
        $endStart = (int) floor($speedCount * 0.7);

        $midSpeeds = array_slice($speeds, $midStart, $endStart - $midStart);
        $endSpeeds = array_slice($speeds, $endStart);

        if (empty($midSpeeds) || empty($endSpeeds)) {
            return 0.0;
        }

        $midAvg = array_sum($midSpeeds) / count($midSpeeds);
        $endAvg = array_sum($endSpeeds) / count($endSpeeds);

        // Évite la division par zéro.
        if ($midAvg < 0.001) {
            return 0.0;
        }

        $ratio = $endAvg / $midAvg;

        // ratio < 0.8 = forte décélération → score max.
        // ratio > 1.2 = pas de décélération → score 0.
        if ($ratio <= 0.8) {
            return 1.0;
        }
        if ($ratio >= 1.2) {
            return 0.0;
        }

        // Interpolation linéaire entre 0.8 et 1.2.
        return 1.0 - (($ratio - 0.8) / 0.4);
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
