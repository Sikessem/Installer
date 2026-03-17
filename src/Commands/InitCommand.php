<?php

declare(strict_types=1);

namespace Sikessem\Installer\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Illuminate\Support\ProcessUtils;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class InitCommand extends Command
{
    protected string $directory = '.';

    protected ?string $composer = null;

    protected ?string $php = null;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('init')
            ->setDescription('Initialize Loom in the current project')
            ->addArgument('directory', InputArgument::OPTIONAL);
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string */
        $directory = $input->getArgument('directory') ?: '.';
        if (! str_starts_with($directory, '.')) {
            $directory = getcwd().'/'.$directory;
        }

        if (! is_dir($directory)) {
            $output->write("<fg=red>{$directory} is not a directory</>");

            return self::INVALID;
        }

        $directory = realpath($directory) ?: $directory;

        $this->directory = $directory;

        $this->runCommands([
            $this->composer('require', 'sikessem/framework'),
            $this->php('artisan', 'sikessem:install'),
        ], $input, $output, $directory);

        return self::SUCCESS;
    }

    protected function php(string ...$args): string
    {
        if (! isset($this->php)) {
            $this->php = $this->findPhp();
        }

        return $this->binaryCommand($this->php, ...$args);
    }

    protected function composer(string ...$args): string
    {
        if (! isset($this->composer)) {
            $this->composer = $this->findComposer();
        }

        return $this->binaryCommand($this->composer, ...$args);
    }

    protected function findPhp(): string
    {
        $phpBinary = function_exists('Illuminate\Support\php_binary')
            ? \Illuminate\Support\php_binary()
            : (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    protected function findComposer(): string
    {
        return implode(' ', new Composer(new Filesystem, $this->directory)->findComposer());
    }

    protected function binaryCommand(string $bin, string ...$args): string
    {
        return implode(' ', [$bin, ...$args]);
    }

    protected function runCommands($commands, InputInterface $input, OutputInterface $output, ?string $workingPath = null, array $env = []): Process
    {
        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }
}
