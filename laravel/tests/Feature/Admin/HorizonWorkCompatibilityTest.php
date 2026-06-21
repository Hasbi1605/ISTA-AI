<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\CompatibleHorizonWorkCommand;
use Laravel\Horizon\Console\WorkCommand as HorizonWorkCommand;
use Tests\TestCase;

class HorizonWorkCompatibilityTest extends TestCase
{
    public function test_horizon_work_command_accepts_laravel_13_stop_when_empty_for_option(): void
    {
        $command = app(HorizonWorkCommand::class);

        $this->assertInstanceOf(CompatibleHorizonWorkCommand::class, $command);
        $this->assertTrue($command->getDefinition()->hasOption('stop-when-empty-for'));
    }
}
