<?php

use LeanMapper\Entity;
use LeanMapper\Fluent;
use LeanMapperQuery\Query;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int $id
 * @property string $name
 */
class Tag extends Entity
{
}

/**
 * @property int $id
 * @property Author $author m:hasOne
 * @property Author|null $reviewer m:hasOne(reviewer_id)
 * @property Tag[] $tags m:hasMany
 * @property string $pubdate
 * @property string $name
 * @property string|null $description
 * @property string|null $website
 * @property bool $available
 */
class Book extends Entity
{
}

/**
 * @property int $id
 * @property string $name
 * @property Book[] $books m:belongsToMany
 * @property Book[] $reviewedBooks m:belongsToMany(reviewer_id)
 * @property string|null $web
 */
class Author extends Entity
{
}

function getFluent($table)
{
	global $connection;
	$fluent = new Fluent($connection);
	return $fluent->select('%n.*', $table)->from($table);
}


// HasOne relationship
$fluent = getFluent('book');
$query = new Query;
$query->where('@author.name', 'Karel');
$query->applyQuery($fluent, $mapper);

$expected = "SELECT [book].* FROM [book] LEFT JOIN [author] ON [book].[author_id] = [author].[id] WHERE ([author].[name] = 'Karel')";
Assert::equal($expected, (string) $fluent);

// Multiple join of the same table
$fluent = getFluent('book');
$query->where('@reviewer.web', 'http://leanmapper.com');
$query->applyQuery($fluent, $mapper);

$expected = "SELECT [book].* FROM [book] LEFT JOIN [author] ON [book].[author_id] = [author].[id] LEFT JOIN [author] [author_reviewer_id] ON [book].[reviewer_id] = [author_reviewer_id].[id] WHERE ([author].[name] = 'Karel') AND ([author_reviewer_id].[web] = 'http://leanmapper.com')";
Assert::equal($expected, (string) $fluent);

// BelongsTo relationship
$fluent = getFluent('author');
$query = new Query;
$query->where('@books.available', TRUE);
$query->applyQuery($fluent, $mapper);

$expected = "SELECT [author].* FROM [author] LEFT JOIN [book] ON [author].[id] = [book].[author_id] WHERE ([book].[available] = 1)";
Assert::equal($expected, (string) $fluent);

// HasMany relationship
$fluent = getFluent('book');
$query = new Query;
$query->where('@tags.name <>', 'php');
$query->applyQuery($fluent, $mapper);

$expected = "SELECT [book].* FROM [book] LEFT JOIN [book_tag] ON [book].[id] = [book_tag].[book_id] LEFT JOIN [tag] ON [book_tag].[tag_id] = [tag].[id] WHERE ([tag].[name] <> 'php')";
Assert::equal($expected, (string) $fluent);
