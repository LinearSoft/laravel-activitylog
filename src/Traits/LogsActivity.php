<?php

namespace Spatie\Activitylog\Traits;

use Illuminate\Support\Collection;
use Spatie\Activitylog\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait LogsActivity
{
    use DetectsChanges;

    protected static function bootLogsActivity()
    {
        static::eventsToBeRecorded()->each(function ($eventName) {
            return static::$eventName(function (Model $model) use ($eventName) {
                if (! $model->shouldLogEvent($eventName)) {
                    return;
                }

                $description = $model->getDescriptionForEvent($eventName);

                $logName = $model->getLogNameToUse($eventName);

                if ($description == '') {
                    return;
                }

                app(ActivityLogger::class)
                    ->useLog($logName)
                    ->performedOn($model)
                    ->withProperties($model->attributeValuesToBeLogged($eventName))
                    ->log($description);
            });
        });
    }

    /**
     * @return MorphMany
     */
    public function activity()
    {
        return $this->morphMany(ActivitylogServiceProvider::determineActivityModel(), 'subject');
    }

    /**
     * @param string $eventName
     * @return string
     */
    public function getDescriptionForEvent($eventName)
    {
        return $eventName;
    }

    /**
     * @param string $eventName
     * @return string
     */
    public function getLogNameToUse($eventName = '')
    {
        return config('laravel-activitylog.default_log_name');
    }

    /**
     * Get the event names that should be recorded.
     *
     * @return Collection
     */
    protected static function eventsToBeRecorded()
    {
        if (isset(static::$recordEvents)) {
            return collect(static::$recordEvents);
        }

        $events = collect([
            'created',
            'updated',
            'deleted',
        ]);

        if (collect(class_uses(__CLASS__))->contains(SoftDeletes::class)) {
            $events->push('restored');
        }

        return $events;
    }

    /**
     * @return array
     */
    public function attributesToBeIgnored()
    {
        if (! isset(static::$ignoreChangedAttributes)) {
            return [];
        }

        return static::$ignoreChangedAttributes;
    }

    protected function shouldLogEvent($eventName)
    {
        if (! in_array($eventName, ['created', 'updated'])) {
            return true;
        }

        if (array_has($this->getDirty(), 'deleted_at')) {
            if ($this->getDirty()['deleted_at'] === null) {
                return false;
            }
        }

        //do not log update event if only ignored attributes are changed
        return (bool) count(array_except($this->getDirty(), $this->attributesToBeIgnored()));
    }
}
