<?php

use LeanMapper\Repository;
use LeanMapperQuery\Entity;
use LeanMapperQuery\Query;
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

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends BaseEntity
{
}

/**
 * @property int         $id
 * @property Tag[]       $tagsIn      m:hasMany
 * @property-read Tag[]  $tagsUnion   m:hasMany(#union)
 * @property string      $name
 */
class Book extends BaseEntity
{
}

////////////////

$bookRepository = new BookRepository($connection, $mapper, $entityFactory);

function extractTags(BookRepository $bookRepository, $tagProperty, Query $query)
{
	$result = [];

	foreach ($bookRepository->findAll() as $book) {
		$tags = $book->find($tagProperty, $query);

		if (count($tags) <= 1) {
			$tag = reset($tags);
			$result[$book->id] = $tag ? $tag->name : NULL;

		} else {
			foreach ($tags as $tag) {
				$result[$book->id][] = $tag->name;
			}
		}
	}

	return $result;
}


////////////////

$query = getQuery()
	->limit(1);

$expected = [
	1 => 'popular',
	2 => NULL,
	3 => 'ebook',
	4 => 'popular',
	5 => NULL,
];

Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));


////////////////

$query = getQuery()
	->offset(1);

$expected = [
	1 => 'ebook',
	2 => NULL,
	3 => NULL,
	4 => NULL,
	5 => NULL,
];

Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));


////////////////

$query = getQuery()
	->orderBy('@name')
	->limit(1);

$expected = [
	1 => 'ebook',
	2 => NULL,
	3 => 'ebook',
	4 => 'popular',
	5 => NULL,
];

Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));
