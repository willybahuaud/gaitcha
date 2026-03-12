<?php

declare(strict_types=1);

namespace Gaitcha;

/**
 * Calcule un score comportemental à partir du log d'interactions.
 *
 * Scoring multi-profil : le profil primaire est déterminé par le type du
 * check event, mais un profil secondaire est scoré si des données existent
 * (ex: moves pour un check clavier). Le score final est le max des deux
 * profils, sauf si le profil primaire déclenche un kill signal (autoritaire).
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
     * Scoring multi-profil :
     * 1. Kill signal global dt < 100ms
     * 2. Score le profil primaire (déterminé par check.type)
     *    → si kill signal → retour immédiat (autoritaire)
     * 3. Score le profil secondaire si données disponibles
     * 4. Retourne le max des scores (bénéfice du doute pour l'humain)
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

        // Profil primaire — déterminé par le type de check.
        $primaryScorer = [
            'click' => 'scoreMouse',
            'key'   => 'scoreKeyboard',
            'touch' => 'scoreTouch',
        ];

        if (!isset($primaryScorer[$checkType])) {
            return $this->result(0.0, 'unknown', []);
        }

        $primary = $this->{$primaryScorer[$checkType]}($log);

        // Kill signal du profil primaire = autoritaire, pas de rattrapage.
        if ($primary['score'] === 0.0) {
            return $primary;
        }

        // Profil secondaire — scorer l'autre profil si des données significatives existent.
        $secondary = null;

        $hasMoves    = count($log['moves'] ?? []) >= self::MIN_MOVES;
        $hasKeyTabs  = $this->hasActualKeyPresses($log['tabs'] ?? [], 3);

        if ($checkType !== 'click' && $checkType !== 'touch' && $hasMoves) {
            $secondary = $this->scoreMouse($log);
        } elseif ($checkType !== 'key' && $hasKeyTabs) {
            $secondary = $this->scoreKeyboard($log);
        }

        // Retourne le max des deux profils (bénéfice du doute).
        if ($secondary !== null && $secondary['score'] > $primary['score']) {
            $secondary['profile'] .= '+secondary';
            return $secondary;
        }

        return $primary;
    }

    /**
     * Scoring profil souris : 10 signaux dont 3 anti-Bézier et 2 anti-CDP.
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

        // Signal 1 : trajectoire existe (poids 0.05).
        $moveCount              = count($moves);
        $trajectoryScore        = min(1.0, $moveCount / self::MIN_MOVES);
        $details['trajectory']  = $trajectoryScore;

        // Signal 2 : non-linéarité (poids 0.05).
        $nonLinearityScore          = $this->calculateNonLinearity($moves);
        $details['non_linearity']   = $nonLinearityScore;

        // Signal 3 : offset du clic (poids 0.10).
        $offsetScore          = $this->calculateClickOffset($check);
        $details['offset']    = $offsetScore;

        // Signal 4 : variation de vitesse (poids 0.05).
        $speedVariationScore        = $this->calculateSpeedVariation($moves);
        $details['speed_variation'] = $speedVariationScore;

        // Signal 5 : angular jitter (poids 0.05).
        $angularJitterScore        = $this->calculateAngularJitter($moves);
        $details['angular_jitter'] = $angularJitterScore;

        // Signal 6 : direction reversals (poids 0.15).
        $directionReversalsScore        = $this->calculateDirectionReversals($moves);
        $details['direction_reversals'] = $directionReversalsScore;

        // Signal 7 : endpoint deceleration (poids 0.15).
        $endpointDecelerationScore        = $this->calculateEndpointDeceleration($moves);
        $details['endpoint_deceleration'] = $endpointDecelerationScore;

        // Signal 8 : speed autocorrelation lag-1 (poids 0.15).
        // Humain : > 0.7 (inertie physique). Bot random : ~0.
        $speedAutocorrelationScore        = $this->calculateSpeedAutocorrelation($moves);
        $details['speed_autocorrelation'] = $speedAutocorrelationScore;

        // Signal 9 : CDP screen delta (poids 0.10).
        // Vrai navigateur : screenX - clientX > 0. CDP : screenX === clientX → delta 0.
        $cdpScreenDeltaScore        = $this->calculateCdpScreenDelta($check);
        $details['cdp_screen_delta'] = $cdpScreenDeltaScore;

        // Signal 10 : coalesced events ratio (poids 0.05).
        // Vrai navigateur : coalescedAvg > 1. CDP : toujours 1.0.
        $coalescedRatioScore        = $this->calculateCoalescedRatio($log);
        $details['coalesced_ratio'] = $coalescedRatioScore;

        $score = ($trajectoryScore * 0.05)
               + ($nonLinearityScore * 0.05)
               + ($offsetScore * 0.10)
               + ($speedVariationScore * 0.05)
               + ($angularJitterScore * 0.05)
               + ($directionReversalsScore * 0.15)
               + ($endpointDecelerationScore * 0.15)
               + ($speedAutocorrelationScore * 0.15)
               + ($cdpScreenDeltaScore * 0.10)
               + ($coalescedRatioScore * 0.05);

        return $this->result($score, 'mouse', $details);
    }

    /**
     * Scoring profil clavier : 9 signaux dont 4 physiologiques + autocorrelation.
     *
     * Signaux hérités (poids réduits — faciles à simuler) :
     * - tab_sequence (0.05), timing_variance (0.05), coherence (0.05), focus_to_check (0.10)
     *
     * Signaux physiologiques :
     * - dwell_variance (0.20) : CV des durées keydown→keyup
     * - rollover_rate (0.20)  : chevauchements de touches
     * - timing_entropy (0.10) : entropie de Shannon des intervalles
     * - correction_bonus (0.10) : présence de Shift+Tab
     * - timing_autocorrelation (0.15) : autocorrelation lag-1 des intervalles
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

        $tabCount = count($tabs);

        // Signal 1 : séquence de tabs existe (poids 0.05).
        $tabExistScore              = min(1.0, $tabCount / 2);
        $details['tab_sequence']    = $tabExistScore;

        // Signal 2 : variance timing entre tabs (poids 0.05).
        $timingVarianceScore        = $this->calculateTimingVariance($tabs);
        $details['timing_variance'] = $timingVarianceScore;

        // Signal 3 : cohérence de navigation (poids 0.05).
        $coherenceScore             = $tabCount >= 2 ? 1.0 : 0.5;
        $details['coherence']       = $coherenceScore;

        // Signal 4 : délai focus → check (poids 0.10).
        $focusToCheckScore          = $this->calculateFocusToCheckDelay($tabs, $check);
        $details['focus_to_check']  = $focusToCheckScore;

        // Signal 5 : dwell time variance (poids 0.20).
        $dwellVarianceScore         = $this->calculateDwellVariance($tabs);
        $details['dwell_variance']  = $dwellVarianceScore;

        // Signal 6 : rollover rate (poids 0.20).
        $rolloverRateScore          = $this->calculateRolloverRate($tabs);
        $details['rollover_rate']   = $rolloverRateScore;

        // Signal 7 : timing entropy (poids 0.10).
        $timingEntropyScore         = $this->calculateTimingEntropy($tabs);
        $details['timing_entropy']  = $timingEntropyScore;

        // Signal 8 : correction bonus — Shift+Tab présent (poids 0.10).
        $correctionBonusScore       = $this->calculateCorrectionBonus($tabs);
        $details['correction_bonus'] = $correctionBonusScore;

        // Signal 9 : timing autocorrelation lag-1 (poids 0.15).
        // Humain : cadence relativement stable → autocorrelation positive.
        // Bot Math.random() : intervalles indépendants → autocorrelation ~0.
        $timingAutocorrelationScore          = $this->calculateTimingAutocorrelation($tabs);
        $details['timing_autocorrelation']   = $timingAutocorrelationScore;

        $score = ($tabExistScore * 0.05)
               + ($timingVarianceScore * 0.05)
               + ($coherenceScore * 0.05)
               + ($focusToCheckScore * 0.10)
               + ($dwellVarianceScore * 0.20)
               + ($rolloverRateScore * 0.20)
               + ($timingEntropyScore * 0.10)
               + ($correctionBonusScore * 0.10)
               + ($timingAutocorrelationScore * 0.15);

        return $this->result($score, 'keyboard', $details);
    }

    /**
     * Scoring profil touch : 10 signaux avec redistribution de poids.
     *
     * Sur un formulaire court (login), l'utilisateur tape les champs sans
     * générer de touchmove → la plupart des signaux de trajectoire manquent.
     * Au lieu de fallback 0.5 (qui donne un score final ~0.45), les poids
     * des signaux sans données sont redistribués aux signaux exploitables.
     *
     * 3 nouveaux signaux touch-spécifiques :
     * - touch_pressure_variance : variation de pression pendant le swipe
     * - touch_radius_variance   : variation du rayon de contact pendant le swipe
     * - natural_tap             : caractéristiques du geste tap sur le widget
     *
     * @param array $log Log parsé.
     * @return array{score: float, profile: string, details: array<string, float>}
     */
    private function scoreTouch(array $log): array
    {
        $moves   = $log['moves'] ?? [];
        $check   = $log['check'];

        // Kill signal : tap exactement au centre.
        if (isset($check['offset'])) {
            $ox = (float) ($check['offset']['x'] ?? 0);
            $oy = (float) ($check['offset']['y'] ?? 0);
            if ($ox === 0.0 && $oy === 0.0) {
                return $this->result(0.0, 'touch', ['reason' => 'perfect_center_touch']);
            }
        }

        $moveCount     = count($moves);
        $minTouchMoves = 3;

        // Définition des 10 signaux avec poids et flag "données exploitables".
        // Les signaux sans données verront leur poids redistribué.
        $signals = [];

        // Signal 1 : trajectoire existe (poids 0.05).
        $signals['trajectory'] = [
            'score'  => min(1.0, $moveCount / $minTouchMoves),
            'weight' => 0.05,
            'actual' => true,
        ];

        // Signal 2 : non-linéarité (poids 0.05).
        $signals['non_linearity'] = [
            'score'  => $moveCount >= 2 ? $this->calculateNonLinearity($moves) : 0.0,
            'weight' => 0.05,
            'actual' => $moveCount >= 2,
        ];

        // Signal 3 : offset du tap (poids 0.10).
        $signals['offset'] = [
            'score'  => $this->calculateClickOffset($check),
            'weight' => 0.10,
            'actual' => isset($check['offset']),
        ];

        // Signal 4 : variation de vitesse (poids 0.05).
        $signals['speed_variation'] = [
            'score'  => $moveCount >= 3 ? $this->calculateSpeedVariation($moves) : 0.0,
            'weight' => 0.05,
            'actual' => $moveCount >= 3,
        ];

        // Signal 5 : angular jitter (poids 0.05).
        $signals['angular_jitter'] = [
            'score'  => $moveCount >= 4 ? $this->calculateAngularJitter($moves) : 0.0,
            'weight' => 0.05,
            'actual' => $moveCount >= 4,
        ];

        // Signal 6 : direction reversals (poids 0.10).
        $signals['direction_reversals'] = [
            'score'  => $moveCount >= 4 ? $this->calculateDirectionReversals($moves) : 0.0,
            'weight' => 0.10,
            'actual' => $moveCount >= 4,
        ];

        // Signal 7 : endpoint deceleration (poids 0.10).
        $signals['endpoint_deceleration'] = [
            'score'  => $moveCount >= 6 ? $this->calculateEndpointDeceleration($moves) : 0.0,
            'weight' => 0.10,
            'actual' => $moveCount >= 6,
        ];

        // Signal 8 : variance de pression touch (poids 0.15).
        $signals['touch_pressure_variance'] = $this->calculateTouchPressureVariance($moves);

        // Signal 9 : variance du rayon de contact (poids 0.15).
        $signals['touch_radius_variance'] = $this->calculateTouchRadiusVariance($moves);

        // Signal 10 : geste tap naturel (poids 0.20).
        $signals['natural_tap'] = $this->calculateNaturalTap($check);

        // Redistribution : les signaux sans données voient leur poids
        // réparti proportionnellement sur les signaux exploitables.
        $score = $this->redistributeAndScore($signals);

        // Construire les détails pour le debug.
        $details = [];
        foreach ($signals as $name => $signal) {
            $details[$name] = $signal['score'];
        }

        return $this->result($score, 'touch', $details);
    }

    /**
     * Calcule la variance de pression (force) sur les touchmove.
     *
     * Un doigt humain varie naturellement en pression pendant un swipe.
     * Un bot CDP ne génère pas de données de force (toujours 0).
     *
     * @param array $moves Points touchmove avec force optionnel.
     * @return array{score: float, weight: float, actual: bool}
     */
    private function calculateTouchPressureVariance(array $moves): array
    {
        $forces = [];
        foreach ($moves as $move) {
            if (isset($move['force']) && is_numeric($move['force']) && (float) $move['force'] > 0) {
                $forces[] = (float) $move['force'];
            }
        }

        if (count($forces) < 3) {
            return ['score' => 0.0, 'weight' => 0.15, 'actual' => false];
        }

        $mean = array_sum($forces) / count($forces);
        if ($mean < 0.001) {
            return ['score' => 0.0, 'weight' => 0.15, 'actual' => false];
        }

        $stddev = $this->standardDeviation($forces, $mean);
        $cv     = $stddev / $mean;

        // CV > 0.15 = variation humaine naturelle. CV ~0 = synthétique.
        return ['score' => min(1.0, $cv / 0.15), 'weight' => 0.15, 'actual' => true];
    }

    /**
     * Calcule la variance du rayon de contact sur les touchmove.
     *
     * L'aire de contact du doigt change quand l'angle ou la pression varie.
     * Disponible sur la plupart des devices touch (même sans force).
     *
     * @param array $moves Points touchmove avec rX/rY optionnels.
     * @return array{score: float, weight: float, actual: bool}
     */
    private function calculateTouchRadiusVariance(array $moves): array
    {
        $radii = [];
        foreach ($moves as $move) {
            if (isset($move['rX']) && is_numeric($move['rX']) && (float) $move['rX'] > 0) {
                $radii[] = (float) $move['rX'];
            }
        }

        if (count($radii) < 3) {
            return ['score' => 0.0, 'weight' => 0.15, 'actual' => false];
        }

        $mean = array_sum($radii) / count($radii);
        if ($mean < 0.001) {
            return ['score' => 0.0, 'weight' => 0.15, 'actual' => false];
        }

        $stddev = $this->standardDeviation($radii, $mean);
        $cv     = $stddev / $mean;

        // CV > 0.10 = variation humaine (l'angle du doigt change).
        return ['score' => min(1.0, $cv / 0.10), 'weight' => 0.15, 'actual' => true];
    }

    /**
     * Score le geste tap sur le widget (touchstart → touchend).
     *
     * Analyse la durée de contact, la pression et le rayon de contact
     * au moment du tap. Un humain produit un tap de 50-200ms avec des
     * données physiques (force, radius). CDP/Playwright produit un tap
     * quasi-instantané (< 5ms) sans données physiques.
     *
     * @param array $check Check event avec tapDuration, tapForce, tapRX optionnels.
     * @return array{score: float, weight: float, actual: bool}
     */
    private function calculateNaturalTap(array $check): array
    {
        if (!isset($check['tapDuration'])) {
            return ['score' => 0.0, 'weight' => 0.20, 'actual' => false];
        }

        $duration = (int) $check['tapDuration'];

        // Durée < 20ms → tap synthétique (CDP keyboard.press-like).
        if ($duration < 20) {
            return ['score' => 0.0, 'weight' => 0.20, 'actual' => true];
        }

        // Scoring de la durée :
        // 20-50ms  → score partiel (tap très rapide, possible mais rare)
        // 50-200ms → zone optimale humaine
        // 200-500ms → long tap, possible (hésitation)
        // > 500ms  → long press, geste différent
        if ($duration <= 50) {
            $score = 0.3 + ($duration - 20) / 30.0 * 0.4;
        } elseif ($duration <= 200) {
            $score = 1.0;
        } elseif ($duration <= 500) {
            $score = 1.0 - ($duration - 200) / 300.0 * 0.3;
        } else {
            $score = 0.5;
        }

        // Bonus pression : le device supporte force ET une valeur réaliste.
        if (isset($check['tapForce'])) {
            $force = (float) $check['tapForce'];
            if ($force >= 0.05 && $force <= 0.9) {
                $score = min(1.0, $score + 0.15);
            }
        }

        // Bonus rayon de contact : empreinte de doigt réaliste (3-40px).
        if (isset($check['tapRX'])) {
            $rx = (float) $check['tapRX'];
            if ($rx >= 3.0 && $rx <= 40.0) {
                $score = min(1.0, $score + 0.15);
            }
        }

        return ['score' => round(min(1.0, $score), 4), 'weight' => 0.20, 'actual' => true];
    }

    /**
     * Calcule le score final avec redistribution des poids.
     *
     * Les signaux sans données exploitables (actual = false) voient leur
     * poids redistribué proportionnellement aux signaux qui ont des données.
     * Évite le biais des fallback 0.5 qui tirent le score vers la médiane.
     *
     * @param array<string, array{score: float, weight: float, actual: bool}> $signals Signaux à scorer.
     * @return float Score final de 0.0 à 1.0.
     */
    private function redistributeAndScore(array $signals): float
    {
        $totalActualWeight = 0.0;

        foreach ($signals as $signal) {
            if ($signal['actual']) {
                $totalActualWeight += $signal['weight'];
            }
        }

        // Aucun signal exploitable — score neutre.
        if ($totalActualWeight < 0.001) {
            return 0.5;
        }

        $score = 0.0;

        foreach ($signals as $signal) {
            if ($signal['actual']) {
                // Poids effectif = poids original × (total / total exploitable).
                // Revient à normaliser pour que les poids exploitables somment à 1.0
                // quand les poids originaux sommaient à 1.0.
                $effectiveWeight = $signal['weight'] / $totalActualWeight;
                $score += $signal['score'] * $effectiveWeight;
            }
        }

        return $score;
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

        // ratio < 0.8 = forte décélération (Fitts' law) → score max.
        // ratio 0.8–1.2 = interpolation linéaire.
        // ratio > 1.2 = pas de décélération claire → neutre (0.5).
        // Note : les données montrent que les vrais humains n'ont pas
        // toujours une décélération mesurable avec un throttle 50ms +
        // buffer circulaire. Pénaliser à 0.0 cause des faux positifs.
        if ($ratio <= 0.8) {
            return 1.0;
        }
        if ($ratio >= 1.2) {
            return 0.5;
        }

        // Interpolation linéaire entre 0.8 et 1.2.
        return 1.0 - (($ratio - 0.8) / 0.4);
    }

    /**
     * Calcule le CV des durées d'appui (dwell time) des touches.
     *
     * Seules les entrées avec dur > 0 sont utilisées (exclut les focus events
     * et les keyups non captés). Un humain a des durées variables selon la touche
     * (Tab ~60-80ms petit doigt, Space ~80-120ms pouce). Un bot Playwright
     * produit des durées très courtes (< 20ms) car keyboard.press() fait
     * keydown→keyup quasi-instantanément.
     *
     * @param array $tabs Events tab/focus avec dur optionnel.
     * @return float Score de 0.0 à 1.0 (0.5 si données insuffisantes).
     */
    private function calculateDwellVariance(array $tabs): float
    {
        $durations = [];
        foreach ($tabs as $tab) {
            $dur = (int) ($tab['dur'] ?? 0);
            if ($dur > 0) {
                $durations[] = (float) $dur;
            }
        }

        if (count($durations) < 3) {
            return 0.5;
        }

        $mean = array_sum($durations) / count($durations);

        // Un humain ne relâche pas une touche en moins de 20ms.
        // Playwright keyboard.press() produit des dwell ~1-5ms.
        if ($mean < 20.0) {
            return 0.0;
        }

        $stddev = $this->standardDeviation($durations, $mean);
        $cv     = $stddev / $mean;

        return min(1.0, $cv / 0.4);
    }

    /**
     * Détecte les chevauchements de touches (rollover).
     *
     * Un humain en navigation rapide enfonce la touche suivante avant
     * de relâcher la précédente (10-30% d'overlap). Un bot Playwright
     * exécute séquentiellement (keydown→keyup→keydown) → 0% overlap.
     *
     * @param array $tabs Events tab/focus avec dur optionnel.
     * @return float Score de 0.0 à 1.0 (0.5 si données insuffisantes).
     */
    private function calculateRolloverRate(array $tabs): float
    {
        // Filtrer pour ne garder que les vraies touches (pas les focus events).
        $keyPresses = array_values(array_filter($tabs, function (array $tab): bool {
            $dur = (int) ($tab['dur'] ?? 0);
            return $dur > 0 && isset($tab['key']) && $tab['key'] !== 'focus';
        }));

        $pairs    = 0;
        $overlaps = 0;

        for ($i = 0; $i < count($keyPresses) - 1; $i++) {
            $pairs++;
            // Chevauchement : la touche i est encore enfoncée quand i+1 commence.
            $releaseTime = (float) $keyPresses[$i]['t'] + (int) $keyPresses[$i]['dur'];
            $nextStart   = (float) $keyPresses[$i + 1]['t'];

            if ($releaseTime > $nextStart) {
                $overlaps++;
            }
        }

        if ($pairs === 0) {
            return 0.5;
        }

        $ratio = $overlaps / $pairs;

        return min(1.0, $ratio / 0.1);
    }

    /**
     * Calcule l'entropie de Shannon des intervalles entre tabs.
     *
     * Les intervalles sont discrétisés en bins de 50ms. Un humain produit
     * une entropie modérée (2-4 bits). Un bot à timing constant → entropie ~0.
     * Un bot random uniforme → entropie très élevée.
     *
     * @param array $tabs Events tab/focus.
     * @return float Score de 0.0 à 1.0 (0.5 si données insuffisantes).
     */
    private function calculateTimingEntropy(array $tabs): float
    {
        if (count($tabs) < 4) {
            return 0.5;
        }

        $intervals = [];
        for ($i = 1; $i < count($tabs); $i++) {
            $intervals[] = (float) $tabs[$i]['t'] - (float) $tabs[$i - 1]['t'];
        }

        if (count($intervals) < 3) {
            return 0.5;
        }

        // Discrétise en bins de 50ms.
        $bins = [];
        foreach ($intervals as $interval) {
            $bin = (int) floor($interval / 50);
            $bins[$bin] = ($bins[$bin] ?? 0) + 1;
        }

        $total   = count($intervals);
        $entropy = 0.0;

        foreach ($bins as $count) {
            $p = $count / $total;
            if ($p > 0) {
                $entropy -= $p * log($p, 2);
            }
        }

        return min(1.0, $entropy / 2.0);
    }

    /**
     * Bonus pour la présence de Shift+Tab (retour en arrière = humain qui corrige).
     *
     * @param array $tabs Events tab/focus.
     * @return float 1.0 si Shift+Tab présent, 0.5 sinon (neutre).
     */
    private function calculateCorrectionBonus(array $tabs): float
    {
        foreach ($tabs as $tab) {
            if (isset($tab['key']) && $tab['key'] === 'Shift+Tab') {
                return 1.0;
            }
        }

        return 0.5;
    }

    /**
     * Vérifie qu'il y a suffisamment de vraies touches (pas des focus events).
     *
     * Les focus events ne sont pas des preuves de navigation clavier volontaire.
     * Seules les touches Tab, Shift+Tab, Space, Enter comptent.
     *
     * @param array $tabs  Events tab/focus.
     * @param int   $min   Nombre minimum de touches requises.
     * @return bool True si suffisamment de touches.
     */
    private function hasActualKeyPresses(array $tabs, int $min): bool
    {
        $count = 0;
        foreach ($tabs as $tab) {
            $key = $tab['key'] ?? '';
            if ($key !== 'focus' && $key !== '') {
                $count++;
                if ($count >= $min) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calcule l'autocorrelation lag-1 du signal de vitesse entre points consécutifs.
     *
     * Humain : > 0.7 (inertie physique, la vitesse varie progressivement).
     * Bot avec random delays : ~0 (chaque vitesse est indépendante).
     *
     * @param array<int, array{t: int|float, x: int|float, y: int|float}> $moves Points.
     * @return float Score de 0.0 à 1.0 (0.5 si données insuffisantes).
     */
    private function calculateSpeedAutocorrelation(array $moves): float
    {
        $speeds = $this->extractSpeeds($moves);

        if (count($speeds) < 5) {
            return 0.5;
        }

        $r = $this->autocorrelationLag1($speeds);

        // Score : r / 0.5, clampé [0, 1].
        return min(1.0, max(0.0, $r / 0.5));
    }

    /**
     * Détecte le leak CDP via le delta screenX - clientX sur le check event.
     *
     * Vrai navigateur desktop : screenX - clientX > 0 (position fenêtre + chrome).
     * CDP Playwright : screenX === clientX → delta 0,0.
     *
     * @param array $check Check event avec screenDx/screenDy optionnels.
     * @return float 1.0 (humain), 0.0 (CDP détecté), 0.5 (champ absent — neutre).
     */
    private function calculateCdpScreenDelta(array $check): float
    {
        if (!array_key_exists('screenDx', $check)) {
            return 0.5;
        }

        $dx = (int) $check['screenDx'];
        $dy = (int) $check['screenDy'];

        if ($dx === 0 && $dy === 0) {
            return 0.0;
        }

        return 1.0;
    }

    /**
     * Score basé sur la moyenne de coalesced events par mousemove.
     *
     * Vrai navigateur : coalescedAvg > 1 (le hardware envoie plusieurs positions
     * entre chaque frame). CDP : toujours 1.0 (un event dispatché par appel).
     *
     * Poids faible car Safari ne supporte pas getCoalescedEvents().
     *
     * @param array $log Log complet avec coalescedAvg optionnel.
     * @return float 1.0 (humain), 0.0 (CDP), 0.5 (absent — neutre).
     */
    private function calculateCoalescedRatio(array $log): float
    {
        if (!array_key_exists('coalescedAvg', $log)) {
            return 0.5;
        }

        $avg = (float) $log['coalescedAvg'];

        return $avg > 1.0 ? 1.0 : 0.0;
    }

    /**
     * Calcule l'autocorrelation lag-1 des intervalles entre touches.
     *
     * Filtre les focus events pour ne garder que les vraies touches (Tab,
     * Shift+Tab, Space, Enter). Sans ce filtre, l'alternance tab→focus
     * (court) puis focus→tab (long) crée une autocorrelation négative
     * même chez un vrai humain.
     *
     * Humain : autocorrelation positive (cadence relativement stable).
     * Bot Math.random() : intervalles indépendants → autocorrelation ~0.
     *
     * @param array $tabs Events tab/focus.
     * @return float Score de 0.0 à 1.0 (0.5 si données insuffisantes).
     */
    private function calculateTimingAutocorrelation(array $tabs): float
    {
        // Filtrer les focus events — ne garder que les vraies touches.
        $keyPresses = array_values(array_filter($tabs, function (array $tab): bool {
            $key = $tab['key'] ?? '';
            return $key !== 'focus' && $key !== '';
        }));

        if (count($keyPresses) < 5) {
            return 0.5;
        }

        $intervals = [];
        for ($i = 1; $i < count($keyPresses); $i++) {
            $intervals[] = (float) $keyPresses[$i]['t'] - (float) $keyPresses[$i - 1]['t'];
        }

        if (count($intervals) < 4) {
            return 0.5;
        }

        $r = $this->autocorrelationLag1($intervals);

        // Seuil plus bas que pour la souris car clavier = moins de données.
        return min(1.0, max(0.0, $r / 0.3));
    }

    /**
     * Calcule l'autocorrelation lag-1 d'un signal.
     *
     * r = Σ((v[i]-mean) * (v[i+1]-mean)) / Σ((v[i]-mean)²)
     *
     * @param float[] $values Série temporelle.
     * @return float Coefficient d'autocorrelation (-1 à 1).
     */
    private function autocorrelationLag1(array $values): float
    {
        $n    = count($values);
        $mean = array_sum($values) / $n;

        $numerator   = 0.0;
        $denominator = 0.0;

        for ($i = 0; $i < $n - 1; $i++) {
            $di = $values[$i] - $mean;
            $di1 = $values[$i + 1] - $mean;
            $numerator += $di * $di1;
            $denominator += $di * $di;
        }

        // Dernier terme du dénominateur.
        $lastDiff = $values[$n - 1] - $mean;
        $denominator += $lastDiff * $lastDiff;

        if ($denominator < 1e-10) {
            return 0.0;
        }

        return $numerator / $denominator;
    }

    /**
     * Extrait les vitesses entre points consécutifs.
     *
     * @param array<int, array{t: int|float, x: int|float, y: int|float}> $moves Points.
     * @return float[] Vitesses (px/ms).
     */
    private function extractSpeeds(array $moves): array
    {
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

        return $speeds;
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
