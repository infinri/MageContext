<?php

declare(strict_types=1);

namespace MageContext;

use MageContext\Command\CompileCommand;
use MageContext\Command\DiffCommand;
use MageContext\Command\GuideCommand;
use MageContext\Command\PackCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public const APP_NAME = 'magecontext';
    public const APP_VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(self::APP_NAME, self::APP_VERSION);

        $this->addCommand(new CompileCommand());
        $this->addCommand(new DiffCommand());
        $this->addCommand(new PackCommand());
        $this->addCommand(new GuideCommand());
    }
}
