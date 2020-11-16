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
 * @property      int    $id
 * @property      Tag[]  $tagsIn    m:hasMany
 * @property-read Tag[]  $tagsUnion m:hasMany(#union)
 * @property      string $name
 */
class Book extends BaseEntity
{
}

////////////////

$sqls = [];
$connection->onEvent[] = function ($event) use (&$sqls) {
	$sqls[] = $event->sql;
};
$bookRepository = new BookRepository($connection, $mapper, $entityFactory);

function extractTags(BookRepository $bookRepository, $tagProperty, Query $query) {
	$result = [];

	foreach ($bookRepository->findAll() as $book) {
		$tags = $book->find($tagProperty, $query);

		if (count($tags) <= 1) {
			$tag = reset($tags);
			$result[$book->id] = $tag ? $tag->name : null;

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
	->limit(1)
	->offset(0); // workaround for Dibi 3.x

$expected = [
	1 => 'popular',
	2 => null,
	3 => 'ebook',
	4 => 'popular',
	5 => null,
];

$sqls = [];
Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));
Assert::same($sqls, [
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (1, 2)',
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (1, 2)',
]);

////////////////

$query = getQuery()
	->limit(99) // workaround for Dibi 3.x
	->offset(1);

$expected = [
	1 => 'ebook',
	2 => null,
	3 => null,
	4 => null,
	5 => null,
];

$sqls = [];
Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));
Assert::same($sqls, [
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 LIMIT 99 OFFSET 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2)',
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 LIMIT 99 OFFSET 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 LIMIT 99 OFFSET 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2)',
]);

////////////////

$query = getQuery()
	->orderBy('@name')
	->limit(1)
	->offset(0); // workaround for Dibi 3.x

$expected = [
	1 => 'ebook',
	2 => null,
	3 => 'ebook',
	4 => 'popular',
	5 => null,
];

$sqls = [];
Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));
Assert::same($sqls, [
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 ORDER BY [tag].[name] LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2, 1) ORDER BY [tag].[name]',
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 ORDER BY [tag].[name] LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 ORDER BY [tag].[name] LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2, 1) ORDER BY [tag].[name]',
]);

////////////////

$query = getQuery()
	->where('@name', 'ebook')
	->limit(1)
	->offset(0); // workaround for Dibi 3.x

$expected = [
	1 => 'ebook',
	2 => null,
	3 => 'ebook',
	4 => null,
	5 => null,
];

$sqls = [];
Assert::same($expected, extractTags($bookRepository, 'tagsIn', $query));
Assert::same($expected, extractTags($bookRepository, 'tagsUnion', $query));
Assert::same($sqls, [
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 AND ([tag].[name] = \'ebook\') LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2) AND ([tag].[name] = \'ebook\')',
	'SELECT [book].* FROM [book]',
	'SELECT * FROM (' . implode(') UNION SELECT * FROM (', [
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 1 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 2 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 3 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 4 AND ([tag].[name] = \'ebook\') LIMIT 1',
		'SELECT [book_tag].* FROM [book_tag] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE [book_tag].[book_id] = 5 AND ([tag].[name] = \'ebook\') LIMIT 1',
	]) . ')',
	'SELECT [tag].* FROM [tag] WHERE [tag].[id] IN (2) AND ([tag].[name] = \'ebook\')',
]);
