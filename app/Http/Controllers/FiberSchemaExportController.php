<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\FiberSchema\FiberSchemaDrawing;
use App\Services\FiberSchema\FiberSchemaDxf;
use Illuminate\Http\Response;

class FiberSchemaExportController extends Controller
{
    /** The fiber schematic as DXF R12 for AutoCAD: the same drawing as on screen and in the PDF. */
    public function exportFiberSchema(Project $project, FiberSchemaDrawing $drawings, FiberSchemaDxf $dxf): Response
    {
        $drawing = $drawings->build($project);
        $exportCode = str($project->code ?: $project->name)->slug()->value() ?: 'projekat-'.$project->id;

        return response($dxf->render($drawing), 200, [
            'Content-Type' => 'application/dxf',
            'Content-Disposition' => 'attachment; filename="'.$exportCode.'-fiber-schema.dxf"',
            'X-Fiber-Plan-Signature' => $drawing['plan']['signature'],
        ]);
    }
}
