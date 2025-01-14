<?php declare(strict_types=1);

namespace Shopware\Psh\ScriptRuntime\Execution;

use InvalidArgumentException;
use Shopware\Psh\Config\Template;
use Shopware\Psh\Listing\Script;
use Shopware\Psh\ScriptRuntime\BashCommand;
use Shopware\Psh\ScriptRuntime\Command;
use Shopware\Psh\ScriptRuntime\DeferredProcessCommand;
use Shopware\Psh\ScriptRuntime\ParsableCommand;
use Shopware\Psh\ScriptRuntime\ProcessCommand;
use Shopware\Psh\ScriptRuntime\SynchronusProcessCommand;
use Shopware\Psh\ScriptRuntime\TemplateCommand;
use Shopware\Psh\ScriptRuntime\WaitCommand;
use Symfony\Component\Process\Process;
use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function unlink;

/**
 * Execute a command in a separate process
 */
class ProcessExecutor
{
    /**
     * @var ProcessEnvironment
     */
    private $environment;

    /**
     * @var TemplateEngine
     */
    private $templateEngine;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var string
     */
    private $applicationDirectory;

    /**
     * @var DeferredProcess[]
     */
    private $deferredProcesses = [];

    public function __construct(
        ProcessEnvironment $environment,
        TemplateEngine $templateEngine,
        Logger $logger,
        string $applicationDirectory
    ) {
        $this->environment = $environment;
        $this->templateEngine = $templateEngine;
        $this->logger = $logger;
        $this->applicationDirectory = $applicationDirectory;
    }

    /**
     * @param Command[] $commands
     */
    public function execute(Script $script, array $commands): void
    {
        $this->logger->startScript($script);

        $this->executeTemplateRendering();

        try {
            foreach ($commands as $index => $command) {
                $this->executeCommand($command, $index, count($commands));
            }
        } finally {
            $this->waitForDeferredProcesses();
        }

        $this->logger->finishScript($script);
    }

    private function executeCommand(Command $command, int $index, int $totalCount): void
    {
        switch (true) {
            case $command instanceof BashCommand:
                $originalContent = file_get_contents($command->getScript()->getPath());

                try {
                    file_put_contents($command->getScript()->getTmpPath(), $this->templateEngine->render($originalContent, $this->environment->getAllValues()));
                    chmod($command->getScript()->getTmpPath(), 0700);

                    $process = $this->environment->createProcess($command->getScript()->getTmpPath());
                    $this->setProcessDefaults($process, $command);
                    $this->logBashStart($command, $index, $totalCount);
                    $this->runProcess($process);

                    if ($command->hasWarning()) {
                        $this->logger->warn($command->getWarning());
                    }

                    $this->testProcessResultValid($process, $command);
                } finally {
                    unlink($command->getScript()->getTmpPath());
                }

                break;
            case $command instanceof SynchronusProcessCommand:
                $parsedCommand = $this->getParsedShellCommand($command);
                $process = $this->environment->createProcess($parsedCommand);

                $this->setProcessDefaults($process, $command);
                $this->logSynchronousProcessStart($command, $index, $totalCount, $parsedCommand);
                $this->runProcess($process);
                $this->testProcessResultValid($process, $command);

                break;
            case $command instanceof DeferredProcessCommand:
                $parsedCommand = $this->getParsedShellCommand($command);
                $process = $this->environment->createProcess($parsedCommand);

                $this->setProcessDefaults($process, $command);
                $this->logDeferedStart($command, $index, $totalCount, $parsedCommand);
                $this->deferProcess($parsedCommand, $command, $process);

                break;
            case $command instanceof TemplateCommand:
                $template = $command->createTemplate();

                $this->logTemplateStart($command, $index, $totalCount, $template);
                $this->renderTemplate($template);

                break;
            case $command instanceof WaitCommand:
                $this->logWaitStart($command, $index, $totalCount);
                $this->waitForDeferredProcesses();

                break;
            default:
                throw new InvalidArgumentException('Trying to execute unknown command');
        }
    }

    private function executeTemplateRendering(): void
    {
        foreach ($this->environment->getTemplates() as $template) {
            $this->renderTemplate($template);
        }
    }

    private function getParsedShellCommand(ParsableCommand $command): string
    {
        $rawShellCommand = $command->getShellCommand();

        $parsedCommand = $this->templateEngine->render(
            $rawShellCommand,
            $this->environment->getAllValues()
        );

        return $parsedCommand;
    }

    private function setProcessDefaults(Process $process, ProcessCommand $command): void
    {
        $process->setWorkingDirectory($this->applicationDirectory);
        $process->setTimeout(0);
        $process->setTty($command->isTTy());
    }

    private function runProcess(Process $process): void
    {
        $process->run(function (string $type, string $response): void {
            $this->logger->log(new LogMessage($response, $type === Process::ERR));
        });
    }

    private function testProcessResultValid(Process $process, ProcessCommand $command): void
    {
        if (!$this->isProcessResultValid($process, $command)) {
            throw new ExecutionErrorException('Command exited with Error');
        }
    }

    private function renderTemplate(Template $template): void
    {
        $renderedTemplateDestination = $this->templateEngine
            ->render($template->getDestination(), $this->environment->getAllValues());

        $template->setDestination($renderedTemplateDestination);

        $renderedTemplateContent = $this->templateEngine
            ->render($template->getContent(), $this->environment->getAllValues());

        $template->setContents($renderedTemplateContent);
    }

    private function waitForDeferredProcesses(): void
    {
        if (count($this->deferredProcesses) === 0) {
            return;
        }

        $this->logger->logWait();

        foreach ($this->deferredProcesses as $index => $deferredProcess) {
            $deferredProcess->getProcess()->wait();

            $this->logDeferredOutputStart($deferredProcess, $index);

            foreach ($deferredProcess->getLog() as $logMessage) {
                $this->logger->log($logMessage);
            }

            if ($this->isProcessResultValid($deferredProcess->getProcess(), $deferredProcess->getCommand())) {
                $this->logger->logSuccess();
            } else {
                $this->logger->logFailure();
            }
        }

        foreach ($this->deferredProcesses as $deferredProcess) {
            $this->testProcessResultValid($deferredProcess->getProcess(), $deferredProcess->getCommand());
        }

        $this->deferredProcesses = [];
    }

    private function deferProcess(string $parsedCommand, DeferredProcessCommand $command, Process $process): void
    {
        $deferredProcess = new DeferredProcess($parsedCommand, $command, $process);

        $process->start(function (string $type, string $response) use ($deferredProcess): void {
            $deferredProcess->log(new LogMessage($response, $type === Process::ERR));
        });

        $this->deferredProcesses[] = $deferredProcess;
    }

    private function isProcessResultValid(Process $process, ProcessCommand $command): bool
    {
        return $command->isIgnoreError() || $process->isSuccessful();
    }

    private function logWaitStart(WaitCommand $command, int $index, int $totalCount): void
    {
        $this->logger->logStart(
            'Waiting',
            '',
            $command->getLineNumber(),
            false,
            $index,
            $totalCount
        );
    }

    private function logTemplateStart(TemplateCommand $command, int $index, int $totalCount, Template $template): void
    {
        $this->logger->logStart(
            'Template',
            $template->getDestination(),
            $command->getLineNumber(),
            false,
            $index,
            $totalCount
        );
    }

    private function logDeferedStart(DeferredProcessCommand $command, int $index, int $totalCount, string $parsedCommand): void
    {
        $this->logger->logStart(
            'Deferring',
            $parsedCommand,
            $command->getLineNumber(),
            $command->isIgnoreError(),
            $index,
            $totalCount
        );
    }

    private function logSynchronousProcessStart(ProcessCommand $command, int $index, int $totalCount, string $parsedCommand): void
    {
        $this->logger->logStart(
            'Starting',
            $parsedCommand,
            $command->getLineNumber(),
            $command->isIgnoreError(),
            $index,
            $totalCount
        );
    }

    private function logBashStart(BashCommand $command, int $index, int $totalCount): void
    {
        $this->logger->logStart(
            'Executing',
            $command->getScript()->getPath(),
            $command->getLineNumber(),
            false,
            $index,
            $totalCount
        );
    }

    private function logDeferredOutputStart(DeferredProcess $deferredProcess, int $index): void
    {
        $this->logger->logStart(
            'Output from',
            $deferredProcess->getParsedCommand(),
            $deferredProcess->getCommand()->getLineNumber(),
            $deferredProcess->getCommand()->isIgnoreError(),
            $index,
            count($this->deferredProcesses)
        );
    }
}
