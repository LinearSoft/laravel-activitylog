<?php

namespace Spatie\Activitylog\Traits;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Exceptions\CouldNotLogChanges;

trait DetectsChanges
{
    protected $oldAttributes = [];

    protected static function bootDetectsChanges()
    {
        if (static::eventsToBeRecorded()->contains('updated')) {
            static::updating(function (Model $model) {

                //temporary hold the original attributes on the model
                //as we'll need these in the updating event
                $oldValues = $model->replicate()->setRawAttributes($model->getOriginal());

                $model->oldAttributes = static::logChanges($oldValues);
            });
        }
    }

    /**
     * @return array
     */
    public function attributesToBeLogged()
    {
        if (! isset(static::$logAttributes)) {
            return [];
        }

        return static::$logAttributes;
    }

    /**
     * @return bool
     */
    public function shouldlogOnlyDirty()
    {
      if (! isset(static::$logOnlyDirty)) {
        return false;
      }

      return static::$logOnlyDirty;
    }

    /**
     * @param string $processingEvent
     * @return array
     */
    public function attributeValuesToBeLogged($processingEvent)
    {
        if (! count($this->attributesToBeLogged())) {
            return [];
        }

      $properties['attributes'] = static::logChanges($this->exists ? $this->fresh() : $this);

        if (static::eventsToBeRecorded()->contains('updated') && $processingEvent == 'updated') {
            $nullProperties = array_fill_keys(array_keys($properties['attributes']), null);

            $properties['old'] = array_merge($nullProperties, $this->oldAttributes);
        }

      if ($this->shouldlogOnlyDirty() && isset($properties['old'])) {
        $properties['attributes'] = array_udiff_assoc(
            $properties['attributes'],
            $properties['old'],
            function ($new, $old) {
              if($new < $old) return -1;
              if($new > $old) return 1;
              return 0;
            }
        );
        $properties['old'] = collect($properties['old'])->only(array_keys($properties['attributes']))->all();
      }

        return $properties;
    }

    /**
     * @param Model $model
     * @return array
     */
    public static function logChanges(Model $model)
    {
      $changes = [];
      foreach ($model->attributesToBeLogged() as $attribute) {
        if (str_contains($attribute, '.')) {
          $changes += self::getRelatedModelAttributeValue($model, $attribute);
        } else {
          $changes += collect($model)->only($attribute)->toArray();
        }
      }

      return $changes;
    }

    /**
     * @param Model $model
     * @param string $attribute
     * @return array
     * @throws CouldNotLogChanges
     */
    protected static function getRelatedModelAttributeValue(Model $model, $attribute)
    {
      if (substr_count($attribute, '.') > 1) {
        throw CouldNotLogChanges::invalidAttribute($attribute);
      }

      list($relatedModelName, $relatedAttribute) = explode('.', $attribute);

      $relatedModel = $model->$relatedModelName != null ? $model->$relatedModelName : $model->$relatedModelName();

      return ["{$relatedModelName}.{$relatedAttribute}" => $relatedModel->$relatedAttribute];
    }
}
