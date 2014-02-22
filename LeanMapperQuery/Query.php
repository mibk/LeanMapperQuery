<?php

namespace LeanMapperQuery;

use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapper\IMapper;
use LeanMapper\Fluent;
use LeanMapper\ImplicitFilters;
use LeanMapper\Entity;

use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\MemberAccessException;

class Query implements IQuery
{

	/** @var IQueryable */
	protected $sourceRepository;

	/** @var IMapper */
	protected $mapper;

	/** @var Fluent */
	protected $fluent;

	/** @var array */
	protected $appliedJoins = array();


	public function __construct(IQueryable $sourceRepository, IMapper $mapper)
	{
		$this->sourceRepository = $sourceRepository;
		$this->mapper = $mapper;
		$this->fluent = $sourceRepository->createFluent();
	}

	protected final function getPropertiesByTable($tableName)
	{
		$entityClass = $this->mapper->getEntityClass($tableName);
		$reflection = $entityClass::getReflection($this->mapper);
		$properties = array();
		foreach ($reflection->getEntityProperties() as $property) {
			$properties[$property->getName()] = $property;
		}
		return array($entityClass, $properties);
	}

	private function joinRelatedTable($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $filters = array())
	{
		// Join if not already joined.
		// TODO: Is it sufficient method?
		if (!in_array($targetTable, $this->appliedJoins)) {
			if (empty($filters)) {
				// Do simple join.
				$this->fluent->leftJoin($targetTable)->on("[$currentTable].[$referencingColumn] = [$targetTable].[$targetTablePrimaryKey]");
			} else {
				// Join sub-query due to applying implicit filters.
				$subFluent = new Fluent($this->fluent->getConnection());
				$subFluent->select('%n.*', $targetTable)->from($targetTable);

				// Apply implicit filters.
				$targetedArgs = array();
				if ($filters instanceof ImplicitFilters) {
					$targetedArgs = $filters->getTargetedArgs();
					$filters = $filters->getFilters();
				}
				foreach ($filters as $filter) {
					$args = array($filter);
					if (is_string($filter) and array_key_exists($filter, $targetedArgs)) {
						$args = array_merge($args, $targetedArgs[$filter]);
					}
					call_user_func_array(array($subFluent, 'applyFilter'), $args);
				}
				$this->fluent->leftJoin($subFluent, $targetTable)->on("[$currentTable].[$referencingColumn] = [$targetTable].[$targetTablePrimaryKey]");
			}
			$this->appliedJoins[] = $targetTable;
		}
	}

	protected final function traverseToRelatedEntity($currentTable, Property $property)
	{
		if (!$property->hasRelationship()) {
			throw new InvalidRelationshipException("Property '$propertyName' in entity '$entityClass' doesn't have any relationship.");
		}
		$implicitFilters= array();
		$propertyType = $property->getType();
		if (is_subclass_of($propertyType, 'LeanMapper\\Entity')) {
			$caller = new Caller($this, $property);
			$implicitFilters = $this->mapper->getImplicitFilters($property->getType(), $caller);
		}

		$relationship = $property->getRelationship();
		if ($relationship instanceof Relationship\HasOne) {
			$targetTable = $relationship->getTargetTable();
			$targetTablePrimaryKey = $this->mapper->getPrimaryKey($targetTable);
			$referencingColumn = $relationship->getColumnReferencingTargetTable();
			// Join table.
			$this->joinRelatedTable($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} elseif ($relationship instanceof Relationship\BelongsTo) { // BelongsToOne, BelongsToMany
			// TODO: Involve getStrategy()?
			$targetTable = $relationship->getTargetTable();
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			// Join table.
			$this->joinRelatedTable($currentTable, $sourceTablePrimaryKey, $targetTable, $referencingColumn, $implicitFilters);

		} elseif ($relationship instanceof Relationship\HasMany) {
			// TODO: Involve getStrategy()?
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();

			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetTablePrimaryKey = $this->mapper->getPrimaryKey($targetTable);
			// Join tables.
			// Don't apply filters on relationship table.
			$this->joinRelatedTable($currentTable, $sourceTablePrimaryKey, $relationshipTable, $sourceReferencingColumn);
			$this->joinRelatedTable($relationshipTable, $targetReferencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} else {
			throw new InvalidRelationshipException('Unknown relationship type. ' . get_class($relationship) . ' given.');
		}

		return array_merge(array($targetTable), $this->getPropertiesByTable($targetTable));
	}

	protected final function parseStatement($statement)
	{
		if (!is_string($statement)) {
			throw new InvalidArgumentException('Type of argument $statement is expected to be string. ' . gettype($statement) . ' given.');
		}
		$rootTableName = $this->sourceRepository->getTable();
		list($rootEntityClass, $rootProperties) = $this->getPropertiesByTable($rootTableName);

		$switches = array(
			'@' => FALSE,
			'"' => FALSE,
			"'" => FALSE,
		);
		$output = '';
		for ($i = 0; $i < strlen($statement) + 1; $i++) {
			// Do one more loop due to succesfuly translating
			// properties attached to the end of the statement.
			$ch = @$statement{$i} ?: '';
			if ($switches['@'] === TRUE) {
				if (preg_match('#^[a-zA-Z_]$#', $ch)) {
					$propertyName .= $ch;
				} else {
					if (!array_key_exists($propertyName, $properties)) {
						throw new MemberAccessException("Entity '$entityClass' doesn't have property '$propertyName'.");
					}
					$property = $properties[$propertyName];

					if ($ch === '.') {
						list($tableName, $entityClass, $properties) = $this->traverseToRelatedEntity($tableName, $property);
						$propertyName = '';
					} else {
						if ($property->getColumn() === NULL)
						{
							// If the last property also has relationship replace with primary key field value.
							if ($property->hasRelationship()) {
								list($tableName, $entityClass) = $this->traverseToRelatedEntity($tableName, $property);
								$column = $this->mapper->getPrimaryKey($tableName);
							} else {
								throw new InvalidStateException("Column not specified in property '$propertyName' of entity '$entityClass'");
							}
						} else {
							$column = $property->getColumn();
						}
						$output .= "[$tableName].[$column]";
						$switches['@'] = FALSE;
						$output .= $ch;
					}
				}
			} elseif ($ch === '@' && $switches["'"] === FALSE && $switches['"'] === FALSE) {
				$switches['@'] = TRUE;
				$propertyName = '';
				$properties = $rootProperties;
				$tableName = $rootTableName;
				$entityClass = $rootEntityClass;
			} else {
				if ($ch === '"' && $switches["'"] === FALSE) {
					$switches['"'] = !$switches['"'];
				} elseif ($ch === "'" && $switches['"'] === FALSE) {
					$switches["'"] = !$switches["'"];
				}
				$output .= $ch;
			}
		}
		return $output;
	}

	public function createQuery()
	{
		return $this->fluent->fetchAll();
	}

	protected function processToFluent($method, array $args = array())
	{
		call_user_func_array(array($this->fluent, $method),	$args);
	}

	public function where($cond)
	{
		if (is_array($cond)) {
			if (func_num_args() > 1) {
				throw new InvalidArgumentException('Number of arguments is limited to 1 if the first argument is array.');
			}
			foreach ($cond as $key => $value) {
				if (is_string($key)) {
					// TODO: use preg_match?
					$this->where($key, $value);
				} else {
					$this->where($value);
				}
			}
		} else {
			$args = func_get_args();
			if (count($args) === 2 && preg_match('#^@[a-zA-Z_.]+$#', trim($args[0]))) {
				$field = &$args[0];
				$value = &$args[1];
				// TODO: Set type of value to property type?
				if (is_array($value)) {
					$field .= ' IN %in';
				} else {
					$field .= ' = ?';
				}
			}
			// Only first argument is parsed. Other arguments will be maintained
			// as parameters.
			$statement = &$args[0];
			$statement = $this->parseStatement($statement);
			$statement = "($statement)";
			// Replace instances of Entity for its values.
			foreach ($args as &$arg) {
				if ($arg instanceof Entity) {
					$entityTable = $this->mapper->getTable(get_class($arg));
					$idField = $this->mapper->getEntityField($entityTable, $this->mapper->getPrimaryKey($entityTable));
					$arg = $arg->$idField;
				}
			}
			$this->processToFluent('where', $args);
		}
		return $this;
	}

	public function orderBy($field)
	{
		if (is_array($field)) {
			foreach ($field as $key => $value) {
				if (is_string($key)) {
					$this->orderBy($key)->asc($value);
				} else {
					$this->orderBy($value);
				}
			}
		} else {
			$field = $this->parseStatement($field);
			$this->processToFluent('orderBy', array($field));
		}
		return $this;
	}

	public function asc($asc = TRUE)
	{
		if ($asc) {
			$this->processToFluent('asc');
		} else {
			$this->processToFluent('desc');
		}
		return $this;
	}

	public function desc($desc = TRUE)
	{
		$this->asc(!$desc);
		return $this;
	}

	public function limit($limit)
	{
		$this->processToFluent('limit', array($limit));
		return $this;
	}

	public function offset($offset)
	{
		$this->processToFluent('offset', array($offset));
		return $this;
	}

}
