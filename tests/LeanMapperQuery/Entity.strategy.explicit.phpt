<?php

/**
 * Test: LeanMapperQuery\Entity.
 */

use LeanMapper\Repository;
use LeanMapperQuery\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class BaseEntity extends Entity
{
	public function find($field, $query)
	{
		$entities = $this->queryProperty($field, $query);
		return $this->entityFactory->createCollection($entities);
	}
}

class BaseRepository extends Repository
{
	public function findAll()
	{
		return $this->createEntities($this->createFluent()->fetchAll());
	}
}

class BookRepository extends BaseRepository
{
}

class AuthorRepository extends BaseRepository
{
}

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends BaseEntity
{
}

/**
 * @property int    $id
 * @property Tag[]  $tags m:hasMany(#union)
 * @property string $name
 */
class Book extends BaseEntity
{
}

/**
 * @property int    $id
 * @property Book[] $books m:belongsToMany(#union)
 */
class Author extends BaseEntity
{
}

////////////////
// belongsToMany

$authorRepository = new AuthorRepository($connection, $mapper, $entityFactory);
$authors = $authorRepository->findAll();
$result = [];

foreach ($authors as $author) {
	$books = $author->find('books', getQuery()
		->limit(1)
		->offset(0) // workaround for Dibi 3.x
	);

	$book = reset($books);
	$result[$author->id] = $book ? $book->name : null;
}

Assert::same([
	1 => 'The Pragmatic Programmer',
	2 => 'The Art of Computer Programming',
	3 => 'Refactoring: Improving the Design of Existing Code',
	4 => null,
	5 => 'Introduction to Algorithms',
], $result);

////////////////
// hasMany: TODO
