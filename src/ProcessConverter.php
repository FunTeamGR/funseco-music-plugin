<?php
namespace Funseco\Music;

use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\Process;

/**
 * Runs the configured converter command (music.converter) as a child process on the event loop.
 * The command is an argument list started without a shell; only the {input} and {output}
 * placeholders are replaced, by file paths the controller built itself from the sanitised
 * names of files in the music folder. Nothing a player typed ever reaches it.
 */
class ProcessConverter implements Converter
{
    public function available(): bool
    {
        $command = config('music.converter');

        return is_array($command) && $command !== [] && is_string($command[0]);
    }

    public function convert(string $input, string $output): PromiseInterface
    {
        $deferred = new Deferred();
        if (!$this->available()) {
            $deferred->resolve(false);

            return $deferred->promise();
        }

        $command = array_map(fn ($arg) => strtr((string) $arg, ['{input}' => $input, '{output}' => $output]), config('music.converter'));
        $process = new Process($command);
        $process->setTimeout((float) config('music.converter_timeout', 300));

        try {
            $process->start();
        } catch (\Throwable $e) {
            Log::warning('[Music] Converter could not start: '.$e->getMessage());
            $deferred->resolve(false);

            return $deferred->promise();
        }

        $timer = Loop::addPeriodicTimer(0.5, function () use (&$timer, $process, $deferred, $output) {
            try {
                $process->checkTimeout();
            } catch (\Throwable) {
                // timed out: the process was stopped
            }
            if ($process->isRunning()) {
                return;
            }
            Loop::cancelTimer($timer);
            $deferred->resolve($process->isSuccessful() && is_file($output));
        });

        return $deferred->promise();
    }
}
