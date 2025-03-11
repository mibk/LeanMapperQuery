<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */
namespace LeanMapperQuery;

use LeanMapper;
use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\MemberAccessException;
use LeanMapperQuery\Exception\NonExistingMethodException;
use LeanMapper\Fluent;
use LeanMapper\IMapper;
use LeanMapper\ImplicitFilters;
use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapper\Result;

/**
 * @author Michal Bohuslávek
 *
 * @template T of \LeanMapper\Entity
 *
 * @method $this where(string $cond, mixed ...$args)
 * @method $this orderBy(string $field, mixed ...$args)
 * @method $this asc(bool $asc = true)
 * @method $this desc(bool $desc = true)
 * @method $this limit(int $limit)
 * @method $this offset(int $offset)
 */
class Query implements IQuery
{
	/** @var string */
	private static $defaultPlaceholder = '?';

	/** @var string */
	private static $variablePatternFirstLetter = '[a-zA-Z_\x7f-\xff]';

	/** @var string */
	private static $variablePatternOtherLetters = '[a-zA-Z0-9_\x7f-\xff]';

	/** @var string */
	private static $typeFlagName = 'type';

	/**
	 * Placeholders transformation table.
	 * @var array
	 */
	private static $placeholders = [
		'string' => '%s',
		'boolean' => '%b',
		'integer' => '%i',
		'float' => '%f',
		'DateTime' => '%t',
		'Date' => '%d',
	];

	////////////////////////////////////////////////////

	/** @var string|null */
	private $castedEntityClass = null;

	/** @var string */
	protected $sourceTableName;

	/** @var Fluent|null */
	private $fluent = null;

	/** @var IMapper */
	protected $mapper;

	/**
	 * Whether to use dumb replacing of placeholders globally.
	 * @var boolean
	 */
	protected $replacePlaceholders = false;

	/** @var array */
	private $queue = [];

	/** @var array */
	private $limitQueue = [];

	/** @var array */
	private $tablesAliases;

	/** @var array|null */
	private $possibleJoin = null;

	/** @var array */
	private $joinAlternative = [];

	private function getPropertiesByTable($tableName)
	{
		$entityClass = $this->mapper->getEntityClass($tableName);
		return $this->getPropertiesByEntity($entityClass);
	}

	private function getPropertiesByEntity($entityClass)
	{
		$reflection = $entityClass::getReflection($this->mapper);
		$properties = [];
		foreach ($reflection->getEntityProperties() as $property) {
			$properties[$property->getName()] = $property;
		}
		return [$entityClass, $properties];
	}

	/**
	 * Returns true if $targetTable is already joined.
	 * @param  string $currentTable
	 * @param  string $targetTable
	 * @param  string $viaColumn
	 * @param  string $alias
	 * @return bool
	 */
	private function getTableAlias($currentTable, $targetTable, $viaColumn, &$globalKey, &$alias)
	{
		// Tables can be joined via different columns from the same table,
		// or from different tables via column with the same name.
		$localKey = $targetTable . '_' . $viaColumn;
		$globalKey = $currentTable . '_' . $localKey;
		if (array_key_exists($globalKey, $this->tablesAliases)) {
			$alias = $this->tablesAliases[$globalKey];
			return true;
		}
		// Find the tiniest unique table alias.
		if (!in_array($targetTable, $this->tablesAliases)) {
			$alias = $targetTable;
		} elseif (!in_array($localKey, $this->tablesAliases)) {
			$alias = $localKey;
		} else {
			$alias = $globalKey;
		}
		return false;
	}

	private function registerTableAlias($globalKey, $alias)
	{
		if (array_key_exists($globalKey, $this->tablesAliases)) {
			throw new InvalidStateException("Global key '$globalKey' is already registered.");
		}
		$this->tablesAliases[$globalKey] = $alias;
	}

	private function registerJoin($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $globalKey, $alias)
	{
		if ($this->possibleJoin !== null) {
			throw new InvalidStateException('Cannot register new join. There is one registered already.');
		}
		$this->possibleJoin = func_get_args();
		$this->joinAlternative = [$currentTable, $referencingColumn];
	}

	private function triggerJoin()
	{
		if ($this->possibleJoin !== null) {
			list($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $globalKey, $alias) = $this->possibleJoin;
			$this->fluent->leftJoin("[$targetTable]" . ($targetTable !== $alias ? " [$alias]" : ''))
				->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
			$this->tryAddGroupBy($this->fluent, $currentTable);
			$this->registerTableAlias($globalKey, $alias);
			$this->possibleJoin = null;
		}
	}

	/**
	 * Dismisses the join and returns alternative table
	 * and column names.
	 * @return array{0: string, 1: string}
	 */
	private function dismissJoin()
	{
		$this->possibleJoin = null;
		return $this->joinAlternative;
	}

	/**
	 * @return bool
	 */
	private function pendingJoin()
	{
		return $this->possibleJoin !== null;
	}

	private function joinRelatedTable($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $filters = [], $joinImmediately = true)
	{
		// Join if not already joined.
		if (!$this->getTableAlias($currentTable, $targetTable, $referencingColumn, $globalKey, $alias)) {
			if (empty($filters)) {
				// Do simple join.
				// In few cases there is no need to do join immediately -> register join
				// to decide later.
				$this->registerJoin($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $globalKey, $alias);
				$joinImmediately && $this->triggerJoin();
			} else {
				// Join sub-query due to applying implicit filters.
				$subFluent = new Fluent($this->fluent->getConnection());
				$subFluent->select('%n.*', $targetTable)->from($targetTable);

				// Apply implicit filters.
				$targetedArgs = [];
				if ($filters instanceof ImplicitFilters) {
					$targetedArgs = $filters->getTargetedArgs();
					$filters = $filters->getFilters();
				}
				foreach ($filters as $filter) {
					$args = [$filter];
					if (is_string($filter) && array_key_exists($filter, $targetedArgs)) {
						$args = array_merge($args, $targetedArgs[$filter]);
					}
					call_user_func_array([$subFluent, 'applyFilter'], $args);
				}
				$this->fluent->leftJoin($subFluent, "[$alias]")
					->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
				$this->tryAddGroupBy($this->fluent, $currentTable);
				$this->registerTableAlias($globalKey, $alias);
			}
		}
		return $alias;
	}

	private function traverseToRelatedEntity(&$currentTable, &$currentTableAlias, Property $property)
	{
		if (!$property->hasRelationship()) {
			$entityClass = $this->mapper->getEntityClass($currentTable);
			throw new InvalidRelationshipException("Property '{$property->getName()}' in entity '$entityClass' doesn't have any relationship.");
		}
		$implicitFilters = [];
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
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $referencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters, false);
		} elseif ($relationship instanceof Relationship\BelongsTo) { // BelongsToOne, BelongsToMany
			$targetTable = $relationship->getTargetTable();
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			// Join table.
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $targetTable, $referencingColumn, $implicitFilters);
		} elseif ($relationship instanceof Relationship\HasMany) {
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();

			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetTablePrimaryKey = $this->mapper->getPrimaryKey($targetTable);
			// Join tables.
			// Don't apply filters on relationship table.
			$relationshipTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $relationshipTable, $sourceReferencingColumn);
			$targetTableAlias = $this->joinRelatedTable($relationshipTableAlias, $targetReferencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters, false);
		} else {
			throw new InvalidRelationshipException('Unknown relationship type in property {$property->getName()}.');
		}
		$currentTable = $targetTable;
		$currentTableAlias = $targetTableAlias;
		return $this->getPropertiesByTable($targetTable);
	}

	private function replacePlaceholder(Property $property)
	{
		$type = $property->getType();
		if ($property->isBasicType()) {
			if (array_key_exists($type, self::$placeholders)) {
				return self::$placeholders[$type];
			} else {
				return self::$defaultPlaceholder;
			}
		} else {
			if ($type === 'DateTime' || is_subclass_of($type, 'DateTime')) {
				if ($property->hasCustomFlag(self::$typeFlagName)
					&& preg_match('#^(DATE|Date|date)$#', $property->getCustomFlagValue(self::$typeFlagName))) {
					return self::$placeholders['Date'];
				} else {
					return self::$placeholders['DateTime'];
				}
			} else {
				return self::$defaultPlaceholder;
			}
		}
	}

	private function tryAddGroupBy(Fluent $fluent, $table)
	{
		$groupBy = $fluent->_export('GROUP BY');

		if (empty($groupBy)) {
			$fluent->groupBy('%n.%n', $table, $this->mapper->getPrimaryKey($table));
		}
	}

	/**
	 * Parses given statement. Basically it replaces '@foo' to
	 * '[table_name].[foo]' and performs automatic joins.
	 * @param  string $statement
	 * @param  bool   $replacePlaceholders
	 * @return string
	 * @throws InvalidArgumentException
	 * @throws MemberAccessException
	 * @throws InvalidStateException
	 */
	protected function parseStatement($statement, $replacePlaceholders = null)
	{
		if (!is_string($statement)) {
			throw new InvalidArgumentException('Type of argument $statement is expected to be string. ' . gettype($statement) . ' given.');
		}
		$replacePlaceholders === null && $replacePlaceholders = (bool) $this->replacePlaceholders;

		$rootTableName = $this->sourceTableName;

		if ($this->castedEntityClass) {
			list($rootEntityClass, $rootProperties) = $this->getPropertiesByEntity($this->castedEntityClass);
		} else {
			list($rootEntityClass, $rootProperties) = $this->getPropertiesByTable($rootTableName);
		}

		$switches = [
			'@' => false,
			'"' => false,
			"'" => false,
		];
		$output = '';
		$properties = [];
		$property = null;
		$propertyName = null;
		$tableNameAlias = null;
		$tableName = null;
		$entityClass = null;
		$firstLetter = true;
		for ($i = 0; $i < strlen($statement) + 1; $i++) {
			// Do one more loop due to succesfuly translating
			// properties attached to the end of the statement.
			$ch = isset($statement[$i]) ? $statement[$i] : '';
			if ($switches['@'] === true) {
				if (preg_match('#^'.($firstLetter ? self::$variablePatternFirstLetter : self::$variablePatternOtherLetters).'$#', $ch)) {
					$propertyName .= $ch;
					$firstLetter = false;
				} else {
					$firstLetter = true;
					if (!array_key_exists($propertyName, $properties)) {
						throw new MemberAccessException("Entity '$entityClass' doesn't have property '$propertyName'.");
					}
					$property = $properties[$propertyName];

					if ($ch === '.') {
						$this->triggerJoin();
						list($entityClass, $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
						$propertyName = '';
					} else {
						if ($property->hasRelationship()) {
							// If the last property also has relationship replace with primary key field value.
							// NOTE: Traversing to a related entity is necessary even for the HasOne and HasMany
							//  relationships if there are implicit filters to be applied.
							$this->triggerJoin();
							list($entityClass, $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
							$column = $this->mapper->getPrimaryKey($tableName);
							$property = null;
							foreach ($properties as $prop) {
								if ($prop->getColumn() === $column) {
									$property = $prop;
								}
							}
							if (!$property) {
								throw new InvalidStateException("Entity '$entityClass' doesn't have any field corresponding to the primary key column '$column'.");
							}
						} else {
							$column = $property->getColumn();
							if ($column === null) {
								throw new InvalidStateException("Column not specified in property '$propertyName' from entity '$entityClass'.");
							}
						}
						if ($column === $this->mapper->getPrimaryKey($tableName) && $this->pendingJoin()) {
							// There is a pending join that does not need to be done. Primary key value
							// is already known from referencing table.
							list($tableNameAlias, $column) = $this->dismissJoin();
						} else {
							$this->triggerJoin();
						}
						$output .= "[$tableNameAlias].[$column]";
						$switches['@'] = false;
						$output .= $ch;
					}
				}
			} elseif ($ch === '@' && $switches["'"] === false && $switches['"'] === false) {
				$switches['@'] = true;
				$propertyName = '';
				$properties = $rootProperties;
				$tableNameAlias = $tableName = $rootTableName;
				$entityClass = $rootEntityClass;
			} elseif ($replacePlaceholders && $ch === self::$defaultPlaceholder && $switches["'"] === false && $switches['"'] === false) {
				if ($property === null) {
					$output .= $ch;
				} else {
					// Dumb replacing of the placeholder.
					// NOTE: Placeholders are replaced by the type of last found property.
					// It is stupid as it doesn't work for all kinds of SQL statements.
					$output .= $this->replacePlaceholder($property);
				}
			} else {
				if ($ch === '"' && $switches["'"] === false) {
					$switches['"'] = !$switches['"'];
				} elseif ($ch === "'" && $switches['"'] === false) {
					$switches["'"] = !$switches["'"];
				}
				$output .= $ch;
			}
		}
		return $output;
	}

	/**
	 * Returns fluent if it is available. It is supposed
	 * to be used within own command<name> methods.
	 * @throws InvalidStateException
	 * @return Fluent
	 */
	protected function getFluent()
	{
		if ($this->fluent === null) {
			throw new InvalidStateException('getFluent() method could be only called within command<name>() methods.');
		}
		return $this->fluent;
	}

	////////////////////////////////////////////////////

	/**
	 * @param  string $entityClass
	 * @return $this
	 */
	public function cast($entityClass)
	{
		if ($this->castedEntityClass !== null) {
			throw new InvalidStateException("Entity class is already casted to {$this->castedEntityClass} class.");
		}
		$this->castedEntityClass = $entityClass;
		return $this;
	}

	/**
	 * @inheritdoc
	 * @throws InvalidArgumentException
	 */
	public function applyQuery(Fluent $fluent, IMapper $mapper, QueryTarget\ITarget $target = null)
	{
		$targetTable = null;

		if ($target instanceof QueryTarget\HasManyRelationshipTable) {
			$targetTable = $target->getRelationship()->getTargetTable();
		}

		$fluent = $this->apply($fluent, $mapper, $targetTable);

		if ($target instanceof QueryTarget\HasManyRelationshipTable) {
			if ($fluent->_export('WHERE') || $fluent->_export('ORDER BY')) {
				$relationship = $target->getRelationship();
				$fluent->leftJoin($targetTable)
					->on(
						"%n.%n = %n.%n",
						$relationship->getRelationshipTable(),
						$relationship->getColumnReferencingTargetTable(),
						$targetTable,
						$mapper->getPrimaryKey($targetTable),
					);
			}
		} elseif ($target instanceof QueryTarget\HasManyTargetTable) {
			$fluent->removeClause('LIMIT');
			$fluent->removeClause('OFFSET');
		} elseif ($target !== null) {
			throw new InvalidArgumentException('Unsupported query target.');
		}

		return $fluent;
	}

	private function apply(Fluent $fluent, IMapper $mapper, $sourceTableName = null)
	{
		// NOTE:
		// $fluent is expected to have called method Fluent::from
		// with pure table name as an argument. For example:
		//   $fluent->from('author');
		//
		// So something like
		//   $fluent->from('[author]');
		// is not supported. If a Fluent::from method is called multiple
		// times, the table name from the first call is used as
		// the source table.
		//
		// The advantage of this way is that there is no need to explicitly
		// specify $tableName when calling Query::applyQuery anymore.
		$fromClause = $fluent->_export('FROM');
		if (count($fromClause) < 3 || $fromClause[1] !== '%n') {
			throw new InvalidArgumentException('Unsupported fluent from clause. Only pure table name as an argument of \\LeanMapper\\Fluent::from method is supported.');
		}
		$this->sourceTableName = $sourceTableName !== null ? $sourceTableName : $fromClause[2];
		if (count($fromClause) > 3) { // complicated from clause
			$subFluent = clone $fluent;
			// Reset fluent.
			foreach (array_keys(\Dibi\Fluent::$separators) as $separator) {
				$fluent->removeClause($separator);
			}
			// If there are some joins, enwrap the original fluent to enable
			// accessing columns from joined tables.
			$fluent->select('*')->from($subFluent)
				->as($this->sourceTableName);
		}

		$this->fluent = $fluent;
		$this->mapper = $mapper;

		if ($this->castedEntityClass !== null) {
			$rootEntityClass = $this->mapper->getEntityClass($this->sourceTableName);

			if (!is_a($this->castedEntityClass, $rootEntityClass, true)) {
				throw new InvalidArgumentException("Query object is limited to {$this->castedEntityClass} entity, {$rootEntityClass} entity used.");
			}

			if ($mapper instanceof ICaster) {
				$mapper->castTo($fluent, $this->castedEntityClass);
			}
		}

		// Add source table name to tables aliases list to avoid error
		// when joining to itself.
		$this->tablesAliases = [$this->sourceTableName];

		foreach (array_merge($this->queue, $this->limitQueue) as $call) {
			list($method, $args) = $call;
			call_user_func_array([$this, $method], $args);
		}

		// Reset fluent.
		$this->fluent = null;
		return $fluent;
	}

	/**
	 * @return string
	 */
	public function getStrategy()
	{
		return empty($this->limitQueue) ? Result::STRATEGY_IN : Result::STRATEGY_UNION;
	}

	/**
	 * Enqueues command.
	 * @param  string $name Command name
	 * @param  array  $args
	 * @return $this
	 * @throws NonExistingMethodException
	 */
	public function __call($name, array $args)
	{
		$method = 'command' . ucfirst($name);
		if (!method_exists($this, $method)) {
			throw new NonExistingMethodException("Command '$name' doesn't exist. To register this command there should be defined protected method " . get_called_class() . "::$method.");
		}

		switch ($name) {
		case 'limit':
		case 'offset':
			$this->limitQueue[] = [$method, $args];
			break;
		default:
			$this->queue[] = [$method, $args];
			break;
		}
		return $this;
	}

	/////////////// basic commands //////////////////////

	private function commandWhere($cond) // @phpstan-ignore-line
	{
		if (is_array($cond)) {
			if (func_num_args() > 1) {
				throw new InvalidArgumentException('Number of arguments is limited to 1 if the first argument is array.');
			}
			foreach ($cond as $key => $value) {
				if (is_string($key)) {
					$this->commandWhere($key, $value);
				} else {
					$this->commandWhere($value);
				}
			}
		} else {
			$replacePlaceholders = null;
			$args = func_get_args();
			$operators = ['=', '<>', '!=', '<=>', '<', '<=', '>', '>='];
			$variablePattern = self::$variablePatternFirstLetter . self::$variablePatternOtherLetters . '*';
			if (count($args) === 2
				&& preg_match('#^\s*(@(?:'.$variablePattern.'|\.)*'.$variablePattern.')\s*(|'.implode('|', $operators).')\s*(?:\?\s*)?$#', $args[0], $matches)) {
				$replacePlaceholders = true;
				$field = &$args[0];
				list(, $field, $operator) = $matches;
				$value = &$args[1];

				$placeholder = self::$defaultPlaceholder;
				if (!$operator) {
					if (is_array($value)) {
						$value = $this->replaceEntitiesForItsPrimaryKeyValues($value);
						$operator = 'IN';
						$placeholder = '%in';
					} elseif ($value === null) {
						$operator = 'IS';
						$placeholder = 'NULL';
						unset($args[1]);
					} else {
						$operator = '=';
					}
				}
				$field .= " $operator $placeholder";
			}
			// Only first argument is parsed. Other arguments will be maintained
			// as parameters.
			$statement = &$args[0];
			$statement = $this->parseStatement($statement, $replacePlaceholders);
			$statement = "($statement)";
			$args = $this->replaceEntitiesForItsPrimaryKeyValues($args);
			call_user_func_array([$this->fluent, 'where'], $args);
		}
	}

	private function replaceEntitiesForItsPrimaryKeyValues(array $entities)
	{
		foreach ($entities as &$entity) {
			if ($entity instanceof LeanMapper\Entity) {
				$entityTable = $this->mapper->getTable(get_class($entity));
				// FIXME: Column name could be specified in the entity instead of mapper provided by 'getEntityField' function.
				$idField = $this->mapper->getEntityField($entityTable, $this->mapper->getPrimaryKey($entityTable));
				$entity = $entity->$idField;
			}
		}
		return $entities;
	}

	private function commandOrderBy($field) // @phpstan-ignore-line
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
			$args = func_get_args();
			// Only first argument is parsed. Other arguments will be maintained
			// as parameters.
			$statement = &$args[0];
			$statement = $this->parseStatement($statement, null);
			$args = $this->replaceEntitiesForItsPrimaryKeyValues($args);
			call_user_func_array([$this->fluent, 'orderBy'], $args);
		}
	}

	private function commandAsc($asc = true)
	{
		$this->fluent->{$asc ? 'asc' : 'desc'}();
	}

	private function commandDesc($desc = true) // @phpstan-ignore-line
	{
		$this->commandAsc(!$desc);
	}

	private function commandLimit($limit) // @phpstan-ignore-line
	{
		$this->fluent->limit($limit);
	}

	private function commandOffset($offset) // @phpstan-ignore-line
	{
		$this->fluent->offset($offset);
	}
}
