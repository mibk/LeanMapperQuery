<?php

/**
 * Test: LeanMapperQuery\Entity.
 */

use LeanMapper\Repository;
use LeanMapperQuery\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

$query = getQuery()->cast('Tag');

Assert::exception(function() use ($query) {
	$query->cast('Book');
}, 'LeanMapperQuery\Exception\InvalidStateException', 'Entity class is already casted to Tag class.');
