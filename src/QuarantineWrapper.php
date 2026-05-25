<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Util\HttpDownloader;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;

final class QuarantineWrapper
{
    private ?string $composerLauncher = null;
    private bool $interrupted = false;
    private ConsoleOutput $consoleOutput;

    public function __construct(
        private readonly ConfigResolver $configResolver = new ConfigResolver(),
        private readonly ?PackagistMetadataFetcher $fetcher = null,
    ) {
        $this->consoleOutput = new ConsoleOutput();
    }

    public function run(array $argv): int
    {
        $this->registerInterruptHandler();

        try {
            $command = $argv[1] ?? '';
            $arguments = array_values(array_slice($argv, 2));
            $bypass = $this->hasIgnoreQuarantineFlag($arguments);
            $arguments = $this->stripIgnoreQuarantineFlag($arguments);

            if ($command === 'init') {
                return $this->runInit($arguments);
            }

            if (!in_array($command, ['require', 'update'], true)) {
                $this->writeError("Usage: composer-quarantine <init|require|update> [options] [packages...]\n");
                return 1;
            }

            if ($bypass) {
                return $this->runComposerDirectly($command, $arguments);
            }

            $workingDirectory = getcwd() ?: '.';
            $config = $this->configResolver->resolveForWorkingDirectory($workingDirectory);
            $fetcher = $this->fetcher ?? $this->createFetcher($config);
            $rootPackageNames = $this->extractPackageNames($arguments);

            $this->writeOutput("<info>[Composer Quarantine]</info> analyzing dependency plan...\n");
            if (!$config->checkDependencies) {
                $this->writeOutput("<comment>[Composer Quarantine]</comment> dependency scanning disabled; only explicit packages will be pinned.\n");
            }
            $dryRunPlan = $this->buildDryRunPlan($workingDirectory, $command, $arguments);
            if ($dryRunPlan['exitCode'] !== 0) {
                $this->writeOutput($dryRunPlan['output']);
                return $dryRunPlan['exitCode'];
            }

            $plannedPackages = $this->parsePlannedPackages($dryRunPlan['output']);
            if ($plannedPackages === []) {
                $this->writeOutput($dryRunPlan['output']);
                return 0;
            }

            $resolvedPlan = $this->applyQuarantinePolicy($plannedPackages, $rootPackageNames, $config, $fetcher);

            if (!$this->confirmPlan($resolvedPlan['packages'], $command)) {
                $this->abort();
            }

            return $this->executeInstall($workingDirectory, $command, $arguments, $resolvedPlan['packages']);
        } catch (UserAbortedException) {
            $this->writeError("\nAborted.\n");

            return 130;
        }
    }

    /**
     * @return array{exitCode:int, output:string}
     */
    private function buildDryRunPlan(string $workingDirectory, string $command, array $arguments): array
    {
        $this->writeOutput("<info>[Composer Quarantine]</info> running Composer dry-run...\n");
        $tempDir = $this->createTempWorkspace($workingDirectory, $command, $arguments);
        $planCommand = $command === 'require'
            ? ['composer', 'update', '--dry-run', '--no-install', '--no-ansi', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--working-dir=' . $tempDir]
            : ['composer', 'update', '--dry-run', '--no-install', '--no-ansi', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--working-dir=' . $tempDir];

        if ($command === 'update') {
            foreach ($this->extractPackageArguments($arguments) as $packageArgument) {
                $planCommand[] = $packageArgument;
            }
        }

        foreach ($this->extractOptions($arguments) as $option) {
            $planCommand[] = $option;
        }

        return $this->runProcess($planCommand);
    }

    /**
     * @param list<string> $arguments
     */
    private function runInit(array $arguments): int
    {
        $workingDirectory = getcwd() ?: '.';
        $composerJsonPath = $workingDirectory . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_file($composerJsonPath)) {
            $this->writeError("composer.json not found in the current directory.\n");
            return 1;
        }

        $contents = file_get_contents($composerJsonPath);
        if ($contents === false || $contents === '') {
            $this->writeError("Unable to read composer.json.\n");
            return 1;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->writeError(sprintf("Invalid composer.json: %s\n", $exception->getMessage()));
            return 1;
        }

        $decoded['extra'] ??= [];
        if (!is_array($decoded['extra'])) {
            $decoded['extra'] = [];
        }

        $policy = $decoded['extra']['composer-quarantine'] ?? [];
        if (!is_array($policy)) {
            $policy = [];
        }

        $policy = array_merge([
            'minimum-age-hours' => 48,
            'fail-open' => true,
            'ignored-packages' => [],
            'allow-dev' => false,
            'allow-prerelease' => false,
            'verbose' => false,
            'packagist-request-interval-ms' => 1000,
            'max-suggested-versions-to-show' => 20,
            'check-dependencies' => false,
        ], $policy);

        foreach ($this->extractInitOptions($arguments) as $key => $value) {
            $policy[$key] = $value;
        }

        $decoded['extra']['composer-quarantine'] = $policy;

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $this->writeError("Failed to encode composer.json.\n");
            return 1;
        }

        $bytesWritten = file_put_contents($composerJsonPath, $encoded . PHP_EOL);
        if ($bytesWritten === false) {
            $this->writeError("Failed to write composer.json.\n");
            return 1;
        }

        $this->writeOutput("<info>[Composer Quarantine]</info> added composer-quarantine config to composer.json\n");

        return 0;
    }

    /**
     * @return array<string, string>
     */
    private function parsePlannedPackages(string $output): array
    {
        $planned = [];
        $pattern = '/^\s*-\s+(?:Locking|Upgrading|Downgrading|Installing)\s+([^\s]+)\s+\(([^)]+)\)/m';

        if (!preg_match_all($pattern, $output, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $planned[$match[1]] = $match[2];
        }

        return $planned;
    }

    /**
     * @param array<string, string> $plannedPackages
     * @param list<string> $rootPackageNames
     * @return array{
     *     packages: array<string, string>,
     *     blocked: array<string, array{current:string, suggestions:list<string>}>
     * }
     */
    private function applyQuarantinePolicy(array $plannedPackages, array $rootPackageNames, QuarantineConfig $config, PackagistMetadataFetcher $fetcher): array
    {
        $inspectDependencies = $config->checkDependencies || $rootPackageNames === [];
        $rootPackageLookup = array_fill_keys($rootPackageNames, true);
        $packages = $inspectDependencies ? $plannedPackages : array_intersect_key($plannedPackages, $rootPackageLookup);
        $blocked = [];
        $validator = new PackageAgeValidator($config);

        foreach ($packages as $packageName => $version) {
            if ($this->isPlatformPackage($packageName) || $packageName === 'maxiviper117/composer-quarantine') {
                continue;
            }

            $package = new CompletePackage($packageName, $version, $version);
            $releaseDate = $this->resolveReleaseDateForVersion($fetcher, $packageName, $version, $config->allowDev);

            if ($releaseDate === null) {
                continue;
            }

            if (!$validator->shouldBlockByAge($package, $releaseDate) && !$validator->shouldBlockByStability($package)) {
                continue;
            }

            $suggestions = $this->suggestAllowedVersions($fetcher, $packageName, $config, $validator);
            if ($suggestions === []) {
                throw new RuntimeException(sprintf('No allowed versions found for %s.', $packageName));
            }

            $blocked[$packageName] = [
                'current' => $version,
                'currentReleaseDate' => $releaseDate,
                'suggestions' => array_map(static fn (array $suggestion): string => $suggestion['version'], $suggestions),
            ];

            $packages[$packageName] = $this->chooseAllowedVersion(
                $packageName,
                $version,
                $releaseDate,
                $suggestions,
                $config->maxSuggestedVersionsToShow,
                $config->minimumAgeHours
            );
        }

        return [
            'packages' => $packages,
            'blocked' => $blocked,
        ];
    }

    /**
     * @return list<array{version:string, releaseDate:DateTimeImmutable}>
     */
    private function suggestAllowedVersions(
        PackagistMetadataFetcher $fetcher,
        string $packageName,
        QuarantineConfig $config,
        PackageAgeValidator $validator
    ): array {
        $releaseDates = $fetcher->getReleaseDates($packageName, $config->allowDev);
        $suggestions = [];

        foreach ($releaseDates as $version => $releaseDate) {
            $package = new CompletePackage($packageName, (string) $version, (string) $version);

            if ($validator->shouldBlockByStability($package)) {
                continue;
            }

            if ($validator->shouldBlockByAge($package, $releaseDate)) {
                continue;
            }

            $suggestions[] = [
                'version' => (string) $version,
                'releaseDate' => $releaseDate,
            ];
        }

        usort($suggestions, static fn (array $left, array $right): int => version_compare($right['version'], $left['version']));

        return array_values($suggestions);
    }

    private function confirmPlan(array $packages, string $command): bool
    {
        $this->writeOutput("\n[Composer Quarantine]\n");
        $this->writeOutput(sprintf("Resolved plan for composer %s:\n", $command));

        foreach ($packages as $packageName => $version) {
            $this->writeOutput(sprintf("  %s %s\n", $packageName, $version));
        }

        if (!$this->isInteractive()) {
            return true;
        }

        $answer = strtolower(trim($this->prompt('Continue with these exact versions? [y/N] ')));
        return in_array($answer, ['y', 'yes'], true);
    }

    /**
     * @param list<array{version:string, releaseDate:DateTimeImmutable}> $suggestions
     */
    private function chooseAllowedVersion(
        string $packageName,
        string $currentVersion,
        ?DateTimeImmutable $currentReleaseDate,
        array $suggestions,
        int $maxVersionsToShow,
        int $minimumAgeHours
    ): string
    {
        if (!$this->isInteractive()) {
            throw new UserAbortedException(sprintf(
                '%s %s is quarantined. Run in an interactive terminal to choose a safe version.',
                $packageName,
                $currentVersion
            ));
        }

        $maxVersionsToShow = max(1, $maxVersionsToShow);
        $displaySuggestions = array_slice($suggestions, 0, $maxVersionsToShow);
        $hiddenCount = count($suggestions) - count($displaySuggestions);

        $this->writeOutput(sprintf("\nChoose a safe version for %s:\n", $packageName));
        $this->writeOutput(sprintf("  Current: %s\n", $currentVersion));
        $this->writeOutput(sprintf("  Policy minimum age: %dh\n", max(0, $minimumAgeHours)));
        if ($currentReleaseDate instanceof DateTimeImmutable) {
            $this->writeOutput(sprintf("  Blocked latest version age: %s old\n", $this->formatAge($currentReleaseDate)));
        }
        if ($hiddenCount > 0) {
            $this->writeOutput(sprintf("  Showing %d of %d allowed versions\n", count($displaySuggestions), count($suggestions)));
        }

        foreach ($displaySuggestions as $index => $suggestion) {
            $this->writeOutput(sprintf("  %d) %s (%s old)\n", $index + 1, $suggestion['version'], $this->formatAge($suggestion['releaseDate'])));
        }

        while (true) {
            $selection = strtolower(trim($this->prompt(sprintf('Select a version [1-%d or s to abort]: ', count($displaySuggestions)))));

            if ($selection === 's' || $selection === '') {
                $this->abort();
            }

            if (!ctype_digit($selection)) {
                $this->writeError("Enter a number or s.\n");
                continue;
            }

            $selectedIndex = (int) $selection - 1;
            if (!isset($displaySuggestions[$selectedIndex])) {
                $this->writeError("Invalid selection.\n");
                continue;
            }

            return $displaySuggestions[$selectedIndex]['version'];
        }
    }

    /**
     * @param list<string> $arguments
     * @return int
     */
    private function executeInstall(string $workingDirectory, string $command, array $arguments, array $packages): int
    {
        $exactSpecs = [];
        foreach ($packages as $packageName => $version) {
            $exactSpecs[] = $packageName . ':' . $version;
        }

        if ($command === 'require') {
            $requireCommand = array_merge(
                ['composer', 'require', '--no-update', '--no-ansi', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--working-dir=' . $workingDirectory],
                $exactSpecs,
                $this->extractOptions($arguments)
            );

            $requireResult = $this->runProcess($requireCommand);
            if ($requireResult['exitCode'] !== 0) {
                $this->writeOutput($requireResult['output']);
                return $requireResult['exitCode'];
            }
        }

        $updateCommand = array_merge(
            ['composer', 'update', '--no-ansi', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--working-dir=' . $workingDirectory],
            $exactSpecs,
            $this->extractOptions($arguments)
        );

        $updateResult = $this->runProcess($updateCommand);
        $this->writeOutput($updateResult['output']);

        return $updateResult['exitCode'];
    }

    /**
     * @param list<string> $arguments
     */
    private function runComposerDirectly(string $command, array $arguments): int
    {
        $process = array_merge(['composer', $command], $arguments);
        $result = $this->runProcess($process);
        $this->writeOutput($result['output']);

        return $result['exitCode'];
    }

    /**
     * @return array{exitCode:int, output:string}
     */
    private function runProcess(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $processCommand = $command;

        if (PHP_OS_FAMILY === 'Windows') {
            $processCommand[0] = $this->resolveComposerLauncher();
        }

        $process = proc_open($processCommand, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Composer process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'output' => (string) $stdout . (string) $stderr,
        ];
    }

    /**
     * @param list<string> $command
     */
    private function buildCommandLine(array $command): string
    {
        return implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
    }

    private function resolveComposerLauncher(): string
    {
        if ($this->composerLauncher !== null) {
            return $this->composerLauncher;
        }

        $launcher = $this->findComposerLauncher();
        $this->composerLauncher = $launcher;

        return $launcher;
    }

    private function findComposerLauncher(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'composer';
        }

        $output = [];
        $exitCode = 1;
        @exec('where composer', $output, $exitCode);

        if ($exitCode === 0) {
            foreach ($output as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                if (str_ends_with(strtolower($line), '.bat')) {
                    return $line;
                }
            }

            foreach ($output as $line) {
                $line = trim($line);
                if ($line !== '') {
                    return $line;
                }
            }
        }

        return 'composer';
    }

    private function createTempWorkspace(string $workingDirectory, string $command, array $arguments): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'composer-quarantine-plan-' . uniqid('', true);
        mkdir($tempDir, 0777, true);

        foreach (['composer.json', 'composer.lock'] as $file) {
            $source = $workingDirectory . DIRECTORY_SEPARATOR . $file;
            if (is_file($source)) {
                copy($source, $tempDir . DIRECTORY_SEPARATOR . $file);
            }
        }

        if ($command === 'require') {
            $requireCommand = array_merge(
                ['composer', 'require', '--no-update', '--no-ansi', '--no-interaction', '--no-progress', '--no-plugins', '--no-scripts', '--working-dir=' . $tempDir],
                $this->extractPackageArguments($arguments)
            );

            foreach ($this->extractOptions($arguments) as $option) {
                $requireCommand[] = $option;
            }

            $result = $this->runProcess($requireCommand);
            if ($result['exitCode'] !== 0) {
                throw new RuntimeException($result['output']);
            }
        }

        return $tempDir;
    }

    /**
     * @return list<string>
     */
    private function extractPackageArguments(array $arguments): array
    {
        return array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '' && $argument[0] !== '-'));
    }

    /**
     * @return list<string>
     */
    private function extractPackageNames(array $arguments): array
    {
        return array_values(array_filter(array_map(static function (string $argument): string {
            $argument = trim($argument);
            if ($argument === '' || $argument[0] === '-') {
                return '';
            }

            $packageName = preg_split('/[:@]/', $argument, 2)[0] ?? $argument;
            return trim($packageName);
        }, $arguments), static fn (string $argument): bool => $argument !== ''));
    }

    /**
     * @return list<string>
     */
    private function extractOptions(array $arguments): array
    {
        return array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '' && $argument[0] === '-'));
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function extractInitOptions(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if ($argument === '--fail-open') {
                $options['fail-open'] = true;
                continue;
            }

            if ($argument === '--no-fail-open') {
                $options['fail-open'] = false;
                continue;
            }

            if (str_starts_with($argument, '--minimum-age-hours=')) {
                $options['minimum-age-hours'] = (int) substr($argument, strlen('--minimum-age-hours='));
                continue;
            }

            if (str_starts_with($argument, '--packagist-request-interval-ms=')) {
                $options['packagist-request-interval-ms'] = (int) substr($argument, strlen('--packagist-request-interval-ms='));
                continue;
            }

            if (str_starts_with($argument, '--max-suggested-versions-to-show=')) {
                $options['max-suggested-versions-to-show'] = (int) substr($argument, strlen('--max-suggested-versions-to-show='));
                continue;
            }

            if ($argument === '--check-dependencies') {
                $options['check-dependencies'] = true;
                continue;
            }

            if ($argument === '--no-check-dependencies') {
                $options['check-dependencies'] = false;
                continue;
            }

            if ($argument === '--allow-dev') {
                $options['allow-dev'] = true;
                continue;
            }

            if ($argument === '--no-allow-dev') {
                $options['allow-dev'] = false;
                continue;
            }

            if ($argument === '--allow-prerelease') {
                $options['allow-prerelease'] = true;
                continue;
            }

            if ($argument === '--no-allow-prerelease') {
                $options['allow-prerelease'] = false;
                continue;
            }

            if ($argument === '--verbose') {
                $options['verbose'] = true;
                continue;
            }

            if ($argument === '--no-verbose') {
                $options['verbose'] = false;
                continue;
            }
        }

        return $options;
    }

    /**
     * @param list<string> $arguments
     */
    private function hasIgnoreQuarantineFlag(array $arguments): bool
    {
        return in_array('--ignore-quarantine', $arguments, true);
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function stripIgnoreQuarantineFlag(array $arguments): array
    {
        return array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--ignore-quarantine'));
    }

    private function resolveReleaseDateForVersion(PackagistMetadataFetcher $fetcher, string $packageName, string $version, bool $includeDev): ?DateTimeImmutable
    {
        $releaseDates = $fetcher->getReleaseDates($packageName, $includeDev);
        $candidates = [
            $version,
            ltrim($version, 'v'),
            str_starts_with($version, 'v') ? substr($version, 1) : 'v' . $version,
        ];

        foreach ($candidates as $candidate) {
            if (isset($releaseDates[$candidate])) {
                return $releaseDates[$candidate];
            }
        }

        return null;
    }

    private function formatAge(DateTimeImmutable $releaseDate): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $interval = $releaseDate->setTimezone(new DateTimeZone('UTC'))->diff($now);

        $days = $interval->days;
        if ($days === false) {
            $days = 0;
        }

        $hours = ($days * 24) + $interval->h;
        $minutes = $interval->i;

        if ($hours >= 24) {
            return sprintf('%dd %dh', intdiv($hours, 24), $hours % 24);
        }

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    private function isPlatformPackage(string $packageName): bool
    {
        $packageName = strtolower($packageName);

        return $packageName === 'php'
            || $packageName === 'composer'
            || $packageName === 'composer-plugin-api'
            || $packageName === 'composer-runtime-api'
            || str_starts_with($packageName, 'ext-')
            || str_starts_with($packageName, 'lib-');
    }

    private function isInteractive(): bool
    {
        return function_exists('stream_isatty') ? stream_isatty(STDIN) : true;
    }

    private function prompt(string $question): string
    {
        $this->writeError($question);
        $input = fgets(STDIN);

        if ($this->interrupted || $input === false) {
            $this->abort();
        }

        return (string) $input;
    }

    private function writeOutput(string $message): void
    {
        $this->consoleOutput->write($message);
    }

    private function writeError(string $message): void
    {
        $this->consoleOutput->getErrorOutput()->write($message);
    }

    private function createFetcher(QuarantineConfig $config): PackagistMetadataFetcher
    {
        return new PackagistMetadataFetcher(
            new HttpDownloader(new NullIO(), new Config(false, ''), []),
            new CacheStore(),
            new RateLimiter(max(0, $config->packagistRequestIntervalMilliseconds), null, null, new NullIO(), false),
            new NullIO(),
            false
        );
    }

    private function registerInterruptHandler(): void
    {
        if (!function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        sapi_windows_set_ctrl_handler(function (): bool {
            $this->interrupted = true;

            return true;
        }, true);
    }

    private function abort(): never
    {
        throw new UserAbortedException('Aborted by user.');
    }
}
