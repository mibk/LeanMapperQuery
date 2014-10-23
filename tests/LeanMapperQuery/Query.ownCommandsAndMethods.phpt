<?php

/**
 * Test: LeanMapperQuery\Query own commands and methods.
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use LeanMapperQuery\Query;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class TestQuery extends Query
{
	protected function commandTest($number)
	{
		$this->getFluent()->select("$number AS [number]");
	}

	public function test2()
	{
		$this->where('@name', 'PHP');
		return $this;
	}

	public function wrong()
	{
		$this->getFluent();
		return $this;
	}
}

/**
 * @property int         $id
 * @property string      $name
 * @property string|NULL $website
 * @property bool        $available
 */
class Book extends Entity
{
}

$fluent = getFluent('book');
$query = id(new TestQuery)
	->test(5)
	->test2();
$query->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->select('5 AS [number]')
	->where('([book].[name] = %s)', 'PHP');
Assert::same($expected->_export(), $fluent->_export());

Assert::exception(function () use ($query) {
	$query->wrong();
}, 'LeanMapperQuery\\Exception\\InvalidStateException');
