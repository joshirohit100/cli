<?php

namespace Acquia\Ads\Tests;

use Acquia\Ads\AdsApplication;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophet;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Class BltTestBase.
 *
 * Base class for all tests that are executed for BLT itself.
 */
abstract class CommandTestBase extends TestCase
{

    /**
     * The command tester.
     *
     * @var \Symfony\Component\Console\Tester\CommandTester
     */
    private $commandTester;
    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    private $consoleOutput;
    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $fs;

    /** @var \Prophecy\Prophet */
    protected $prophet;
    /**
     * @var \Symfony\Component\Console\Command\Command
     */
    protected $command;

    /**
     * Creates a command object to test.
     *
     * @return \Symfony\Component\Console\Command\Command
     *   A command object with mocked dependencies injected.
     */
    abstract protected function createCommand(): Command;

    /** @var Application */
    protected $application;

    /**
     * This method is called before each test.
     */
    protected function setUp(): void
    {
        $this->consoleOutput = new ConsoleOutput();
        $this->fs = new Filesystem();
        $this->prophet = new Prophet();
        $this->printTestName();

        parent::setUp();
    }

    protected function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    /**
     * Executes a given command with the command tester.
     *
     * @param array $args
     *   The command arguments.
     * @param string[] $inputs
     *   An array of strings representing each input passed to the command input
     *   stream.
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function executeCommand(array $args = [], array $inputs = []): void
    {
        $cwd = __DIR__ . '/../../fixtures/project';
        chdir($cwd);
        $tester = $this->getCommandTester();
        $tester->setInputs($inputs);
        $command_name = $this->command->getName();
        $args = array_merge(['command' => $command_name], $args);

        if (getenv('ADS_PRINT_COMMAND_OUTPUT')) {
            $this->consoleOutput->writeln("");
            $this->consoleOutput->writeln("Executing <comment>" . $this->command::getDefaultName() . "</comment> in " . $cwd);
            $this->consoleOutput->writeln("<comment>------Begin command output-------</comment>");
        }

        $tester->execute($args, ['verbosity' => Output::VERBOSITY_VERBOSE]);

        if (getenv('ADS_PRINT_COMMAND_OUTPUT')) {
            $this->consoleOutput->writeln($tester->getDisplay());
            $this->consoleOutput->writeln("<comment>------End command output---------</comment>");
            $this->consoleOutput->writeln("");
        }
    }

    /**
     * Gets the command tester.
     *
     * @return \Symfony\Component\Console\Tester\CommandTester
     *   A command tester.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getCommandTester(): CommandTester
    {
        if ($this->commandTester) {
            return $this->commandTester;
        }

        if (!isset($this->command)) {
            $this->command = $this->createCommand();
        }

        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $logger = new ConsoleLogger($output);
        $repo_root = null;
        $this->application = new AdsApplication('ads', 'UNKNOWN', $input, $output, $logger, $repo_root);
        $this->application->add($this->command);
        $found_command = $this->application->find($this->command->getName());
        $this->assertInstanceOf(get_class($this->command), $found_command, 'Instantiated class.');
        $this->commandTester = new CommandTester($found_command);

        return $this->commandTester;
    }

    /**
     * Gets the display returned by the last execution of the command.
     *
     * @return string
     *   The display.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getDisplay(): string
    {
        return $this->getCommandTester()->getDisplay();
    }

    /**
     * Gets the status code returned by the last execution of the command.
     *
     * @return int
     *   The status code.
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getStatusCode(): int
    {
        return $this->getCommandTester()->getStatusCode();
    }

    /**
     * Write full width line.
     *
     * @param string $message
     *   Message.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   Output.
     */
    protected function writeFullWidthLine($message, OutputInterface $output): void
    {
        $terminal_width = (new Terminal())->getWidth();
        $padding_len = ($terminal_width - strlen($message)) / 2;
        $pad = $padding_len > 0 ? str_repeat('-', $padding_len) : '';
        $output->writeln("<comment>{$pad}{$message}{$pad}</comment>");
    }

    /**
     *
     */
    protected function printTestName(): void
    {
        if (getenv('ADS_PRINT_COMMAND_OUTPUT')) {
            $this->consoleOutput->writeln("");
            $this->writeFullWidthLine(get_class($this) . "::" . $this->getName(), $this->consoleOutput);
        }
    }

    /**
     * @param $path
     * @param $method
     * @param $http_code
     *
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    protected function getResourceFromSpec($path, $method)
    {
        $acquia_cloud_spec = $this->getCloudApiSpec();
        return $acquia_cloud_spec['paths'][$path][$method];
    }

    /**
     * @param $path
     * @param $method
     * @param $http_code
     *
     * @return false|string
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getMockResponseFromSpec($path, $method, $http_code)
    {
        $endpoint = $this->getResourceFromSpec($path, $method);
        $response = $endpoint['responses'][$http_code];

        if (array_key_exists('application/json', $response['content'])) {
            $content = $response['content']['application/json'];
        } else {
            $content = $response['content']['application/x-www-form-urlencoded'];
        }

        if (array_key_exists('example', $content)) {
            $response_body = json_encode($content['example']);
        } elseif (array_key_exists('examples', $content)) {
            $response_body = json_encode($content['examples']);
        } elseif (array_key_exists('example', $response['content'])) {
            $response_body = json_encode($response['content']['example']);
        } else {
            return [];
        }

        return json_decode($response_body);
    }

    /**
     * @param $path
     *
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function getMockRequestBodyFromSpec($path)
    {
        $endpoint = $this->getResourceFromSpec($path, 'post');
        return $endpoint['requestBody']['content']['application/json']['example'];
    }

    /**
     * @return mixed
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getCloudApiSpec()
    {
        // We cache the yaml file because it's 20k+ lines and takes FOREVER
        // to parse when xDebug is enabled.
        $acquia_cloud_spec_file = __DIR__ . '/../../../assets/acquia-spec.yaml';
        $acquia_cloud_spec_file_checksum = md5_file($acquia_cloud_spec_file);

        $cache = new PhpArrayAdapter(__DIR__ . '/../../../cache/ApiSpec.cache', new FilesystemAdapter());
        $is_command_cache_valid = $this->isApiSpecCacheValid($cache, $acquia_cloud_spec_file_checksum);
        $api_spec_cache_item = $cache->getItem('api_spec.yaml');
        if ($is_command_cache_valid && $api_spec_cache_item->isHit()) {
            return $api_spec_cache_item->get();
        }

        $api_spec = Yaml::parseFile($acquia_cloud_spec_file);
        $this->saveApiSpecCacheItems($cache, $acquia_cloud_spec_file_checksum, $api_spec_cache_item, $api_spec);

        return $api_spec;
    }

    /**
     * @param \Symfony\Component\Cache\Adapter\PhpArrayAdapter $cache
     *
     * @param string $acquia_cloud_spec_file_checksum
     *
     * @return bool
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function isApiSpecCacheValid(PhpArrayAdapter $cache, $acquia_cloud_spec_file_checksum): bool
    {
        $api_spec_checksum_item = $cache->getItem('api_spec.checksum');
        // If there's an invalid entry OR there's no entry, return false.
        return !(!$api_spec_checksum_item->isHit() || ($api_spec_checksum_item->isHit()
            && $api_spec_checksum_item->get() !== $acquia_cloud_spec_file_checksum));
    }

    /**
     * @param \Symfony\Component\Cache\Adapter\PhpArrayAdapter $cache
     * @param string $acquia_cloud_spec_file_checksum
     * @param \Symfony\Component\Cache\CacheItem $api_spec_cache_item
     * @param $api_spec
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function saveApiSpecCacheItems(
        PhpArrayAdapter $cache,
        string $acquia_cloud_spec_file_checksum,
        CacheItem $api_spec_cache_item,
        $api_spec
    ): void {
        $api_spec_checksum_item = $cache->getItem('api_spec.checksum');
        $api_spec_checksum_item->set($acquia_cloud_spec_file_checksum);
        $cache->save($api_spec_checksum_item);
        $api_spec_cache_item->set($api_spec);
        $cache->save($api_spec_cache_item);
    }
}
