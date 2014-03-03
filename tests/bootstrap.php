<?php

use LeanMapper\Connection;
use LeanMapper\DefaultEntityFactory;
use LeanMapper\DefaultMapper;

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer update --dev`';
	exit(1);
}

Tester\Environment::setup();
date_default_timezone_set('Europe/Prague');

if (extension_loaded('xdebug')) {
	xdebug_disable();
	Tester\CodeCoverage\Collector::start(__DIR__ . '/coverage.dat');
}

class TestMapper extends DefaultMapper
{
	protected $defaultEntityNamespace = NULL;
}

$connection = new Connection(array(
				'driver' => 'sqlite3',
				'database' => __DIR__ . '/db/library.sq3',
));

$mapper = new TestMapper;
$entityFactory = new DefaultEntityFactory;
