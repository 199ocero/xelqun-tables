<?php

namespace Xelqun\Tables\Exceptions;

use LogicException;

/**
 * The table's definition can't work. It reports a developer's mistake, so it
 * should fail loudly instead of showing wrong data.
 */
class TableDefinitionException extends LogicException {}
