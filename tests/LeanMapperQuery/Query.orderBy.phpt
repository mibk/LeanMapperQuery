<?php

/**
 * Test: LeanMapperQuery\Query ordering methods.
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int         $id
 * @property Author      $author    m:hasOne
 * @property string      $name
 * @property string|NULL $website
 * @property bool        $available
 */
class Book extends Entity
{
}

/**
 * @property int         $id
 * @property string      $name
 * @property string|NULL $web
 */
class Author extends Entity
{
}

$fluent = getFluent('book');
getQuery()
	->orderBy('@author.name')
	->orderBy('@name')->asc()
	->orderBy('@website')->desc()
	->orderBy('@author.web DESC')
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->orderBy('[author].[name]')
	->orderBy('[book].[name]')->asc()
	->orderBy('[book].[website]')->desc()
	->orderBy('[author].[web] DESC');
Assert::same($expected->_export('ORDER BY'), $fluent->_export('ORDER BY'));
