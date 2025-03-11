<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */
namespace LeanMapperQuery;

use LeanMapper\Fluent;
use LeanMapper\IMapper;

/**
 * @author     Michal Bohuslávek
 * @deprecated use Query instead; the IQuery interface might get removed in the future
 *
 * @template T of \LeanMapper\Entity
 */
interface IQuery
{
	/**
	 * @param  Fluent                   $fluent
	 * @param  IMapper                  $mapper
	 * @param  QueryTarget\ITarget|null $target
	 * @return Fluent
	 */
	public function applyQuery(Fluent $fluent, IMapper $mapper, QueryTarget\ITarget $target = null);

	/**
	 * @return string
	 */
	public function getStrategy();
}
