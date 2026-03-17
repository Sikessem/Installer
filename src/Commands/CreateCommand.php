<?php

declare(strict_types=1);

namespace Sikessem\Installer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateCommand extends Command
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('create')
            ->setDescription('Create a new Sikessem application from a custom starter kit')
            ->addArgument('starter', InputArgument::REQUIRED, 'The starter kit')
            ->addArgument('name', InputArgument::REQUIRED, 'The project name')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'The branch that should be created for a new repository')
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $starter = $input->getArgument('starter');
        /** @var NewCommand */
        $command = $this->getApplication()->find('new');
        $options = [];
        foreach ($input->getOptions() as $key => $value) {
            $options["--{$key}"] = $value;
        }
        if (! $options['--branch']) {
            $options['--branch'] = $command->defaultBranch();
        }

        $arguments = new ArrayInput([
            'name' => $name,
            '--using' => $starter,
            ...$options,
        ]);

        return $command->run($arguments, $output);
    }
}
