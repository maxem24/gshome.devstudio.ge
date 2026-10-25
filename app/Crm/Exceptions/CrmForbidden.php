<?php

namespace App\Crm\Exceptions;

use RuntimeException;

/** Действие не разрешено этому человеку или в этом состоянии записи. */
final class CrmForbidden extends RuntimeException {}
