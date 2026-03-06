<?php

declare(strict_types=1);

namespace Gaitcha\Tests;

use Gaitcha\Config;
use Gaitcha\FileTokenStore;
use Gaitcha\TokenGenerator;
use Gaitcha\ValidationOrchestrator;
use Gaitcha\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationOrchestratorTest extends TestCase
{
    private const SECRET = 'test-secret-key';
    private const NOW    = 1700000000;

    private Config $config;
    private TokenGenerator $generator;
    private ValidationOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->config       = new Config(['secret' => self::SECRET, 'debug' => true]);
        $this->generator    = new TokenGenerator($this->config);
        $this->orchestrator = new ValidationOrchestrator($this->config);
    }

    // --- Flux complet accept ---

    public function testAcceptsValidHumanSubmission(): void
    {
        $fieldName = '_gc_test1234';
        $token     = $this->generator->generateToken($fieldName, self::NOW);
        $log       = $this->buildHumanMouseLog();

        $post = [
            '_ct'                => $token,
            $fieldName           => 'on',
            $fieldName . '_log'  => json_encode($log),
        ];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertTrue($result->isAccepted());
        $this->assertGreaterThan(0.5, $result->getScore());
    }

    // --- Rejet : token absent ---

    public function testRejectsWhenTokenAbsent(): void
    {
        $result = $this->orchestrator->validate([], self::NOW);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_TOKEN_ABSENT, $result->getReason());
    }

    // --- Rejet : token invalide ---

    public function testRejectsForgedToken(): void
    {
        $post = ['_ct' => '_gc_test.1700000000.forgedsig'];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_TOKEN_INVALID, $result->getReason());
    }

    // --- Rejet : token expiré ---

    public function testRejectsExpiredToken(): void
    {
        $token = $this->generator->generateToken('_gc_test', self::NOW);
        $post  = ['_ct' => $token];

        $result = $this->orchestrator->validate($post, self::NOW + 300);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_TOKEN_EXPIRED, $result->getReason());
    }

    // --- Rejet : log absent ---

    public function testRejectsWhenLogMissing(): void
    {
        $fieldName = '_gc_test1234';
        $token     = $this->generator->generateToken($fieldName, self::NOW);

        $post = [
            '_ct'      => $token,
            $fieldName => 'on',
        ];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_LOG_MALFORMED, $result->getReason());
    }

    // --- Rejet : log malformé ---

    public function testRejectsWhenLogIsMalformed(): void
    {
        $fieldName = '_gc_test1234';
        $token     = $this->generator->generateToken($fieldName, self::NOW);

        $post = [
            '_ct'               => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => '{invalid json',
        ];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_LOG_MALFORMED, $result->getReason());
    }

    // --- Rejet : score insuffisant (bot) ---

    public function testRejectsBotBehavior(): void
    {
        $fieldName = '_gc_test1234';
        $token     = $this->generator->generateToken($fieldName, self::NOW);

        // Bot : aucun mouvement, clic au centre exact.
        $log = [
            'moves' => [],
            'check' => ['type' => 'click', 't' => 500, 'offset' => ['x' => 0, 'y' => 0]],
            'tabs'  => [],
            'dt'    => 500,
        ];

        $post = [
            '_ct'               => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => json_encode($log),
        ];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertFalse($result->isAccepted());
        $this->assertSame(ValidationResult::REASON_SCORE_INSUFFICIENT, $result->getReason());
    }

    // --- No-JS fallback : allow ---

    public function testNoJsFallbackAllowAcceptsWithoutToken(): void
    {
        $config       = new Config(['secret' => self::SECRET, 'no_js_fallback' => 'allow']);
        $orchestrator = new ValidationOrchestrator($config);

        $result = $orchestrator->validate([], self::NOW);

        $this->assertTrue($result->isAccepted());
        $this->assertSame(0.0, $result->getScore());
    }

    // --- Anti-replay ---

    public function testAntiReplayRejectsSecondSubmission(): void
    {
        $storePath    = sys_get_temp_dir() . '/gaitcha_test_replay_' . uniqid() . '.json';
        $store        = new FileTokenStore($storePath);
        $config       = new Config([
            'secret'       => self::SECRET,
            'anti_replay'  => true,
            'token_store'  => $store,
            'debug'        => true,
        ]);
        $generator    = new TokenGenerator($config);
        $orchestrator = new ValidationOrchestrator($config);

        $fieldName = '_gc_replay';
        $token     = $generator->generateToken($fieldName, self::NOW);
        $log       = $this->buildHumanMouseLog();

        $post = [
            '_ct'               => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => json_encode($log),
        ];

        // Première soumission : acceptée.
        $result1 = $orchestrator->validate($post, self::NOW);
        $this->assertTrue($result1->isAccepted());

        // Deuxième soumission (replay) : rejetée.
        $result2 = $orchestrator->validate($post, self::NOW);
        $this->assertFalse($result2->isAccepted());
        $this->assertSame(ValidationResult::REASON_TOKEN_ALREADY_USED, $result2->getReason());

        // Cleanup.
        if (file_exists($storePath)) {
            unlink($storePath);
        }
    }

    // --- Debug mode ---

    public function testDebugModeIncludesDetails(): void
    {
        $fieldName = '_gc_test1234';
        $token     = $this->generator->generateToken($fieldName, self::NOW);
        $log       = $this->buildHumanMouseLog();

        $post = [
            '_ct'               => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => json_encode($log),
        ];

        $result = $this->orchestrator->validate($post, self::NOW);

        $this->assertNotEmpty($result->getDebug());
        $this->assertArrayHasKey('scoring', $result->getDebug());
    }

    public function testProductionModeHidesDebug(): void
    {
        $config       = new Config(['secret' => self::SECRET, 'debug' => false]);
        $generator    = new TokenGenerator($config);
        $orchestrator = new ValidationOrchestrator($config);

        $fieldName = '_gc_test1234';
        $token     = $generator->generateToken($fieldName, self::NOW);
        $log       = $this->buildHumanMouseLog();

        $post = [
            '_ct'               => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => json_encode($log),
        ];

        $result = $orchestrator->validate($post, self::NOW);

        $this->assertEmpty($result->getDebug());
    }

    // --- Custom token field name ---

    public function testCustomTokenFieldName(): void
    {
        $config       = new Config(['secret' => self::SECRET, 'token_field_name' => '_tok', 'debug' => true]);
        $generator    = new TokenGenerator($config);
        $orchestrator = new ValidationOrchestrator($config);

        $fieldName = '_gc_test1234';
        $token     = $generator->generateToken($fieldName, self::NOW);
        $log       = $this->buildHumanMouseLog();

        $post = [
            '_tok'              => $token,
            $fieldName          => 'on',
            $fieldName . '_log' => json_encode($log),
        ];

        $result = $orchestrator->validate($post, self::NOW);

        $this->assertTrue($result->isAccepted());
    }

    // --- Helpers ---

    /**
     * @return array Log humain réaliste (profil mouse).
     */
    private function buildHumanMouseLog(): array
    {
        return [
            'moves' => [
                ['t' => 0,   'x' => 200, 'y' => 300],
                ['t' => 55,  'x' => 210, 'y' => 295],
                ['t' => 105, 'x' => 225, 'y' => 288],
                ['t' => 170, 'x' => 240, 'y' => 284],
                ['t' => 220, 'x' => 260, 'y' => 276],
                ['t' => 300, 'x' => 275, 'y' => 270],
                ['t' => 340, 'x' => 290, 'y' => 268],
                ['t' => 410, 'x' => 298, 'y' => 265],
                ['t' => 450, 'x' => 302, 'y' => 263],
                ['t' => 520, 'x' => 305, 'y' => 261],
            ],
            'check' => [
                'type'   => 'click',
                't'      => 550,
                'x'      => 305,
                'y'      => 261,
                'offset' => ['x' => 4, 'y' => -3],
            ],
            'tabs'  => [],
            'dt'    => 550,
        ];
    }
}
