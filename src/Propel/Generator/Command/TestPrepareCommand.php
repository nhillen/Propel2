<?php

/**
 * This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Propel\Generator\Command;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class TestPrepareCommand extends AbstractCommand
{
    /**
     * @var string
     */
    const FIXTURES_DIR      = 'tests/Fixtures';

    /**
     * @var string
     */
    const DEFAULT_VENDOR    = 'mysql';

    /**
     * @var string
     */
    const DEFAULT_DSN       = 'mysql:host=127.0.0.1;dbname=test';

    /**
     * @var string
     */
    const DEFAULT_DB_USER   = 'root';

    /**
     * @var string
     */
    const DEFAULT_DB_PASSWD = '';

    /**
     * @var array
     */
    protected $fixtures = array(
        // directory - array of connections
        'bookstore'             => array('bookstore', 'bookstore-cms', 'bookstore-behavior'),
        'bookstore-packaged'    => array('bookstore-packaged', 'bookstore-log'),
        'namespaced'            => array('bookstore_namespaced'),
        'reverse/mysql'         => array('reverse-bookstore'),
        'reverse/pgsql'         => array('reverse-bookstore'),
        'schemas'               => array('bookstore-schemas'),
    );

    /**
     * @var string
     */
    protected $root = null;

    public function __construct()
    {
        parent::__construct();

        $this->root = realpath(__DIR__.'/../../../../');
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDefinition(array(
                new InputOption('vendor',       null, InputOption::VALUE_REQUIRED, 'The database vendor', self::DEFAULT_VENDOR),
                new InputOption('dsn',          null, InputOption::VALUE_OPTIONAL, 'The data source name', self::DEFAULT_DSN),
                new InputOption('user',          'u', InputOption::VALUE_REQUIRED, 'The database user', self::DEFAULT_DB_USER),
                new InputOption('password',      'p', InputOption::VALUE_REQUIRED, 'The database password', self::DEFAULT_DB_PASSWD),
            ))
            ->setName('test:prepare')
            ->setDescription('Prepare the Propel test suite by building fixtures')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->fixtures as $fixturesDir => $connections) {
            $this->buildFixtures(sprintf('%s/%s', self::FIXTURES_DIR, $fixturesDir), $connections, $input, $output);
        }
    }

    /**
     * @param string $fixturesDir
     */
    protected function buildFixtures($fixturesDir, $connections, InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($fixturesDir)) {
            $output->writeln(sprintf('<error>Directory "%s" not found.</error>', $fixturesDir));

            return;
        }

        $output->writeln(sprintf('Building fixtures in <info>%-40s</info> ', $fixturesDir));

        chdir($fixturesDir);

        $distributionFiles = array(
            'runtime-conf.xml.dist' => 'runtime-conf.xml',
            'build.properties.dist' => 'build.properties',
        );

        foreach ($distributionFiles as $sourceFile => $targetFile) {
            if (is_file($sourceFile)) {
                $content = file_get_contents($sourceFile);

                $content = str_replace('##DATABASE_VENDOR##',   $input->getOption('vendor'), $content);
                $content = str_replace('##DATABASE_URL##',      $input->getOption('dsn'), $content);
                $content = str_replace('##DATABASE_USER##',     $input->getOption('user'), $content);
                $content = str_replace('##DATABASE_PASSWORD##', $input->getOption('password'), $content);

                file_put_contents($targetFile, $content);
            } else {
                $output->writeln(sprintf('<comment>No "%s" file found, skipped.</comment>', $sourceFile));
            }
        }

        if (0 < count((array) $this->getSchemas('.')) || false === strstr($fixturesDir, 'reverse')) {
            $in = new ArrayInput(array(
                'command'      => 'sql:build',
                '--input-dir'  => '.',
                '--output-dir' => 'build/sql/',
                '--platform'   => ucfirst($input->getOption('vendor')) . 'Platform',
                '--verbose'    => $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('sql:build');
            $command->run($in, $output);

            $conParams = array();
            foreach ($connections as $con) {
                $conParams[] = sprintf(
                    '%s=%s;user=%s;password=%s',
                    $con, $input->getOption('dsn'),
                    $input->getOption('user'), $input->getOption('password')
                );
            }

            $in = new ArrayInput(array(
                'command'      => 'sql:insert',
                '--output-dir' => 'build/sql/',
                '--connection' => $conParams,
                '--verbose'    => $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('sql:insert');
            $command->run($in, $output);
        }

        if (is_file('runtime-conf.xml')) {
            $in = new ArrayInput(array(
                'command'       => 'config:convert-xml',
                '--input-dir'   => '.',
                '--output-dir'  => './build/conf',
                '--output-file' => sprintf('%s-conf.php', $connections[0]), // the first connection is the main one
            ));

            $command = $this->getApplication()->find('config:convert-xml');
            $command->run($in, $output);
        }

        if (0 < count((array) $this->getSchemas('.'))) {
            $in = new ArrayInput(array(
                'command'      => 'model:build',
                '--input-dir'  => '.',
                '--output-dir' => 'build/classes/',
                '--platform'   => ucfirst($input->getOption('vendor')) . 'Platform',
                '--verbose'    => $input->getOption('verbose'),
            ));

            $command = $this->getApplication()->find('model:build');
            $command->run($in, $output);
        }

        chdir($this->root);
    }
}
