<?php

namespace Sikessem\Installer;

use Sikessem\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    use ApplicationTrait;

    public function __construct(string $name = self::NAME, string $version = self::VERSION)
    {
        parent::__construct($name, $version);

        $this->addCommand(new Commands\NewCommand);
        $this->addCommand(new Commands\CreateCommand);
        $this->addCommand(new Commands\InitCommand);
    }
}
