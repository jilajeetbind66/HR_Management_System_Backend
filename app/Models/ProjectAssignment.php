<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProjectAssignment extends Model
{
    protected $table = 'project_assignments';

    protected $guarded = ['id', 'due_date'];

    protected static function booted(): void
    {
        static::updating(function (ProjectAssignment $assignment): void {
            if ($assignment->isDirty('due_date')) {
                throw new LogicException('The project baseline due date is immutable.');
            }
        });
    }
}
