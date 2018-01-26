<?php

/**
 * Test: LeanMapperQuery\Query::groupBy().
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int           $id
 * @property Tag[]         $tags      m:hasMany
 * @property DateTime      $pubdate   m:type(date)
 * @property string        $name
 * @property string|NULL   $website
 * @property bool          $available
 */
class Book extends Entity
{
}

/**
 * @property int        $id
 * @property Book       $book m:hasOne
 * @property Borrower   $borrower m:hasOne
 * @property DateTime   $date
 */
class Borrowing extends Entity
{
}

/**
 * @property int      $id
 * @property string   $name
 */
class Borrower extends Entity
{
}


/**
 * @property int      $id
 * @property string   $name
 */
class Tag extends Entity
{
}

// Test replacing placeholders
$fluent = getFluent('borrowing');
getQuery()
	->groupBy('@book')
	->applyQuery($fluent, $mapper);

$expected = getFluent('borrowing')
	->groupBy('[borrowing].[book_id]');

Assert::same($expected->_export(), $fluent->_export());
