<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersFiberSchemaDxf;
use App\Http\Controllers\Concerns\WritesDxfEntities;

class FiberSchemaExportController extends Controller
{
    use RendersFiberSchemaDxf;
    use WritesDxfEntities;
}
