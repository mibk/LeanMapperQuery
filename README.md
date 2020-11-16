Lean Mapper Query
=================

Lean Mapper Query is a concept of a *query object* for
[Lean Mapper library](https://github.com/Tharos/LeanMapper) which helps to build complex
queries using automatic joins (*idea taken from [NotORM library](http://www.notorm.com/)*).
Look at the [suggested base classes](https://gist.github.com/mibk/9410266). For Czech
documentation have a look at the [wiki](https://github.com/mibk/LeanMapperQuery/wiki).

Features
--------

- behaves as a `SQL` preprocessor, hence most SQL expressions are available
- automatic joins using the *dot notation* (`@book.tags.name`)
- ability to query repositories or entities
- support for implicit filters


Installation
------------

It can be installed via [Composer](http://getcomposer.org/download).

```
composer require mbohuslavek/leanmapper-query:@dev
```


What does it do?
----------------

Suppose we have the following repositories:

```php
class BaseRepository extends LeanMapper\Repository
{
	public function find(IQuery $query)
	{
		$this->createEntities($query
			->applyQuery($this->createFluent(), $this->mapper)
			->fetchAll()
		);
	}
}

class BookRepository extends BaseRepository
{
}
```

and the following entities:

```php
/**
 * @property int    $id
 * @property string $name
 */
class Tag extends LeanMapper\Entity
{
}

/**
 * @property int      $id
 * @property Author   $author m:hasOne
 * @property Tag[]    $tags m:hasMany
 * @property DateTime $pubdate
 * @property string   $name
 * @property bool     $available
 */
class Book extends LeanMapper\Entity
{
}

/**
 * @property int    $id
 * @property string $name
 * @property Book[] $books m:belongsToMany
 */
class Author extends LeanMapper\Entity
{
}
```

We build a *query*:

```php
$query = new LeanMapperQuery\Query;
$query->where('@author.name', 'Karel');
```

Now, if we want to get all books whose author's name is Karel, we have to do this:

```php
$bookRepository = new BookRepository(...);
$books = $bookRepository->find($query);
```

The database query will look like this:

```sql
SELECT [book].*
FROM [book]
LEFT JOIN [author] ON [book].[author_id] = [author].[id]
WHERE ([author].[name] = 'Karel')
```

You can see it performs automatic joins via the *dot notation*. It supports all relationship
types known to **Lean Mapper**.

It is very easy to use SQL functions. We can update query like this:

```php
$query->where('DATE(@pubdate) > %d', '1998-01-01');
$books = $bookRepository->find($query);
```

which changes the database query into the following:

```sql
SELECT [book].*
FROM [book]
LEFT JOIN [author] ON [book].[author_id] = [author].[id]
WHERE ([author].[name] = 'Karel') AND (DATE([book].[pubdate]) > '1998-01-01')
```

Don't repeat yourself
---------------------

You can extend the `Query` class and define your own methods.

```php
class BookQuery extends LeanMapperQuery\Query
{
	public function restrictAvailable()
	{
		$this->where('@available', true)
			->orderBy('@author.name');
		return $this;
	}
}

/////////

$query = new BookQuery;
$query->restrictAvailable();
$books = $this->bookRepository->find($query);
```

Querying entities
-----------------

It is also possible to query an entity property (*currently only those properties with
`BelongsToMany` or `HasMany` relationships*). Let's make the `BaseEntity` class:

```php
class BaseEntity extends LeanMapperQuery\Entity
{
	protected static $magicMethodsPrefixes = array('find');

	protected function find($field, IQuery $query)
	{
		$entities = $this->queryProperty($field, $query);
		return $this->entityFactory->createCollection($entities);
	}
}

/*
 * ...
 */
class Book extends BaseEntity
{
}
```

*Note that `BaseEntity` must extend `LeanMapperQuery\Entity` to make the following possible.*

We have defined the `find` method as `protected` because by specifying the method name in the
`$magicMethodsPrefixes` property, you can query entities like this:

```php
$book; // previously fetched instance of an entity from a repository
$query = new LeanMapper\Query;
$query->where('@name !=', 'ebook');
$tags = $book->findTags($query);
```

*The magic method `findTags` will eventually call your protected method `find` with 'tags' as
the 1st argument.*

The resulting database query looks like this:

```sql
SELECT [tag].*
FROM [tag]
WHERE [tag].[id] IN (1, 2) AND ([tag].[name] != 'ebook')
```

The first condition in the `where` clause, `[tag].[id] IN (1, 2)`, is taken from the entity
traversing (*tags are queried against this particular book entity's own tags*).


What else you can do?
---------------------

If we slightly modify `BaseRepository` and `BaseEntity`, we can simplify working with query objects.
*To achieve this look at the [suggested base classes](https://gist.github.com/mibk/9410266)*. It makes
the following possible.

```php
$books = $bookRepository->query()
	->where('@author.name', 'Karel')
	->where('DATE(@pubdate) > ?', '1998-01-01')
	->find();

// or...

$tags = $book->queryTags()
	->where('@name !=', 'ebook')
	->find();
```


License
-------

Copyright (c) 2013 Michal Bohuslávek

Licensed under the MIT license.
