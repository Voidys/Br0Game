<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * A production/storage formula could not be parsed or uses something the FormulaEvaluator does not allow
 * (unknown variable, unknown function, unsupported operator). Formulas come from the vars table, so this
 * signals a configuration error, never user input.
 */
final class FormulaException extends \InvalidArgumentException {}
