<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\App\Platform\Php;
use Platformsh\Cli\Command\App\Platform\PlatformInterface;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Helper\QuestionHelper;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class AppInitCommand extends CommandBase
{
    protected $local = true;

    /** @var Form */
    protected $form;

    static $platforms = [
        'php' => Php::class,
    ];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:init')
            ->setAliases(['init'])
            ->setDescription('Create an application in the local repository');
        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());
    }

    /**
     *
     *
     * @return PlatformInterface[]
     */
    protected function getPlatforms()
    {
        /** @var PlatformInterface[] $platforms */
        return array_map(function($class) { return new $class; }, static::$platforms);
    }

    /**
     * @return array
     */
    protected function getFields()
    {
        $fields['name'] = new Field('Application name', [
            'optionName' => 'name',
            'default' => 'app',
            'validator' => function ($value) {
                return preg_match('/^[a-z0-9-]+$/', $value)
                    ? true
                    : 'The application name can only consist of lower-case letters and numbers.';
            },
        ]);

        $platforms = $this->getPlatforms();

        $languages = array_map(function(PlatformInterface $platform) { return $platform->name(); }, $platforms);

        $fields['type'] = new OptionsField('Application type', [
            'optionName' => 'type',
            'options' => $languages,
            'default' => 'php',
        ]);

        $fields = array_reduce($platforms, function($fields, PlatformInterface $platform) {
            return $fields + $platform->getFields();
        }, $fields);

        /*
        $fields['type'] = new OptionsField('Application type', [
            'optionName' => 'type',
            'options' => [
                'php:5.6',
                'php:7.0',
                'hhvm:3.8',
            ],
            'default' => 'php:7.0',
        ]);
        */

        $fields['subdir'] = new BooleanField('Create the application in a subdirectory', [
            'optionName' => 'subdir',
            'default' => false,
        ]);

        $fields['directory'] = new Field('Directory name', [
            'conditions' => ['subdir' => true],
            'optionName' => 'directory-name',
            'defaultCallback' => function (array $previousValues) {
                return $previousValues['name'];
            },
            'normalizer' => function ($value) {
                return trim($value, '/');
            },
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // The project root is a Git repository, as we assume there are no
        // config files yet.
        $projectRoot = $this->findTopDirectoryContaining('.git');

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $options = $this->form->resolveOptions($input, $output, $questionHelper);

        $configFile = self::$config->get('service.app_config_file');

        $configFileAbsolute = isset($options['directory'])
            ? sprintf('%s/%s/%s', $projectRoot, $options['directory'], $configFile)
            : sprintf('%s/%s', $projectRoot, $configFile);

        if (file_exists($configFileAbsolute) && !$questionHelper->confirm('The config file already exists. Overwrite?')) {
            return 1;
        }

        $platform = $this->getPlatforms()[$options['type']];

        $this->makeAppYaml($configFileAbsolute, $platform, $options);
        $this->makeRoutesYaml($projectRoot, $options['name']);
        $this->makeServicesYaml($projectRoot);

        return 0;
    }

    /**
     * Generates the .platform.app.yaml file, based on the user-supplied information.
     *
     * @param string $configFile
     *   The absolute path to the file to create.
     * @param PlatformInterface $platform
     *   The platform type we're creating.
     * @param array $appConfig
     *   The user-supplied configuration information from which to generate a file.
     */
    protected function makeAppYaml($configFile, PlatformInterface $platform, array $appConfig)
    {
        $this->stdErr->writeln('Creating config file: ' . $configFile);

        unset($appConfig['directory'], $appConfig['subdir']);

        $template = $platform->appYamlTemplate();

        $replace = [];
        foreach ($appConfig as $key => $value) {
            $replace['{' . $key . '}'] = $value;
        }

        $file = strtr($template, $replace);

        (new Filesystem())->dumpFile($configFile, $file);
    }

    /**
     * Generates a stock routes.yaml file.
     *
     * @param string $projectRoot
     *   The absolute path to the project root.
     * @param string $applicationName
     *   The user-supplied name of the application.
     */
    protected function makeRoutesYaml($projectRoot, $applicationName)
    {
        $this->stdErr->writeln('Creating routes file.');

        mkdir($projectRoot . '/.platform');

        $routingYaml = <<<END
# The routes.yaml file describes how an incoming URL is going
# to be processed by Platform.sh.  With the defaults below, all requests to
# The the domain name configured in the UI will pass through to the application
# and all requests to the www. prefix will be redirected to the bare domain.
# To reverse that behavior, simply swap the definitions.
#
# See https://docs.platform.sh/user_guide/reference/routes-yaml.html for more information.

"http://{default}/":
  type: upstream
  upstream: "{$applicationName}:http"
"http://www.{default}/":
  type: redirect
  to: "http://{default}/"

END;

        (new Filesystem())->dumpFile($projectRoot . '/.platform/routes.yaml', $routingYaml);
    }

    /**
     * Generates a stock services.yaml file.
     *
     * @param string $projectRoot
     *   The absolute path to the project root.
     */
    protected function makeServicesYaml($projectRoot)
    {
        $this->stdErr->writeln('Creating services file.');

        mkdir($projectRoot . '/.platform');

        $routingYaml = <<<END
# The services.yaml file defines what other services will be part of your cluster,
# such as a database or caching server. The keys are the name of the service, which
# you will reference in the .platform.app.yaml relationships section. The type specifies the
# service and its version. In some cases a disk key is also available and indicates
# the size in megabytes to reserve for that service's storage. You may also have
# more than one service of a given type, as long as they have unique names.
#
# See https://docs.platform.sh/user_guide/reference/services-yaml.html for more information.

#mysqldb:
#    type: mysql:5.5
#    disk: 2048

#rediscache:
#    type: redis:3.0

#solrsearch:
#    type: solr:3.6
#    disk: 1024

END;

        (new Filesystem())->dumpFile($projectRoot . '/.platform/services.yaml', $routingYaml);
    }

    /**
     * Find the highest level directory that contains a file.
     *
     * @param string $file
     *   The filename to look for.
     * @param callable $callback
     *   A callback to validate the directory when found. Accepts one argument
     *   (the directory path). Return true to use the directory, or false to
     *   continue traversing upwards.
     *
     * @return string|false
     *   The path to the directory, or false if the file is not found.
     */
    protected static function findTopDirectoryContaining($file, callable $callback = null)
    {
        static $roots = [];
        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }
        if (isset($roots[$cwd][$file])) {
            return $roots[$cwd][$file];
        }

        $roots[$cwd][$file] = false;
        $root = &$roots[$cwd][$file];

        $currentDir = $cwd;
        while (!$root) {
            if (file_exists($currentDir . '/' . $file)) {
                if ($callback === null || $callback($currentDir)) {
                    $root = $currentDir;
                    break;
                }
            }

            // The file was not found, go one directory up.
            $levelUp = dirname($currentDir);
            if ($levelUp === $currentDir || $levelUp === '.') {
                break;
            }
            $currentDir = $levelUp;
        }

        return $root;
    }
}