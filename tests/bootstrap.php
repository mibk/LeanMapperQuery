<?php

use LeanMapper\Connection;
use LeanMapper\DefaultEntityFactory;
use LeanMapper\DefaultMapper;
use LeanMapper\Fluent;
use LeanMapperQuery\Query;

if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo 'Install Nette Tester using `composer update --dev`';
	exit(1);
}

Tester\Environment::setup();
date_default_timezone_set('Europe/Prague');

class TestMapper extends DefaultMapper
{
	public function __construct()
	{
		$this->defaultEntityNamespace = null;
	}
}

$connection = new Connection([
				'driver' => 'sqlite3',
				'database' => __DIR__ . '/db/library.sq3',
]);

$mapper = new TestMapper;
$entityFactory = new DefaultEntityFactory;

function getFluent($table) {
	global $connection;
	$fluent = new Fluent($connection);
	return $fluent->select('%n.*', $table)->from($table);
}

function getQuery() {
	return new Query;
}

function id($instance) {
	return $instance;
}
