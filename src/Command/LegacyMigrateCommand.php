<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LegacyMigrateCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('legacy-migrate')
            ->setDescription('Migrate from the legacy file structure');
    }

    public function hideInList()
    {
        return $this->localProject->getLegacyProjectRoot() ? false : true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $legacyRoot = $this->localProject->getLegacyProjectRoot();
        if (!$legacyRoot) {
            $this->stdErr->writeln('Legacy project root not found.');
            return 1;
        }

        $cwd = getcwd();

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');
        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $repositoryDir = $legacyRoot . '/repository';
        if (!is_dir($repositoryDir)) {
            $this->stdErr->writeln('Directory not found: <error>' . $repositoryDir . '</error>');
            return 1;
        }
        elseif (!is_dir($repositoryDir . '/.git')) {
            $this->stdErr->writeln('Not a Git repository: <error>' . $repositoryDir . '</error>');
            return 1;
        }

        if (file_exists($legacyRoot . '/builds')) {
            if (glob($legacyRoot . '/builds/*') && !$questionHelper->confirm("This will remove the old 'builds' directory. Continue?", $input, $this->stdErr)) {
                return 1;
            }
            $this->stdErr->writeln('Removing old "builds" directory');
            $fsHelper->remove($legacyRoot . '/builds');
        }

        $this->localProject->ensureLocalDir($repositoryDir);

        if (file_exists($legacyRoot . '/shared')) {
            $this->stdErr->writeln('Moving "shared" directory');
            if (is_dir($repositoryDir . '/' . CLI_LOCAL_SHARED_DIR)) {
                $fsHelper->copyAll($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/shared', $repositoryDir . '/' . CLI_LOCAL_SHARED_DIR);
            }
        }

        if (file_exists($legacyRoot . '/.build-archives')) {
            $this->stdErr->writeln('Moving ".build-archives" directory');
            if (is_dir($repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR)) {
                $fsHelper->copyAll($legacyRoot . '/.build-archives', $repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR);
                $fsHelper->remove($legacyRoot . '/shared');
            }
            else {
                rename($legacyRoot . '/.build-archives', $repositoryDir . '/' . CLI_LOCAL_ARCHIVE_DIR);
            }
        }

        if (file_exists($legacyRoot . '/www')) {
            $fsHelper->remove($legacyRoot . '/www');
        }

        $this->localProject->writeGitExclude($repositoryDir);

        $this->stdErr->writeln('Moving repository to be the new project root');
        $fsHelper->copyAll($repositoryDir, $legacyRoot, []);
        $fsHelper->remove($repositoryDir);

        if (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $fsHelper->copy($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY, $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG);
            $fsHelper->remove($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY);
        }

        $success = true;

        if (file_exists($legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY)) {
            $this->stdErr->writeln('Error: file still exists: <error>' . $legacyRoot . '/' . CLI_LOCAL_PROJECT_CONFIG_LEGACY . '</error>');
            $success = false;
        }

        if (!is_dir($legacyRoot . '/.git')) {
            $this->stdErr->writeln('Error: not found: <error>' . $legacyRoot . '/.git</error>');
            $success = false;
        }

        if (strpos($cwd, $repositoryDir) === 0) {
            $this->stdErr->writeln('Type this to refresh your shell:');
            $this->stdErr->writeln('    cd ' . $legacyRoot);
        }

        return $success ? 0 : 1;
    }
}
