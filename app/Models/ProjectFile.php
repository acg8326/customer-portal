<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document in a project's knowledge base. The extracted text lives in a
 * storage sidecar ("{path}.extracted.txt") and is injected into the system
 * prompt of every chat in the project.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $path
 */
#[Fillable(['name', 'mime', 'size', 'path'])]
class ProjectFile extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
