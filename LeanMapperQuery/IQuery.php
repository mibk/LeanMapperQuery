<?php

namespace LeanMapperQuery;

use LeanMapper\Fluent;
use LeanMapper\IMapper;

interface IQuery
{

	/**
	 * @param  string  $tableName
	 * @param  Fluent  $fluent
	 * @param  IMapper $mapper
	 * @return Fluent
	 */
	public function applyQuery($tableName, Fluent $fluent, IMapper $mapper);
}
