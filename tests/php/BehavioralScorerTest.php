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
                ['t' => 100, 'key' => 'Tab', 'dur' => 72],
                ['t' => 180, 'key' => 'focus', 'dur' => 0],
                ['t' => 350, 'key' => 'Tab', 'dur' => 65],
                ['t' => 430, 'key' => 'focus', 'dur' => 0],
                ['t' => 520, 'key' => 'Tab', 'dur' => 110],
                ['t' => 600, 'key' => 'Shift+Tab', 'dur' => 88],
                ['t' => 680, 'key' => 'focus', 'dur' => 0],
                ['t' => 1800, 'key' => ' ', 'dur' => 95],
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
        // Tabs parfaitement réguliers (100ms chacun), dur uniforme, 0 rollover.
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 600],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab', 'dur' => 50],
                ['t' => 200, 'key' => 'Tab', 'dur' => 50],
                ['t' => 300, 'key' => 'Tab', 'dur' => 50],
                ['t' => 400, 'key' => 'Tab', 'dur' => 50],
                ['t' => 500, 'key' => 'Tab', 'dur' => 50],
            ],
            'dt'    => 600,
        ];

        $result = $this->scorer->score($log);

        $this->assertLessThanOrEqual(0.7, $result['score']);
    }

    public function testBotKeyboardUniformTimingScoresLow(): void
    {
        // Bot Playwright : tabs à intervalles parfaitement réguliers (150ms),
        // dur uniforme (45ms), 0 rollover, pas de Shift+Tab.
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 900],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab', 'dur' => 45],
                ['t' => 250, 'key' => 'Tab', 'dur' => 45],
                ['t' => 400, 'key' => 'Tab', 'dur' => 45],
                ['t' => 550, 'key' => 'Tab', 'dur' => 45],
                ['t' => 700, 'key' => 'Tab', 'dur' => 45],
                ['t' => 850, 'key' => ' ', 'dur' => 45],
            ],
            'dt'    => 900,
        ];

        $result = $this->scorer->score($log);

        $this->assertLessThan(0.5, $result['score'], 'Un bot clavier uniforme doit scorer < 0.5');
    }

    // --- Multi-profil ---

    public function testMixedMouseKeyboardUsesMaxScore(): void
    {
        // Humain qui navigue au clavier mais a aussi bougé la souris.
        // check='key' → profil primaire keyboard, profil secondaire mouse.
        $log = [
            'moves' => [
                ['t' => 0,   'x' => 200, 'y' => 300],
                ['t' => 35,  'x' => 218, 'y' => 293],
                ['t' => 70,  'x' => 240, 'y' => 284],
                ['t' => 110, 'x' => 265, 'y' => 278],
                ['t' => 150, 'x' => 282, 'y' => 271],
                ['t' => 185, 'x' => 296, 'y' => 268],
                ['t' => 225, 'x' => 312, 'y' => 263],
                ['t' => 275, 'x' => 308, 'y' => 264],
                ['t' => 330, 'x' => 305, 'y' => 262],
                ['t' => 400, 'x' => 306, 'y' => 261],
                ['t' => 480, 'x' => 305, 'y' => 261],
                ['t' => 570, 'x' => 305, 'y' => 260],
            ],
            'check' => ['type' => 'key', 't' => 2000],
            'tabs'  => [
                ['t' => 800, 'key' => 'Tab', 'dur' => 70],
                ['t' => 1800, 'key' => ' ', 'dur' => 100],
            ],
            'dt'    => 2000,
        ];

        $result = $this->scorer->score($log);

        // Le profil secondaire (mouse) doit remonter le score.
        $this->assertGreaterThan(0.5, $result['score'], 'Un humain mixte mouse+keyboard doit scorer > 0.5');
    }

    public function testPrimaryCenterClickKillNotBypassedByKeyboard(): void
    {
        // check='click' avec offset (0,0) → kill signal mouse autoritaire.
        // Même avec des tabs valides, le kill signal prime.
        $log = [
            'moves' => [
                ['t' => 0, 'x' => 100, 'y' => 100],
                ['t' => 50, 'x' => 110, 'y' => 110],
            ],
            'check' => ['type' => 'click', 't' => 500, 'offset' => ['x' => 0, 'y' => 0]],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab', 'dur' => 70],
                ['t' => 250, 'key' => 'Tab', 'dur' => 65],
                ['t' => 400, 'key' => 'Shift+Tab', 'dur' => 80],
            ],
            'dt'    => 500,
        ];

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['score'], 'Kill signal du profil primaire ne doit pas etre contourne');
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

    // --- Speed autocorrelation ---

    public function testSpeedAutocorrelationHighForHuman(): void
    {
        $log = $this->buildHumanMouseLog();

        $result = $this->scorer->score($log);

        // La trajectoire humaine a une inertie physique → autocorrelation élevée.
        $this->assertArrayHasKey('speed_autocorrelation', $result['details']);
        $this->assertGreaterThan(0.5, $result['details']['speed_autocorrelation']);
    }

    public function testSpeedAutocorrelationLowForRandomBot(): void
    {
        // Bot avec délais random entre chaque point → vitesses indépendantes.
        $moves = [];
        $t     = 0;
        for ($i = 0; $i < 15; $i++) {
            $moves[] = ['t' => $t, 'x' => $i * 20, 'y' => 200];
            // Intervalles très variables (alternance rapide/lent sans corrélation).
            $t += ($i % 2 === 0) ? 10 : 200;
        }

        $log = [
            'moves' => $moves,
            'check' => ['type' => 'click', 't' => $t + 100, 'offset' => ['x' => 3, 'y' => -2], 'screenDx' => 50, 'screenDy' => 80],
            'tabs'  => [],
            'dt'    => $t + 100,
            'coalescedAvg' => 2.0,
        ];

        $result = $this->scorer->score($log);

        $this->assertArrayHasKey('speed_autocorrelation', $result['details']);
        $this->assertLessThan(0.5, $result['details']['speed_autocorrelation']);
    }

    // --- CDP screen delta ---

    public function testCdpScreenDeltaKillsBot(): void
    {
        $log = $this->buildHumanMouseLog();
        // CDP : screenX === clientX → delta 0.
        $log['check']['screenDx'] = 0;
        $log['check']['screenDy'] = 0;

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['details']['cdp_screen_delta']);
    }

    public function testCdpScreenDeltaPassesHuman(): void
    {
        $log = $this->buildHumanMouseLog();
        // Vrai navigateur : screenX - clientX > 0.
        $log['check']['screenDx'] = 50;
        $log['check']['screenDy'] = 80;

        $result = $this->scorer->score($log);

        $this->assertSame(1.0, $result['details']['cdp_screen_delta']);
    }

    // --- Coalesced ratio ---

    public function testCoalescedRatioDetectsBot(): void
    {
        $log = $this->buildHumanMouseLog();
        // CDP : un event dispatché par appel → coalescedAvg = 1.0.
        $log['coalescedAvg'] = 1.0;

        $result = $this->scorer->score($log);

        $this->assertSame(0.0, $result['details']['coalesced_ratio']);
    }

    // --- Timing autocorrelation (clavier) ---

    public function testTimingAutocorrelationLowForRandomBot(): void
    {
        // Bot avec intervalles aléatoires indépendants entre tabs.
        $log = [
            'moves' => [],
            'check' => ['type' => 'key', 't' => 3000],
            'tabs'  => [
                ['t' => 100, 'key' => 'Tab', 'dur' => 70],
                ['t' => 500, 'key' => 'focus', 'dur' => 0],
                ['t' => 550, 'key' => 'Tab', 'dur' => 65],
                ['t' => 1200, 'key' => 'focus', 'dur' => 0],
                ['t' => 1220, 'key' => 'Tab', 'dur' => 80],
                ['t' => 1900, 'key' => 'focus', 'dur' => 0],
                ['t' => 1910, 'key' => 'Tab', 'dur' => 72],
                ['t' => 2800, 'key' => ' ', 'dur' => 90],
            ],
            'dt' => 3000,
        ];

        $result = $this->scorer->score($log);

        $this->assertArrayHasKey('timing_autocorrelation', $result['details']);
        $this->assertLessThan(0.7, $result['details']['timing_autocorrelation']);
    }

    // --- Helpers ---

    /**
     * Construit un log d'interaction souris humain réaliste.
     *
     * Inclut micro-corrections (overshoot en X puis retour), décélération
     * dans le dernier tiers, angles irréguliers entre segments, et quelques
     * tabs avec dwell time (l'humain a aussi navigué au clavier).
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
                'type'     => 'click',
                't'        => 620,
                'x'        => 305,
                'y'        => 260,
                'offset'   => ['x' => 4, 'y' => -3],
                'screenDx' => 85,
                'screenDy' => 132,
            ],
            'tabs'  => [
                ['t' => 50, 'key' => 'Tab', 'dur' => 68],
                ['t' => 130, 'key' => 'focus', 'dur' => 0],
                ['t' => 200, 'key' => 'Tab', 'dur' => 75],
                ['t' => 280, 'key' => 'focus', 'dur' => 0],
            ],
            'dt'           => 620,
            'coalescedAvg' => 2.4,
            'moveCount'    => 48,
        ];
    }
}
