<?php

namespace App\Filament\Admin\Resources\AuditEventResource\Pages;

use App\Filament\Admin\Resources\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;
}
