<?php declare(strict_types=1);

namespace Shopware\Psh\ScriptRuntime\ScriptLoader;

use Shopware\Psh\ScriptRuntime\Command;
use Shopware\Psh\ScriptRuntime\DeferredProcessCommand;
use Shopware\Psh\ScriptRuntime\SynchronusProcessCommand;
use Shopware\Psh\ScriptRuntime\TemplateCommand;
use Shopware\Psh\ScriptRuntime\WaitCommand;

/**
 * Again, a statefull builder to ease the parsing
 */
class CommandBuilder
{
    /**
     * @var Command[]
     */
    private $allCommands = [];

    /**
     * @var bool
     */
    private $ignoreError;

    /**
     * @var bool
     */
    private $tty;

    /**
     * @var bool
     */
    private $template;

    /**
     * @var bool
     */
    private $deferred;

    public function __construct()
    {
        $this->reset();
    }

    private function reset(): void
    {
        $this->ignoreError = false;
        $this->tty = false;
        $this->template = false;
        $this->deferred = false;
    }

    public function addProcessCommand(string $shellCommand, int $startLine): CommandBuilder
    {
        if ($this->deferred) {
            $this->allCommands[] = new DeferredProcessCommand(
                $shellCommand,
                $startLine,
                $this->ignoreError,
                $this->tty
            );
        } else {
            $this->allCommands[] = new SynchronusProcessCommand(
                $shellCommand,
                $startLine,
                $this->ignoreError,
                $this->tty
            );
        }

        $this->reset();

        return $this;
    }

    /**
     * @return $this
     */
    public function addTemplateCommand(string $source, string $destination, int $lineNumber): CommandBuilder
    {
        $this->reset();

        $this->allCommands[] = new TemplateCommand(
            $source,
            $destination,
            $lineNumber
        );

        return $this;
    }

    public function addWaitCommand(int $lineNumber): CommandBuilder
    {
        $this->reset();
        $this->allCommands[] = new WaitCommand($lineNumber);

        return $this;
    }

    public function setIgnoreError(bool $set = true): CommandBuilder
    {
        $this->ignoreError = $set;

        return $this;
    }

    public function setTty(bool $set = true): CommandBuilder
    {
        $this->tty = $set;

        return $this;
    }

    public function setDeferredExecution(bool $set = true): CommandBuilder
    {
        $this->deferred = $set;

        return $this;
    }

    public function replaceCommands(array $commands): CommandBuilder
    {
        $this->allCommands = $commands;

        return $this;
    }

    /**
     * @return Command[]
     */
    public function getAll(): array
    {
        $this->reset();
        $allCommands = $this->allCommands;
        $this->allCommands = [];

        return $allCommands;
    }
}
