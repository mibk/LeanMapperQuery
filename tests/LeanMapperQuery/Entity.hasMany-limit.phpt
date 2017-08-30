<?php

/**
 * Test: LeanMapperQuery\Entity.
 * @author Michal Bohuslávek
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

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends BaseEntity
{
}

/**
 * @property int         $id
 * @property Tag[]       $tags        m:hasMany(#union)
 * @property string      $name
 */
class Book extends BaseEntity
{
}

////////////////

$bookRepository = new BookRepository($connection, $mapper, $entityFactory);
$books = $bookRepository->findAll();
$result = array();

foreach ($books as $book) {
	$tags = $book->find('tags', getQuery()
		->limit(1)
	);

	$tag = reset($tags);
	$result[$book->id] = $tag ? $tag->name : NULL;
}

Assert::same(array(
	1 => 'popular',
	2 => NULL,
	3 => 'ebook',
	4 => 'popular',
	5 => NULL,
), $result);
