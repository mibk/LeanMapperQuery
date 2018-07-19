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
	public function find($query)
	{
		$fluent = $this->createFluent();
		$query->applyQuery($fluent, $this->mapper);
		return $this->createEntities($fluent->fetchAll());
	}


	public function findAll()
	{
		return $this->createEntities($this->createFluent()->fetchAll());
	}
}

class ClientMapper extends TestMapper
{
	public function getEntityClass($table, LeanMapper\Row $row = NULL)
	{
		if ($table === 'client') {
			if (isset($row->type)) {
				return $row->type === Client::TYPE_INDIVIDUAL ? 'ClientIndividual' : 'ClientCompany';
			}
			return 'Client';
		}

		return parent::getEntityClass($table, $row);
	}


	public function getTable($entity)
	{
		if ($entity === 'ClientIndividual' || $entity === 'ClientCompany') {
			return 'client';
		}
		return parent::getTable($entity);
	}
}

class ClientRepository extends BaseRepository
{
}

class TagRepository extends BaseRepository
{
}

/**
 * @property int    $id
 * @property string $type m:enum(self::TYPE_*)
 * @property string $name
 */
abstract class Client extends BaseEntity
{
	const TYPE_INDIVIDUAL = 'individual';
	const TYPE_COMPANY = 'company';
}

/**
 * @property string $birthdate
 */
class ClientIndividual extends Client
{
	protected function initDefaults()
	{
		$this->type = self::TYPE_INDIVIDUAL;
	}
}

/**
 * @property string $ic
 * @property string $dic
 */
class ClientCompany extends Client
{
	protected function initDefaults()
	{
		$this->type = self::TYPE_COMPANY;
	}
}

/**
 * @property int      $id
 * @property string   $name
 * @property Client[] $clients   m:hasMany(:client_tag:)
 */
class Tag extends BaseEntity
{
}

////////////////

$connection = new LeanMapper\Connection(array(
	'driver' => 'sqlite3',
	'database' => __DIR__ . '/../db/clients.sq3',
));
$mapper = new ClientMapper;
$clientRepository = new ClientRepository($connection, $mapper, $entityFactory);
$tagRepository = new TagRepository($connection, $mapper, $entityFactory);


//////// repository query ////////
$clients = array_values($clientRepository->find(getQuery()
	->orderBy('@name')
));

Assert::same(2, count($clients));
Assert::same('John Doe', $clients[0]->name);
Assert::same('ClientIndividual', get_class($clients[0]));

Assert::same('Seznam.cz', $clients[1]->name);
Assert::same('ClientCompany', get_class($clients[1]));


//////// entity query ////////
$tags = $tagRepository->findAll();
$tag = $tags[1];

$tagClients = array_values($tag->find('clients', getQuery()
	->orderBy('@name')
));

Assert::same(2, count($tagClients));
Assert::same('John Doe', $tagClients[0]->name);
Assert::same('ClientIndividual', get_class($tagClients[0]));

Assert::same('Seznam.cz', $tagClients[1]->name);
Assert::same('ClientCompany', get_class($tagClients[1]));
