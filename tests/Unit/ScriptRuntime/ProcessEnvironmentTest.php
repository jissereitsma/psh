<?php declare(strict_types=1);

namespace Shopware\Psh\Test\Unit\ScriptRuntime;

use PHPUnit\Framework\TestCase;
use Shopware\Psh\Config\DotenvFile;
use Shopware\Psh\Config\EnvironmentResolver;
use Shopware\Psh\Config\SimpleValueProvider;
use Shopware\Psh\Config\Template;
use Shopware\Psh\Config\ValueProvider;
use Shopware\Psh\ScriptRuntime\Execution\ProcessEnvironment;
use Symfony\Component\Process\Process;
use function putenv;
use function trim;

class ProcessEnvironmentTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('FOO');
    }

    public function test_it_returns_all_passed_constants(): void
    {
        $env = new ProcessEnvironment((new EnvironmentResolver())->resolveConstants(['FOO' => 'BAR']), [], [], []);
        self::assertEquals(['FOO' => new SimpleValueProvider('BAR')], $env->getAllValues());
    }

    public function test_it_creates_processes(): void
    {
        $env = new ProcessEnvironment([], [], [], []);
        self::assertInstanceOf(Process::class, $env->createProcess('foo'));
        self::assertEquals('foo', $env->createProcess('foo')->getCommandLine());
    }

    public function test_it_resolves_variables(): void
    {
        $env = new ProcessEnvironment([], (new EnvironmentResolver())->resolveVariables([
            'FOO' => 'ls',
            'BAR' => 'echo "HEY"',
        ]), [], []);

        $resolvedValues = $env->getAllValues();

        self::assertContainsOnlyInstancesOf(ValueProvider::class, $resolvedValues);
        self::assertCount(2, $resolvedValues);

        foreach ($resolvedValues as $value) {
            self::assertEquals(trim($value->getValue()), $value->getValue());
        }
    }

    public function test_it_creates_templates(): void
    {
        $env = new ProcessEnvironment([], [], (new EnvironmentResolver())->resolveTemplates([
            ['source' => __DIR__ . '_foo.tpl', 'destination' => 'bar.txt'],
        ]), []);

        $templates = $env->getTemplates();

        self::assertContainsOnlyInstancesOf(Template::class, $templates);
        self::assertCount(1, $templates);
    }

    public function test_dotenv_can_be_overwritten_by_existing_env_vars(): void
    {
        putenv('FOO=baz');
        $env = new ProcessEnvironment([], [], [], (new EnvironmentResolver())->resolveDotenvVariables([
            new DotenvFile(__DIR__ . '/_dotenv/simple.env'),
        ]));

        self::assertSame('baz', $env->getAllValues()['FOO']->getValue());
    }

    public function test_dotenv_file_variables(): void
    {
        $env = new ProcessEnvironment([], [], [], (new EnvironmentResolver())->resolveDotenvVariables([
            new DotenvFile(__DIR__ . '/_dotenv/simple.env'),
        ]));

        self::assertCount(1, $env->getAllValues());
        self::assertSame('bar', $env->getAllValues()['FOO']->getValue());
    }
}
