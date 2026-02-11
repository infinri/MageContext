<?php

declare(strict_types=1);

namespace MageContext;

use MageContext\Command\CompileCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public const APP_NAME = 'context-compiler';
    public const APP_VERSION = '0.1.0';

    public function __construct()
    {
        parent::__construct(self::APP_NAME, self::APP_VERSION);

        $this->add(new CompileCommand());
    }
}
