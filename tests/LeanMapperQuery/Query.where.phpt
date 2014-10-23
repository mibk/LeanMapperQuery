<?php

/**
 * Test: LeanMapperQuery\Query::where().
 * @author Michal Bohuslávek
 */

use LeanMapper;
use LeanMapper\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

class OtherDateTime extends DateTime
{
}

/**
 * @property int           $id
 * @property Tag[]         $tags      m:hasMany
 * @property DateTime      $pubdate   m:type(date)
 * @property OtherDateTime $created
 * @property string        $name
 * @property string|NULL   $website
 * @property bool          $available
 */
class Book extends Entity
{
}

/**
 * @property int    $id
 * @property string $name
 */
class Tag extends Entity
{
}

/////////// TEST 2 ARGS WHERE ////////////

// Test replacing placeholders
$datetime = new DateTime('2000-04-04');
$fluent = getFluent('book');
getQuery()
	->where('@id', 1)
	->where('@pubdate', $datetime)
	->where('@created <', $datetime)
	->where('@available =', FALSE)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book].[id] = %i)', 1)
	->where('([book].[pubdate] = %d)', $datetime)
	->where('([book].[created] < %t)', $datetime)
	->where('([book].[available] = %b)', FALSE);

Assert::same($expected->_export(), $fluent->_export());

// Default placeholder is also replaced
$datetime = new DateTime('2000-04-04');
$fluent = getFluent('book');
getQuery()
	->where('@id = ?', 1)
	->where('@pubdate = ?', $datetime)
	->where('@created < ?', $datetime)
	->where('@available = ?', FALSE)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book].[id] = %i)', 1)
	->where('([book].[pubdate] = %d)', $datetime)
	->where('([book].[created] < %t)', $datetime)
	->where('([book].[available] = %b)', FALSE);

Assert::same($expected->_export(), $fluent->_export());

$fluent = getFluent('book');
$bookNames = array('PHP', 'Javascript');
getQuery()
	->where('@name', $bookNames)
	->where('@website', NULL)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book].[name] IN %in)', $bookNames)
	->where('([book].[website] IS NULL)');

Assert::same($expected->_export(), $fluent->_export());

$fluent = getFluent('book');
getQuery()
	->where('@name IN %in', $bookNames)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book].[name] IN %in)', $bookNames);

Assert::same($expected->_export(), $fluent->_export());

$fluent = getFluent('book');
getQuery()
	->where(array(
		'@name' => $bookNames,
		'@available' => FALSE,
	))
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book].[name] IN %in)', $bookNames)
	->where('([book].[available] = %b)', FALSE);

Assert::same($expected->_export(), $fluent->_export());

// Test replacing instances of entities
$tag = new Tag;
$tag->makeAlive($entityFactory, $connection, $mapper);
$tag->attach(2);

$fluent = getFluent('book');
getQuery()
	->where('@tags', $tag)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book_tag].[tag_id] = %i)', 2);

Assert::same($expected->_export('WHERE'), $fluent->_export('WHERE'));

////////////////

class TagRepository extends LeanMapper\Repository
{
	public function findAll()
	{
		return $this->createEntities($this->createFluent()->fetchAll());
	}
}

$tagRepository = new TagRepository($connection, $mapper, $entityFactory);
$tags = $tagRepository->findAll();

$fluent = getFluent('book');
getQuery()
	->where('@tags', $tags)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->where('([book_tag].[tag_id] IN %in)', array(1 => 1, 2));

Assert::same($expected->_export('WHERE'), $fluent->_export('WHERE'));
