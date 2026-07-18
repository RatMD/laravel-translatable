<?php declare(strict_types=1);

namespace Rat\Translatable\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rat\Translatable\Collections\Translation as MongoDBTranslation;
use Rat\Translatable\Models\Translation;

trait Translatable
{
    /**
     * Holds pending translations to be saved.
     * @var array
     */
    protected $pendingTranslations = [];

    /**
     * Cached translations for quick lookup by locale.
     * @var array<string,MongoDBTranslation|Translation|null>
     */
    protected array $translationCache = [];

    /**
     * Currently active locale for this model instance.
     * @var mixed
     */
    protected $currentLocale = null;

    /**
     * Boot the Translatable trait and register model event listeners.
     * @return void
     */
    static public function bootTranslatable()
    {
        static::saved(function (Model $model) {
            if (empty($model->pendingTranslations)) {
                return;
            }

            $modelType = $model->getMorphClass();
            foreach ($model->pendingTranslations as $locale => $attributes) {
                $translation = $model->translationQuery()->firstOrNew([
                    'model_id'   => $model->getKey(),
                    'model_type' => $modelType,
                    'locale'     => $locale,
                ]);

                $strings = $translation->strings ?? [];
                foreach ($attributes as $attrKey => $attrValue) {
                    $strings[$attrKey] = $attrValue;
                }
                $translation->strings = $strings;
                $translation->save();

                $model->flushTranslationCache($locale);
            }
            $model->pendingTranslations = [];
        });
    }

    /**
     * Check whether the current connection (or given target) uses the MongoDB driver.
     * @param null|Builder|Model $target
     * @return bool
     */
    protected function isMongo(null|Builder|Model $target = null): bool
    {
        $target ??= $this;
        $conn = $target instanceof Builder ? $target->getModel()->getConnection() : $target->getConnection();
        return method_exists($conn, 'getDriverName') && $conn->getDriverName() === 'mongodb';
    }

    /**
     * Clear the translation cache, either for a specific locale or for all.
     * @param null|string $locale
     * @return void
     */
    public function flushTranslationCache(?string $locale = null): void
    {
        if ($locale === null) {
            $this->translationCache = [];
        } else {
            $cacheKey = implode(':', [$locale, $this->getKey(), $this->getMorphClass()]);
            unset($this->translationCache[$cacheKey]);
        }
    }

    /**
     * Resolve which translation model class should be used.
     * @return string
     * @throws InvalidArgumentException
     */
    protected function getTranslationModelClass(): string
    {
        $default = $this->isMongo($this) ? MongoDBTranslation::class: Translation::class;
        $class = property_exists($this, 'translationModel') && $this->translationModel
            ? $this->translationModel
            : config('translatable.translatable_model', $default);

        if (!is_string($class) || !class_exists($class)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid translatable model class "%s". Make sure it exists and is loadable.',
                (string) $class
            ));
        }

        if ($this->isMongo($this)) {
            if ($class !== MongoDBTranslation::class && !is_subclass_of($class, MongoDBTranslation::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'The translatable model "%s" must extend %s.',
                    $class, MongoDBTranslation::class
                ));
            }
        } else {
            if ($class !== Translation::class && !is_subclass_of($class, Translation::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'The translatable model "%s" must extend %s.',
                    $class, Translation::class
                ));
            }
        }

        return $class;
    }

    /**
     * Create a new instance of the translation model.
     * @return Model
     * @throws InvalidArgumentException
     */
    protected function newTranslationModel(): Model
    {
        return app()->make($this->getTranslationModelClass());
    }

    /**
     * Get a new query builder for the translation model.
     * @return Builder
     */
    protected function translationQuery(): Builder
    {
        return $this->newTranslationModel()->newQuery();
    }

    /**
     * Retrieve the translation model for the given locale (with caching).
     * @param string $locale
     * @return mixed
     */
    public function getTranslationModel(string $locale)
    {
        $modelType = $this->getMorphClass();
        $cacheKey = implode(':', [$locale, $this->getKey(), $modelType]);

        if (!array_key_exists($cacheKey, $this->translationCache)) {
            $this->translationCache[$cacheKey] = $this->translationQuery()
                ->where([
                    'model_id'   => $this->getKey(),
                    'model_type' => $modelType,
                    'locale'     => $locale,
                ])
                ->first();
        }

        return $this->translationCache[$cacheKey];
    }

    /**
     * Return the list of cast types considered as "array-like".
     * @return array
     */
    protected function arrayLikeCastTypes(): array
    {
        $defaults = [
            'array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:object',
            \Illuminate\Database\Eloquent\Casts\AsArrayObject::class,
            \Illuminate\Database\Eloquent\Casts\AsCollection::class,
        ];

        $config = config('translatable.array_like_casts', $defaults);
        $model  = property_exists($this, 'translatableArrayCastTypes')
            ? (array) $this->translatableArrayCastTypes
            : [];

        return array_values(array_unique(array_merge($config, $model)));
    }

    /**
     * Return the list of attributes considered as "array-like".
     * @return array
     */
    protected function arrayLikeAttributes(): array
    {
        $config = config('translatable.array_like_attributes', []);
        $model  = property_exists($this, 'translatableArrayAttributes')
            ? (array) $this->translatableArrayAttributes
            : [];

        return array_values(array_unique(array_merge($config, $model)));
    }

    /**
     * Determine if the given attribute is considered "array-like".
     * @param string $key
     * @return bool
     */
    public function isArrayLikeField(string $key): bool
    {
        if (in_array($key, $this->arrayLikeAttributes(), true)) {
            return true;
        } else {
            return $this->hasCast($key, $this->arrayLikeCastTypes());
        }
    }

    /**
     * Get the application's fallback locale (base locale).
     * @return string
     */
    public function getBaseLocale(): string
    {
        return (string) config('app.fallback_locale', 'en');
    }

    /**
     * Switch the current model instance to a given locale (mutates instance).
     * @param ?string $locale
     * @return static
     */
    public function locale(?string $locale = null)
    {
        $this->currentLocale = $locale ? $locale : app()->getLocale();
        return $this;
    }

    /**
     * Switch to a given locale without mutating the current model (returns clone).
     * @param null|string $locale
     * @return static
     */
    public function in(?string $locale)
    {
        $clone = clone $this;
        $clone->currentLocale = $locale ? $locale : app()->getLocale();
        return $clone;
    }

    /**
     * Temporarily switch the locale for the given callback and then restore it.
     * @param null|string $locale
     * @param callable $callback
     * @return mixed
     */
    public function withLocale(?string $locale, callable $callback)
    {
        $previous = $this->currentLocale ?? app()->getLocale();
        $this->locale($locale);

        try {
            return $callback($this);
        } finally {
            $this->locale($previous);
        }
    }

    /**
     * Retrieve a model attribute, applying translations if available for the current locale.
     * @param mixed $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $locale = $this->currentLocale ?? app()->getLocale();
        $translatable = $this->translatable ?? [];
        if ($locale == $this->getBaseLocale() || !in_array($key, $translatable)) {
            return parent::getAttribute($key);
        }

        $translation = $this->getTranslationModel($locale);
        if ($translation && isset($translation->strings[$key])) {
            return $translation->strings[$key];
        } else {
            return parent::getAttribute($key);
        }
    }

    /**
     * Set a model attribute, redirecting to translations if needed.
     * @param mixed $key
     * @param mixed $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        $translatable = $this->translatable ?? [];
        if (!in_array($key, $translatable)) {
            return parent::setAttribute($key, $value);
        }

        if (is_array($value) && !$this->isArrayLikeField($key)) {
            $keys = array_keys($value);
            $isLocaleMap = !empty($keys) && collect($keys)->every(
                fn ($k) => is_string($k) && preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $k)
            );

            if ($isLocaleMap) {
                foreach ($value as $locale => $translated) {
                    if ($locale === $this->getBaseLocale()) {
                        parent::setAttribute($key, $translated);
                    } else {
                        $this->pendingTranslations[$locale][$key] = $translated;
                    }
                }
                return $this;
            }

            throw new \InvalidArgumentException(sprintf(
                'Translatable attribute "%s" received an array but is not declared array-like. Add an array-like cast ($casts) or add it to translatableArrayAttributes.',
                $key
            ));
        } else {
            $locale = $this->currentLocale ?? app()->getLocale();
            if ($locale === $this->getBaseLocale()) {
                parent::setAttribute($key, $value);
            } else {
                $this->pendingTranslations[$locale][$key] = $value;
            }
        }
        return $this;
    }

    /**
     * Set a translation value for a specific attribute and locale.
     * @param string $locale
     * @param string $key
     * @param mixed $value
     * @return $this
     * @throws InvalidArgumentException
     */
    public function setTranslation(string $locale, string $key, mixed $value)
    {
        if (isset($this->translatable) && in_array($key, $this->translatable)) {
            if ($locale === $this->getBaseLocale()) {
                $this->setAttribute($key, $value);
            } else {
                if (!isset($this->pendingTranslations)) {
                    $this->pendingTranslations = [];
                }
                $this->pendingTranslations[$locale][$key] = $value;
            }
            return $this;
        }
        throw new \InvalidArgumentException(
            sprintf('The attribute "%s" is not marked as translatable on %s.', $key, static::class)
        );
    }

    /**
     * Get a translation value for a specific attribute and locale.
     * @param string $locale
     * @param string $key
     * @param bool $fallback
     * @return mixed
     * @throws InvalidArgumentException
     */
    public function getTranslation(string $locale, string $key, bool $fallback = true)
    {
        $translatable = $this->translatable ?? [];
        if (!in_array($key, $translatable)) {
            throw new \InvalidArgumentException(
                sprintf('The attribute "%s" is not marked as translatable on %s.', $key, static::class)
            );
        }

        if ($locale === $this->getBaseLocale()) {
            return $this->getAttributeFromArray($key) ?? $this->getAttribute($key);
        }

        $translation = $this->getTranslationModel($locale);
        if ($translation && isset($translation->strings[$key])) {
            return $translation->strings[$key];
        } else {
            return $fallback ? ($this->getAttributeFromArray($key) ?? $this->getAttribute($key)) : null;
        }
    }

    /**
     * Get all translations for the model, or only for a specific locale.
     * @param null|string $locale
     * @return array
     */
    public function getTranslations(?string $locale = null): array
    {
        $map = $this->localizations;
        return $locale ? ($map[$locale] ?? []) : $map;
    }

    /**
     * Remove a translation for a given attribute and locale.
     * @param string $locale
     * @param string $key
     * @return void
     */
    public function removeTranslation(string $locale, string $key): void
    {
        $translation = $this->getTranslationModel($locale);
        if ($translation) {
            $strings = $translation->strings;
            unset($strings[$key]);
            if (empty($strings)) {
                $translation->delete();
            } else {
                $translation->strings = $strings;
                $translation->save();
            }
        }
    }

    /**
     * Define the "translations" relationship (morph-many).
     * @return HasMany
     */
    public function translations(): HasMany
    {
        return $this->hasMany($this->getTranslationModelClass(), 'model_id')
            ->where('model_type', $this->getMorphClass());
    }

    /**
     * Accessor: Return all localizations for the model as an array.
     * @return array
     */
    public function localizations(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->translations
                ->mapWithKeys(fn ($model) => [$model->locale => $model->strings])
                ->toArray()
        );
    }

    /**
     * Convert the model to array, applying the current locale if necessary.
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();
        $locale = $this->currentLocale ?? app()->getLocale();
        if ($locale != $this->getBaseLocale()) {
            foreach ($this->translatable ?? [] as $key) {
                $array[$key] = $this->getAttribute($key);
            }
        }
        return $array;
    }

    /**
     * Scope: Filter models where a given attribute has a translation in the specified locale.
     * @param Builder $query
     * @param string $locale
     * @param string $key
     * @param string $value
     * @return Builder
     */
    public function scopeWhereLocale(Builder $query, string $locale, string $key, string $value)
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        if ($driver === 'mongodb') {
            return $query->whereHas('translations', function ($q) use ($locale, $key, $value) {
                $q->where('locale', $locale)->where("strings.$key", $value);
            });
        } else {
            $model = $query->getModel();
            return $query->whereHas('translations', function ($q) use ($model, $locale, $key, $value) {
                $q->where('locale', $locale);
                $model->jsonWhere($q, 'strings', $key, $value);
            });
        }
    }

    /**
     * Scope: Filter models that have at least one translation for the specified locale.
     * @param Builder $query
     * @param string $locale
     * @return Builder
     */
    public function scopeWhereHasLocale(Builder $query, string $locale): Builder
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        if ($driver === 'mongodb') {
            return $query->whereHas('translations', function ($q) use ($query, $locale) {
                $q->where('locale', $locale)->where('model_type', $query->getModel()->getMorphClass());
            });
        } else {
            $model = $query->getModel();
            return $query->whereHas('translations', function ($q) use ($model, $locale) {
                $q->where('locale', $locale)->where('model_type', $model->getMorphClass());
            });
        }
    }

    /**
     * Scope: Filter models that are missing a translation for the specified locale.
     * @param Builder $query
     * @param string $locale
     * @return Builder
     */
    public function scopeWhereMissingLocale(Builder $query, string $locale): Builder
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        if ($driver === 'mongodb') {
            $model = $query->getModel();
            return $query->whereDoesntHave('translations', function ($q) use ($model, $locale) {
                $q->where('locale', $locale)->where('model_type', $model->getMorphClass());
            });
        } else {
            $model = $query->getModel();
            $table = $this->newTranslationModel()->getTable();
            $local = $model->getTable();
            $morph = $model->getMorphClass();
            $localKey = $model->getKeyName();

            return $query
                ->leftJoin($table, function ($join) use ($table, $local, $morph, $locale, $localKey) {
                    $join->on("{$table}.model_id", '=', "$local.{$localKey}")
                        ->where("{$table}.model_type", '=', $morph)
                        ->where("{$table}.locale", '=', $locale);
                })
                ->whereNull("{$table}.{$localKey}")
                ->select("$local.*");
        }
    }

    /**
     * Scope: Alias for whereMissingLocale().
     * @param Builder $query
     * @param string $locale
     * @return Builder
     */
    public function scopeWhereDoesntHaveLocale(Builder $query, string $locale): Builder
    {
        return $this->scopeWhereMissingLocale($query, $locale);
    }

    /**
     * Scope: Order models by the translated value of a given attribute for a specific locale.
     * @param Builder $query
     * @param string $locale
     * @param mixed $key
     * @param string $direction
     * @return Builder
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function scopeOrderByLocale(Builder $query, string $locale, string $key, string $direction = 'ASC')
    {
        $driver = $query->getModel()->getConnection()->getDriverName();
        if ($driver === 'mongodb') {
            throw new \RuntimeException(sprintf(
                'Driver "%s" not supported in scopeOrderByLocale(). Supported: mysql, mariadb, pgsql, sqlite.',
                $driver
            ));
        }

        if ($key === '') {
            throw new \InvalidArgumentException(
                sprintf('The JSON key must not be empty on class %s.', static::class)
            );
        }

        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $model = $this->newTranslationModel();
        $table = $model->getTable();
        $morph = $this->getMorphClass();
        $local = $this->getTable();
        $localKey = $this->getKeyName();

        // Join
        $query->leftJoin($table, function ($join) use ($localKey, $locale, $morph, $local, $table) {
            $join->on("{$table}.model_id", '=', "{$local}.{$localKey}")
                ->where("{$table}.model_type", '=', $morph)
                ->where("{$table}.locale", '=', $locale);
        });

        // driver-specific order expression
        $order = match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'COALESCE(JSON_UNQUOTE(JSON_EXTRACT(%s.strings, \'$."%s"\')), %s.%s)',
                $table, str_replace('"', '\"', $key), $local, $key
            ),
            'pgsql' => sprintf(
                'COALESCE((%s.strings->>\'%s\'), %s.%s)',
                $table, str_replace("'", "''", $key), $local, $key
            ),
            'sqlite' => sprintf(
                'COALESCE(json_extract(%s.strings, \'$."%s"\'), %s.%s)',
                $table, str_replace('"', '\"', $key), $local, $key
            ),
            default => throw new \RuntimeException(sprintf(
                'Driver "%s" not supported in scopeOrderByLocale(). Supported: mysql, mariadb, pgsql, sqlite.',
                $driver
            )),
        };

        // Return
        return $query->orderByRaw($order . ' ' . $dir)->select("{$local}.*");
    }

    /**
     * Add a JSON WHERE clause for the given driver and key/value pair.
     * @param Builder $query
     * @param string $column
     * @param string $key
     * @param string $value
     * @return Builder
     * @throws InvalidArgumentException
     */
    protected function jsonWhere(Builder $query, string $column, string $key, string $value)
    {
        if ($key === '') {
            throw new \InvalidArgumentException(
                sprintf('The JSON key must not be empty on "%s" on class %s.', $column, static::class)
            );
        }

        $driver = $query->getModel()->getConnection()->getDriverName();
        return match ($driver) {
            'mysql'     => $query->where("{$column}->{$key}", $value),
            'mariadb'   => $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$key}\"')) = ?", [$value]),
            'pgsql'     => $query->whereRaw("({$column}->>'$key') = ?", [$value]),
            'sqlite'    => $query->whereRaw("json_extract({$column}, '$.$key') = ?", [$value]),
            'mongodb'   => $query->where("{$column}.{$key}", $value),
            default     => throw new \RuntimeException(sprintf(
                'JSON queries are not supported for driver "%s". Supported: mysql, mariadb, pgsql, sqlite and mongodb.',
                $driver
            )),
        };
    }
}
