<?php

declare(strict_types=1);

namespace MaxiViper117\ComposerQuarantine;

use Composer\Command\BaseCommand;
use Composer\IO\IOInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class QuarantinePromptCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('quarantine:prompt');
        $this->setDescription('Review quarantined packages from the last Composer run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $reportPath = getcwd() . DIRECTORY_SEPARATOR . '.composer-quarantine.json';

        if (!is_file($reportPath)) {
            $io->writeError('<info>[Composer Quarantine]</info> no quarantine report found.');
            return 0;
        }

        $report = json_decode((string) file_get_contents($reportPath), true);
        if (!is_array($report) || !isset($report['packages']) || !is_array($report['packages'])) {
            $io->writeError('<error>[Composer Quarantine]</error> invalid quarantine report.');
            return 1;
        }

        foreach ($report['packages'] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['suggested'])) {
                continue;
            }

            $name = (string) $package['name'];
            $suggested = array_values(array_filter((array) $package['suggested'], 'is_string'));

            if ($suggested === []) {
                $io->writeError(sprintf('<comment>[Composer Quarantine]</comment> %s has no suggested safe versions.', $name));
                continue;
            }

            $choices = ['skip' => 'Skip this package'];
            foreach ($suggested as $version) {
                $choices[$version] = $version;
            }

            $selected = $io->select(
                sprintf('Pick a safe version for %s', $name),
                $choices,
                'skip'
            );

            if ($selected === 'skip') {
                continue;
            }

            $io->writeError(sprintf(
                '<info>[Composer Quarantine]</info> rerun with: composer require %s:%s',
                $name,
                $selected
            ));
        }

        return 0;
    }
}
