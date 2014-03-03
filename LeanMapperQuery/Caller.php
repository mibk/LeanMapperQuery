<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery;

/**
 * There are 4 possibilities:
 * a) caller is just instance of
 *   LeanMapper\Caller:
 *   1) caller is entity
 *   2) caller is repository
 * b) caller is instance of this class
 *   (LeanMapperQuery\Caller):
 *   3) caller is query object
 *   4) if method self::isEntity return TRUE,
 *     caller is query object via entity
 *
 * @author Michal Bohuslávek
 */
class Caller extends \LeanMapper\Caller
{
}
