<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery;

use LeanMapper;
use LeanMapper\Fluent;
use LeanMapper\ImplicitFilters;
use LeanMapper\IMapper;
use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\MemberAccessException;
use LeanMapperQuery\Exception\NonExistingMethodException;

/**
 * @author Michal Bohuslávek
 *
 * @method Query where($cond)
 * @method Query orderBy($field)
 * @method Query asc(bool $asc = TRUE)
 * @method Query desc(bool $desc = TRUE)
 * @method Query limit(int $limit)
 * @method Query offset(int $offset)
 */
class Query implements IQuery
{
	/** @var string */
	private static $defaultPlaceholder = '?';

	/** @var string */
	private static $variablePatternFirstLetter = '[a-zA-Z_\x7f-\xff]';

	/** @var string */
	private static $variablePatternOtherLetters = '[a-zA-Z0-9_\x7f-\xff]';

	/**
	 * Placeholders transformation table.
	 * @var array
	 */
	private static $placeholders = array(
		'string' => '%s',
		'boolean' => '%b',
		'integer' => '%i',
		'float' => '%f',
		'Datetime' => '%t',
		'Date' => '%d',
	);

	////////////////////////////////////////////////////

	/** @var string */
	protected $sourceTableName;

	/** @var Fluent */
	protected $fluent;

	/** @var IMapper */
	protected $mapper;


	/** @var array */
	private $queue = array();

	/** @var array */
	private $appliedJoins = array();


	private function getPropertiesByTable($tableName)
	{
		$entityClass = $this->mapper->getEntityClass($tableName);
		$reflection = $entityClass::getReflection($this->mapper);
		$properties = array();
		foreach ($reflection->getEntityProperties() as $property) {
			$properties[$property->getName()] = $property;
		}
		return array($entityClass, $properties);
	}

	private function getTableAlias($currentTable, $targetTable, $viaColumn)
	{
		// Tables can be joined via different columns from the same table,
		// or from different tables via column with the same name.
		$localKey = $targetTable . '_' . $viaColumn;
		$globalKey = $currentTable . '_' . $localKey;
		if (array_key_exists($globalKey, $this->appliedJoins)) {
			return array(TRUE, $this->appliedJoins[$globalKey]);
		}
		// Find the tiniest unique table alias.
		if (!in_array($targetTable, $this->appliedJoins)) {
			$value = $targetTable;
		} elseif (!in_array($localKey, $this->appliedJoins)) {
			$value = $localKey;
		} else {
			$value = $globalKey;
		}
		$this->appliedJoins[$globalKey] = $value;
		return array(FALSE, $value);
	}

	private function joinRelatedTable($currentTable, $referencingColumn, $targetTable, $targetTablePrimaryKey, $filters = array())
	{
		list($alreadyJoined, $alias) = $this->getTableAlias($currentTable, $targetTable, $referencingColumn);
		// Join if not already joined.
		if (!$alreadyJoined) {
			if (empty($filters)) {
				// Do simple join.
				$this->fluent->leftJoin("[$targetTable]" . ($targetTable !== $alias ? " [$alias]" : ''))
					->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
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
					if (is_string($filter) && array_key_exists($filter, $targetedArgs)) {
						$args = array_merge($args, $targetedArgs[$filter]);
					}
					call_user_func_array(array($subFluent, 'applyFilter'), $args);
				}
				$this->fluent->leftJoin($subFluent, "[$alias]")->on("[$currentTable].[$referencingColumn] = [$alias].[$targetTablePrimaryKey]");
			}
			$this->appliedJoins[] = $alias;
		}
		return $alias;
	}

	private function traverseToRelatedEntity($currentTable, $currentTableAlias, Property $property)
	{
		if (!$property->hasRelationship()) {
			$entityClass = $this->mapper->getEntityClass($currentTable);
			throw new InvalidRelationshipException("Property '{$property->getName()}' in entity '$entityClass' doesn't have any relationship.");
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
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $referencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} elseif ($relationship instanceof Relationship\BelongsTo) { // BelongsToOne, BelongsToMany
			// TODO: Involve getStrategy()?
			$targetTable = $relationship->getTargetTable();
			$sourceTablePrimaryKey = $this->mapper->getPrimaryKey($currentTable);
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			// Join table.
			$targetTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $targetTable, $referencingColumn, $implicitFilters);

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
			$relationshipTableAlias = $this->joinRelatedTable($currentTableAlias, $sourceTablePrimaryKey, $relationshipTable, $sourceReferencingColumn);
			$targetTableAlias = $this->joinRelatedTable($relationshipTableAlias, $targetReferencingColumn, $targetTable, $targetTablePrimaryKey, $implicitFilters);

		} else {
			throw new InvalidRelationshipException('Unknown relationship type. ' . get_class($relationship) . ' given.');
		}

		return array_merge(array($targetTable, $targetTableAlias), $this->getPropertiesByTable($targetTable));
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
			if ($type === 'Datetime' || is_subclass_of($type, 'Datetime')) {
				if ($property->hasCustomFlag('type')) {
					$type = $property->getCustomFlagValue('type');
					if (preg_match('#^(DATE|Date|date)$#', $type)) {
						return self::$placeholders['Date'];
					} else {
						return self::$placeholders['Datetime'];
					}
				} else {
					return self::$placeholders['Datetime'];
				}
			} else {
				return self::$defaultPlaceholder;
			}
		}
	}

	private function parseStatement($statement, $replacePlaceholders = FALSE)
	{
		if (!is_string($statement)) {
			throw new InvalidArgumentException('Type of argument $statement is expected to be string. ' . gettype($statement) . ' given.');
		}
		$rootTableName = $this->sourceTableName;
		list($rootEntityClass, $rootProperties) = $this->getPropertiesByTable($rootTableName);

		$switches = array(
			'@' => FALSE,
			'"' => FALSE,
			"'" => FALSE,
		);
		$output = '';
		$property = NULL;
		$firstLetter = TRUE;
		for ($i = 0; $i < strlen($statement) + 1; $i++) {
			// Do one more loop due to succesfuly translating
			// properties attached to the end of the statement.
			$ch = isset($statement{$i}) ? $statement{$i} : '';
			if ($switches['@'] === TRUE) {
				if (preg_match('#^'.($firstLetter ? self::$variablePatternFirstLetter : self::$variablePatternOtherLetters).'$#', $ch)) {
					$propertyName .= $ch;
					$firstLetter = FALSE;
				} else {
					$firstLetter = TRUE;
					if (!array_key_exists($propertyName, $properties)) {
						throw new MemberAccessException("Entity '$entityClass' doesn't have property '$propertyName'.");
					}
					$property = $properties[$propertyName];

					if ($ch === '.') {
						list($tableName, $tableNameAlias, $entityClass, $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
						$propertyName = '';
					} else {
						if ($property->getColumn() === NULL)
						{
							// If the last property also has relationship replace with primary key field value.
							if ($property->hasRelationship()) {
								list($tableName, , , $properties) = $this->traverseToRelatedEntity($tableName, $tableNameAlias, $property);
								$column = $this->mapper->getPrimaryKey($tableName);
								$property = $properties[$column];
							} else {
								throw new InvalidStateException("Column not specified in property '$propertyName' of entity '$entityClass'");
							}
						} else {
							$column = $property->getColumn();
						}
						$output .= "[$tableNameAlias].[$column]";
						$switches['@'] = FALSE;
						$output .= $ch;
					}
				}
			} elseif ($ch === '@' && $switches["'"] === FALSE && $switches['"'] === FALSE) {
				$switches['@'] = TRUE;
				$propertyName = '';
				$properties = $rootProperties;
				$tableNameAlias = $tableName = $rootTableName;
				$entityClass = $rootEntityClass;

			} elseif ($replacePlaceholders && $ch === self::$defaultPlaceholder && $switches["'"] === FALSE && $switches['"'] === FALSE) {
				if ($property === NULL) {
					$output .= $ch;
				} else {
					// Dumb replacing placeholder.
					// NOTE: Placeholders are replaced by the type of last found property.
					// 	It is stupid as it doesn't work for all kinds of SQL statements.
					$output .= $this->replacePlaceholder($property);
				}
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

	////////////////////////////////////////////////////

	/**
	 * @inheritdoc
	 */
	public function applyQuery(Fluent $fluent, IMapper $mapper)
	{
		// NOTE:
		// $fluent is expected to have once called method Fluent::from
		// with pure table name as an argument. For example:
		//   $fluent->from('author');
		//
		// Multiple calling Fluent::from method or something like
		//   $fluent->from('[author]');
		// is not supported.
		//
		// The advantage of this way is that there is no need to explicitly
		// specify $tableName when calling Query::applyQuery anymore.
		$fromClause = $fluent->_export('FROM');
		if (count($fromClause) !== 3 || $fromClause[1] !== '%n') {
			throw new InvalidArgumentException('Unsupported fluent from clause. Only one calling of \\LeanMapper\\Fluent::from method with pure table name as an argument is supported.');
		}
		list(, , $this->sourceTableName) = $fromClause;
		$this->fluent = $fluent;
		$this->mapper = $mapper;

		foreach ($this->queue as $call) {
			list($method, $args) = $call;
			call_user_func_array(array($this, $method), $args);
		}
		$this->appliedJoins = array(); // reset context
		return $fluent;
	}

	/**
	 * Enqueues command.
	 * @param  string $name Command name
	 * @param  array  $args
	 * @return self
	 */
	public function __call($name, array $args)
	{
		$method = 'command' . ucfirst($name);
		if (!method_exists($this, $method)) {
			throw new NonExistingMethodException("Command '$name' doesn't exist. To register this command there should be defined protected method " . get_called_class() . "::$method.");
		}
		$this->queue[] = array($method, $args);
		return $this;
	}

	////////////////////////////////////////////////////

	private function processToFluent($method, array $args = array())
	{
		call_user_func_array(array($this->fluent, $method),	$args);
	}

	/////////////// basic commands //////////////////////

	private function commandWhere($cond)
	{
		if (is_array($cond)) {
			if (func_num_args() > 1) {
				throw new InvalidArgumentException('Number of arguments is limited to 1 if the first argument is array.');
			}
			foreach ($cond as $key => $value) {
				if (is_string($key)) {
					$this->where($key, $value);
				} else {
					$this->where($value);
				}
			}
		} else {
			$replacePlaceholders = FALSE;
			$args = func_get_args();
			$operators = array('=', '<>', '!=', '<=>', '<', '<=', '>', '>=');
			$variablePattern = self::$variablePatternFirstLetter . self::$variablePatternOtherLetters . '*';
			if (count($args) === 2
				&& preg_match('#^\s*(@(?:'.$variablePattern.'|.)*'.$variablePattern.')\s*(|'.implode('|', $operators).')\s*$#', $args[0], $matches)) {
				$replacePlaceholders = TRUE;
				$field = &$args[0];
				list(, $field, $operator) = $matches;
				$value = $args[1];

				$placeholder = self::$defaultPlaceholder;
				if (!$operator) {
					if (is_array($value)) {
						$operator = 'IN';
						$placeholder = '%in';
					} elseif ($value === NULL) {
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
			// Replace instances of Entity for its values.
			foreach ($args as &$arg) {
				if ($arg instanceof LeanMapper\Entity) {
					$entityTable = $this->mapper->getTable(get_class($arg));
					$idField = $this->mapper->getEntityField($entityTable, $this->mapper->getPrimaryKey($entityTable));
					$arg = $arg->$idField;
				}
			}
			$this->processToFluent('where', $args);
		}
	}

	private function commandOrderBy($field)
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
	}

	private function commandAsc($asc = TRUE)
	{
		if ($asc) {
			$this->processToFluent('asc');
		} else {
			$this->processToFluent('desc');
		}
	}

	private function commandDesc($desc = TRUE)
	{
		$this->asc(!$desc);
	}

	private function commandLimit($limit)
	{
		$this->processToFluent('limit', array($limit));
	}

	private function commandOffset($offset)
	{
		$this->processToFluent('offset', array($offset));
	}

}
