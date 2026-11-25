<?php

namespace App\Crm\Exceptions;

use App\Models\ListingObject;

/** Объект уже закрыт (сдан, фейк, брошен): в исключении — закрытый объект, по нему строится понятное сообщение. */
class ObjectClosed extends CrmForbidden
{
    public function __construct(public readonly ListingObject $object)
    {
        parent::__construct('Object closed.');
    }
}
