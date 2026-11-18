<?php

namespace App\Console\Commands;

use App\Support\TrainingNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendTrainingNotificationsCommand extends Command
{
    protected $signature = 'hrms:training-notifications';

    protected $description = 'Send due-date reminders to employees and overdue training alerts to their managers';

    public function handle(): int
    {
        Log::info('Training notification sweep started.', [
            'db_host' => config('database.connections.' . config('database.default') . '.host'),
            'db_database' => config('database.connections.' . config('database.default') . '.database'),
        ]);

        $today = Carbon::today();
        $reminderEnd = $today->copy()->addDays(3);
        $reminders = DB::table('training_enrollments as enrollment')
            ->join('training_courses as course', 'course.id', '=', 'enrollment.course_id')
            ->join('employees', 'employees.id', '=', 'enrollment.employee_id')
            ->join('users as employees_user', 'employees_user.employee_id', '=', 'employees.id')
            ->where('course.status', 'active')
            ->where('course.mandatory', true)
            ->whereIn('enrollment.status', ['assigned', 'in_progress'])
            ->whereDate('course.due_date', '>', $today->toDateString())
            ->whereDate('course.due_date', '<=', $reminderEnd->toDateString())
            ->select([
                'enrollment.id as enrollment_id',
                'employees_user.id as user_id',
                'course.title',
                'course.due_date',
            ])
            ->get();

        foreach ($reminders as $reminder) {
            $dispatchKey = 'reminder:' . $reminder->due_date;
            TrainingNotification::sendOnce(
                (int) $reminder->enrollment_id,
                (int) $reminder->user_id,
                $dispatchKey,
                'training.due_reminder',
                'Training due soon',
                "{$reminder->title} is due on {$reminder->due_date}. Please complete it before the deadline."
            );
        }

        $overdueEnrollments = DB::table('training_enrollments as enrollment')
            ->join('training_courses as course', 'course.id', '=', 'enrollment.course_id')
            ->join('employees', 'employees.id', '=', 'enrollment.employee_id')
            ->join('users as managers', function ($join) {
                $join->on('managers.employee_id', '=', 'employees.reporting_manager_id')
                    ->where('managers.role', '=', 'manager');
            })
            ->where('course.status', 'active')
            ->where('course.mandatory', true)
            ->where('enrollment.status', '<>', 'completed')
            ->whereDate('course.due_date', '<', $today->toDateString())
            ->select([
                'enrollment.id as enrollment_id',
                'managers.id as manager_user_id',
                'course.title',
                'course.due_date',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code',
            ])
            ->get();

        foreach ($overdueEnrollments as $overdue) {
            $employeeName = trim($overdue->first_name . ' ' . $overdue->last_name);
            TrainingNotification::sendOnce(
                (int) $overdue->enrollment_id,
                (int) $overdue->manager_user_id,
                'overdue:' . $overdue->due_date,
                'training.overdue',
                'Training is overdue',
                "{$employeeName} ({$overdue->employee_code}) has not completed {$overdue->title}, due {$overdue->due_date}."
            );
        }

        $this->info(sprintf(
            'Training notification sweep completed: %d due reminders checked, %d overdue assignments checked.',
            $reminders->count(),
            $overdueEnrollments->count()
        ));
        Log::info('Training notification sweep completed.', [
            'reminder_count' => $reminders->count(),
            'overdue_count' => $overdueEnrollments->count(),
        ]);

        return self::SUCCESS;
    }
}
