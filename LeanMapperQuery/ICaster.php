<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (https://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery;

use LeanMapper\Fluent;

interface ICaster
{
	/**
	 * @param  Fluent $fluent
	 * @param  string $entityClass
	 * @return void
	 */
	public function castTo(Fluent $fluent, $entityClass);
}
