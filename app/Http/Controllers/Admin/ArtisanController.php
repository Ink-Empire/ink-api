<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ArtisanController extends Controller
{
    private const DESTRUCTIVE_COMMANDS = [
        'data:seed',
        'data:clean',
        'elastic:reset',
        'elastic:migrate',
    ];

    /**
     * Return the list of custom artisan commands with metadata.
     */
    public function index(): JsonResponse
    {
        $commands = [];
        $commandFiles = File::files(app_path('Console/Commands'));

        foreach ($commandFiles as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'App\\Console\\Commands\\' . $file->getFilenameWithoutExtension();

            if (!class_exists($className)) {
                continue;
            }

            try {
                $command = app($className);
            } catch (\Throwable $e) {
                continue;
            }

            $name = $command->getName();
            if (!$name) {
                continue;
            }

            $definition = $command->getDefinition();

            $arguments = [];
            foreach ($definition->getArguments() as $arg) {
                $arguments[] = [
                    'name' => $arg->getName(),
                    'description' => $arg->getDescription(),
                    'required' => $arg->isRequired(),
                    'default' => $arg->getDefault(),
                ];
            }

            $options = [];
            foreach ($definition->getOptions() as $opt) {
                // Skip framework-level options
                if (in_array($opt->getName(), ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'])) {
                    continue;
                }

                $options[] = [
                    'name' => '--' . $opt->getName(),
                    'description' => $opt->getDescription(),
                    'accepts_value' => $opt->acceptValue(),
                    'default' => $opt->getDefault(),
                ];
            }

            $commands[] = [
                'name' => $name,
                'description' => $command->getDescription(),
                'arguments' => $arguments,
                'options' => $options,
                'destructive' => in_array($name, self::DESTRUCTIVE_COMMANDS),
            ];
        }

        usort($commands, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return response()->json(['commands' => $commands]);
    }

    /**
     * Execute an artisan command and return its output.
     */
    public function run(Request $request): JsonResponse
    {
        $request->validate([
            'command' => 'required|string',
            'options' => 'nullable|array',
        ]);

        $commandName = $request->input('command');
        $options = $request->input('options', []);

        // Validate the command exists and is a custom command
        $allowedCommands = $this->getAllowedCommandNames();
        if (!in_array($commandName, $allowedCommands)) {
            return response()->json([
                'error' => 'Command not found or not allowed.',
            ], 422);
        }

        $start = microtime(true);

        try {
            $exitCode = Artisan::call($commandName, $options);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'output' => $e->getMessage(),
                'exit_code' => 1,
                'duration_ms' => round((microtime(true) - $start) * 1000),
            ]);
        }

        $durationMs = round((microtime(true) - $start) * 1000);

        return response()->json([
            'output' => $output,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Get list of allowed command names from custom commands.
     */
    private function getAllowedCommandNames(): array
    {
        $names = [];
        $commandFiles = File::files(app_path('Console/Commands'));

        foreach ($commandFiles as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'App\\Console\\Commands\\' . $file->getFilenameWithoutExtension();

            if (!class_exists($className)) {
                continue;
            }

            try {
                $command = app($className);
                $name = $command->getName();
                if ($name) {
                    $names[] = $name;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $names;
    }
}
