<?php

declare(strict_types=1);

namespace SvnManage\Svn;

/**
 * Runs external programs WITHOUT a shell (proc_open with an argv array),
 * so arguments can never be interpreted as shell syntax.
 */
final class ProcessRunner
{
    /**
     * @param int $maxBuffer most bytes of stdout kept in memory (streamed output via
     *                       $onStdout is not limited); protects against huge listings
     */
    public function __construct(
        private readonly int $timeout = 300,
        private readonly int $maxBuffer = 16_777_216,
    ) {
    }

    /**
     * @param list<string> $argv
     * @param resource|null $stdin stream to feed on stdin
     * @param (callable(string): void)|null $onStdout receives stdout chunks instead of buffering
     */
    public function run(array $argv, $stdin = null, ?callable $onStdout = null): ProcessResult
    {
        $spec = [
            0 => $stdin !== null ? $stdin : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = ['LC_ALL' => 'C', 'LANG' => 'C', 'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin', 'HOME' => sys_get_temp_dir()];
        $proc = @proc_open($argv, $spec, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new SvnException(sprintf('Cannot execute "%s". Check the executable path in the settings.', $argv[0] ?? '?'));
        }
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeout;
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while ($open !== []) {
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $stderr .= "\nProcess timed out after {$this->timeout}s.";
                break;
            }
            $read = array_values($open);
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                $fd = $stream === $pipes[1] ? 1 : 2;
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }
                    continue;
                }
                if ($fd === 1 && $onStdout !== null) {
                    $onStdout($chunk);
                } elseif ($fd === 1) {
                    $stdout .= $chunk;
                    if (strlen($stdout) > $this->maxBuffer) {
                        $stdout = substr($stdout, 0, $this->maxBuffer);
                        proc_terminate($proc, 9);
                        $stderr .= "\nThe output was too large and has been cut off.";
                        break 2;
                    }
                } elseif (strlen($stderr) < 65536) {
                    $stderr .= $chunk;
                }
            }
        }
        foreach ($open as $stream) {
            fclose($stream);
        }
        $code = proc_close($proc);

        return new ProcessResult($code, $stdout, $stderr);
    }
}
