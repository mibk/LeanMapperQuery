<?php

/**
 * Test: LeanMapperQuery\Query ordering methods.
 * @author Michal Bohuslávek
 */

use LeanMapper\Repository;
use LeanMapperQuery\Entity;
use LeanMapperQuery\Query;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int   $id
 * @property Tag[] $tags m:hasMany
 */
class Book extends Entity
{
	public function queryTags(Query $query)
	{
		return $this->queryProperty('tags', $query);
	}
}

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends Entity
{
}

class BookRepository extends Repository
{
	public function get($id)
	{
		$row = $this->connection->select('*')
			->from($this->getTable())
			->where('%n = ?', $this->mapper->getPrimaryKey($this->getTable()), $id)
			->fetch();
		if ($row === FALSE) {
			return $row;
		}
		return $this->createEntity($row);
	}
}

$bookRepository = new BookRepository($connection, $mapper, $entityFactory);
$book = $bookRepository->get(1);

$query = getQuery()
	->orderBy('@name');

$tags = $book->queryTags($query);
$names = array();

foreach ($tags as $tag) {
	$names[] = $tag->name;
}

Assert::same(array('ebook', 'popular'), $names);
