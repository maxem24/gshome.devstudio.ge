<?php

namespace App\Crm\Exceptions;

/** Получатель передачи уже ведёт этот объект. */
class RecipientOnObject extends CrmForbidden
{
    public function __construct()
    {
        parent::__construct('Recipient already works on this object.');
    }
}
