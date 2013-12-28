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

	/**
	 * Executes query.
	 *
	 * @param  IQuery $query
	 * @return array
	 */
	public function find(IQuery $query);
}