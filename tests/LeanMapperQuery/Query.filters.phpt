<?php

/**
 * Test: LeanMapperQuery\Query implicit filters.
 * @author Michal Bohuslávek
 */

use LeanMapper\Caller;
use LeanMapper\Entity;
use LeanMapper\Fluent;
use LeanMapper\ImplicitFilters;
use LeanMapper\Repository;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

const FILTER = '1 = 1';

class FilterMapper extends TestMapper
{
	public function getImplicitFilters($entityClass, Caller $caller = NULL)
	{
		return new ImplicitFilters(function (Fluent $statement) use ($entityClass) {
			$entityClass === 'Book' && $statement->join('test')->on('[book].[id] = [test].[book_id]');
			$statement->where(FILTER);
		});
	}
}

/**
 * @property int         $id
 * @property Author      $author      m:hasOne
 * @property Author|NULL $reviewer    m:hasOne(reviewer_id)
 * @property string      $pubdate
 * @property string      $name
 * @property string|NULL $description
 * @property string|NULL $website
 * @property bool        $available
 */
class Book extends Entity
{
}

/**
 * @property int         $id
 * @property string      $name
 * @property Book[]      $books         m:belongsToMany
 * @property Book[]      $reviewedBooks m:belongsToMany(reviewer_id)
 * @property string|NULL $web
 */
class Author extends Entity
{
}

class BookRepository extends Repository
{
	public function createFluent()
	{
		return parent::createFluent();
	}
}

////////////////

$mapper = new FilterMapper;
$bookRepository = new BookRepository($connection, $mapper, $entityFactory);

$fluent = $bookRepository->createFluent();
getQuery()
	->where('@author', 2)
	->applyQuery($fluent, $mapper);

$expected = new Fluent($connection);
$expected->select('*')->from(
		getFluent('book')
			->join('test')->on('[book].[id] = [test].[book_id]')
			->where(FILTER)
		, 'book')
	->leftJoin(
		getFluent('author')
			->where(FILTER)
		, '[author]')
	->on('[book].[author_id] = [author].[id]')
	->where('([author].[id] = 2)');
Assert::same((string) $expected, (string) $fluent);
