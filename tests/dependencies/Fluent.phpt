<?php

/**
 * Test: required dependencies.
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use LeanMapper\Filtering;
use LeanMapper\Fluent;
use LeanMapper\Repository;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class TestRepository extends Repository
{
	public function createFluent()
	{
		return parent::createFluent();
	}
}

// Test default generated fluent by repository
$testRepository = new TestRepository($connection, $mapper, $entityFactory);
$fluent = $testRepository->createFluent();
$expected = array(
	'FROM',
	'%n',
	'test'
);

Assert::equal($fluent->_export('FROM'), $expected);


// Test generated fluent by entity traversing

/**
 * @property int $id
 */
class Book extends Entity
{
	public function test()
	{
		$this->row->referencing('book_tag', 'book_id', new Filtering(function (Fluent $fluent) {
			$expected = array(
				'FROM',
				'%n',
				'book_tag'
			);
			Assert::equal($fluent->_export('FROM'), $expected);
		}));
	}
}

$book = new Book;
$book->makeAlive($entityFactory, $connection, $mapper);
$book->attach(1);
$book->test();
