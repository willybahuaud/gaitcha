<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\BehavioralScorer;
use PHPUnit\Framework\TestCase;

class BehavioralScorerTest extends TestCase
{
    private BehavioralScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new BehavioralScorer();
    }

    // --- Kill signals ---

    public function testKillSignalDtTooShort(): void
    {
        $log = [
            'moves' => [['t' => 0, 'x' => 10, 'y' => 10]],
            'check' => ['type' => 'click', 't' => 50],
            'tabs'  => [],
            'dt'    => 50,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('kill', $result['profile']);
    }

    public function testKillSignalNoMovesOnMouseProfile(): void
    {
        $log = [
            'moves' => [],
            'check' => ['type' => 'click', 't' => 500],
            'tabs'  => [],
            'dt'    => 500,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('mouse', $result['profile']);
    }

    public function testKillSignalPerfectCenterClick(): void
    {
        $log = [
            'moves' => [
                ['t' => 0, 'x' => 100, 'y' => 100],
                ['t' => 50, 'x' => 110, 'y' => 110],
            ],
            'check' => ['type' => 'click', 't' => 500, 'offset' => ['x' => 0, 'y' => 0]],
            'tabs'  => [],
            'dt'    => 500,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score']);
    }

    public function testKillSignalNoTabsOnKeyboardProfile(): void
    {
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 500],
            'tabs'  => [],
            'dt'    => 500,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score']);
        $this->assertSame('keyboard', $result['profile']);
    }

    // --- Profil souris : scores élevés (humain réaliste) ---

    public function testHumanMouseInteractionScoresHigh(): void
    {
        $log = $this->buildHumanMouseLog();

        $result = $this->scorer->score($log);

        $this->assertGreaterThan(0.5, $result['score'], 'Un humain realiste doit scorer > 0.5');
        $this->assertSame('mouse', $result['profile']);
    }

    // --- Profil souris : scores faibles (bot) ---

    public function testBezierBotScoresLow(): void
    {
        // Trajectoire de Bézier quadratique : P0(100,300) P1(250,200) P2(400,280).
        // 15 points réguliers, pas de jitter, pas de reversals, pas de décélération.
        $moves = [];
        $steps = 15;
        for ($i = 0; $i <= $steps; $i++) {
            $t  = $i / $steps;
            $x  = (1 - $t) * (1 - $t) * 100 + 2 * (1 - $t) * $t * 250 + $t * $t * 400;
            $y  = (1 - $t) * (1 - $t) * 300 + 2 * (1 - $t) * $t * 200 + $t * $t * 280;
            // Délai aléatoire simulé (20-55ms entre chaque point).
            $ms = $i * 38;
            $moves[] = ['t' => $ms, 'x' => round($x, 1), 'y' => round($y, 1)];
        }

        $log = [
            'moves' => $moves,
            'check' => ['type' => 'click', 't' => 620, 'offset' => ['x' => 5, 'y' => -2]],
            'tabs'  => [],
            'dt'    => 620,
        ];

        $result = $this->scorer->score($log);

        $this->assertLessThan(0.5, $result['score'], 'Un bot Bezier doit scorer < 0.5');
        $this->assertSame('mouse', $result['profile']);
    }

    public function testBotLinearTrajectoryScoresLow(): void
    {
        // Trajectoire parfaitement linéaire, vitesse constante.
        $moves = [];
        for ($i = 0; $i < 10; $i++) {
            $moves[] = ['t' => $i * 50, 'x' => $i * 10, 'y' => $i * 10];
        }

        $log = [
            'moves' => $moves,
            'check' => ['type' => 'click', 't' => 500, 'offset' => ['x' => 5, 'y' => 3]],
            'tabs'  => [],
            'dt'    => 500,
        ];

        $result = $this->scorer->score($log);

        // Linéaire + vitesse constante = score bas sur 2/4 critères.
        $this->assertLessThan(0.7, $result['score'], 'Un bot lineaire doit scorer bas.');
    }

    // --- Profil clavier : scores élevés (humain réaliste) ---

    public function testHumanKeyboardInteractionScoresHigh(): void
    {
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 2000],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab'],
                ['t' => 350, 'key' => 'Tab'],
                ['t' => 520, 'key' => 'Tab'],
                ['t' => 1800, 'key' => 'Tab'],
            ],
            'dt'    => 2000,
        ];

        $result = $this->scorer->score($log);

        $this->assertGreaterThan(0.5, $result['score'], 'Un humain clavier doit scorer > 0.5');
        $this->assertSame('keyboard', $result['profile']);
    }

    // --- Profil clavier : bot ---

    public function testBotRegularTabTimingScoresLow(): void
    {
        // Tabs parfaitement réguliers (100ms chacun).
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 600],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab'],
                ['t' => 200, 'key' => 'Tab'],
                ['t' => 300, 'key' => 'Tab'],
                ['t' => 400, 'key' => 'Tab'],
                ['t' => 500, 'key' => 'Tab'],
            ],
            'dt'    => 600,
        ];

        $result = $this->scorer->score($log);

        // La variance de timing est nulle → score bas sur ce critère.
        $this->assertLessThanOrEqual(0.7, $result['score']);
    }

    // --- Profil touch ---

    public function testTouchWithOffsetScoresReasonably(): void
    {
        $log = [
            'moves' => [
                ['t' => 0, 'x' => 50, 'y' => 100],
                ['t' => 30, 'x' => 55, 'y' => 98],
                ['t' => 60, 'x' => 60, 'y' => 95],
            ],
            'check' => ['type' => 'touch', 't' => 300, 'offset' => ['x' => 4, 'y' => -3]],
            'tabs'  => [],
            'dt'    => 300,
        ];

        $result = $this->scorer->score($log);

        $this->assertGreaterThan(0.3, $result['score']);
        $this->assertSame('touch', $result['profile']);
    }

    public function testTouchPerfectCenterIsKilled(): void
    {
        $log = [
            'moves' => [['t' => 0, 'x' => 50, 'y' => 100]],
            'check' => ['type' => 'touch', 't' => 300, 'offset' => ['x' => 0, 'y' => 0]],
            'tabs'  => [],
            'dt'    => 300,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score']);
    }

    // --- Helpers ---

    /**
     * Construit un log d'interaction souris humain réaliste.
     *
     * Inclut micro-corrections (overshoot en X puis retour), décélération
     * dans le dernier tiers, et angles irréguliers entre segments.
     *
     * @return array Log structuré.
     */
    private function buildHumanMouseLog(): array
    {
        return [
            'moves' => [
                // Phase d'accélération — mouvements amples, rapides.
                ['t' => 0,   'x' => 200, 'y' => 300],
                ['t' => 35,  'x' => 218, 'y' => 293],
                ['t' => 70,  'x' => 240, 'y' => 284],
                ['t' => 110, 'x' => 265, 'y' => 278],
                // Légère déviation vers le haut (tremblement).
                ['t' => 150, 'x' => 282, 'y' => 271],
                ['t' => 185, 'x' => 296, 'y' => 268],
                // Overshoot en X : dépasse la cible puis corrige.
                ['t' => 225, 'x' => 312, 'y' => 263],
                ['t' => 275, 'x' => 308, 'y' => 264],  // retour X
                ['t' => 330, 'x' => 305, 'y' => 262],  // correction Y
                // Phase de décélération — mouvements courts, plus lents.
                ['t' => 400, 'x' => 306, 'y' => 261],
                ['t' => 480, 'x' => 305, 'y' => 261],  // retour X fin
                ['t' => 570, 'x' => 305, 'y' => 260],
            ],
            'check' => [
                'type'   => 'click',
                't'      => 620,
                'x'      => 305,
                'y'      => 260,
                'offset' => ['x' => 4, 'y' => -3],
            ],
            'tabs'  => [],
            'dt'    => 620,
        ];
    }
}
