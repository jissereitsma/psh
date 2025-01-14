<?php declare(strict_types=1);

namespace Shopware\Psh\ScriptRuntime;

/**
 * A single command of a script
 */
class SynchronusProcessCommand implements ProcessCommand, ParsableCommand
{
    /**
     * @var string
     */
    private $shellCommand;

    /**
     * @var bool
     */
    private $ignoreError;

    /**
     * @var int
     */
    private $lineNumber;

    /**
     * @var bool
     */
    private $tty;

    public function __construct(
        string $shellCommand,
        int $lineNumber,
        bool $ignoreError,
        bool $tty
    ) {
        $this->shellCommand = $shellCommand;
        $this->ignoreError = $ignoreError;
        $this->lineNumber = $lineNumber;
        $this->tty = $tty;
    }

    public function getShellCommand(): string
    {
        return $this->shellCommand;
    }

    public function isIgnoreError(): bool
    {
        return $this->ignoreError;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function isTTy(): bool
    {
        return $this->tty;
    }
}
