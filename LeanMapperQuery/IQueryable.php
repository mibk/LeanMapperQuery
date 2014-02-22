<?php

namespace LeanMapperQuery;

use LeanMapper\Fluent;

interface IQueryable
{

	/**
	 * Creates fluent.
	 *
	 * @return Fluent
	 */
	public function createFluent();

	/**
	 * Returns table name.
	 *
	 * @return string
	 */
	public function getTable();

}
