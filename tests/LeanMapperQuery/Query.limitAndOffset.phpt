<?php

/**
 * Test: LeanMapperQuery\Query limit and offset.
 * @author Michal Bohuslávek
 */

use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

const LIMIT = 10;
const OFFSET = 50;

$fluent = getFluent('book');
getQuery()
	->limit(LIMIT)
	->offset(OFFSET)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->limit(LIMIT)
	->offset(OFFSET);
Assert::same((string) $expected, (string) $fluent);
