<?php

declare(strict_types=1);

namespace Sikessem\Installer\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Laravel\Installer\Console\NewCommand as BaseCommand;
use Override;
use RuntimeException;
use Sikessem\Installer\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class NewCommand extends BaseCommand
{
    /**
     * Configure the command options.
     *
     * @return void
     */
    #[Override]
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Sikessem application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: '.implode(', ', self::DATABASE_DRIVERS))
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies')
            ->addOption('using', null, InputOption::VALUE_OPTIONAL, 'Install a custom starter kit from a community maintained package')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Interact with the user before validating the input.
     *
     * @return void
     */
    #[Override]
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        Command::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'<fg=blue>'.Application::LOGO.'</>'.PHP_EOL);

        $this->ensureExtensionsAreAvailable($input, $output);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: 'What is the name of your project?',
                placeholder: 'E.g. example-app',
                required: 'The project name is required.',
                validate: function ($value) use ($input) {
                    if (preg_match('/[^\pL\pN\-_.]/', $value) !== 0) {
                        return 'The name may only contain letters, numbers, dashes, underscores, and periods.';
                    }

                    if ($input->getOption('force') !== true) {
                        try {
                            $this->verifyApplicationDoesntExist($this->getInstallationDirectory($value));
                        } catch (RuntimeException) {
                            return 'Application already exists.';
                        }
                    }
                },
            ));
        }

        if ($input->getOption('force') !== true) {
            $this->verifyApplicationDoesntExist(
                $this->getInstallationDirectory($input->getArgument('name'))
            );
        }
    }

    /**
     * Execute the command.
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateDatabaseOption($input);

        $name = rtrim((string) $input->getArgument('name'), '/\\');

        $directory = $this->getInstallationDirectory($name);

        $this->composer = new Composer(new Filesystem, $directory);

        $version = $this->getVersion($input);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();
        $phpBinary = $this->phpBinary();

        $createProjectCommand = $composer." create-project sikessem/starter \"$directory\" $version --remove-vcs --prefer-dist --no-scripts";

        $starterKit = $this->getStarterKit($input);

        if ($starterKit) {
            $createProjectCommand = $composer." create-project {$starterKit} \"{$directory}\" --stability=dev";

            if (! $this->usingSikessemStarterKit($input) && str_contains($starterKit, '://')) {
                $createProjectCommand = 'npx tiged@latest '.$starterKit.' "'.$directory.'" && cd "'.$directory.'" && composer install';
            }
        }

        $commands = [
            $createProjectCommand,
            $composer." run post-root-package-install -d \"$directory\"",
            $phpBinary." \"$directory/artisan\" key:generate --ansi",
        ];

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[] = "chmod 755 \"$directory/artisan\"";
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($name !== '.') {
                $this->replaceInFile(
                    'APP_URL=http://localhost',
                    'APP_URL='.$this->generateAppUrl($name, $directory),
                    $directory.'/.env'
                );

                [$database, $migrate] = $this->promptForDatabaseOptions($directory, $input);

                $this->configureDefaultDatabaseConnection($directory, $database, $name);

                if ($migrate) {
                    if ($database === 'sqlite') {
                        touch($directory.'/database/database.sqlite');
                    }

                    $commands = [
                        trim(sprintf(
                            $this->phpBinary().' artisan migrate %s',
                            ! $input->isInteractive() ? '--no-interaction' : '',
                        )),
                    ];

                    $this->runCommands($commands, $input, $output, workingPath: $directory);
                }
            }

            if ($input->getOption('git') || $input->getOption('github') !== false) {
                $this->createRepository($directory, $input, $output);
            }

            if ($input->getOption('github') !== false) {
                $this->pushToGitHub($name, $directory, $input, $output);
                $output->writeln('');
            }

            $packageInstall = 'npm install';
            $packageRun = 'npm run';
            $packageDlx = 'npx';

            if (file_exists($directory.'/bun.lock')) {
                $packageInstall = 'bun install';
                $packageRun = 'bun run';
                $packageDlx = 'bunx';
            } elseif (file_exists($directory.'/pnpm-lock.yaml')) {
                $packageInstall = 'pnpm install';
                $packageRun = 'pnpm';
                $packageDlx = 'pnpx';
            } elseif (file_exists($directory.'/yarn.lock')) {
                $packageInstall = 'yarn install';
                $packageRun = 'yarn';
                $packageDlx = 'yarn dlx';
            }

            $packageBuild = "{$packageRun} build";

            $this->configureComposerDevScript($directory, $packageRun, $packageDlx);

            $runNpm = $input->getOption('npm');

            if (! $input->getOption('npm') && $input->isInteractive()) {
                $runNpm = confirm(
                    label: 'Would you like to run <options=bold>'.$packageInstall.'</> and <options=bold>'.$packageBuild.'</>?'
                );
            }

            if ($runNpm) {
                $this->runCommands([$packageInstall, $packageBuild], $input, $output, workingPath: $directory);
            }

            $output->writeln("  <bg=blue;fg=white> INFO </> Application ready in <options=bold>[{$name}]</>. You can start your local development using:".PHP_EOL);
            $output->writeln('<fg=gray>➜</> <options=bold>cd '.$name.'</>');

            if (! $runNpm) {
                $output->writeln('<fg=gray>➜</> <options=bold>'.$packageInstall.' && '.$packageBuild.'</>');
            }

            $output->writeln('<fg=gray>➜</> <options=bold>composer run dev</>');

            $output->writeln('');
            $output->writeln('  New to Sikessem? Check out our <href=https://sikessem.github.io>documentation</>. <options=bold>Build something amazing! ✨</>');
            $output->writeln('');
        }

        return $process->getExitCode();
    }

    /**
     * Configure the default database connection.
     *
     * @return void
     */
    #[Override]
    protected function configureDefaultDatabaseConnection(string $directory, string $database, string $name)
    {
        parent::configureDefaultDatabaseConnection($directory, $database, $name);

        $this->replaceInFile(
            'DB_DATABASE=sikessem',
            'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
            $directory.'/.env'
        );
    }

    /**
     * Determine if the application is using Sikessem 11 or newer.
     */
    public function usingSikessemVersionOrNewer(int $usingVersion, string $directory): bool
    {
        $version = json_decode(file_get_contents($directory.'/composer.json'), true)['require']['sikessem/framework'] ?? Application::VERSION;
        $version = str_replace('^', '', $version);
        $version = explode('.', $version)[0];

        return $version >= $usingVersion;
    }

    /**
     * Comment the irrelevant database configuration entries for SQLite applications.
     */
    #[Override]
    protected function commentDatabaseConfigurationForSqlite(string $directory): void
    {
        parent::uncommentDatabaseConfiguration($directory);

        $defaults = [
            'DB_DATABASE=sikessem',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => "# {$default}")->all(),
            $directory.'/.env.example'
        );
    }

    /**
     * Uncomment the relevant database configuration entries for non SQLite applications.
     *
     * @return void
     */
    #[Override]
    protected function uncommentDatabaseConfiguration(string $directory)
    {
        parent::uncommentDatabaseConfiguration($directory);

        $defaults = [
            '# DB_DATABASE=sikessem',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env'
        );

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn ($default) => substr($default, 2))->all(),
            $directory.'/.env.example'
        );
    }

    /**
     * Configure the Composer "dev" script.
     */
    protected function configureComposerDevScript(string $directory, string $run = 'npm', string $dlx = 'npx'): void
    {
        $this->composer->modify(function (array $content) use ($run, $dlx): array {
            if (windows_os()) {
                $content['scripts']['dev'] = [
                    'Composer\\Config::disableProcessTimeout',
                    "{$dlx} concurrently -c \"#93c5fd,#c4b5fd,#fdba74\" \"php artisan serve\" \"php artisan queue:listen --tries=1\" \"{$run} dev\" --names='server,queue,vite'",
                ];
            }

            return $content;
        });
    }

    /**
     * Get the starter kit repository, if any.
     */
    #[Override]
    protected function getStarterKit(InputInterface $input): ?string
    {
        return $input->getOption('using');
    }

    /**
     * Determine if a starter kit is being used.
     *
     * @return bool
     */
    #[Override]
    protected function usingStarterKit(InputInterface $input)
    {
        return (bool) $input->getOption('using');
    }

    /**
     * Determine if a Sikessem first-party starter kit has been chosen.
     */
    protected function usingSikessemStarterKit(InputInterface $input): bool
    {
        return $this->usingStarterKit($input) &&
               str_starts_with((string) $this->getStarterKit($input), 'sikessem/');
    }

    #[Override]
    public function defaultBranch(): string
    {
        return parent::defaultBranch();
    }
}
