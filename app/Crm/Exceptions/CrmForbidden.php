<?php

namespace App\Crm\Exceptions;

use RuntimeException;

/** Действие не разрешено этому человеку или в этом состоянии записи. */
class CrmForbidden extends RuntimeException {}
