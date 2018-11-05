<?php

use LeanMapper\Repository;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class BaseEntity extends LeanMapper\Entity
{
	public function find($field, $query)
	{
		$entities = LeanMapperQuery\Entity::queryEntityProperty($this, $field, $query);
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
 * @property int         $id
 * @property Author      $author      m:hasOne
 * @property Author|NULL $reviewer    m:hasOne(reviewer_id)
 * @property Tag[]       $tags        m:hasMany
 * @property string      $pubdate
 * @property string      $name
 * @property string|NULL $description
 * @property string|NULL $website
 * @property bool        $available
 */
class Book extends BaseEntity
{
}

/**
 * @property int         $id
 * @property string      $name
 * @property Book[]      $books         m:belongsToMany
 * @property Book[]      $reviewedBooks m:belongsToMany(reviewer_id)
 * @property string|NULL $web
 */
class Author extends BaseEntity
{
}

////////////////

$bookRepository = new BookRepository($connection, $mapper, $entityFactory);
$books = $bookRepository->findAll();
$book = $books[1];

$bookTags = $book->find('tags', getQuery()
	->where('@name', 'ebook')
);

Assert::same(1, count($bookTags));

$authorRepository = new AuthorRepository($connection, $mapper, $entityFactory);
$authors = $authorRepository->findAll();
$author = $authors[1];

$authorBooks = $author->find('books', getQuery()
	->where('@available', FALSE)
);

Assert::same(0, count($authorBooks));

// exceptions
$book = $books[2];

Assert::exception(function () use ($book) {
	$book->find('author', getQuery());
}, 'LeanMapperQuery\\Exception\\InvalidRelationshipException');

Assert::exception(function () use ($book) {
	$book->find('name', getQuery());
}, 'LeanMapperQuery\\Exception\\InvalidArgumentException');

Assert::exception(function () use ($book) {
	$book->find('xyz', getQuery());
}, 'LeanMapperQuery\\Exception\\MemberAccessException');

Assert::exception(function () {
	$book = new Book;
	$book->find('xyz', getQuery());
}, 'LeanMapperQuery\\Exception\\InvalidStateException');
