<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (https://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery\QueryTarget;

use LeanMapper\Relationship;

class HasManyRelationshipTable implements ITarget
{
	/** @var Relationship\HasMany */
	private $relationship;

	public function __construct(Relationship\HasMany $relationship)
	{
		$this->relationship = $relationship;
	}

	/**
	 * @return Relationship\HasMany
	 */
	public function getRelationship()
	{
		return $this->relationship;
	}
}
