<?php declare(strict_types=1);

namespace Shopware\Psh\ScriptRuntime\ScriptLoader;

use RuntimeException;
use Shopware\Psh\Listing\Script;
use Shopware\Psh\Listing\ScriptFinder;
use function array_pop;
use function explode;
use function file_exists;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function pathinfo;
use function trim;

/**
 * Load scripts and parse it into commands
 */
class PshScriptParser implements ScriptParser
{
    const TOKEN_MODIFIER_TTY = 'TTY: ';

    const TOKEN_MODIFIER_IGNORE_ERROR = 'I: ';

    const TOKEN_MODIFIER_DEFERRED = 'D: ';

    const TOKEN_INCLUDE = 'INCLUDE: ';

    const TOKEN_ACTION = 'ACTION: ';

    const TOKEN_WAIT = 'WAIT:';

    const TOKEN_TEMPLATE = 'TEMPLATE: ';

    const CONCATENATE_PREFIX = '   ';

    const TOKEN_WILDCARD = '*';

    /**
     * @var CommandBuilder
     */
    private $commandBuilder;

    /**
     * @var ScriptFinder
     */
    private $scriptFinder;

    public function __construct(CommandBuilder $commandBuilder, ScriptFinder $scriptFinder)
    {
        $this->commandBuilder = $commandBuilder;
        $this->scriptFinder = $scriptFinder;
    }

    /**
     * {@inheritdoc}
     */
    public function parseContent(string $content, Script $script, ScriptLoader $loader): array
    {
        $lines = $this->splitIntoLines($content);
        $tokenHandler = $this->createTokenHandler($loader);

        foreach ($lines as $lineNumber => $currentLine) {
            foreach ($tokenHandler as $token => $handler) {
                if ($this->startsWith($token, $currentLine)) {
                    $currentLine = $handler($currentLine, $lineNumber, $script);
                }

                if ($currentLine === '') {
                    break;
                }
            }
        }

        return $this->commandBuilder->getAll();
    }

    private function createTokenHandler(ScriptLoader $loader): array
    {
        return [
            self::TOKEN_ACTION => function (string $currentLine) use ($loader): string {
                $scriptName = $this->removeFromStart(self::TOKEN_ACTION, $currentLine);
                $actionScript = $this->scriptFinder->findScriptByName($scriptName);

                $commands = $loader->loadScript($actionScript);
                $this->commandBuilder->replaceCommands($commands);

                return '';
            },

            self::TOKEN_INCLUDE => function (string $currentLine, int $lineNumber, Script $script) use ($loader): string {
                $path = $this->findInclude($script, $this->removeFromStart(self::TOKEN_INCLUDE, $currentLine));
                $includeScript = new Script(pathinfo($path, PATHINFO_DIRNAME), pathinfo($path, PATHINFO_BASENAME), false);

                $commands = $loader->loadScript($includeScript);
                $this->commandBuilder->replaceCommands($commands);

                return '';
            },

            self::TOKEN_TEMPLATE => function (string $currentLine, int $lineNumber, Script $script): string {
                $definition = $this->removeFromStart(self::TOKEN_TEMPLATE, $currentLine);
                list($rawSource, $rawDestination) = explode(':', $definition);

                $source = $script->getDirectory() . '/' . $rawSource;
                $destination = $script->getDirectory() . '/' . $rawDestination;

                $this->commandBuilder
                    ->addTemplateCommand($source, $destination, $lineNumber);

                return '';
            },

            self::TOKEN_WAIT => function (string $currentLine, int $lineNumber): string {
                $this->commandBuilder
                    ->addWaitCommand($lineNumber);

                return '';
            },

            self::TOKEN_MODIFIER_IGNORE_ERROR => function (string $currentLine): string {
                $this->commandBuilder->setIgnoreError();

                return $this->removeFromStart(self::TOKEN_MODIFIER_IGNORE_ERROR, $currentLine);
            },

            self::TOKEN_MODIFIER_TTY => function (string $currentLine): string {
                $this->commandBuilder->setTty();

                return  $this->removeFromStart(self::TOKEN_MODIFIER_TTY, $currentLine);
            },

            self::TOKEN_MODIFIER_DEFERRED => function (string $currentLine): string {
                $this->commandBuilder->setDeferredExecution();

                return $this->removeFromStart(self::TOKEN_MODIFIER_DEFERRED, $currentLine);
            },

            self::TOKEN_WILDCARD => function (string $currentLine, int $lineNumber): string {
                $this->commandBuilder
                    ->addProcessCommand($currentLine, $lineNumber);

                return '';
            },
        ];
    }

    private function findInclude(Script $fromScript, string $includeStatement): string
    {
        if (file_exists($includeStatement)) {
            return $includeStatement;
        }

        if (file_exists($fromScript->getDirectory() . '/' . $includeStatement)) {
            return $fromScript->getDirectory() . '/' . $includeStatement;
        }

        throw new RuntimeException('Unable to parse include statement "' . $includeStatement . '" in "' . $fromScript->getPath() . '"');
    }

    private function isExecutableLine(string $command): bool
    {
        $command = trim($command);

        if (!$command) {
            return false;
        }

        if ($this->startsWith('#', $command)) {
            return false;
        }

        return true;
    }

    private function removeFromStart(string $needle, string $haystack): string
    {
        return mb_substr($haystack, mb_strlen($needle));
    }

    private function startsWith(string $needle, string $haystack): bool
    {
        return ($needle === self::TOKEN_WILDCARD && $haystack !== '') || mb_strpos($haystack, $needle) === 0;
    }

    /**
     * @return string[]
     */
    private function splitIntoLines(string $contents): array
    {
        $lines = [];
        $lineNumber = -1;

        foreach (explode("\n", $contents) as $line) {
            $lineNumber++;

            if (!$this->isExecutableLine($line)) {
                continue;
            }

            if ($this->startsWith(self::CONCATENATE_PREFIX, $line)) {
                $lastValue = array_pop($lines);
                $lines[] = $lastValue . ' ' . trim($line);

                continue;
            }

            $lines[$lineNumber] = $line;
        }

        return $lines;
    }
}
