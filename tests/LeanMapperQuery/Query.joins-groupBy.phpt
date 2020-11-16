<?php

/**
 * Test: LeanMapperQuery\Query automatic joins.
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends Entity
{
}

/**
 * @property int         $id
 * @property Author      $author     m:hasOne
 * @property Author|null $reviewer   m:hasOne(reviewer_id)
 * @property Borrowing[] $borrowings m:belongsToMany
 * @property string      $pubdate
 * @property string      $name
 * @property string|null $description
 * @property string|null $website
 * @property bool        $available
 */
class Book extends Entity
{
}

/**
 * @property int    $id
 * @property Book   $book m:hasOne
 * @property string $date
 */
class Borrowing extends Entity
{
}

/**
 * @property int         $id
 * @property string      $name
 * @property Book[]      $books         m:belongsToMany
 * @property Book[]      $reviewedBooks m:belongsToMany(reviewer_id)
 * @property string|null $web
 */
class Author extends Entity
{
}

//////// Basic test ////////

$fluent = getFluent('author');
$query = getQuery();
$query->orderBy('@books.borrowings.date DESC')
	->applyQuery($fluent, $mapper);

Assert::same(5, $fluent->count());

$fluent->removeClause('GROUP BY');
Assert::same(8, $fluent->count());
