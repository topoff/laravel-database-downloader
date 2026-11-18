<?php

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Console\Kernel;
use Topoff\DatabaseDownloader\Concerns\InteractsWithMysql;

/**
 * Regression: in verbose mode the full shell output went into one bullet list. Termwind
 * renders it through libxml2, which is quadratic on 2.9.x (Ubuntu 24.04): the unzip of a
 * backup with ~17k files hung db:download -v for ~47 minutes.
 */
beforeEach(function (): void {
    $this->bulletLists = [];
    $bulletLists = &$this->bulletLists;

    // Records every bullet list and refuses large ones, so a regression fails at once
    // instead of hanging the suite on an old libxml2 (time-boxed by construction).
    $this->app->bind(Factory::class, function ($app, array $parameters) use (&$bulletLists): Factory {
        return new class($parameters['output'], $bulletLists) extends Factory
        {
            /** @param  array<int, int>  $bulletLists */
            public function __construct($output, private array &$bulletLists)
            {
                parent::__construct($output);
            }

            public function __call($method, $parameters)
            {
                if ($method === 'bulletList') {
                    $this->bulletLists[] = count($parameters[0]);

                    if (count($parameters[0]) > 50) {
                        throw new RuntimeException('bulletList received '.count($parameters[0]).' lines');
                    }
                }

                return parent::__call($method, $parameters);
            }
        };
    });

    $this->app->make(Kernel::class)->registerCommand(new class extends Command
    {
        use InteractsWithMysql;

        protected $signature = 'db:shell-output-test {lines}';

        public function handle(): int
        {
            $command = 'seq 1 '.(int) $this->argument('lines');
            $this->components->task('Extracting backup archive', fn (): ?string => $this->executeShellCommand($command));

            return self::SUCCESS;
        }
    });
});

it('shows only the first lines of a long shell output in verbose mode', function (): void {
    $startedAt = microtime(true);

    $this->artisan('db:shell-output-test 20000 -v')
        ->expectsOutputToContain('19980 more lines not shown')
        ->doesntExpectOutputToContain('⇂ 21')
        ->assertExitCode(0);

    expect($this->bulletLists)->toBe([21])
        ->and(microtime(true) - $startedAt)->toBeLessThan(10);
});

it('shows a short shell output completely in verbose mode', function (): void {
    $this->artisan('db:shell-output-test 20 -v')
        ->expectsOutputToContain('⇂ 20')
        ->doesntExpectOutputToContain('more lines not shown')
        ->assertExitCode(0);

    expect($this->bulletLists)->toBe([20]);
});

it('renders no shell output without verbose mode', function (): void {
    $this->artisan('db:shell-output-test 20000')->assertExitCode(0);

    expect($this->bulletLists)->toBe([]);
});
