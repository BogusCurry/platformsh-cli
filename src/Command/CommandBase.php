<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Api;
use Platformsh\Cli\CliConfig;
use Platformsh\Cli\Console\OutputAwareInterface;
use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputAwareInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

abstract class CommandBase extends Command implements CanHideInListInterface, MultiAwareInterface
{
    use HasExamplesTrait;

    /** @var string|null */
    private static $homeDir;

    /** @var bool */
    private static $checkedUpdates;

    /** @var bool */
    protected static $interactive = false;

    /**
     * @see self::getProjectRoot()
     * @see self::setProjectRoot()
     *
     * @var string|false|null
     */
    private static $projectRoot = null;

    /** @var OutputInterface|null */
    protected $output;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $envArgName = 'environment';
    protected $hiddenInList = false;
    protected $local = false;
    protected $canBeRunMultipleTimes = true;
    protected $runningViaMulti = false;

    /** @var CliConfig */
    protected static $config;

    /** @var LocalProject|null */
    protected $localProject;

    /** @var Api|null */
    private $api;

    /** @var InputInterface|null */
    private $input;

    /**
     * @see self::setHiddenAliases()
     *
     * @var array
     */
    private $hiddenAliases = [];

    /**
     * The project, selected either by an option or the CWD.
     *
     * @var Project|false
     */
    private $project;

    /**
     * The current project, based on the CWD.
     *
     * @var Project|false|null
     */
    private $currentProject;

    /**
     * The environment, selected by an option, an argument, or the CWD.
     *
     * @var Environment|false
     */
    private $environment;

    public function __construct($name = null)
    {
        // The config dependency is static for performance reasons: there are
        // always many CommandBase objects instantiated, and the config does not
        // need to change.
        if (!isset(self::$config)) {
            self::$config = new CliConfig();
        }

        $this->localProject = new LocalProject(self::$config);

        parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    public function isHiddenInList() {
        return $this->hiddenInList;
    }

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        // Set up dependencies that are only needed once per command run.
        $this->output = $output;
        $this->input = $input;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        self::$interactive = $input->isInteractive();

        if (self::$config->get('api.debug')) {
            $this->stdErr->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        // Tune error reporting based on the output verbosity.
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            error_reporting(E_ALL);
        }
        elseif ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            error_reporting(false);
        }
        else {
            error_reporting(E_PARSE | E_ERROR);
        }

        $this->promptLegacyMigrate();
    }

    /**
     * Set up the API object.
     *
     * @return Api
     */
    protected function api()
    {
        if (!isset($this->api)) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener('login_required', [$this, 'login']);
            $dispatcher->addListener('environments_changed', [$this, 'updateDrushAliases']);
            $this->api = new Api(self::$config, $dispatcher);
        }

        return $this->api;
    }

    /**
     * Prompt the user to migrate from the legacy project file structure.
     *
     * If the input is interactive, the user will be asked to migrate up to once
     * per hour. The time they were last asked will be stored in the project
     * configuration. If the input is not interactive, the user will be warned
     * (on every command run) that they should run the 'legacy-migrate' command.
     */
    protected function promptLegacyMigrate()
    {
        static $asked = false;
        if ($this->localProject->getLegacyProjectRoot() && $this->getName() !== 'legacy-migrate' && !$asked) {
            $asked = true;

            $projectRoot = $this->getProjectRoot();
            $timestamp = time();
            $promptMigrate = true;
            if ($projectRoot) {
                $projectConfig = $this->getProjectConfig($projectRoot);
                if (isset($projectConfig['migrate']['3.x']['last_asked']) && $projectConfig['migrate']['3.x']['last_asked'] > $timestamp - 3600) {
                    $promptMigrate = false;
                }
            }

            $this->stdErr->writeln('You are in a project using an old file structure, from previous versions of the ' . self::$config->get('application.name') .'.');
            if ($this->input->isInteractive() && $promptMigrate) {
                if ($projectRoot && isset($projectConfig)) {
                    $projectConfig['migrate']['3.x']['last_asked'] = $timestamp;
                    $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
                }
                /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
                $questionHelper = $this->getHelper('question');
                if ($questionHelper->confirm('Migrate to the new structure?')) {
                    $code = $this->runOtherCommand('legacy-migrate');
                    exit($code);
                }
            }
            else {
                $this->stdErr->writeln('Fix this with: <comment>' . self::$config->get('application.executable') . ' legacy-migrate</comment>');
            }
            $this->stdErr->writeln('');
        }
    }

    /**
     * @return string
     */
    protected function getHomeDir()
    {
        if (!isset(self::$homeDir)) {
            self::$homeDir = FilesystemHelper::getHomeDirectory();
        }

        return self::$homeDir;
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        if ($this->getName() === 'welcome' && isset($GLOBALS['argv']) && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
            self::$interactive = false;
            return;
        }

        $this->checkUpdates();
    }

    /**
     * Check for updates.
     *
     * @param bool $reset
     */
    protected function checkUpdates($reset = false)
    {
        if (!$reset && self::$checkedUpdates) {
            return;
        }
        self::$checkedUpdates = true;

        // Check that this instance of the CLI was installed as a Phar.
        if (!extension_loaded('Phar') || !\Phar::running(false)) {
            return;
        }

        $timestamp = time();

        if (!self::$config->get('updates.check')) {
            return;
        }
        elseif (!$reset && self::$config->get('updates.last_checked') > $timestamp - self::$config->get('updates.check_interval')) {
            return;
        }

        self::$config->writeUserConfig([
            'updates' => [
                'check' => true,
                'last_checked' => $timestamp,
            ],
        ]);

        $this->runOtherCommand('self-update', ['--timeout' => 10]);
        $this->stdErr->writeln('');
    }

    /**
     * @return string
     */
    protected function getSessionsDir()
    {
        return self::$config->getUserConfigDir() . '/.session';
    }

    /**
     * Log in the user.
     *
     * This is called via the 'login_required' event.
     *
     * @see Api::getClient()
     */
    public function login()
    {
        if (!$this->output || !self::$interactive) {
            throw new LoginRequiredException();
        }
        $exitCode = $this->runOtherCommand('login');
        $this->stdErr->writeln('');
        if ($exitCode) {
            throw new \Exception('Login failed');
        }
    }

    /**
     * Is this a local command? (if it does not make API requests)
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->local;
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject()
    {
        if (isset($this->currentProject)) {
            return $this->currentProject;
        }
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        $config = $this->getProjectConfig($projectRoot);
        if ($config) {
            $project = $this->api()->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            // There is a chance that the project isn't available.
            if (!$project) {
                if (isset($config['host'])) {
                    $projectUrl = sprintf('https://%s/projects/%s', $config['host'], $config['id']);
                    $message = "Project not found: " . $projectUrl
                        . "\nThe project probably no longer exists.";
                }
                else {
                    $message = "Project not found: " . $config['id']
                        . "\nEither you do not have access to the project or it no longer exists.";
                }
                throw new ProjectNotFoundException($message);
            }
            $this->debug('Selecting project ' . $config['id'] . ' based on project root');
        }
        $this->currentProject = $project;

        return $project;
    }

    /**
     * Get the project configuration.
     *
     * @param string $projectRoot
     *
     * @return array
     */
    protected function getProjectConfig($projectRoot)
    {
        $this->debug('Loading project config');

        return $this->localProject->getProjectConfig($projectRoot) ?: [];
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project $expectedProject The expected project.
     * @param bool|null $refresh Whether to refresh the environments or projects
     *                           cache.
     *
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(Project $expectedProject = null, $refresh = null)
    {
        if (!($projectRoot = $this->getProjectRoot())
            || !($project = $this->getCurrentProject())
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($this->getProjectRoot());
        $currentBranch = $gitHelper->getCurrentBranch();

        // Check if there is a manual mapping set for the current branch.
        if ($currentBranch) {
            $config = $this->getProjectConfig($projectRoot);
            if (!empty($config['mapping'][$currentBranch])) {
                $environment = $this->api()->getEnvironment($config['mapping'][$currentBranch], $project, $refresh);
                if ($environment) {
                    $this->debug('Found mapped environment for branch ' . $currentBranch . ': ' . $environment->id);
                    return $environment;
                }
                else {
                    unset($config['mapping'][$currentBranch]);
                    $this->localProject->writeCurrentProjectConfig($config, $projectRoot);
                }
            }
        }

        // Check whether the user has a Git upstream set to a remote environment
        // ID.
        $upstream = $gitHelper->getUpstream();
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
            $environment = $this->api()->getEnvironment($potentialEnvironment, $project, $refresh);
            if ($environment) {
                $this->debug('Selected environment ' . $potentialEnvironment . ', based on Git upstream: ' . $upstream);
                return $environment;
            }
        }

        // There is no Git remote set. Fall back to trying the current branch
        // name.
        if ($currentBranch) {
            $environment = $this->api()->getEnvironment($currentBranch, $project, $refresh);
            if (!$environment) {
                // Try a sanitized version of the branch name too.
                $currentBranchSanitized = Environment::sanitizeId($currentBranch);
                $environment = $this->api()->getEnvironment($currentBranchSanitized, $project, $refresh);
            }
            if ($environment) {
                $this->debug('Selected environment ' . $environment->id . ' based on branch name: ' . $currentBranch);
                return $environment;
            }
        }

        return false;
    }

    /**
     * Update the user's local Drush aliases.
     *
     * This is called via the 'environments_changed' event.
     *
     * @see Api::getEnvironments()
     *
     * @param EnvironmentsChangedEvent $event
     */
    public function updateDrushAliases(EnvironmentsChangedEvent $event)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Double-check that the passed project is the current one.
        $currentProject = $this->getCurrentProject();
        if (!$currentProject || $currentProject->id != $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        $this->debug('Updating Drush aliases');
        /** @var \Platformsh\Cli\Helper\DrushHelper $drushHelper */
        $drushHelper = $this->getHelper('drush');
        $drushHelper->setHomeDir($this->getHomeDir());
        $drushHelper->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
    }

    /**
     * @param string $root
     */
    protected function setProjectRoot($root)
    {
        if (!is_dir($root)) {
            throw new \InvalidArgumentException("Invalid project root: $root");
        }
        self::$projectRoot = $root;
    }

    /**
     * @return string|false
     */
    public function getProjectRoot()
    {
        if (!isset(self::$projectRoot)) {
            $this->debug('Finding the project root based on the CWD');
            self::$projectRoot = $this->localProject->getProjectRoot();
            $this->debug(
                self::$projectRoot
                    ? 'Project root found: ' . self::$projectRoot
                    : 'Project root not found'
            );
        }

        return self::$projectRoot;
    }

    /**
     * @return bool
     */
    protected function selectedProjectIsCurrent()
    {
        $current = $this->getCurrentProject();
        if (!$current || !$this->hasSelectedProject()) {
            return false;
        }

        return $current->id === $this->getSelectedProject()->id;
    }

    /**
     * Warn the user that the remote environment needs rebuilding.
     */
    protected function rebuildWarning()
    {
        $this->stdErr->writeln('<comment>The remote environment must be rebuilt for the change to take effect.</comment>');
        $this->stdErr->writeln("Use 'git push' with new commit(s) to trigger a rebuild.");
    }

    /**
     * Detect automatically whether the output is a TTY terminal.
     *
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function isTerminal(OutputInterface $output)
    {
        if (!$output instanceof StreamOutput) {
            return false;
        }
        // If the POSIX extension doesn't exist, default to true. It's better
        // for Windows users if we assume the output is a terminal.
        if (!function_exists('posix_isatty')) {
            return true;
        }
        // This uses the same test as StreamOutput::hasColorSupport().
        $stream = $output->getStream();

        /** @noinspection PhpParamsInspection */

        return @posix_isatty($stream);
    }

    /**
     * Add the --project and --host options.
     *
     * @return CommandBase
     */
    protected function addProjectOption()
    {
        $this->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname");

        return $this;
    }

    /**
     * Add the --environment option.
     *
     * @return CommandBase
     */
    protected function addEnvironmentOption()
    {
        return $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID');
    }

    /**
     * Add the --app option.
     *
     * @return CommandBase
     */
    protected function addAppOption()
    {
        return $this->addOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name');
    }

    /**
     * Add the --no-wait option.
     *
     * @param string $description
     *
     * @return CommandBase
     */
    protected function addNoWaitOption($description = 'Do not wait for the operation to complete')
    {
        return $this->addOption('no-wait', null, InputOption::VALUE_NONE, $description);
    }

    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param string $projectId
     * @param string $host
     *
     * @return Project
     */
    protected function selectProject($projectId = null, $host = null)
    {
        if (!empty($projectId)) {
            $project = $this->api()->getProject($projectId, $host);
            if (!$project) {
                throw new \RuntimeException('Specified project not found: ' . $projectId);
            }
        } else {
            $project = $this->getCurrentProject();
            if (!$project) {
                throw new RootNotFoundException(
                    "Could not determine the current project."
                    . "\nSpecify it manually using --project or go to a project directory."
                );
            }
        }

        return $project;
    }

    /**
     * Select the current environment for the user.
     *
     * @throws \RuntimeException If the current environment cannot be found.
     *
     * @param string|null $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     *
     * @return Environment
     */
    protected function selectEnvironment($environmentId = null)
    {
        if (!empty($environmentId)) {
            // Try the environment ID first.
            if ($environment = $this->api()->getEnvironment($environmentId, $this->project)) {
                $this->debug('Selecting environment ' . $environment->id . ' based on user input');
                return $environment;
            }

            // The environment ID may be the machine name.
            $environments = $this->api()->getEnvironments($this->project);
            foreach ($environments as $environment) {
                if ($environment->hasProperty('machine_name')
                    && $environment->getProperty('machine_name') === $environmentId) {
                    $this->debug('Selecting environment ' . $environment->id . ' based on the machine name ' . $environmentId);
                    return $environment;
                }
            }

            throw new \RuntimeException('Specified environment not found: ' . $environmentId);
        }

        // If no ID is specified, try to auto-detect the current environment.
        if ($environment = $this->getCurrentEnvironment($this->project)) {
            return $environment;
        }

        $message = "Could not determine the current environment.";
        if ($this->getProjectRoot()) {
            throw new \RuntimeException(
                $message . "\nSpecify it manually using --environment."
            );
        }
        else {
            throw new RootNotFoundException(
                $message . "\nSpecify it manually using --environment or go to a project directory."
            );
        }
    }

    /**
     * Find the name of the app the user wants to use for an SSH command.
     *
     * @param InputInterface $input
     *   The user input object.
     * @param callable|null $filter
     *   A filter callback that takes one argument: a LocalApplication object.
     *
     * @return string|null
     *   The application name, or null if it could not be found.
     */
    protected function selectApp(InputInterface $input, callable $filter = null)
    {
        $appName = $input->getOption('app');
        if ($appName) {
            return $appName;
        }
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot || !$this->selectedProjectIsCurrent()) {
            return null;
        }

        $this->debug('Searching for applications in local repository');
        /** @var LocalApplication[] $apps */
        $apps = LocalApplication::getApplications($projectRoot, self::$config);

        if ($filter) {
            $apps = array_filter($apps, $filter);
        }

        if (count($apps) > 1 && $input->isInteractive()) {
            /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $choices = [];
            foreach ($apps as $app) {
                $choices[$app->getName()] = $app->getName();
            }
            $appName = $questionHelper->choose($choices, 'Enter a number to choose an app:');
        }

        $input->setOption('app', $appName);

        return $appName;
    }

    /**
     * Parse the project ID and possibly other details from a provided URL.
     *
     * @param string $url
     *     A web UI, API, or public URL of the project.
     *
     * @throws \InvalidArgumentException
     *     If the project ID can't be found in the URL.
     *
     * @return array
     *     An array of containing at least a 'projectId'. Keys 'host',
     *     'environmentId', and 'appId' will be either null or strings.
     */
    protected function parseProjectId($url)
    {
        $result = [
            'projectId' => null,
            'host' => null,
            'environmentId' => null,
            'appId' => null,
        ];

        // If it's a plain alphanumeric string, then it's an ID already.
        if (!preg_match('/\W/', $url)) {
            $result['projectId'] = $url;

            return $result;
        }

        $this->debug('Parsing URL to determine project ID');

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if ((!$path || $path === '/') && preg_match('/\-\w+\.[a-z]{2}\.' . preg_quote(self::$config->get('detection.api_domain')) . '$/', $host)) {
            list($env_project_app, $result['host']) = explode('.', $host, 2);
            if (($doubleDashPos = strrpos($env_project_app, '--')) !== false) {
                $env_project = substr($env_project_app, 0, $doubleDashPos);
                $result['appId'] = substr($env_project_app, $doubleDashPos + 2);
            }
            else {
                $env_project = $env_project_app;
            }
            if (($dashPos = strrpos($env_project, '-')) !== false) {
                $result['projectId'] = substr($env_project, $dashPos + 1);
                $result['environmentId'] = substr($env_project, 0, $dashPos);
            }
        }
        else {
            $result['host'] = $host;
            $result['projectId'] = basename(preg_replace('#/projects(/\w+)/?.*$#', '$1', $url));
            if (preg_match('#/environments(/[^/]+)/?.*$#', $url, $matches)) {
                $result['environmentId'] = basename($matches[1]);
            }
        }
        if (empty($result['projectId']) || preg_match('/\W/', $result['projectId'])) {
            throw new \InvalidArgumentException(sprintf('Invalid project URL: %s', $url));
        }

        return $result;
    }

    /**
     * @param InputInterface  $input
     * @param bool $envNotRequired
     */
    protected function validateInput(InputInterface $input, $envNotRequired = false)
    {
        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Parse the project ID.
        if ($projectId !== null) {
            $result = $this->parseProjectId($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Set the --app option based on the parsed project URL, if relevant.
        if (isset($result['appId']) && $input->hasOption('app') && !$input->getOption('app')) {
            $input->setOption('app', $result['appId']);
        }

        // Select the project.
        $this->project = $this->selectProject($projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        if ($input->hasArgument($this->envArgName) && $input->getArgument($this->envArgName)) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        "You cannot use both the '%s' argument and the '--%s' option",
                        $this->envArgName,
                        $envOptionName
                    )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $this->environment = $this->selectEnvironment($argument);
            }
        } elseif ($input->hasOption($envOptionName)) {
            $environmentId = $input->getOption($envOptionName) ?: $environmentId;
            if (!$environmentId && $envNotRequired) {
                $this->environment = $this->getCurrentEnvironment($this->project);
            }
            else {
                $this->environment = $this->selectEnvironment($environmentId);
            }
        }

        $this->debug('Validated input');
    }

    /**
     * Check whether a project is selected.
     *
     * @return bool
     */
    protected function hasSelectedProject()
    {
        return !empty($this->project);
    }

    /**
     * Get the project selected by the user.
     *
     * The project is selected via validateInput(), if there is a --project
     * option in the command.
     *
     * @throws \BadMethodCallException
     *
     * @return Project
     */
    protected function getSelectedProject()
    {
        if (!$this->project) {
            throw new \BadMethodCallException('No project selected');
        }

        return $this->project;
    }

    /**
     * Check whether a single environment is selected.
     *
     * @return bool
     */
    protected function hasSelectedEnvironment()
    {
        return !empty($this->environment);
    }

    /**
     * Get the environment selected by the user.
     *
     * The project is selected via validateInput(), if there is an
     * --environment option in the command.
     *
     * @return Environment
     */
    protected function getSelectedEnvironment()
    {
        if (!$this->environment) {
            throw new \BadMethodCallException('No environment selected');
        }

        return $this->environment;
    }

    /**
     * Run another CLI command.
     *
     * @param string         $name
     *   The name of the other command.
     * @param array          $arguments
     *   Arguments for the other command.
     * @param OutputInterface $output
     *   The output for the other command. Defaults to the current output.
     *
     * @return int
     */
    protected function runOtherCommand($name, array $arguments = [], OutputInterface $output = null)
    {
        /** @var \Platformsh\Cli\Application $application */
        $application = $this->getApplication();
        $command = $application->find($name);

        // Pass on interactivity arguments to the other command.
        if (isset($this->input)) {
            $arguments += [
                '--yes' => $this->input->getOption('yes'),
                '--no' => $this->input->getOption('no'),
            ];
        }

        $cmdInput = new ArrayInput(['command' => $name] + $arguments);
        $cmdInput->setInteractive(self::$interactive);

        $this->debug('Running command: ' . $name);

        $application->setCurrentCommand($command);
        $result = $command->run($cmdInput, $output ?: $this->output);
        $application->setCurrentCommand($this);

        return $result;
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param array $hiddenAliases
     *
     * @return CommandBase
     */
    protected function setHiddenAliases(array $hiddenAliases)
    {
        $this->hiddenAliases = $hiddenAliases;
        $this->setAliases(array_merge($this->getAliases(), $hiddenAliases));

        return $this;
    }

    /**
     * Get aliases that should be visible in help.
     *
     * @return array
     */
    public function getVisibleAliases()
    {
        return array_diff($this->getAliases(), $this->hiddenAliases);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides the default method so that the description is not repeated
     * twice.
     */
    public function getProcessedHelp()
    {
        $help = $this->getHelp();
        if ($help === '') {
            return $help;
        }
        $name = $this->getName();

        $placeholders = ['%command.name%', '%command.full_name%'];
        $replacements = [$name, self::$config->get('application.executable') . ' ' . $name];

        return str_replace($placeholders, $replacements, $help);
    }

    /**
     * Print a message if debug output is enabled.
     *
     * @param string $message
     */
    protected function debug($message)
    {
        if (isset($this->stdErr) && $this->stdErr->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getHelper($name)
    {
        $helper = parent::getHelper($name);
        if ($this->input !== null && $helper instanceof InputAwareInterface) {
            $helper->setInput($this->input);
        }
        if ($this->output !== null && $helper instanceof OutputAwareInterface) {
            $helper->setOutput($this->output);
        }

        return $helper;
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes()
    {
        return $this->canBeRunMultipleTimes;
    }

    /**
     * {@inheritdoc}
     */
    public function setRunningViaMulti($runningViaMulti = true)
    {
        $this->runningViaMulti = $runningViaMulti;
    }
}
