<?php
/**
 * HCZ R1.5b: PointsActions Request-Time DDL Removal Guard
 *
 * 静态断言：PointsActions.php 不再包含任何 request-time DDL。
 * 这是 R0-P1-01 Request-Time DDL 关闭的必要条件。
 */

declare(strict_types=1);

namespace tests\Unit;

use PHPUnit\Framework\TestCase;

class PointsActionsNoDdlTest extends TestCase
{
    private string $pointsActionsPath;

    protected function setUp(): void
    {
        $this->pointsActionsPath = root_path() . 'app' . DIRECTORY_SEPARATOR
            . 'controller' . DIRECTORY_SEPARATOR . 'indexapi'
            . DIRECTORY_SEPARATOR . 'PointsActions.php';
    }

    public function testPointsActionsFileExists(): void
    {
        $this->assertFileExists($this->pointsActionsPath);
    }

    public function testNoCreateTableInPointsActions(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'CREATE TABLE',
            $content,
            'PointsActions.php must not contain CREATE TABLE (R0-P1-01 Request-Time DDL)'
        );
    }

    public function testNoAlterTableInPointsActions(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'ALTER TABLE',
            $content,
            'PointsActions.php must not contain ALTER TABLE'
        );
    }

    public function testNoDropTableInPointsActions(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'DROP TABLE',
            $content,
            'PointsActions.php must not contain DROP TABLE'
        );
    }

    public function testNoTruncateTableInPointsActions(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'TRUNCATE',
            $content,
            'PointsActions.php must not contain TRUNCATE'
        );
    }

    public function testNoEnsurePointsTaskClaimTableMethod(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'ensurePointsTaskClaimTable',
            $content,
            'ensurePointsTaskClaimTable method must be removed (R1.5b)'
        );
    }

    public function testNoEnsurePointsExchangeOrderTableMethod(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertStringNotContainsString(
            'ensurePointsExchangeOrderTable',
            $content,
            'ensurePointsExchangeOrderTable method must be removed (R1.5b)'
        );
    }

    public function testNoEnsureMethodPatternInPointsActions(): void
    {
        $content = file_get_contents($this->pointsActionsPath);
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+ensure\w*Table/i',
            $content,
            'PointsActions.php must not contain any ensure*Table method'
        );
    }

    /**
     * 全局 request-controller DDL scan：
     * 扫描 app/controller 下所有 PHP 文件，确认 HTTP request path 中无 DDL。
     * MigrationRunner/CLI/migration 文件中的合法 DDL 不计入。
     */
    public function testGlobalRequestControllerDdlScan(): void
    {
        $controllerPath = root_path() . 'app' . DIRECTORY_SEPARATOR . 'controller';
        $ddlPatterns = ['CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE'];
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            foreach ($ddlPatterns as $pattern) {
                if (stripos($content, $pattern) !== false) {
                    $violations[] = $file->getFilename() . " contains {$pattern}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "HTTP request path DDL found (R0-P1-01):\n" . implode("\n", $violations)
        );
    }
}
