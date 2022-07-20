<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery;

use LeanMapper;
use LeanMapper\Filtering;
use LeanMapper\Fluent;
use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapper\Result;
use LeanMapperQuery\Caller;
use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidMethodCallException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\MemberAccessException;
use LeanMapperQuery\IQuery;

/**
 * @author Michal Bohuslávek
 */
abstract class Entity extends LeanMapper\Entity
{
	/** @var array */
	protected static $magicMethodsPrefixes = [];

	/**
	 * @template T of Entity
	 * @param    string    $field
	 * @param    IQuery<T> $query
	 * @return   T[]
	 */
	protected function queryProperty($field, IQuery $query)
	{
		return static::queryEntityProperty($this, $field, $query);
	}

	public static function queryEntityProperty(LeanMapper\Entity $entity, $field, IQuery $query)
	{
		if ($entity->isDetached()) {
			throw new InvalidStateException('Cannot query detached entity.');
		}
		$property = $entity->getCurrentReflection()->getEntityProperty($field);
		if ($property === null) {
			throw new MemberAccessException("Cannot access undefined property '$field' in entity " . get_called_class() . '.');
		}
		if (!$property->hasRelationship()) {
			throw new InvalidArgumentException("Property '{$property->getName()}' in entity ". get_called_class() . " has no relationship.");
		}
		$class = $property->getType();
		$filters = $entity->createImplicitFilters($class, new Caller($entity, $property))->getFilters();
		$mapper = $entity->mapper;
		$relationship = $property->getRelationship();

		if (!($relationship instanceof Relationship\BelongsToMany) && !($relationship instanceof Relationship\HasMany)) {
			throw new InvalidRelationshipException('Only BelongsToMany and HasMany relationships are supported when querying entity property. ' . get_class($relationship) . ' given.');
		}

		$strategy = $relationship->getStrategy();

		if ($strategy !== Result::STRATEGY_UNION) {
			$strategy = $query->getStrategy();
		}

		$rows = [];
		if ($relationship instanceof Relationship\BelongsToMany) {
			$filters[] = function (Fluent $fluent) use ($mapper, $query) {
				$query->applyQuery($fluent, $mapper);
			};

			$targetTable = $relationship->getTargetTable();
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			$rows = $entity->row->referencing($targetTable, $referencingColumn, new Filtering($filters), $strategy);

		} elseif ($relationship instanceof Relationship\HasMany) {
			$filters[] = function (Fluent $fluent) use ($mapper, $query) {
				$query->applyQuery($fluent, $mapper, new QueryTarget\HasManyTargetTable);
			};

			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();
			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetPrimaryKey = $mapper->getPrimaryKey($targetTable);
			$rows = [];
			$resultRows = [];
			$targetResultProxy = null;
			$relationshipFiltering = null;

			if ($strategy === Result::STRATEGY_UNION) {
				$relationshipFiltering = new Filtering(function (Fluent $fluent) use ($mapper, $query, $relationship) {
					$query->applyQuery($fluent, $mapper, new QueryTarget\HasManyRelationshipTable($relationship));
				});
			}

			foreach ($entity->row->referencing($relationshipTable, $sourceReferencingColumn, $relationshipFiltering, $strategy) as $relationship) {
				$row = $relationship->referenced($targetTable, $targetReferencingColumn, new Filtering($filters));
				if ($row !== null && $targetResultProxy === null) {
					$targetResultProxy = $row->getResultProxy();
				}
				if ($row !== null) {
					$resultRows[$row->{$targetPrimaryKey}] = $row;
				}
			}

			if ($targetResultProxy) {
				foreach ($targetResultProxy as $rowId => $rowData) {
					if (isset($resultRows[$rowId])) {
						$rows[] = $resultRows[$rowId];
					}
				}
			} else {
				$rows = $resultRows;
			}
		}
		$entities = [];
		$table = $mapper->getTable($class);
		foreach ($rows as $row) {
			$newEntity = $entity->entityFactory->createEntity($mapper->getEntityClass($table, $row), $row);
			$newEntity->makeAlive($entity->entityFactory);
			$entities[] = $newEntity;
		}
		return $entities;
	}

	public function __call($name, array $arguments)
	{
		if (preg_match('#^('.implode('|', static::$magicMethodsPrefixes).')(.+)$#', $name, $matches)) {
			if (count($arguments) !== 1) {
				throw new InvalidMethodCallException(get_called_class() . "::$name expects exactly 1 argument. " . count($arguments) . ' given.');
			}
			list($query) = $arguments;
			if (!$query instanceof IQuery) {
				throw new InvalidArgumentException('Argument 1 passed to ' . get_called_class() . "::$name must implement interface LeanMapperQuery\\IQuery. " . gettype($query) . ' given.');
			}
			list(, $method, $field) = $matches;
			$field = lcfirst($field);
			return $this->$method($field, $query);

		} else {
			return parent::__call($name, $arguments);
		}
	}

}
