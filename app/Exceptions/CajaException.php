<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Operación de caja o de cobro que no se puede hacer (caja cerrada, cobro que no cierra,
 * promoción que no aplica...). El mensaje es para mostrárselo al cajero tal cual.
 */
class CajaException extends RuntimeException {}
