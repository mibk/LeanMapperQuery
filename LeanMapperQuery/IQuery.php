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
 * @author Michal Bohuslávek
 */
interface IQuery
{

	/**
	 * @param  Fluent  $fluent
	 * @param  IMapper $mapper
	 * @param  QueryTarget\ITarget|NULL $target
	 * @return Fluent
	 */
	public function applyQuery(Fluent $fluent, IMapper $mapper, QueryTarget\ITarget $target = NULL);

	/**
	 * @return string
	 */
	public function getStrategy();
}
