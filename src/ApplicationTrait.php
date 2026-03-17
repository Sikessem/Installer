<?php

namespace Sikessem\Installer;

trait ApplicationTrait
{
    public const NAME = 'Sikessem Installer';

    public const VERSION = 'v0.0.0';

    public const LOGO = '
 ███████╗ ██╗ ██╗  ██╗ ███████╗ ███████╗ ███████╗ ███████╗ ███╗   ███╗
 ██╔════╝ ██║ ██║ ██╔╝ ██╔════╝ ██╔════╝ ██╔════╝ ██╔════╝ ████╗ ████║
 ███████╗ ██║ █████╔╝  █████╗   ███████╗ ███████╗ █████╗   ██╔████╔██║
 ╚════██║ ██║ ██╔═██╗  ██╔══╝   ╚════██║ ╚════██║ ██╔══╝   ██║╚██╔╝██║
 ███████║ ██║ ██║  ██╗ ███████╗ ███████║ ███████║ ███████╗ ██║ ╚═╝ ██║
 ╚══════╝ ╚═╝ ╚═╝  ╚═╝ ╚══════╝ ╚══════╝ ╚══════╝ ╚══════╝ ╚═╝     ╚═╝';

    abstract public function __construct(string $name = self::NAME, string $version = self::VERSION);
}
