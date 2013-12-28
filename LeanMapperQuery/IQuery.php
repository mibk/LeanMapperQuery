<?php

namespace LeanMapperQuery;

interface IQuery
{

	/**
	 * Executes query.
	 *
	 * @return array
	 */
	public function createQuery();
}