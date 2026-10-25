<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Настройки CRM «ключ — json»: сейчас там только этапы сделки. */
#[Fillable(['key', 'value'])]
class CrmSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
