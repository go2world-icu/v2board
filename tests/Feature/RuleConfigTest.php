<?php

namespace Tests\Feature;

use App\Services\RuleConfigService;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Tests\TestCase;

class RuleConfigTest extends TestCase
{
    private $defaultFile;
    private $customFile;

    protected function setUp(): void
    {
        parent::setUp();
        config(['v2board.rule_cache_enable' => 1]);
        $this->defaultFile = tempnam(sys_get_temp_dir(), 'v2board-rule-');
        $this->customFile = $this->defaultFile . '.custom';
    }

    protected function tearDown(): void
    {
        if (is_file($this->customFile)) {
            unlink($this->customFile);
        }
        unlink($this->defaultFile);
        RuleConfigService::flush();
        parent::tearDown();
    }

    public function testYamlReloadsAfterAnEqualSizeRewriteWithTheSameTimestamp(): void
    {
        $timestamp = time() - 60;
        file_put_contents($this->defaultFile, "rules: [one]\n");
        touch($this->defaultFile, $timestamp);
        $config = RuleConfigService::yaml($this->defaultFile, $this->customFile);
        $config['rules'][] = 'user-specific';
        $this->assertSame(['rules' => ['one']], RuleConfigService::yaml($this->defaultFile, $this->customFile));

        file_put_contents($this->defaultFile, "rules: [two]\n");
        touch($this->defaultFile, $timestamp);
        $this->assertSame(['rules' => ['two']], RuleConfigService::yaml($this->defaultFile, $this->customFile));

        file_put_contents($this->customFile, "rules: [custom]\n");
        $this->assertSame(['rules' => ['custom']], RuleConfigService::yaml($this->defaultFile, $this->customFile));
        unlink($this->customFile);
        $this->assertSame(['rules' => ['two']], RuleConfigService::yaml($this->defaultFile, $this->customFile));
    }

    public function testInvalidJsonDoesNotReplaceTheTemplateWithAnEmptyConfig(): void
    {
        file_put_contents($this->defaultFile, '{"rules":["default"]}');
        $this->assertSame(['rules' => ['default']], RuleConfigService::json($this->defaultFile, $this->customFile));
        file_put_contents($this->customFile, '{"rules":');
        try {
            RuleConfigService::json($this->defaultFile, $this->customFile);
            $this->fail('Invalid custom JSON must fail instead of producing an empty config.');
        } catch (JsonException $e) {
            $this->assertSame(JSON_ERROR_SYNTAX, $e->getCode());
        }
        file_put_contents($this->customFile, '{"rules":["custom"]}');
        $this->assertSame(['rules' => ['custom']], RuleConfigService::json($this->defaultFile, $this->customFile));
    }

    public function testInvalidYamlRetainsTheTemplateFilenameInTheError(): void
    {
        file_put_contents($this->customFile, "rules: [broken\n");
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($this->customFile);
        RuleConfigService::yaml($this->defaultFile, $this->customFile);
    }
}
